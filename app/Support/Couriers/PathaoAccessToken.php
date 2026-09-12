<?php

namespace App\Support\Couriers;

use App\Support\Settings\CourierSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;

/**
 * The access token Pathao's calls are signed with, obtained from the
 * Merchant's four credentials and kept only as long as Pathao says it is good
 * for.
 *
 * Derived state a machine issues and ages out, never a credential a human
 * owns — which is the whole reason it is here and not in `CourierSettings`
 * beside the four it is issued from. A Merchant types a client id, a client
 * secret, a username and a password once; nobody is ever asked to paste a
 * token, and nobody has to notice one expiring at three in the morning.
 *
 * Its own class rather than three private methods on the driver, because it
 * has a lifecycle the driver has not: it is issued, it is held, it goes stale,
 * and it is thrown away when Pathao stops recognising it. `Pathao` asks for a
 * word to put in a header and knows none of that.
 *
 * The refresh-token grant Pathao also offers is deliberately unused. The
 * password grant is available at every moment this class runs, so a refresh
 * token would be a second code path that can itself expire and would fall
 * back here anyway — one way of getting a token is one way of getting it
 * wrong.
 */
class PathaoAccessToken
{
    private const PATH = '/aladdin/api/v1/issue-token';

    /** Pathao's own name for "here are the merchant's credentials, issue me a token". */
    private const GRANT_TYPE = 'password';

    /**
     * How long before Pathao's own expiry the held token is let go of.
     *
     * Renewing early is the whole point: a token handed to a booking with two
     * seconds left on it is a parcel refused while a User waits, and the cost
     * of being early is one extra grant call every few days.
     */
    private const RENEW_BEFORE_SECONDS = 300;

    /**
     * How long a token is held when Pathao says nothing about its lifetime, and
     * the floor under a lifetime the margin above would otherwise wipe out.
     */
    private const SHORTEST_LIFETIME_SECONDS = 60;

    /** Seconds to connect, and seconds for the whole call: a User is waiting on the booking behind this. */
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 15;

    /**
     * Where the token is held between requests. Keyed by the credentials it
     * was issued from, so a Merchant who has just corrected a mistyped
     * password is not held to a token issued from the wrong one for the rest
     * of the day.
     */
    private const CACHE_PREFIX = 'courier.pathao.access-token.';

    public function __construct(
        private readonly CourierSettings $settings,
        private readonly Http $http,
    ) {}

    /**
     * A token good to sign a call with: the one being held where it is still
     * fresh, a newly issued one otherwise.
     *
     * @throws CourierUnreachable when Pathao could not be asked for one.
     * @throws CourierNotUsable when Pathao answered and would not issue one.
     */
    public function value(): string
    {
        $held = Cache::get($this->cacheKey());

        if (is_string($held) && filled($held)) {
            return $held;
        }

        return $this->issue();
    }

    /**
     * Let go of the held token, so the next call asks Pathao for a fresh one.
     *
     * For the case the margin cannot cover: a token Pathao stopped
     * recognising before it was due to expire, because the Merchant's account
     * was re-issued or their session was revoked at Pathao's end. Without
     * this, one such answer would poison every booking until the held token
     * aged out on its own.
     */
    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * Ask Pathao for a token on the Merchant's credentials and hold it.
     *
     * A grant Pathao answers and refuses is `CourierNotUsable` and never a
     * refusal of the parcel: nobody asked Pathao about a parcel, and an
     * operator told "Pathao refused this parcel" would ring Pathao about a
     * password only the Merchant can retype.
     *
     * @throws CourierUnreachable
     * @throws CourierNotUsable
     */
    private function issue(): string
    {
        try {
            $response = $this->http
                ->asJson()
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->post(Pathao::HOST.self::PATH, [
                    'client_id' => $this->settings->pathaoClientId,
                    'client_secret' => $this->settings->pathaoClientSecret,
                    'username' => $this->settings->pathaoUsername,
                    'password' => $this->settings->pathaoPassword,
                    'grant_type' => self::GRANT_TYPE,
                ]);
        } catch (ConnectionException $exception) {
            throw new CourierUnreachable(
                'Pathao could not be reached to issue an access token.',
                ['error' => $exception->getMessage()],
            );
        }

        $answer = PathaoAnswer::of($response);
        $token = $answer->text('access_token');

        if ($token === null) {
            throw new CourierNotUsable(
                'Pathao would not issue an access token: '.$answer->reason().' Check its credentials in Settings.',
                $answer->body(),
            );
        }

        Cache::put($this->cacheKey(), $token, $this->lifetime($answer));

        return $token;
    }

    /**
     * How long the token is held for: what Pathao said it was good for, less
     * the renewal margin, and never less than the floor.
     */
    private function lifetime(PathaoAnswer $answer): int
    {
        $expiresIn = $answer->get('expires_in');

        if (! is_numeric($expiresIn)) {
            return self::SHORTEST_LIFETIME_SECONDS;
        }

        return max((int) $expiresIn - self::RENEW_BEFORE_SECONDS, self::SHORTEST_LIFETIME_SECONDS);
    }

    /**
     * A fingerprint of the credentials and never the credentials themselves:
     * a cache store is not a place a Merchant's password belongs, and all
     * this has to answer is "were these the ones it was issued from".
     */
    private function cacheKey(): string
    {
        return self::CACHE_PREFIX.hash('sha256', implode('|', [
            $this->settings->pathaoClientId,
            $this->settings->pathaoClientSecret,
            $this->settings->pathaoUsername,
            $this->settings->pathaoPassword,
        ]));
    }
}
