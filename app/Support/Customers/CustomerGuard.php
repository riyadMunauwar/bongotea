<?php

namespace App\Support\Customers;

use App\Models\Customer;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Who is Signed in on this browser: a phone number proven with a One-Time
 * Code, and nothing else.
 *
 * Its own guard beside the admin's, because a Customer is never a User. It
 * keeps what it knows in a dedicated, framework-encrypted cookie rather than
 * in the session, because the session is the admin's two hours and a
 * Customer stays signed in for thirty days; the expiry is written inside the
 * cookie too, so one replayed after its thirty days is refused here and not
 * only by the browser that dropped it.
 *
 * Signing in creates nothing: the Customer handed back is the number's own
 * row where an Order has made one, and an unsaved one where none has.
 */
class CustomerGuard implements Guard
{
    use GuardHelpers;

    public const COOKIE = 'customer_session';

    public const LIFETIME_DAYS = 30;

    public function __construct(private Request $request) {}

    public function user(): ?Customer
    {
        if ($this->user === null) {
            $phone = $this->signedInPhone();

            $this->user = $phone === null ? null : $this->customerFor($phone);
        }

        return $this->user;
    }

    /**
     * Nothing is signed in by credentials here: only `signIn()`, once a
     * One-Time Code has been redeemed.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    /**
     * Sign this browser in as a number in its canonical form.
     */
    public function signIn(string $phone): void
    {
        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: (string) json_encode(['phone' => $phone, 'until' => now()->addDays(self::LIFETIME_DAYS)->getTimestamp()]),
            minutes: self::LIFETIME_DAYS * 24 * 60,
            path: '/',
            sameSite: 'lax',
        ));

        $this->user = $this->customerFor($phone);
    }

    public function signOut(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE, '/'));

        $this->user = null;
    }

    /**
     * The request a long-lived guard reads, swapped for each new one.
     */
    public function setRequest(Request $request): static
    {
        $this->request = $request;
        $this->user = null;

        return $this;
    }

    /**
     * The number the cookie names while it is still good, or null. Read
     * totally: anything unparseable is nobody.
     */
    private function signedInPhone(): ?string
    {
        $stored = $this->request->cookie(self::COOKIE);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        if (! is_array($decoded) || ! is_string($decoded['phone'] ?? null) || ! is_int($decoded['until'] ?? null)) {
            return null;
        }

        return $decoded['until'] > now()->getTimestamp() ? $decoded['phone'] : null;
    }

    private function customerFor(string $phone): Customer
    {
        return Customer::query()->firstOrNew(['phone' => $phone]);
    }
}
