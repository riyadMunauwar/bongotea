<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\Courier;
use App\Filament\Resources\Orders\Schemas\OrderDocument;
use App\Models\Order;
use App\Models\Shipment;
use App\Support\Couriers\BookingNotMade;
use App\Support\Couriers\CourierBooker;
use App\Support\Couriers\CourierNotUsable;
use App\Support\Couriers\CourierUnreachable;
use App\Support\Couriers\DeliveryArea;
use App\Support\Couriers\DeliveryAreaMatcher;
use App\Support\Couriers\DeliveryAreaNotResolved;
use App\Support\Couriers\OrderAlreadyDelivered;
use App\Support\Couriers\OrderAlreadyShipping;
use App\Support\Couriers\ParcelDetails;
use App\Support\Couriers\ParcelField;
use App\Support\Money;
use App\Support\Settings\CourierSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * "Send with courier": a User reads back exactly what the Courier is about to
 * be told, corrects whatever is wrong, and watches the Consignment come back.
 *
 * A header action on both the reading and the working screen, never a field
 * on the form (ADR-0018's separation, the precedent the payment actions set):
 * the parcel is its own axis, and a save after a booking must not be able to
 * write stale data back over what the Courier said.
 *
 * The dialog is the whole point of the feature. A mistyped address is a parcel
 * the Merchant pays to have returned, and the moment to catch it is while the
 * Customer is still on the phone — so the recipient, what will be collected
 * and the note all go in front of the User before anything is sent, and a
 * correction to a shared detail is saved onto the Order as well as sent.
 *
 * What each Courier asks beyond that is the driver's declaration
 * (`ParcelField`) and nothing here knows any of it by name: the chosen
 * Courier's questions are drawn and no other's, pre-filled from the
 * installation's Settings, and a change is an override for this parcel rather
 * than a second place to configure the installation. A question with a single
 * possible answer — a Merchant with one pickup store — is never asked at all.
 *
 * One Courier's question is not an override of anything: a Courier that will
 * not read an address in free text (`needsAreaResolution()`) is asked which of
 * its own delivery areas the parcel is going to, guessed from the address and
 * pre-selected for a human to confirm. Nothing is sent until one is chosen —
 * a guessed depot is a parcel in the wrong city.
 *
 * The Courier is asked inside the request and the answer is shown at once —
 * the Consignment and the tracking code, which is the entire reason a single
 * booking is not swept (ADR-0020). A Courier that says no is worded back in
 * its own words with nothing recorded; a Courier that cannot be reached
 * leaves the Order exactly as it was and says to try again.
 */
class BookWithCourierAction
{
    public static function make(): Action
    {
        return Action::make('bookWithCourier')
            ->label('Send with courier')
            ->icon(Heroicon::OutlinedTruck)
            ->color('primary')

            /**
             * Offered while a parcel may be sent: this installation has a
             * usable Courier, nothing is already out for the Order, and
             * nothing of the Order's has reached a door. The booking asks all
             * three again under the Order's lock, because a screen is only as
             * fresh as the moment it was drawn.
             *
             * A returned Order is offered it, which is the point: a parcel
             * that came back is a question a User answers by sending again,
             * where a parcel that arrived is finished (ADR-0018).
             *
             * And never on a deleted Order.
             * `OrderResource::getRecordRouteBindingEloquentQuery()` drops the
             * soft-delete scope, so both of the Order's own screens open one
             * and the row shows under the Trashed filter — but the booking reads
             * the Order back under its lock through the scope that is still
             * on the model, so a parcel nobody may send would be refused with
             * a 404 after the User had filled the whole dialog in. An Order
             * that should never have existed is not one to send; restoring it
             * first is the answer, and that action is on the same row.
             */
            ->visible(fn (Order $record): bool => filled(self::couriers())
                && ! $record->trashed()
                && ! $record->isShipping()
                && ! $record->hasArrived())
            ->modalHeading('Send this order with a courier')
            ->modalDescription(fn (Order $record): string => self::whatWillBeCollected($record))
            ->modalSubmitActionLabel('Send')
            ->modalWidth(Width::TwoExtraLarge)
            ->fillForm(self::whatTheCourierWillBeTold(...))
            ->schema(self::dialog())
            ->action(function (Order $record, array $data, CourierBooker $booker): void {
                $courier = Courier::fromStored($data['courier'] ?? null);

                if ($courier === null) {
                    return;
                }

                $details = ParcelDetails::confirmed(
                    recipientName: $data['recipientName'] ?? null,
                    recipientAddress: $data['recipientAddress'] ?? null,
                    recipientDistrict: $data['recipientDistrict'] ?? null,
                    note: $data['note'] ?? null,

                    /** Only the chosen Courier's own answers: the others were never on screen and are not dehydrated. */
                    courierFields: is_array($data[$courier->value] ?? null) ? $data[$courier->value] : [],
                );

                try {
                    $shipment = $booker->book($record, $courier, $details);
                } catch (OrderAlreadyShipping $refusal) {
                    self::refuse('Already with a courier', $refusal->getMessage());

                    return;
                } catch (OrderAlreadyDelivered $refusal) {
                    /** Not a Courier's objection and not a temporary one: this Order is finished, and a second parcel would be one nobody ordered. */
                    self::refuse('Already delivered', $refusal->getMessage());

                    return;
                } catch (DeliveryAreaNotResolved $unresolved) {
                    /** A box on this screen and not a Courier's objection: nobody was asked, and the answer is on the dialog the User is looking at. */
                    self::refuse("{$courier->getLabel()} needs a delivery area", $unresolved->getMessage());

                    return;
                } catch (CourierUnreachable) {
                    self::refuse("{$courier->getLabel()} could not be reached", 'No parcel was sent. Anything you corrected is saved on the order. Try again in a moment.');

                    return;
                } catch (CourierNotUsable $unusable) {
                    /** Never worded as a refusal: nobody asked this Courier anything, and the answer is a Merchant's to give in Settings. */
                    self::refuse("{$courier->getLabel()} cannot be used", $unusable->getMessage());

                    return;
                } catch (BookingNotMade $refusal) {
                    self::refuse("{$courier->getLabel()} refused this parcel", $refusal->getMessage());

                    return;
                }

                $record->refresh();

                self::announce($shipment);
            });
    }

    /**
     * The dialog: who is being sent to, then whatever the chosen Courier asks
     * on top of that.
     *
     * @return list<mixed>
     */
    private static function dialog(): array
    {
        return [
            Select::make('courier')
                ->label('Courier')
                ->options(self::couriers(...))
                ->selectablePlaceholder(false)
                ->required()

                /** Live, because the questions underneath are the chosen Courier's and change with it. */
                ->live(),

            Section::make('Deliver to')
                ->description('What the courier will be told. A correction here is saved onto the order as well.')
                ->columns(2)
                ->schema([
                    TextInput::make('recipientName')
                        ->label('Name')
                        ->maxLength(255)

                        /** What goes out if this is left blank, shown as it will be sent rather than as an empty box. */
                        ->placeholder(CourierBooker::PLACEHOLDER_NAME),

                    /**
                     * Read back, never corrected here. A Customer *is* their
                     * phone number, so a changed number is a different
                     * Customer and the Order has to be re-pointed at them —
                     * which is the editor's job (`EditOrder`), done under
                     * validation this dialog does not run.
                     */
                    TextInput::make('recipientPhone')
                        ->label('Phone')
                        ->disabled()
                        ->hintIcon(
                            Heroicon::OutlinedPhone,
                            tooltip: 'A customer is their phone number, so a wrong number is corrected in the order editor, which moves the order to the right customer.',
                        ),

                    Select::make('recipientDistrict')
                        ->label('District')
                        ->options(fn (): array => array_combine(
                            config('giopages.districts'),
                            config('giopages.districts'),
                        ))
                        ->searchable()
                        ->native(false)
                        ->placeholder(OrderDocument::NOT_ASKED),

                    Textarea::make('recipientAddress')
                        ->label('Address')
                        ->rows(3)
                        ->maxLength(1000)
                        ->placeholder(CourierBooker::PLACEHOLDER_ADDRESS)
                        ->columnSpanFull(),

                    /**
                     * Sent with this parcel and not written back. It is the
                     * Customer's own answer to a Merchant's question on the
                     * Page, and an instruction a User adds for this rider is
                     * not a correction to what the Customer wrote.
                     */
                    Textarea::make('note')
                        ->label('Note for the courier')
                        ->rows(2)
                        ->maxLength(1000)
                        ->helperText('Sent with this parcel only — clear it to send none. The customer’s own note on the order is kept as they wrote it.')
                        ->columnSpanFull(),
                ]),

            ...self::courierQuestions(),
        ];
    }

    /**
     * One section per offered Courier, holding that Courier's own questions
     * and shown only while it is the one chosen.
     *
     * Built from the drivers' declarations, so nothing here names a field or a
     * Courier: a fourth Courier's questions appear the moment its driver
     * declares them. A Courier that asks nothing, or asks only questions with
     * a single possible answer, contributes no section at all.
     *
     * @return list<Section>
     */
    private static function courierQuestions(): array
    {
        $sections = [];

        foreach (self::offered() as $courier) {
            $driver = $courier->driver();

            $questions = array_map(
                fn (ParcelField $field): TextInput|Select => self::question($courier, $field),
                array_values(array_filter(
                    $driver->parcelFields(),
                    fn (ParcelField $field): bool => $field->isWorthAsking(),
                )),
            );

            /** Leads the Courier's own questions: it decides where the parcel goes, where the rest describe what it is. */
            if ($driver->needsAreaResolution()) {
                array_unshift($questions, self::deliveryArea($courier));
            }

            if ($questions === []) {
                continue;
            }

            $sections[] = Section::make("What {$courier->getLabel()} needs")
                ->description('Filled in from Settings. A change here applies to this parcel only.')
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('courier') === $courier->value)
                ->schema($questions);
        }

        return $sections;
    }

    /**
     * Which of this Courier's own delivery areas the parcel is going to,
     * opened on the system's guess and searchable so a User can correct it.
     *
     * Asked of every Courier that says it `needsAreaResolution()` and of no
     * Courier by name. The guess comes from the address and the District
     * (`DeliveryAreaMatcher`) and is pre-selected rather than merely offered,
     * because the common case is that it is right and the User is on the
     * phone — but it is a guess, so it is a box they can change, and a parcel
     * whose area the system could not guess opens on an empty one rather than
     * on the first area in the list.
     *
     * Searched rather than listed: a Courier publishes thousands of areas,
     * which is a chooser nobody can read and a payload nobody should be sent.
     */
    private static function deliveryArea(Courier $courier): Select
    {
        return Select::make("{$courier->value}.".DeliveryArea::ANSWER)
            ->label('Delivery area')
            ->helperText("{$courier->getLabel()} will not take a parcel without one of its own areas. Guessed from the address — correct it if it is wrong.")
            ->required()
            ->searchable()
            ->native(false)
            ->getSearchResultsUsing(fn (string $search): array => DeliveryArea::search($search, $courier->driver()->deliveryAreas()))
            ->getOptionLabelUsing(fn (mixed $value): ?string => DeliveryArea::find($value, $courier->driver()->deliveryAreas())?->label())
            ->columnSpanFull();
    }

    /** One of a Courier's own questions, drawn as the declaration describes it. */
    private static function question(Courier $courier, ParcelField $field): TextInput|Select
    {
        $statePath = self::statePath($courier, $field);

        if ($field->isChoice()) {
            return Select::make($statePath)
                ->label($field->label)
                ->options($field->options)
                ->selectablePlaceholder(false)
                ->native(false)
                ->required()
                ->helperText($field->helperText);
        }

        /** A length cap on a numeric input is a cap on its digits, which is a rule about a figure nobody meant. */
        return TextInput::make($statePath)
            ->label($field->label)
            ->when(
                $field->isNumeric,
                fn (TextInput $input): TextInput => $input->numeric()->minValue(0),
                fn (TextInput $input): TextInput => $input->maxLength(255),
            )
            ->helperText($field->helperText);
    }

    /**
     * Where one Courier's answer to one of its questions lives in the dialog's
     * state: under the Courier's own name, so two couriers asking about a
     * weight cannot read each other's answer.
     */
    private static function statePath(Courier $courier, ParcelField $field): string
    {
        return "{$courier->value}.{$field->name}";
    }

    /**
     * The dialog as it opens: the Order as it stands, and every offered
     * Courier's questions pre-filled from what the Merchant decided once in
     * Settings.
     *
     * Every Courier's answers are filled rather than only the first one's, so
     * changing the chooser reveals questions that are already answered instead
     * of blank boxes.
     *
     * @return array<string, mixed>
     */
    private static function whatTheCourierWillBeTold(Order $record): array
    {
        $settings = app(CourierSettings::class);

        $filled = [
            'courier' => array_key_first(self::couriers()),
            'recipientName' => $record->customer_name,
            'recipientPhone' => $record->customer_phone,
            'recipientDistrict' => $record->customer_district,
            'recipientAddress' => $record->customer_address,
            'note' => $record->deliveryNote(),
        ];

        foreach (self::offered() as $courier) {
            $driver = $courier->driver();

            foreach ($driver->parcelFields() as $field) {
                $filled[$courier->value][$field->name] = $field->default($settings);
            }

            /** A guess and not a default: it is read off this Order's own address rather than off anything a Merchant decided. */
            if ($driver->needsAreaResolution()) {
                $filled[$courier->value][DeliveryArea::ANSWER] = DeliveryAreaMatcher::guess(
                    $record->customer_address,
                    $record->customer_district,
                    $driver->deliveryAreas(),
                )?->id;
            }
        }

        return $filled;
    }

    /**
     * The couriers a parcel can actually be handed to on this installation.
     * One that is switched off or half-supplied is not offered at all, and why
     * is said in Settings rather than restated here.
     *
     * @return list<Courier>
     */
    private static function offered(): array
    {
        return app(CourierSettings::class)->usableCouriers();
    }

    /**
     * The same couriers by name, which is the shape the chooser takes.
     *
     * @return array<string, string>
     */
    private static function couriers(): array
    {
        $couriers = [];

        foreach (self::offered() as $courier) {
            $couriers[$courier->value] = $courier->getLabel();
        }

        return $couriers;
    }

    /**
     * What the Courier will be told to collect, said before the parcel goes
     * rather than discovered afterwards: nothing where money has already
     * moved, the Order's total — Delivery Charge included — otherwise.
     */
    private static function whatWillBeCollected(Order $record): string
    {
        return $record->amountDueOnDelivery() === 0
            ? 'This order is paid, so the courier will be told to collect nothing.'
            : 'The courier will be told to collect '.Money::format($record->amountDueOnDelivery()).' at the door, the delivery charge included.';
    }

    /**
     * The Consignment and the tracking code, in front of the User who asked
     * for them, while the Customer is still on the phone.
     */
    private static function announce(Shipment $shipment): void
    {
        $tracking = $shipment->tracking_code === null ? '' : " Tracking code {$shipment->tracking_code}.";

        Notification::make()
            ->title("Sent with {$shipment->courier->getLabel()}")
            ->body("Consignment {$shipment->consignment_reference}.{$tracking}")
            ->success()
            ->send();
    }

    /** Nothing was sent, and the reason is the Courier's own wording wherever there is one. */
    private static function refuse(string $title, string $reason): void
    {
        Notification::make()
            ->title($title)
            ->body($reason)
            ->danger()
            ->send();
    }
}
