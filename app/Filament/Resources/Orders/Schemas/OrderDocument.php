<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Filament\Resources\Shipments\Schemas\ShipmentTimeline;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Support\Money;
use Closure;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

/**
 * The parts the view and edit screens hold in common.
 *
 * Both screens are the same document — the same blocks in the same places, so
 * that opening the editor moves nothing on screen except which cells accept
 * typing. Anything identical between them belongs here rather than being
 * written twice and drifting apart.
 */
class OrderDocument
{
    /**
     * What stands where an optional detail would have been.
     *
     * Said once for both screens, and said in those words rather than left
     * blank: an empty cell reads as an Order someone filled in badly, and
     * whether to chase a customer for the missing line turns on which of the
     * two it is.
     */
    public const NOT_ASKED = 'Not asked for';

    /**
     * The heading of the Unpaid callout, spelled once so the test that pins it
     * and the screen that draws it cannot drift apart.
     */
    public const UNPAID_WARNING = 'Unpaid — no payment has been verified for this order.';

    /**
     * Statuses that mean "do not send this parcel".
     *
     * The document is deliberately shaped to look routine, which is exactly why
     * these need more than the status badge: the cost of skimming past one is a
     * courier delivering goods nobody is going to pay for.
     *
     * @return array<int, Callout>
     */
    public static function warnings(): array
    {
        return [
            Callout::make('Cancelled — this order will not be fulfilled.')
                ->description('The order genuinely happened, so it stays on the books. Do not hand it to a courier.')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->danger()
                ->visible(fn (?Order $record): bool => $record?->status === OrderStatus::Cancelled)
                ->columnSpanFull(),

            /**
             * Reachable because OrderResource drops the SoftDeletingScope from
             * its route binding, so a trashed Order still opens rather than
             * 404ing. A deleted Order is one that should never have existed —
             * a different thing from a Cancelled one, and both can be true.
             */
            Callout::make('Deleted — this order has been removed.')
                ->description('Restore it before working it. A deleted order is one that should never have existed, which is not the same as a cancelled one.')
                ->icon(Heroicon::OutlinedTrash)
                ->danger()
                ->visible(fn (?Order $record): bool => $record?->trashed() ?? false)
                ->columnSpanFull(),

            /**
             * The other axis (ADR-0013). Not a "do not send" — an Unpaid Order
             * sits in the queue like any other — but the call about it is a
             * different call, and the document is shaped to look routine.
             */
            Callout::make(self::UNPAID_WARNING)
                ->description('The customer chose to pay online and no payment has been confirmed. Offer to pay now, or switch the order to Cash on Delivery.')
                ->icon(Heroicon::OutlinedCreditCard)
                ->warning()
                ->visible(fn (?Order $record): bool => $record?->isUnpaid() ?? false)
                ->columnSpanFull(),
        ];
    }

    /**
     * How the Customer said they would pay, and whether they have.
     *
     * One line in the Order block on both screens, read-only on both: the
     * method is changed by the Retry Page or the "switch" action, never by
     * typing, so the form shows it as text the way it shows the order number.
     */
    public static function paymentMethod(): TextEntry
    {
        return TextEntry::make('payment_method')
            ->label('Payment')
            ->badge()
            ->state(fn (?Order $record): ?string => $record?->paymentBadgeLabel())
            ->color(fn (?Order $record): ?string => $record?->paymentBadgeColor())
            ->hintIcon(
                Heroicon::OutlinedQuestionMarkCircle,
                tooltip: 'Its own axis: a paid order still starts New and needs the confirmation call, and cancelling says nothing about the money.',
            );
    }

    /**
     * Why the Order was let past Free to Sell, and who said so (ADR-0037) —
     * "Taken against tomorrow's Supply — Karim". One line in the Order block
     * on both screens, drawn only on an Order that was, since every oversold
     * Order is meant to be explained and every other one has nothing to say.
     */
    public static function soldBeyondStock(): TextEntry
    {
        return TextEntry::make('sold_beyond_stock_reason')
            ->label('Sold beyond stock')
            ->color('warning')
            ->state(fn (?Order $record): ?string => $record?->sold_beyond_stock_reason === null
                ? null
                : $record->sold_beyond_stock_reason.($record->soldBeyondStockBy ? " — {$record->soldBeyondStockBy->name}" : ''))
            ->visible(fn (?Order $record): bool => $record?->sold_beyond_stock_reason !== null);
    }

    /**
     * Every Payment on the Order, with what the gateway said about each.
     *
     * Drawn for an Order under an online method, and for one that holds a
     * Payment whatever its method now says: an Order switched to Cash on
     * Delivery keeps its history, and a Pending Payment on it may still end
     * Paid. Absent only for a Cash on Delivery Order that never had one,
     * which is most Orders. Handed the Payments themselves as records rather
     * than form data: the edit screen hydrates whatever a repeatable holds,
     * and a Payment is written by a Verification alone. Empty where nothing
     * was ever started, and it says so, because an Unpaid Order with no
     * Payment is the "method the store no longer takes" case and an operator
     * should be able to tell it from a Customer who tried and failed.
     *
     * The two raw responses are printed whole, as JSON, because a dispute
     * months later needs what the gateway actually said rather than what was
     * made of it at the time (ADR-0013).
     *
     * @return array<int, Section>
     */
    public static function payments(): array
    {
        return [
            Section::make('Payments')
                ->columnSpanFull()
                ->visible(fn (?Order $record): bool => $record !== null && ($record->payment_method->isOnline() || $record->payments->isNotEmpty()))
                ->schema([
                    TextEntry::make('no_payments')
                        ->hiddenLabel()
                        ->state('No payment has been started.')
                        ->color('gray')
                        ->visible(fn (?Order $record): bool => $record?->payments->isEmpty() ?? true),

                    RepeatableEntry::make('payments')
                        ->hiddenLabel()
                        ->visible(fn (?Order $record): bool => $record?->payments->isNotEmpty() ?? false)
                        ->state(fn (?Order $record): array => $record?->payments->all() ?? [])
                        ->columns(3)
                        ->schema([
                            TextEntry::make('invoice_reference')
                                ->label('Reference')
                                ->copyable()
                                ->weight('bold')
                                ->hintIcon(
                                    Heroicon::OutlinedQuestionMarkCircle,
                                    tooltip: 'Our reference, as the gateway knows it: the order number plus the attempt.',
                                ),

                            TextEntry::make('method')
                                ->label('Method'),

                            TextEntry::make('state')
                                ->label('State')
                                ->badge(),

                            TextEntry::make('amount')
                                ->label('Amount')
                                ->formatStateUsing(fn (int $state, Payment $record): string => Money::format($state).' '.$record->currency),

                            TextEntry::make('gateway_reference')
                                ->label('Gateway reference')
                                ->copyable()
                                ->placeholder('None yet'),

                            TextEntry::make('verified_at')
                                ->label('Verified')
                                ->dateTime()
                                ->placeholder('Not verified'),

                            TextEntry::make('created_at')
                                ->label('Started')
                                ->dateTime(),

                            TextEntry::make('start_response')
                                ->label('Gateway said at start')
                                ->columnSpanFull()
                                ->fontFamily(FontFamily::Mono)
                                ->size(TextSize::ExtraSmall)
                                ->state(self::asJson('start_response'))
                                ->placeholder('Nothing recorded'),

                            TextEntry::make('verification_response')
                                ->label('Gateway said when asked')
                                ->columnSpanFull()
                                ->fontFamily(FontFamily::Mono)
                                ->size(TextSize::ExtraSmall)
                                ->state(self::asJson('verification_response'))
                                ->placeholder('Not asked yet'),
                        ]),
                ]),
        ];
    }

    /**
     * Every attempt to get this Order to a door, each with its own history
     * under it.
     *
     * One card per Shipment and never one merged list, because an Order's
     * delivery history is a history of attempts: a parcel that went out with
     * Steadfast, came back, and was re-sent with Pathao is two attempts that
     * cost money separately and failed for different reasons, and a single
     * run of dates would read as one confused journey. Each card names its
     * Courier for the same reason — "on hold" means four different things
     * across the three vocabularies (ADR-0018).
     *
     * Absent for an Order that has never been sent, which is every Order
     * until somebody presses the button. An empty "delivery attempts" heading
     * is a section a fulfiller learns to skip.
     *
     * Handed the Shipments themselves as records rather than as form data,
     * for the reason `payments()` gives: the edit screen hydrates whatever a
     * repeatable holds, and a Shipment is written by a booking and by the
     * sweep alone.
     *
     * @return array<int, Section>
     */
    public static function shipments(): array
    {
        return [
            Section::make('Delivery attempts')
                ->columnSpanFull()
                ->visible(fn (?Order $record): bool => $record?->shipments->isNotEmpty() ?? false)
                ->schema([
                    RepeatableEntry::make('shipments')
                        ->hiddenLabel()
                        /** Every attempt's history in one query rather than one per attempt: the timeline under each card reads `events`. */
                        ->state(fn (?Order $record): array => $record?->shipments->loadMissing('events')->all() ?? [])
                        ->columns(4)
                        ->schema([
                            TextEntry::make('courier')
                                ->label('Courier')
                                ->weight('bold')
                                ->size(TextSize::Large),

                            TextEntry::make('status')
                                ->label('State')
                                ->badge(),

                            /**
                             * The reference, and the way through to the
                             * attempt on its own screen: everything this card
                             * leaves out — what was sent, what the Courier
                             * answered, what it charged — is one click away
                             * rather than crowded in beside the next attempt.
                             */
                            TextEntry::make('invoice_reference')
                                ->label('Our reference')
                                ->copyable()
                                ->url(fn (Shipment $record): string => ShipmentResource::getUrl('view', ['record' => $record])),

                            TextEntry::make('consignment_reference')
                                ->label('Consignment')
                                ->copyable()
                                ->placeholder('None yet'),

                            ShipmentTimeline::entries()
                                ->columnSpanFull(),
                        ]),
                ]),
        ];
    }

    /**
     * A raw response as a person reads it: pretty-printed, with the slashes
     * in the gateway's URLs left alone.
     *
     * Handed to `state()` rather than `formatStateUsing()`, because a
     * TextEntry whose state is an array formats each element on its own as a
     * list, and a response is one document, not a list of its values. Null
     * where nothing was recorded, so the placeholder shows.
     */
    private static function asJson(string $attribute): Closure
    {
        return function (Payment $record) use ($attribute): ?string {
            $response = $record->getAttribute($attribute);

            return $response === null
                ? null
                : (string) json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        };
    }
}
