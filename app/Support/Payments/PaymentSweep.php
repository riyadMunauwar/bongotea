<?php

namespace App\Support\Payments;

use App\Enums\PaymentState;
use App\Models\Payment;
use Illuminate\Support\LazyCollection;

/**
 * The sweep over Pending Payments: the third place a Verification runs from,
 * for the Customer who paid and closed the browser before the gateway could
 * send them back, and for the Payment nothing will ever answer for.
 *
 * Two rules by age. A Pending Payment older than a couple of minutes is asked
 * about again — younger than that, the Customer may still be on the gateway's
 * page, and the Processing Page will ask. One older than a day is asked
 * about one last time and then marked Expired, so the queue is not full of
 * Payments that will never resolve. Everything settled is left alone.
 */
class PaymentSweep
{
    /** How old a Pending Payment must be before the sweep asks about it. */
    public const RECHECK_AFTER_SECONDS = 120;

    /** How old a Pending Payment may be before the sweep gives up on it. */
    public const EXPIRE_AFTER_SECONDS = 86400;

    public function __construct(private readonly PaymentVerifier $verifier) {}

    /**
     * How many Pending Payments were asked about, and how many of those were
     * given up on.
     *
     * @return array{verified: int, expired: int}
     */
    public function run(): array
    {
        $verified = 0;
        $expired = 0;

        $this->pendingOlderThan(self::RECHECK_AFTER_SECONDS)->each(function (Payment $payment) use (&$verified, &$expired): void {
            $verified++;

            $outcome = $payment->created_at->lte(now()->subSeconds(self::EXPIRE_AFTER_SECONDS))
                ? $this->verifier->verifyOrExpire($payment)
                : $this->verifier->verify($payment);

            if ($outcome->state === PaymentState::Expired) {
                $expired++;
            }
        });

        return ['verified' => $verified, 'expired' => $expired];
    }

    /**
     * @return LazyCollection<int, Payment>
     */
    private function pendingOlderThan(int $seconds): LazyCollection
    {
        return Payment::query()
            ->where('state', PaymentState::Pending)
            ->where('created_at', '<=', now()->subSeconds($seconds))
            ->orderBy('id')
            ->lazyById();
    }
}
