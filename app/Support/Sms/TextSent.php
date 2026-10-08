<?php

namespace App\Support\Sms;

/**
 * What became of one text: the gateway took it, refused it, or said nothing.
 *
 * Refused and silent are kept apart because retrying helps with one and not
 * the other — a rejected token or a sender id the gateway does not know will
 * be rejected again, where an outage or a rate limit may have passed in a
 * minute.
 */
final class TextSent
{
    private function __construct(
        private readonly bool $sent,
        private readonly bool $worthRetrying,
        public readonly ?string $reference,
        public readonly ?string $reason,
    ) {}

    /** The gateway took the text, under its own reference where it gives one. */
    public static function sent(?string $reference = null): self
    {
        return new self(true, false, $reference, null);
    }

    /** The gateway answered and will not send it; asking again changes nothing. */
    public static function refused(string $reason): self
    {
        return new self(false, false, null, $reason);
    }

    /** The gateway could not be reached or would not take the call just now. */
    public static function unreachable(string $reason): self
    {
        return new self(false, true, null, $reason);
    }

    public function isSent(): bool
    {
        return $this->sent;
    }

    public function isWorthRetrying(): bool
    {
        return $this->worthRetrying;
    }
}
