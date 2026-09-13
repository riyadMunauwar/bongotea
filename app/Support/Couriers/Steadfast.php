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
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Steadfast: a static API key and secret key on every call, five hundred
 * parcels to a booking, an address in free text, and nothing at all coming
 * back on its own.
 *
 * The one Courier of the three that announces nothing. Polling is not a
 * backstop for a missed webhook here, it is the only channel there is, which
 * is why it is asked again far more often than the other two and why a
 * Shipment Event carries our own time beside the Courier's.
 *
 * Steadfast is also the only one that will say what it is holding of the
 * Merchant's money.
 */
class Steadfast implements CourierDriver
{
    /** Where Steadfast answers, and the calls this driver makes. */
    private const HOST = 'https://portal.packzy.com/api/v1';

    private const BOOKING_PATH = '/create_order';

    /**
     * Where Steadfast is asked about a parcel, by its own Consignment id.
     *
     * Steadfast answers the same question by invoice and by tracking code as
     * well; the Consignment is what is asked by, because it is the one name
     * Steadfast issued itself and so the one it cannot disagree with us
     * about.
     */
    private const TRACKING_PATH = '/status_by_cid/';

    /**
     * Steadfast's own status words, as its documentation lists them, in the
     * system's vocabulary (ADR-0018).
     *
     * The only place these words are spoken. Note what is not here: Steadfast
     * never reports a parcel in transit, so `ShipmentStatus::InTransit` is a
     * state this Courier simply never emits, and that is expected rather than
     * a gap. Note also `cancelled`, which is a parcel that came back and
     * means **Returned** — never anything resembling the Order's own
     * Cancelled, which is a User's decision and not a courier's.
     *
     * @var array<string, ShipmentStatus>
     */
    private const STATUSES = [
        'in_review' => ShipmentStatus::Booked,
        'pending' => ShipmentStatus::Booked,
        'hold' => ShipmentStatus::OnHold,
        'delivered' => ShipmentStatus::Delivered,
        'delivered_approval_pending' => ShipmentStatus::Delivered,
        'partial_delivered' => ShipmentStatus::PartlyDelivered,
        'partial_delivered_approval_pending' => ShipmentStatus::PartlyDelivered,
        'cancelled' => ShipmentStatus::Returned,
        'cancelled_approval_pending' => ShipmentStatus::Returned,
        'unknown' => ShipmentStatus::Unknown,
        'unknown_approval_pending' => ShipmentStatus::Unknown,
    ];

    /**
     * Parcels one booking call may carry, as Steadfast documents its bulk
     * endpoint.
     */
    private const BATCH_LIMIT = 500;

    /** Seconds to connect, and seconds for the whole call: an operator is waiting on this one, with a Customer on the phone. */
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 15;

    /**
     * How long a Shipment is left alone before Steadfast is asked about it
     * again: half an hour, because nothing else will ever tell us. The
     * timeline's resolution is this number.
     */
    private const RE_ASK_INTERVAL_SECONDS = 1800;

    /**
     * The two things Steadfast is told about the parcel itself, by the names
     * its own `ParcelField`s are declared under. Named once, because a
     * renamed key read under the old spelling sends a blank field.
     */
    private const DELIVERY_TYPE = 'deliveryType';

    private const ITEM_TYPE = 'itemType';

    public function __construct(
        private readonly CourierSettings $settings,
        private readonly Http $http,
    ) {}

    public function courier(): Courier
    {
        return Courier::Steadfast;
    }

    /**
     * Steadfast's create-order call: every mandatory field filled from the
     * Shipment, the two keys in the headers, and one answer read.
     *
     * The address goes as the Checkout captured it — Steadfast resolves its
     * own areas and nothing here has to guess one. The phone goes as it is
     * dialled at home, because Steadfast documents eleven digits and will not
     * take the stored `+880` form; a number the dialling rules cannot read at
     * all is sent as it stands, so that Steadfast words the objection rather
     * than the parcel being held back by a rule of ours.
     */
    public function book(Shipment $shipment): ShipmentBooked
    {
        try {
            $response = $this->client()->post(self::HOST.self::BOOKING_PATH, [
                'invoice' => $shipment->invoice_reference,
                'recipient_name' => Str::limit($shipment->recipient_name, 100, ''),
                'recipient_phone' => PhoneNumber::asDialled($shipment->recipient_phone) ?? $shipment->recipient_phone,
                'recipient_address' => Str::limit($shipment->recipient_address, 250, ''),

                /** Major units, as Steadfast documents it: "including all charges", which is what the Order's total already is. */
                'cod_amount' => Money::toMajor($shipment->collect_amount),
                'note' => $shipment->recipient_note ?? '',

                /** Read off the attempt and never off Settings: what the row says was sent is what was sent. */
                'item_description' => $shipment->parcelDetail(self::ITEM_TYPE) ?? '',
                'delivery_type' => $shipment->parcelDetail(self::DELIVERY_TYPE) ?? '',
            ]);
        } catch (ConnectionException $exception) {
            throw new CourierUnreachable(
                "Steadfast could not be reached to book parcel [{$shipment->invoice_reference}].",
                ['error' => $exception->getMessage()],
            );
        }

        return $this->read($shipment, $response);
    }

    /**
     * Steadfast's status call: one Consignment, one word back, and no time of
     * its own and no history at all.
     *
     * This is the whole of what is ever known about a Steadfast parcel, which
     * is why it is asked every half hour and why the Shipment Event it
     * produces leans on our own timestamp. A word Steadfast has stopped
     * documenting reads as Unknown rather than as an error: a parcel whose
     * status we cannot read is still a parcel to ask about again.
     */
    public function track(Shipment $shipment): ShipmentProgress
    {
        try {
            $response = $this->client()->get(self::HOST.self::TRACKING_PATH.$shipment->consignment_reference);
        } catch (ConnectionException $exception) {
            return ShipmentProgress::unreachable(
                "Steadfast could not be reached about parcel [{$shipment->invoice_reference}]: ".$exception->getMessage(),
            );
        }

        if (($silence = ShipmentProgress::silenceFrom($response, $this->courier())) !== null) {
            return $silence;
        }

        $answer = is_array($response->json()) ? $response->json() : [];
        $rawStatus = is_scalar($answer['delivery_status'] ?? null) ? trim((string) $answer['delivery_status']) : '';

        if ($rawStatus === '') {
            return ShipmentProgress::refused(
                "Steadfast would not say where parcel [{$shipment->invoice_reference}] is: ".$this->reason($answer, $response),
                $answer,
            );
        }

        return ShipmentProgress::reported(
            [new ReportedStatus($this->canonicalStatus($rawStatus), $rawStatus)],
            $answer,
        );
    }

    public function canonicalStatus(string $rawStatus): ShipmentStatus
    {
        return self::STATUSES[strtolower(trim($rawStatus))] ?? ShipmentStatus::Unknown;
    }

    /**
     * Steadfast announces nothing at all — it publishes no webhook, so its
     * address is never handed to it and nothing it sends could arrive there.
     *
     * Null rather than an exception, for the reason `deliveryAreas()` is
     * empty for a Courier that reads a free-text address: it is an honest
     * answer to a question the contract is entitled to put to every Courier,
     * and it is what lets one address serve all of them without anybody
     * speaking a courier's name. A probe at Steadfast's address is heard and
     * ignored, and the parcel goes on being polled, which for this Courier is
     * the only channel there ever was (ADR-0019).
     */
    public function readAnnouncement(Request $request): ?Announcement
    {
        return null;
    }

    /**
     * The two things Steadfast is told about a parcel that are not the
     * recipient: where it is going to be handed over, and what is inside it.
     *
     * No pickup store: Steadfast collects from one arrangement per account and
     * has no notion of a store at all, so a User booking with it is never
     * asked which warehouse the parcel leaves from.
     *
     * @return list<ParcelField>
     */
    public function parcelFields(): array
    {
        return [
            ParcelField::choice(
                self::DELIVERY_TYPE,
                'Delivery type',
                CourierSettings::property($this->courier(), 'DeliveryType'),
                [
                    '0' => 'To the customer’s door',
                    '1' => 'To a Steadfast point',
                ],
            ),

            ParcelField::text(
                self::ITEM_TYPE,
                'What is inside',
                CourierSettings::property($this->courier(), 'ItemType'),
                'What the rider is told the parcel holds.',
            ),
        ];
    }

    /**
     * Steadfast resolves the destination from the free-text address itself
     * and publishes no area list, so there is none to offer and none to guess
     * from (ADR-0018).
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
        return ['steadfastApiKey', 'steadfastSecretKey'];
    }

    public function supportsBulk(): bool
    {
        return true;
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    public function supportsBalance(): bool
    {
        return true;
    }

    /**
     * Steadfast takes the Customer's address as the Checkout captured it, so
     * there is nothing to resolve and nothing for a User to confirm.
     */
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
     * Steadfast's answer, read as either a Consignment or a refusal.
     *
     * Only an answer naming a Consignment is taken as a booking. Anything
     * else — a 4xx, a body with no consignment in it, an unparseable answer —
     * is the Courier saying no in the words it chose, and nothing is recorded
     * as sent (ADR-0020).
     *
     * @throws CourierRefusedBooking
     */
    private function read(Shipment $shipment, Response $response): ShipmentBooked
    {
        $answer = is_array($response->json()) ? $response->json() : [];

        $consignment = $answer['consignment'] ?? null;

        if (! is_array($consignment) || blank($consignment['consignment_id'] ?? null)) {
            throw new CourierRefusedBooking(
                "Steadfast refused parcel [{$shipment->invoice_reference}]: ".$this->reason($answer, $response),
                $answer,
            );
        }

        $rawStatus = is_string($consignment['status'] ?? null) ? $consignment['status'] : null;

        return new ShipmentBooked(
            consignmentReference: (string) $consignment['consignment_id'],
            trackingCode: filled($consignment['tracking_code'] ?? null) ? (string) $consignment['tracking_code'] : null,
            status: $rawStatus === null ? ShipmentStatus::Booked : $this->canonicalStatus($rawStatus),
            rawStatus: $rawStatus,
            response: $answer,
        );
    }

    /**
     * Why Steadfast said no, in Steadfast's own words where it gave any.
     *
     * It words a refusal more than one way — a message, or a bag of field
     * errors — so both are read, and the HTTP status stands in where it said
     * nothing at all. An operator reads this and acts on it, so it must never
     * come back blank.
     *
     * @param  array<string, mixed>  $answer
     */
    private function reason(array $answer, Response $response): string
    {
        $errors = collect(is_array($answer['errors'] ?? null) ? $answer['errors'] : [])
            ->flatten()
            ->filter(fn (mixed $error): bool => is_string($error) && filled($error));

        return match (true) {
            filled($answer['message'] ?? null) && is_string($answer['message']) => trim($answer['message']),
            $errors->isNotEmpty() => $errors->implode(' '),
            default => "HTTP {$response->status()}.",
        };
    }

    /** Both keys on every call, as Steadfast's authentication is documented. */
    private function client(): PendingRequest
    {
        return $this->http
            ->withHeaders([
                'Api-Key' => $this->settings->steadfastApiKey,
                'Secret-Key' => $this->settings->steadfastSecretKey,
            ])
            ->asJson()
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT);
    }
}
