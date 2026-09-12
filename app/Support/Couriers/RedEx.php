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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * RedEx: one static access token, one parcel to a call, a delivery area it
 * insists on knowing by id, and a callback announcing progress.
 *
 * The awkward one, and the reason two of this feature's decisions look the
 * way they do. It has no bulk endpoint, so two hundred parcels is two hundred
 * sequential requests and a batch cannot live in a web request under any
 * circumstances (ADR-0020). And it will not take an address in free text: its
 * area list is cached from RedEx (`RedExAreas`), matched against the
 * Customer's address and District (`DeliveryAreaMatcher`) and the best guess
 * is put in front of a User to confirm, because a parcel sent to a guessed
 * depot is a parcel in the wrong city.
 *
 * The area reaches this driver the way every other per-parcel answer does —
 * off the attempt's own `parcel_details`, never off a list looked up again
 * here — so what the row says was sent is what was sent. A parcel with no
 * area on it never arrives: `CourierBooker` refuses it in words before RedEx
 * is asked anything (`DeliveryAreaNotResolved`).
 *
 * It also demands a weight and a declared value the system holds nowhere,
 * which is what the Merchant's per-Courier defaults exist to supply.
 */
class RedEx implements CourierDriver
{
    /**
     * Where RedEx answers. Public because its area list is fetched by the
     * class that holds it, which is the one thing besides this driver that
     * speaks to RedEx at all.
     */
    public const HOST = 'https://openapi.redx.com.bd/v1.0.0-beta';

    private const BOOKING_PATH = '/parcel';

    /**
     * RedEx's own parcel statuses, as its documentation lists them, in the
     * system's vocabulary (ADR-0018).
     *
     * The only place these words are spoken. Note `agent-returning`, which
     * reads as **Returned** and not as In Transit: a parcel a rider is
     * carrying back is a parcel that failed, the Merchant is already paying
     * for the return leg, and an operator has the same decision to make on it
     * as on one already back at the hub — ring the Customer, correct the
     * address, send it again, or give up. Waiting for `returned` to say so
     * would hide a dead parcel among the moving ones for a day.
     *
     * A pickup that never happened is On Hold and not Returned: that parcel
     * never left the Merchant's hands, so there is nothing for it to have
     * come back from. And RedEx names every state it reports, so nothing here
     * reads as Unknown.
     *
     * @var array<string, ShipmentStatus>
     */
    private const STATUSES = [
        'pickup-pending' => ShipmentStatus::Booked,
        'pickup-assigned' => ShipmentStatus::Booked,
        'picked-up' => ShipmentStatus::InTransit,
        'received-warehouse' => ShipmentStatus::InTransit,
        'in-transit' => ShipmentStatus::InTransit,
        'ready-for-delivery' => ShipmentStatus::InTransit,
        'agent-assigned' => ShipmentStatus::InTransit,
        'delivery-in-progress' => ShipmentStatus::InTransit,
        'hold' => ShipmentStatus::OnHold,
        'pickup-failed' => ShipmentStatus::OnHold,
        'delivery-failed' => ShipmentStatus::OnHold,
        'delivered' => ShipmentStatus::Delivered,
        'partial-delivered' => ShipmentStatus::PartlyDelivered,
        'agent-returning' => ShipmentStatus::Returned,
        'returned' => ShipmentStatus::Returned,
    ];

    /**
     * One parcel to a call. Declared rather than absent so that a batch is
     * the same loop for every Courier and nothing has to ask whether there is
     * a bulk endpoint before cutting one.
     */
    private const BATCH_LIMIT = 1;

    /**
     * How long a Shipment is left alone before RedEx is asked about it again:
     * four hours, because RedEx calls back as a parcel moves and this is only
     * the backstop against a callback that never arrived.
     */
    private const RE_ASK_INTERVAL_SECONDS = 14400;

    /** Seconds to connect, and seconds for the whole call: an operator is waiting on this one, with a Customer on the phone. */
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 15;

    /**
     * The two things RedEx alone asks about the parcel itself, by the names
     * its own `ParcelField`s are declared under. Named once, because a
     * renamed key read under the old spelling sends a blank field.
     *
     * The third — which store the parcel leaves from — is not here: that
     * declaration is shared with every Courier that has a notion of a store,
     * so its name is `ParcelField::PICKUP_STORE`. The fourth, the delivery
     * area, is not a `ParcelField` at all: it is answered per Order rather
     * than defaulted per installation, and its names are `DeliveryArea`'s.
     */
    private const PARCEL_WEIGHT = 'parcelWeight';

    private const DECLARED_VALUE = 'declaredValue';

    /** RedEx counts a parcel in grams; every Merchant default here is in kilograms, as the other couriers take it. */
    private const GRAMS_TO_THE_KILOGRAM = 1000;

    public function __construct(
        private readonly CourierSettings $settings,
        private readonly Http $http,
        private readonly RedExAreas $areas,
    ) {}

    public function courier(): Courier
    {
        return Courier::RedEx;
    }

    /**
     * RedEx's create-parcel call: every mandatory field filled from the
     * attempt, the Merchant's token in RedEx's own header, and one answer
     * read.
     *
     * The area goes as both the id RedEx routes on and the name RedEx spells
     * it under, read off the attempt where the booking recorded them. The
     * phone goes as it is dialled at home, because RedEx documents eleven
     * digits and will not take the stored `+880` form; a number the dialling
     * rules cannot read at all is sent as it stands, so that RedEx words the
     * objection rather than the parcel being held back by a rule of ours.
     */
    public function book(Shipment $shipment): ShipmentBooked
    {
        try {
            $response = $this->client()->post(self::HOST.self::BOOKING_PATH, [
                'customer_name' => Str::limit($shipment->recipient_name, 100, ''),
                'customer_phone' => PhoneNumber::asDialled($shipment->recipient_phone) ?? $shipment->recipient_phone,
                'customer_address' => Str::limit($shipment->recipient_address, 250, ''),

                /** Both, and both off the row: RedEx routes on the id and prints the name, and a name looked up afresh here could differ from the one a User confirmed. */
                'delivery_area' => $shipment->parcelDetail(DeliveryArea::NAME) ?? '',
                'delivery_area_id' => self::areaId($shipment->parcelDetail(DeliveryArea::ANSWER)),

                /** RedEx echoes this back on every announcement, so it names the attempt and never the Order. */
                'merchant_invoice_id' => $shipment->invoice_reference,

                /** Major units and a whole number, as RedEx documents it: what the rider collects at the door. */
                'cash_collection_amount' => (string) (int) round(Money::toMajor($shipment->collect_amount)),
                'parcel_weight' => $this->grams($shipment->parcelDetail(self::PARCEL_WEIGHT)),
                'value' => (int) round((float) $shipment->parcelDetail(self::DECLARED_VALUE)),
                'instruction' => $shipment->recipient_note ?? '',
                'pickup_store_id' => (int) $shipment->parcelDetail(ParcelField::PICKUP_STORE),
            ]);
        } catch (ConnectionException $exception) {
            throw new CourierUnreachable(
                "RedEx could not be reached to book parcel [{$shipment->invoice_reference}].",
                ['error' => $exception->getMessage()],
            );
        }

        return $this->read($shipment, $response);
    }

    public function canonicalStatus(string $rawStatus): ShipmentStatus
    {
        return self::STATUSES[strtolower(trim($rawStatus))] ?? ShipmentStatus::Unknown;
    }

    /**
     * The areas RedEx will deliver to, as RedEx published them, out of the
     * list held for the installation rather than asked for per parcel.
     *
     * @return list<DeliveryArea>
     */
    public function deliveryAreas(): array
    {
        return $this->areas->all();
    }

    /**
     * What RedEx is told about a parcel: which pickup store it leaves from,
     * what it weighs and what it is worth.
     *
     * The weight and the declared value are the two RedEx refuses a parcel
     * without and the system holds neither — no Page asks a Customer how heavy
     * their order is — so the Merchant's defaults are what make a RedEx
     * booking possible at all, and this dialog is where an unusual parcel
     * departs from them.
     *
     * The store is declared as a choice and so is never put in front of a User
     * while this installation knows of only one.
     *
     * The delivery area is not declared here, though RedEx refuses a parcel
     * without one too: a `ParcelField` is an override of something the
     * Merchant decided once for the installation, and an area belongs to one
     * Order's address. It is asked as its own question, of every Courier that
     * answers `needsAreaResolution()`.
     *
     * @return list<ParcelField>
     */
    public function parcelFields(): array
    {
        return [
            ParcelField::pickupStore($this->courier(), $this->settings),

            ParcelField::number(
                self::PARCEL_WEIGHT,
                'Weight (kg)',
                CourierSettings::property($this->courier(), 'ParcelWeight'),
                'RedEx refuses a parcel with no weight.',
            ),

            ParcelField::number(
                self::DECLARED_VALUE,
                'Declared value',
                CourierSettings::property($this->courier(), 'DeclaredValue'),
                'What the parcel is declared to be worth, in major units.',
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function credentialProperties(): array
    {
        return ['redexAccessToken'];
    }

    public function supportsBulk(): bool
    {
        return false;
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

    /**
     * RedEx reports no balance at all. The widget says so in words rather
     * than drawing a zero, because a zero is a Merchant told their money is
     * gone.
     */
    public function supportsBalance(): bool
    {
        return false;
    }

    public function needsAreaResolution(): bool
    {
        return true;
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
     * RedEx's answer, read as either a Consignment or a refusal.
     *
     * Only an answer naming a tracking id is taken as a booking. Anything
     * else — a 4xx, a body with no id in it, an unparseable answer — is the
     * Courier saying no in the words it chose, and nothing is recorded as
     * sent (ADR-0020).
     *
     * @throws CourierRefusedBooking when RedEx considered the parcel and said no.
     */
    private function read(Shipment $shipment, Response $response): ShipmentBooked
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $tracking = $body['tracking_id'] ?? null;

        if (! is_string($tracking) || blank($tracking)) {
            throw new CourierRefusedBooking(
                "RedEx refused parcel [{$shipment->invoice_reference}]: ".self::reason($response, $body),
                $body,
            );
        }

        $rawStatus = is_string($body['status'] ?? null) ? $body['status'] : null;

        return new ShipmentBooked(
            /** RedEx issues one word for a parcel and it is both: the Consignment it answers to and the code a Customer follows. */
            consignmentReference: $tracking,
            trackingCode: $tracking,
            status: $rawStatus === null ? ShipmentStatus::Booked : $this->canonicalStatus($rawStatus),
            rawStatus: $rawStatus,

            /** RedEx quotes no charge when it takes a parcel; what the delivery cost arrives with the invoice. */
            response: $body,
        );
    }

    /**
     * Why RedEx said no, in RedEx's own words wherever it gave any — an
     * operator acts on what the Courier objected to, not on a sentence of
     * ours about it.
     *
     * @param  array<string, mixed>  $body
     */
    private static function reason(Response $response, array $body): string
    {
        $message = $body['message'] ?? null;

        return is_string($message) && filled($message)
            ? $message
            : "it answered {$response->status()} with nothing to say.";
    }

    /**
     * The area id as RedEx takes it: a whole number where its own id is one,
     * and the word itself where it is not.
     *
     * An id is a word a Courier chose rather than a figure — `DeliveryArea`
     * holds every one of them as a string for that reason — and RedEx happens
     * to spell its own in digits. Casting regardless would send nought for
     * anything else, and nought is a depot: an id this cannot read goes as it
     * stands, so that RedEx words the objection.
     */
    private static function areaId(?string $id): int|string
    {
        $id = (string) $id;

        return ctype_digit($id) ? (int) $id : $id;
    }

    /**
     * The Merchant's kilograms in the grams RedEx counts them by.
     *
     * A weight of nothing is sent as nothing and RedEx refuses it in its own
     * words, which is the right answer: the Merchant's default is what stops
     * that happening, and a floor invented here would send a weight nobody
     * agreed to.
     */
    private function grams(?string $kilograms): int
    {
        return (int) round(((float) $kilograms) * self::GRAMS_TO_THE_KILOGRAM);
    }

    /** The Merchant's own access token, in the header RedEx documents for it, on every call. */
    private function client(): PendingRequest
    {
        return $this->http
            ->withHeaders(['API-ACCESS-TOKEN' => 'Bearer '.$this->settings->redexAccessToken])
            ->asJson()
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT);
    }
}
