<?php

namespace App\Support\Couriers;

use App\Support\Settings\CourierSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The delivery areas RedEx will take a parcel to, fetched from RedEx once and
 * held.
 *
 * Derived state a Courier publishes, kept the way `PathaoAccessToken` keeps a
 * token and for the same reason: it is not a thing a Merchant owns or types,
 * and it must not be asked for every time a parcel goes. RedEx lists
 * thousands of areas and changes them rarely, so the list is fetched on the
 * first booking that needs it and answers every booking after that out of the
 * cache — which is the difference between one call a day and one call per
 * parcel on a two-hundred-parcel batch.
 *
 * Its own class rather than a method on the driver, because it has a
 * lifecycle the driver has not: it is fetched, it is held, it goes stale. The
 * driver asks for a list of areas and knows none of that.
 *
 * Not keyed by the Merchant's token, unlike Pathao's cached token, which *is*
 * that Merchant's credentials' product. RedEx's areas are RedEx's own
 * geography and the same list for every account, so a corrected token does
 * not make them a different list.
 *
 * A fetch that fails answers with no areas rather than raising: the list is
 * read while a dialog is being drawn, and a screen that will not open is a
 * worse answer than a chooser with nothing in it beside a booking that
 * refuses itself in words (`DeliveryAreaNotResolved`, which words an empty
 * list and an unanswered question together, because to the operator holding
 * an empty chooser they are the same moment).
 */
class RedExAreas
{
    private const PATH = '/areas';

    /** Where the list is held between requests. One list for the installation, because it is one list at RedEx. */
    private const CACHE_KEY = 'courier.redex.areas';

    /** A day. RedEx adds an area from time to time; nothing about a parcel turns on hearing about it within the hour. */
    private const LIFETIME_SECONDS = 86400;

    /**
     * How long RedEx having said nothing is held for.
     *
     * A minute and not a day, and not nothing at all. Not a day, because an
     * installation must not be unable to address a parcel until tomorrow over
     * one bad moment. Not nothing, because RedEx has no bulk endpoint: a sweep
     * of two hundred parcels against a RedEx that is down would otherwise be
     * two hundred lists asked for and two hundred timeouts waited out.
     */
    private const SILENCE_SECONDS = 60;

    /** Seconds to connect, and seconds for the whole call: a User is waiting on the dialog this fills. */
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 15;

    public function __construct(
        private readonly CourierSettings $settings,
        private readonly Http $http,
    ) {}

    /**
     * Every area RedEx will deliver to: the held list where there is one, a
     * freshly fetched list otherwise, and none at all where RedEx could not
     * be asked.
     *
     * @return list<DeliveryArea>
     */
    public function all(): array
    {
        $held = Cache::get(self::CACHE_KEY);

        if (! is_array($held)) {
            $held = $this->fetch();

            /** An answer of nothing is held too, and briefly: held apart, so that a bad moment is not tomorrow's list. */
            Cache::put(self::CACHE_KEY, $held, $held === [] ? self::SILENCE_SECONDS : self::LIFETIME_SECONDS);
        }

        return self::areasFrom($held);
    }

    /**
     * RedEx's area list as RedEx gave it, rows and all, or nothing at all
     * where RedEx could not be asked or would not say.
     *
     * Held as it arrived rather than as this reads it: `areasFrom()` is then
     * the one place a row becomes an area, and a list coming back out of the
     * cache is read exactly as carefully as one straight off the wire.
     *
     * @return array<array-key, mixed>
     */
    private function fetch(): array
    {
        try {
            $response = $this->http
                ->withHeaders(['API-ACCESS-TOKEN' => 'Bearer '.$this->settings->redexAccessToken])
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->get(RedEx::HOST.self::PATH);
        } catch (ConnectionException $exception) {
            Log::warning('RedEx could not be reached for its delivery areas.', ['error' => $exception->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            Log::warning('RedEx would not give its delivery areas.', ['status' => $response->status()]);

            return [];
        }

        $rows = $response->json('areas');

        return is_array($rows) ? $rows : [];
    }

    /**
     * RedEx's rows as areas: the id it insists on being told, the name it
     * spells that area under, and the District a guess is settled by.
     *
     * The one place a row becomes an area, so a list read back out of the
     * cache is read exactly as carefully as one straight off the wire, and the
     * three fields this uses are named once. A row with no usable id or name
     * is dropped rather than raised over — RedEx could not be told about that
     * area anyway, and one malformed row out of thousands must not be a
     * Merchant unable to send anything.
     *
     * @param  array<array-key, mixed>  $rows
     * @return list<DeliveryArea>
     */
    private static function areasFrom(array $rows): array
    {
        $areas = [];

        foreach ($rows as $row) {
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            $name = is_array($row) ? ($row['name'] ?? null) : null;

            if (! is_scalar($id) || blank($id) || ! is_string($name) || blank($name)) {
                continue;
            }

            $district = $row['district_name'] ?? null;

            $areas[] = new DeliveryArea((string) $id, $name, is_string($district) ? $district : null);
        }

        return $areas;
    }
}
