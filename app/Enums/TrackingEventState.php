<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where one outbox row stands: one provider event, for one Provider,
 * waiting to be delivered or done with.
 *
 * Mirrors `PaymentState` deliberately, including the rule that matters most:
 * a transport failure is never final. A Provider that cannot be reached, or
 * that answers 5xx, leaves the row Pending for the next sweep; only a refusal
 * the Provider itself made — a credential or payload defect a human must fix
 * — writes Failed. Expired is a row the Provider will no longer accept
 * whatever we do, so the queue can drain.
 */
enum TrackingEventState: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Expired = 'expired';

    /**
     * The states that mean an event a Provider was owed never got there: one
     * it refused, and one it would no longer accept. One wording, because
     * both the prune's longer retention and the diagnostics screen's list are
     * about the same set and must not be able to disagree about it.
     *
     * @return list<self>
     */
    public static function undelivered(): array
    {
        return [self::Failed, self::Expired];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Sent => 'success',
            self::Failed => 'danger',
            self::Expired => 'gray',
        };
    }
}
