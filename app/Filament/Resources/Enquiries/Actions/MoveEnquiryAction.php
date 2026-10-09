<?php

namespace App\Filament\Resources\Enquiries\Actions;

use App\Enums\EnquiryStatus;
use App\Models\Enquiry;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Moving an Enquiry along: "Replied" once somebody has called back, "Close"
 * once there is nothing more to do, and "Reopen" for one closed too soon.
 * Each is offered only where it moves the Enquiry somewhere new, and the
 * User who presses it is who handled it.
 */
class MoveEnquiryAction
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            self::make(EnquiryStatus::Replied, 'Mark replied', Heroicon::OutlinedPhoneArrowUpRight, 'info'),
            self::make(EnquiryStatus::Closed, 'Close', Heroicon::OutlinedCheckCircle, 'gray'),
            self::make(EnquiryStatus::New, 'Reopen', Heroicon::OutlinedArrowUturnLeft, 'warning'),
        ];
    }

    private static function make(EnquiryStatus $status, string $label, Heroicon $icon, string $color): Action
    {
        return Action::make('moveTo'.ucfirst($status->value))
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (Enquiry $record): bool => $record->canMoveTo($status))
            ->action(function (Enquiry $record, Action $action) use ($status): void {
                $record->moveTo($status, auth()->user());

                $action->success();
            })
            ->successNotificationTitle(fn (Enquiry $record): string => "{$record->name}'s enquiry is {$status->getLabel()}");
    }
}
