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
use Illuminate\Support\Facades\Log;
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

    /** Where Steadfast takes a whole batch at once, as its bulk documentation describes it. */
    private const BULK_BOOKING_PATH = '/create_order/bulk-order';

    /**
     * Where Steadfast is asked about a parcel, by its own Consignment id.
     *
     * Steadfast answers the same question by invoice and by tracking code as
     * well; the Consignment is what is asked by, because it is the one name
     * Steadfast issued itself and so the one it cannot disagree with us
     * about.
     */
    private const TRACKING_PATH = '/status_by_cid/';

    /** Where Steadfast says what it is holding of the Merchant's money — the one Courier of the three that will. */
    private const BALANCE_PATH = '/get_balance';

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
            $response = $this->client()->post(self::HOST.self::BOOKING_PATH, $this->parcel($shipment));
        } catch (ConnectionException $exception) {
            throw new CourierUnreachable(
                "Steadfast could not be reached to book parcel [{$shipment->invoice_reference}].",
                ['error' => $exception->getMessage()],
            );
        }

        return $this->read($shipment, $response);
    }

    /**
     * Steadfast's bulk create-order call: up to five hundred parcels in one
     * body, and one line of answer per parcel.
     *
     * The parcels are described exactly as a single booking describes one, so
     * a batch cannot send a field a single booking would not — and every line
     * of the answer is matched back by our own invoice reference, which is
     * what Steadfast echoes and what names one attempt rather than an Order.
     *
     * Steadfast's bulk answer says a parcel was taken and gives the
     * Consignment, but no delivery status word: a parcel it accepted is
     * Booked in our own vocabulary with nothing of Steadfast's beside it, and
     * the first poll fills that in. That is the honest reading — inventing a
     * raw word Steadfast did not say would put it on the timeline verbatim.
     *
     * @param  list<Shipment>  $shipments
     */
    public function bookAll(array $shipments): BatchBooked
    {
        try {
            $response = $this->client()->post(self::HOST.self::BULK_BOOKING_PATH, [
                'data' => array_map(fn (Shipment $shipment): array => $this->parcel($shipment), $shipments),
            ]);
        } catch (ConnectionException $exception) {
            /**
             * The one place in this system where something may have happened
             * that nothing can be read about: a line dropped after Steadfast
             * created the consignments and before the answer arrived leaves
             * real parcels moving with no Consignment against their rows.
             * They stay written and are offered again, and Steadfast's own
             * invoice uniqueness is what stops the Merchant paying twice — it
             * refuses the second offer, and the row then says so in its words.
             * Every reference in the chunk is logged so an operator can
             * reconcile the handful this happens to (ADR-0020).
             */
            Log::error('A bulk booking was sent to Steadfast and no answer came back; these parcels may or may not exist.', [
                'courier' => $this->courier()->value,
                'invoices' => array_map(fn (Shipment $shipment): string => $shipment->invoice_reference, $shipments),
                'error' => $exception->getMessage(),
            ]);

            return BatchBooked::silencedAfter(
                'Steadfast could not be reached to book '.count($shipments).' parcels: '.$exception->getMessage(),
            );
        }

        /** A 5xx or a rejected credential settles nothing about any of them, so none of the rows moves and the run stops. */
        if (($silence = ShipmentProgress::silenceFrom($response, $this->courier())) !== null) {
            return BatchBooked::silencedAfter((string) $silence->error);
        }

        return $this->readBatch($shipments, $response);
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

    /**
     * Steadfast's balance call: no parcel, no arguments, one figure back.
     *
     * Everything that is not a figure is `unavailable()` in words — silence,
     * a rejected credential, an answer with no readable balance in it — and
     * never a zero, because a Merchant reading a zero here concludes the
     * Courier has paid them and a figure we merely failed to fetch would say
     * so falsely.
     *
     * Note the conversion: Steadfast reports major units, as it takes them on
     * a booking, and every amount this system holds is minor.
     */
    public function balance(): CourierBalance
    {
        try {
            $response = $this->client()->get(self::HOST.self::BALANCE_PATH);
        } catch (ConnectionException $exception) {
            return CourierBalance::unavailable('Steadfast could not be reached: '.$exception->getMessage());
        }

        $answer = is_array($response->json()) ? $response->json() : [];
        $reported = $answer['current_balance'] ?? null;

        /**
         * One reading for a refusal and for an answer with no figure in it,
         * because Steadfast words both the same way and sometimes words a
         * refusal at HTTP 200: what an operator needs is Steadfast's own
         * sentence, which `reason()` is the one place that is read from.
         */
        if ($response->failed() || ! is_numeric($reported)) {
            return CourierBalance::unavailable('Steadfast would not say what it is holding: '.$this->reason($answer, $response));
        }

        return CourierBalance::of(Money::toMinorUnits($reported));
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
     * One parcel as Steadfast is told about it, which is the same description
     * whether it travels alone or in a batch of five hundred.
     *
     * The address goes as the Checkout captured it — Steadfast resolves its
     * own areas and nothing here has to guess one. The phone goes as it is
     * dialled at home, because Steadfast documents eleven digits and will not
     * take the stored `+880` form; a number the dialling rules cannot read at
     * all is sent as it stands, so that Steadfast words the objection rather
     * than the parcel being held back by a rule of ours.
     *
     * @return array<string, mixed>
     */
    private function parcel(Shipment $shipment): array
    {
        return [
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
        ];
    }

    /**
     * Steadfast's bulk answer, read line by line and matched back to the
     * parcels that were offered.
     *
     * A line naming a Consignment took that parcel; a line without one is
     * Steadfast refusing it in the words it chose, and only that parcel. A
     * parcel no line names is left exactly as it was — written, undispatched
     * — and offered again next sweep, because a body we could not find it in
     * says nothing about whether it was taken.
     *
     * An answer holding no readable list at all refuses the whole batch, in
     * Steadfast's own wording where it gave any: the same reading `read()`
     * gives a single booking, where anything that does not name a Consignment
     * is a no. Silence has already been separated out before this is reached.
     *
     * @param  list<Shipment>  $shipments
     */
    private function readBatch(array $shipments, Response $response): BatchBooked
    {
        $answer = is_array($response->json()) ? $response->json() : [];
        $lines = is_array($answer['data'] ?? null) ? $answer['data'] : null;

        if ($lines === null) {
            $refused = [];

            foreach ($shipments as $shipment) {
                $refused[$shipment->invoice_reference] = $this->refusalOf($shipment, $answer, $response);
            }

            return BatchBooked::of([], $refused);
        }

        $byInvoice = [];

        foreach ($lines as $line) {
            if (is_array($line) && filled($line['invoice'] ?? null)) {
                $byInvoice[(string) $line['invoice']] = $line;
            }
        }

        $accepted = [];
        $refused = [];

        foreach ($shipments as $shipment) {
            $line = $byInvoice[$shipment->invoice_reference] ?? null;

            if ($line === null) {
                continue;
            }

            if (blank($line['consignment_id'] ?? null)) {
                $refused[$shipment->invoice_reference] = $this->refusalOf($shipment, $line, $response);

                continue;
            }

            $accepted[$shipment->invoice_reference] = new ShipmentBooked(
                consignmentReference: (string) $line['consignment_id'],
                trackingCode: filled($line['tracking_code'] ?? null) ? (string) $line['tracking_code'] : null,

                /** Steadfast names no delivery status in a bulk answer, and a word it did not say is not put on a timeline. */
                status: ShipmentStatus::Booked,
                response: $line,
            );
        }

        return BatchBooked::of($accepted, $refused);
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
     * Steadfast refusing one parcel of a batch, worded the way a single
     * booking's refusal is worded — the one place a batch's refusals are
     * spelled, whether the whole answer was unreadable or one line of it said
     * no.
     *
     * @param  array<string, mixed>  $answer  the whole body, or the one line about this parcel.
     */
    private function refusalOf(Shipment $shipment, array $answer, Response $response): string
    {
        return "Steadfast refused parcel [{$shipment->invoice_reference}]: ".$this->reason($answer, $response);
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
