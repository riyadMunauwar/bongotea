<?php

namespace App\Support\Sms;

use App\Support\Settings\SmsSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One text message to one number, sent from the queue through whichever SMS
 * Gateway the store sends through.
 *
 * A gateway that said nothing is asked again a few times, a minute or so
 * apart; one that refused is not, because it would refuse again. Neither
 * ever fails the job: what did not go out is logged, and the Order it was
 * about has long since moved on without it.
 */
class SendText implements ShouldQueue
{
    use Queueable;

    /** One send and two more for a gateway that said nothing. */
    public int $tries = 3;

    /** How long a gateway that said nothing is left before it is asked again. */
    private const RETRY_AFTER_SECONDS = 60;

    public function __construct(
        public readonly string $phone,
        public readonly string $message,
    ) {}

    public function handle(): void
    {
        /** A worker lives across many jobs, and the gateway a Merchant chose since it started is the one that sends. */
        app()->forgetInstance(SmsSettings::class);

        $driver = app(SmsSettings::class)->gateway()->driver();

        $refusal = $driver->refusal();

        if ($refusal !== null) {
            Log::warning('A text message was not sent: the SMS Gateway cannot be used.', ['to' => $this->phone, 'reason' => $refusal]);

            return;
        }

        $outcome = $driver->send($this->phone, $this->message);

        if ($outcome->isSent()) {
            return;
        }

        if ($outcome->isWorthRetrying() && $this->attempts() < $this->tries) {
            $this->release(self::RETRY_AFTER_SECONDS);

            return;
        }

        Log::warning('A text message was not sent.', ['to' => $this->phone, 'reason' => $outcome->reason]);
    }
}
