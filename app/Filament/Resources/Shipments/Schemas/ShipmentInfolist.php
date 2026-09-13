<?php

namespace App\Filament\Resources\Shipments\Schemas;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Shipment;
use App\Support\Money;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

/**
 * One attempt, read on its own: what was sent, what the Courier answered,
 * what it charged, what it was to collect, and everything that has happened
 * to it since.
 *
 * The shape follows the questions asked of a parcel in the order they get
 * asked — who has it and under what name, where it was going, what money
 * rides on it, what the Courier was told, what it said back — with the
 * timeline last, because that is the part that keeps growing.
 *
 * Every figure here is what this attempt was sent with and never what the
 * Order says today: an address corrected after a parcel left is a correction
 * the *next* attempt carries, and being able to see the two differ is how a
 * return gets explained (ADR-0018).
 */
class ShipmentInfolist
{
    /**
     * What stands where a Courier has not named its charge.
     *
     * Said in those words rather than left blank or read as zero: Steadfast
     * quotes nothing when it takes a parcel and names the charge later,
     * against the payment it settles, so an empty cell here is the ordinary
     * case and never a free delivery.
     */
    public const CHARGE_NOT_SAID = 'Not said yet';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                /**
                 * A fact that is neither a status nor an outcome, and so
                 * cannot be read off the State badge: this attempt has gone on
                 * so long that somebody should look at it. Written once by the
                 * sweep and never cleared, which is why it is said as a date
                 * rather than as a flag.
                 *
                 * Abandonment is the other such fact and is deliberately not
                 * drawn yet: nothing writes `abandoned_at` — the action that
                 * will is still to be built — so a callout for it would be a
                 * screen for a state this system cannot reach.
                 */
                Callout::make('Stuck — this parcel has been out far longer than it should be.')
                    ->description(fn (Shipment $record): string => 'Nobody has been able to account for it since '.$record->stuck_at?->format('M j, Y').'. It is still being asked about.')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->warning()
                    ->visible(fn (Shipment $record): bool => $record->stuck_at !== null && $record->abandoned_at === null)
                    ->columnSpanFull(),

                Section::make('Attempt')
                    ->schema([
                        TextEntry::make('courier')
                            ->label('Courier')
                            ->weight('bold'),

                        TextEntry::make('status')
                            ->label('State')
                            ->badge()
                            ->hintIcon(
                                Heroicon::OutlinedQuestionMarkCircle,
                                tooltip: 'Our words, not the courier\'s. Returned means the parcel came back — it is the courier\'s outcome and says nothing about whether the order was cancelled.',
                            ),

                        ShipmentTimeline::couriersOwnWord(),

                        TextEntry::make('invoice_reference')
                            ->label('Our reference')
                            ->copyable()
                            ->hintIcon(
                                Heroicon::OutlinedQuestionMarkCircle,
                                tooltip: 'Ours, as the courier knows it: the order number plus the attempt. A re-send after a return carries the next number, because a courier refuses a duplicate.',
                            ),

                        TextEntry::make('consignment_reference')
                            ->label('Consignment')
                            ->copyable()
                            ->placeholder('None yet')
                            ->hintIcon(
                                Heroicon::OutlinedQuestionMarkCircle,
                                tooltip: 'The courier\'s own identifier, and the only name it will answer to. Quote it, never interpret it.',
                            ),

                        TextEntry::make('tracking_code')
                            ->label('Tracking code')
                            ->copyable()
                            ->placeholder('None'),

                        /**
                         * The way back to the Order, which is where anything
                         * a User can actually do about this parcel lives.
                         */
                        TextEntry::make('order.order_number')
                            ->label('Order')
                            ->url(fn (Shipment $record): string => OrderResource::getUrl('view', ['record' => $record->order_id])),

                        TextEntry::make('booked_at')
                            ->label('Handed over')
                            ->dateTime()
                            ->placeholder('Not yet'),

                        TextEntry::make('polled_at')
                            ->label('Courier last answered')
                            ->dateTime()
                            ->placeholder('Never asked')
                            ->hintIcon(
                                Heroicon::OutlinedQuestionMarkCircle,
                                tooltip: 'When this courier last answered a question about this parcel — not when it was last asked. A courier that could not be reached leaves this alone, so the next sweep asks again.',
                            ),
                    ]),

                /**
                 * The recipient as this attempt was told it, which need not be
                 * what the Order says now. Attempt 1 and attempt 2 can and
                 * should differ — that difference is usually the whole reason
                 * there was a second one.
                 */
                Section::make('Sent to')
                    ->schema([
                        TextEntry::make('recipient_name')
                            ->label('Name'),

                        TextEntry::make('recipient_phone')
                            ->label('Phone')
                            ->copyable(),

                        TextEntry::make('recipient_district')
                            ->label('District')
                            ->placeholder('—'),

                        TextEntry::make('recipient_address')
                            ->label('Address')
                            ->columnSpanFull(),

                        TextEntry::make('recipient_note')
                            ->label('Note for the rider')
                            ->columnSpanFull()
                            ->placeholder('None'),
                    ])
                    ->description('As this parcel was sent, not as the order reads today. Correcting an address writes to the order; what went out with this attempt stays here.'),

                Section::make('Money')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('collect_amount')
                            ->label('To collect at the door')
                            ->formatStateUsing(fn (int $state): string => Money::format($state))
                            ->weight('bold')
                            ->size(TextSize::Large)
                            ->hintIcon(
                                Heroicon::OutlinedQuestionMarkCircle,
                                tooltip: 'What this parcel went out to collect: nothing where the order was already paid, its total — delivery included — otherwise.',
                            ),

                        TextEntry::make('courier_charge')
                            ->label('The courier\'s charge')
                            ->formatStateUsing(fn (int $state): string => Money::format($state))
                            ->placeholder(self::CHARGE_NOT_SAID),
                    ]),

                /**
                 * What this Courier alone needed told about the parcel, by
                 * the names its own driver declared them under. Absent for a
                 * Courier that asked nothing, which is not the same as one
                 * that asked and was told nothing.
                 */
                Section::make('What the courier was told about the parcel')
                    ->columnSpanFull()
                    ->visible(fn (Shipment $record): bool => filled($record->parcel_details))
                    ->schema([
                        KeyValueEntry::make('parcel_details')
                            ->hiddenLabel()
                            ->keyLabel('Asked')
                            ->valueLabel('Sent'),
                    ]),

                /**
                 * Printed whole, as JSON, for the reason a gateway's response
                 * is: a dispute months later needs what the Courier actually
                 * said rather than what was made of it at the time. Handed to
                 * `state()` and not `formatStateUsing()`, because a TextEntry
                 * given an array formats each element on its own as a list,
                 * and an answer is one document.
                 */
                Section::make('What the courier answered')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('booking_response')
                            ->hiddenLabel()
                            ->fontFamily(FontFamily::Mono)
                            ->size(TextSize::ExtraSmall)
                            ->state(fn (Shipment $record): ?string => $record->booking_response === null
                                ? null
                                : (string) json_encode($record->booking_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                            ->placeholder('Nothing recorded'),
                    ]),

                Section::make('Timeline')
                    ->columnSpanFull()
                    ->schema([
                        ShipmentTimeline::entries(),
                    ]),
            ]);
    }
}
