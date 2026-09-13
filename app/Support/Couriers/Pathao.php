<?php

namespace App\Support\Couriers;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Support\Money;
use App\Support\PhoneNumber;
use App\Support\Settings\CourierSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;

/**
 * Pathao: an OAuth password grant issuing an access token that expires, a
 * store a parcel leaves from, many parcels to a booking, and both a status
 * and a message announced back.
 *
 * The credentials here are the four the token grant takes. The access token
 * itself is not among them and never becomes a Setting: it is derived state
 * a machine issues and ages out, so it is held by `PathaoAccessToken` and
 * renewed before it expires, rather than being something a Merchant is
 * expected to paste again every few hours.
 *
 * Pathao populates city, zone and area from the address it is given, so
 * nothing here resolves an area and none of the three is sent at all — the
 * Checkout's free-text address is the whole of where the parcel is going
 * (ADR-0018). The price plan that would have quoted a charge up front is
 * given up with them: it takes a city and a zone as mandatory integers, and
 * asking a Customer for either is a question the Checkout will not grow for a
 * courier's sake.
 */
class Pathao implements CourierDriver
{
    /**
     * Where Pathao answers. Public because the token grant is a call to the
     * same host made by the class that holds the token, which is the one
     * thing outside this class that speaks to Pathao at all.
     */
    public const HOST = 'https://api-hermes.pathao.com';

    private const BOOKING_PATH = '/aladdin/api/v1/orders';

    /**
     * Where Pathao is asked about a parcel, by the Consignment it issued. It
     * answers with where the parcel stands now and keeps no history of how it
     * got there, which is why a Pathao timeline is built out of what its
     * webhook narrated and what these polls caught (ADR-0019).
     */
    private const TRACKING_PATH = '/aladdin/api/v1/orders/%s/info';

    /**
     * Pathao's own status words, as its documentation lists them, in the
     * system's vocabulary (ADR-0018).
     *
     * The only place these words are spoken. Pathao narrates a parcel far more
     * finely than Steadfast does — five separate words for a parcel that is
     * simply on its way — and all of them read as In Transit, because the
     * canonical set says where a parcel stands while the Courier's own word is
     * kept beside it for the timeline to show verbatim.
     *
     * Note `cancelled`, which is a parcel that came back and means
     * **Returned** — never anything resembling the Order's own Cancelled,
     * which is a User's decision and not a courier's. A pickup that failed or
     * was called off is On Hold and not Returned: that parcel never left the
     * Merchant's hands, so there is nothing for it to have come back from.
     *
     * @var array<string, ShipmentStatus>
     */
    private const STATUSES = [
        'pending' => ShipmentStatus::Booked,
        'pickup_requested' => ShipmentStatus::Booked,
        'assigned_for_pickup' => ShipmentStatus::Booked,
        'picked' => ShipmentStatus::InTransit,
        'at_the_sorting_hub' => ShipmentStatus::InTransit,
        'in_transit' => ShipmentStatus::InTransit,
        'received_at_last_mile_hub' => ShipmentStatus::InTransit,
        'assigned_for_delivery' => ShipmentStatus::InTransit,
        'on_hold' => ShipmentStatus::OnHold,
        'pickup_failed' => ShipmentStatus::OnHold,
        'pickup_cancelled' => ShipmentStatus::OnHold,
        'delivery_failed' => ShipmentStatus::OnHold,
        'delivered' => ShipmentStatus::Delivered,
        'partial_delivery' => ShipmentStatus::PartlyDelivered,
        'cancelled' => ShipmentStatus::Returned,
        'returned' => ShipmentStatus::Returned,
    ];

    /**
     * Parcels one booking call may carry.
     *
     * Pathao documents a bulk endpoint and no ceiling for it, so this is a
     * deliberate conservative cut rather than a limit anybody published: a
     * refused oversized batch would cost a whole sweep's parcels, and the
     * sweep is the retry either way.
     */
    private const BATCH_LIMIT = 100;

    /**
     * How long a Shipment is left alone before Pathao is asked about it
     * again: four hours, because Pathao announces a change as it happens and
     * this is only the backstop against an announcement that never arrived.
     */
    private const RE_ASK_INTERVAL_SECONDS = 14400;

    /** Seconds to connect, and seconds for the whole call: an operator is waiting on this one, with a Customer on the phone. */
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 15;

    /**
     * The three things Pathao alone asks about the parcel itself, by the names
     * its own `ParcelField`s are declared under. Named once, because a renamed
     * key read under the old spelling sends a blank field.
     *
     * The fourth — which store the parcel leaves from — is not here: that
     * declaration is shared between every Courier that has a notion of a
     * store, so its name is `ParcelField::PICKUP_STORE` and a copy of the
     * spelling here is exactly the drift this pattern exists to stop.
     */
    private const DELIVERY_TYPE = 'deliveryType';

    private const ITEM_TYPE = 'itemType';

    private const PARCEL_WEIGHT = 'parcelWeight';

    /**
     * What Pathao is told a consignment holds, in parcels.
     *
     * One, always: a Shipment is one attempt at one parcel (ADR-0018), and
     * nothing on it counts what is inside. The alternative — summing the
     * Order's lines — would have the driver reading the Order rather than the
     * attempt, which is the one thing keeping what was sent and what was
     * recorded from drifting apart.
     */
    private const ITEM_QUANTITY = 1;

    public function __construct(
        private readonly CourierSettings $settings,
        private readonly Http $http,
        private readonly PathaoAccessToken $token,
    ) {}

    public function courier(): Courier
    {
        return Courier::Pathao;
    }

    /**
     * Pathao's create-order call: every mandatory field filled from the
     * Shipment, a bearer token this driver obtained for itself, and one answer
     * read.
     *
     * The address goes as the Checkout captured it, and `recipient_city`,
     * `recipient_zone` and `recipient_area` are not sent at all — Pathao
     * resolves the destination from the address itself, and the three are
     * mandatory integers nothing here could supply without the Checkout
     * growing a question for a courier's sake (ADR-0018). The phone goes as it
     * is dialled at home, because Pathao documents eleven digits and will not
     * take the stored `+880` form; a number the dialling rules cannot read at
     * all is sent as it stands, so that Pathao words the objection rather than
     * the parcel being held back by a rule of ours.
     */
    public function book(Shipment $shipment): ShipmentBooked
    {
        $token = $this->token->value();

        try {
            $response = $this->client($token)->post(self::HOST.self::BOOKING_PATH, [
                'store_id' => $shipment->parcelDetail(ParcelField::PICKUP_STORE) ?? '',

                /** Pathao echoes this back on every announcement, so it names the attempt and never the Order. */
                'merchant_order_id' => $shipment->invoice_reference,
                'recipient_name' => Str::limit($shipment->recipient_name, 100, ''),
                'recipient_phone' => PhoneNumber::asDialled($shipment->recipient_phone) ?? $shipment->recipient_phone,
                'recipient_address' => Str::limit($shipment->recipient_address, 250, ''),

                /** Read off the attempt and never off Settings: what the row says was sent is what was sent. */
                'delivery_type' => $shipment->parcelDetail(self::DELIVERY_TYPE) ?? '',
                'item_type' => $shipment->parcelDetail(self::ITEM_TYPE) ?? '',
                'item_quantity' => self::ITEM_QUANTITY,
                'item_weight' => $shipment->parcelDetail(self::PARCEL_WEIGHT) ?? '',
                'special_instruction' => $shipment->recipient_note ?? '',

                /** Major units and a whole number, as Pathao documents it: what the rider collects at the door. */
                'amount_to_collect' => (int) round(Money::toMajor($shipment->collect_amount)),
            ]);
        } catch (ConnectionException $exception) {
            throw new CourierUnreachable(
                "Pathao could not be reached to book parcel [{$shipment->invoice_reference}].",
                ['error' => $exception->getMessage()],
            );
        }

        return $this->read($shipment, PathaoAnswer::of($response));
    }

    /**
     * Pathao's order-info call: one Consignment, the status it stands at now,
     * and the time Pathao last touched it.
     *
     * The time is Pathao's own and is kept as such — it is what a Shipment
     * Event's `occurred_at` is for — and it is also what stops a poll every
     * four hours writing the same happening twice: a status Pathao has not
     * changed carries the moment it last changed, so two polls agree on one
     * deduplication id (ADR-0019).
     *
     * A rejected token is nothing about this parcel. The held token is let go
     * of and the answer is silence, so the next sweep signs with a fresh one
     * and asks again — where a refusal would put "Pathao would not accept its
     * own access token" on the timeline of every parcel Pathao is carrying.
     */
    public function track(Shipment $shipment): ShipmentProgress
    {
        try {
            $response = $this->client($this->token->value())
                ->get(self::HOST.sprintf(self::TRACKING_PATH, $shipment->consignment_reference));
        } catch (ConnectionException $exception) {
            return ShipmentProgress::unreachable(
                "Pathao could not be reached about parcel [{$shipment->invoice_reference}]: ".$exception->getMessage(),
            );
        } catch (BookingNotMade $exception) {
            /** The token grant's own two outcomes, and neither is an answer about this parcel. */
            return ShipmentProgress::unreachable($exception->getMessage());
        }

        if ($response->status() === 401) {
            $this->token->forget();

            return ShipmentProgress::unreachable(
                'Pathao would not accept its own access token; a fresh one is issued before the next sweep.',
            );
        }

        if (($silence = ShipmentProgress::silenceFrom($response, $this->courier())) !== null) {
            return $silence;
        }

        $answer = PathaoAnswer::of($response);

        /** The slug is the word Pathao's own status table is written in; the prose beside it is what a human reads. */
        $rawStatus = $answer->text('data.order_status_slug') ?? $answer->text('data.order_status');

        if ($rawStatus === null) {
            return ShipmentProgress::refused(
                "Pathao would not say where parcel [{$shipment->invoice_reference}] is: ".$answer->reason(),
                $answer->body(),
            );
        }

        return ShipmentProgress::reported(
            [new ReportedStatus($this->canonicalStatus($rawStatus), $rawStatus, $answer->text('data.updated_at'))],
            $answer->body(),
        );
    }

    public function canonicalStatus(string $rawStatus): ShipmentStatus
    {
        return self::STATUSES[strtolower(trim($rawStatus))] ?? ShipmentStatus::Unknown;
    }

    /**
     * What Pathao is told about a parcel: which of the Merchant's stores it
     * leaves from, how fast it is to go, what it holds, and what it weighs.
     *
     * The store is declared as a choice and is therefore not put in front of a
     * User until this installation knows of more than one — which, while a
     * store is a single Setting, is never. Declaring it rather than leaving it
     * out is what makes a Merchant with two stores a change to `pickupStore()`
     * and to nothing else, and it goes out on every parcel either way.
     *
     * @return list<ParcelField>
     */
    public function parcelFields(): array
    {
        return [
            ParcelField::pickupStore($this->courier(), $this->settings),

            ParcelField::choice(
                self::DELIVERY_TYPE,
                'Delivery type',
                CourierSettings::property($this->courier(), 'DeliveryType'),
                [
                    '48' => 'Normal delivery',
                    '12' => 'On demand',
                ],
            ),

            ParcelField::choice(
                self::ITEM_TYPE,
                'What is inside',
                CourierSettings::property($this->courier(), 'ItemType'),
                [
                    '2' => 'A parcel',
                    '1' => 'A document',
                ],
            ),

            ParcelField::number(
                self::PARCEL_WEIGHT,
                'Weight (kg)',
                CourierSettings::property($this->courier(), 'ParcelWeight'),
            ),
        ];
    }

    /**
     * Pathao resolves the destination from the free-text address itself and
     * is sent no city, zone or area at all, so there is no list to offer and
     * nothing to guess from (ADR-0018).
     *
     * @return list<DeliveryArea>
     */
    public function deliveryAreas(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function credentialProperties(): array
    {
        return ['pathaoClientId', 'pathaoClientSecret', 'pathaoUsername', 'pathaoPassword'];
    }

    public function supportsBulk(): bool
    {
        return true;
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

    /**
     * Pathao reports no balance at all. The widget says so in words rather
     * than drawing a zero, because a zero is a Merchant told their money is
     * gone.
     */
    public function supportsBalance(): bool
    {
        return false;
    }

    public function needsAreaResolution(): bool
    {
        return false;
    }

    public function batchLimit(): int
    {
        return self::BATCH_LIMIT;
    }

    public function reAskIntervalSeconds(): int
    {
        return self::RE_ASK_INTERVAL_SECONDS;
    }

    public function refusal(): ?string
    {
        return $this->settings->refusal($this->courier());
    }

    /**
     * Pathao's answer, read as either a Consignment or a refusal.
     *
     * Only an answer naming a Consignment is taken as a booking. Anything
     * else — a 4xx, a body with no consignment in it, an unparseable answer —
     * is the Courier saying no in the words it chose, and nothing is recorded
     * as sent (ADR-0020).
     *
     * One of those answers is not a refusal at all: a 401 is Pathao declining
     * the token rather than the parcel, and it is raised as such.
     *
     * @throws CourierRefusedBooking when Pathao considered the parcel and said no.
     * @throws CourierNotUsable when Pathao would not accept the token the call was signed with.
     */
    private function read(Shipment $shipment, PathaoAnswer $answer): ShipmentBooked
    {
        $consignment = $answer->text('data.consignment_id');

        if ($consignment === null) {
            /**
             * A rejected token is not a refused parcel. Pathao never looked at
             * this consignment — it declined the word the call was signed with
             * — so it is worded like the grant's own refusal and never as
             * "Pathao refused this parcel", which would send an operator to
             * ring Pathao about a token only this system issues.
             *
             * The held token is let go of rather than kept until it was due to
             * expire, so the next attempt asks for a fresh one. This attempt
             * is still not retried here: a booking is asked once while a User
             * waits (ADR-0020), and pressing the button again is the retry.
             */
            if ($answer->status() === 401) {
                $this->token->forget();

                throw new CourierNotUsable(
                    'Pathao would not accept its own access token: '.$answer->reason().' A fresh one will be issued — send this order again.',
                    $answer->body(),
                );
            }

            throw new CourierRefusedBooking(
                "Pathao refused parcel [{$shipment->invoice_reference}]: ".$answer->reason(),
                $answer->body(),
            );
        }

        $rawStatus = $answer->text('data.order_status');
        $fee = $answer->get('data.delivery_fee');

        return new ShipmentBooked(
            consignmentReference: $consignment,

            /** Pathao issues no second code: the Consignment id is what a Customer follows the parcel by. */
            trackingCode: $consignment,
            status: $rawStatus === null ? ShipmentStatus::Booked : $this->canonicalStatus($rawStatus),
            rawStatus: $rawStatus,
            charge: is_numeric($fee) ? Money::toMinorUnits($fee) : null,
            response: $answer->body(),
        );
    }

    /** The token this driver obtained for itself, on every call, as Pathao's authentication is documented. */
    private function client(string $token): PendingRequest
    {
        return $this->http
            ->withToken($token)
            ->asJson()
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT);
    }
}
