<?php

namespace Tests\Fixtures\Couriers;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Support\Money;
use App\Support\PhoneNumber;
use App\Support\Settings\CourierSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Steadfast's create-order call, as its documentation describes it: two keys
 * in the headers, a JSON body naming the invoice and the recipient, and an
 * answer carrying a consignment id, a tracking code and a status of
 * `in_review`.
 */
final class SteadfastScript extends CourierScript
{
    private const API_KEY = 'sf_api_test_1';

    private const SECRET_KEY = 'sf_secret_test_1';

    private const CONSIGNMENT_ID = 1424107;

    private const TRACKING_CODE = '15BAEB8A';

    /** What Steadfast says when it will not take a parcel, in the shape its errors arrive in. */
    private const REFUSAL = 'The recipient phone must be 11 digits.';

    /** Where Steadfast is asked where a parcel is: by its own Consignment id, which is appended to this. */
    private const TRACKING_PREFIX = 'https://portal.packzy.com/api/v1/status_by_cid/';

    /** What Steadfast says when it has no record of the Consignment it was asked about. */
    private const TRACKING_REFUSAL = 'No consignment found with this id.';

    public function courier(): Courier
    {
        return Courier::Steadfast;
    }

    /**
     * What Steadfast is told about an ordinary parcel of this Merchant's, set
     * the way a Merchant filling in Settings sets it — deliberately not the
     * shipped config defaults, so a test proving the dialog opens on the
     * Merchant's decision cannot pass by accident on ours.
     */
    private const DELIVERY_TYPE = '1';

    private const ITEM_TYPE = 'Clothing';

    public function makeUsable(): void
    {
        app(CourierSettings::class)->with([
            'steadfastEnabled' => true,
            'steadfastApiKey' => self::API_KEY,
            'steadfastSecretKey' => self::SECRET_KEY,
            'steadfastDeliveryType' => self::DELIVERY_TYPE,
            'steadfastItemType' => self::ITEM_TYPE,
        ])->save();

        app()->forgetInstance(CourierSettings::class);
    }

    public function consignmentReference(): string
    {
        return (string) self::CONSIGNMENT_ID;
    }

    public function trackingCode(): string
    {
        return self::TRACKING_CODE;
    }

    public function fakeBooked(): void
    {
        $this->fakeBookedReporting('in_review');
    }

    public function fakeBookedReporting(string $rawStatus): void
    {
        Http::fake([$this->bookingUrl() => Http::response([
            'status' => 200,
            'message' => 'Consignment has been created successfully.',
            'consignment' => [
                'consignment_id' => self::CONSIGNMENT_ID,
                'invoice' => 'Aa12-das4',
                'tracking_code' => self::TRACKING_CODE,
                'recipient_name' => 'John Smith',
                'recipient_phone' => '01234567890',
                'recipient_address' => 'Fla# A1, House# 17/1, Road# 3/A, Dhanmondi, Dhaka-1209',
                'cod_amount' => 1060,
                'status' => $rawStatus,
                'note' => null,
                'created_at' => '2021-03-21T07:05:31.000000Z',
                'updated_at' => '2021-03-21T07:05:31.000000Z',
            ],
        ])]);
    }

    public function fakeBookingRefused(): void
    {
        Http::fake([$this->bookingUrl() => Http::response([
            'status' => 422,
            'errors' => ['recipient_phone' => [self::REFUSAL]],
        ], 422)]);
    }

    public function refusalWording(): string
    {
        return self::REFUSAL;
    }

    /**
     * Every field Steadfast documents as required, filled: the invoice, the
     * recipient's name, an eleven-digit phone, the address, and an amount to
     * collect in major units. The two keys travel in the headers on every
     * call.
     */
    public function assertBookedFor(Shipment $shipment): void
    {
        Http::assertSent(fn (Request $request): bool => $request->url() === $this->bookingUrl()
            && $request->method() === 'POST'
            && $request->hasHeader('Api-Key', self::API_KEY)
            && $request->hasHeader('Secret-Key', self::SECRET_KEY)
            && $request['invoice'] === $shipment->invoice_reference
            && $request['recipient_name'] === $shipment->recipient_name
            && $request['recipient_phone'] === PhoneNumber::asDialled($shipment->recipient_phone)
            && $request['recipient_address'] === $shipment->recipient_address
            && (float) $request['cod_amount'] === Money::toMajor($shipment->collect_amount));
    }

    public function assertBookingsSent(int $count): void
    {
        Http::assertSentCount($count);
    }

    /**
     * Steadfast answering where a parcel is: one word, no time, and no
     * history of how it got there. It is scripted with whatever the last word
     * was, because that is the whole of what Steadfast will ever say.
     */
    public function fakeTracking(string ...$rawStatuses): void
    {
        Http::fake([self::TRACKING_PREFIX.'*' => Http::response([
            'status' => 200,
            'delivery_status' => end($rawStatuses),
        ])]);
    }

    /** Steadfast answering about a Consignment it has no record of. */
    public function fakeTrackingRefused(): void
    {
        Http::fake([self::TRACKING_PREFIX.'*' => Http::response([
            'status' => 404,
            'message' => self::TRACKING_REFUSAL,
        ], 404)]);
    }

    public function trackingRefusalWording(): string
    {
        return self::TRACKING_REFUSAL;
    }

    /**
     * Asked by Steadfast's own Consignment id, with both keys in the headers
     * as on every other call.
     */
    public function assertTrackedFor(Shipment $shipment): void
    {
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::TRACKING_PREFIX.$shipment->consignment_reference
            && $request->hasHeader('Api-Key', self::API_KEY)
            && $request->hasHeader('Secret-Key', self::SECRET_KEY));
    }

    public function assertPollsSent(int $count): void
    {
        expect(Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), self::TRACKING_PREFIX))->count())
            ->toBe($count, "Steadfast was asked where a parcel is other than {$count} times.");
    }

    /**
     * Half an hour, because Steadfast announces nothing whatsoever: this is
     * not a backstop, it is the only channel there is, and it is the
     * resolution of every Steadfast timeline.
     */
    public function reAskIntervalSeconds(): int
    {
        return 1800;
    }

    /** Steadfast answers with where a parcel stands and keeps no history at all. */
    public function tracksWholeHistory(): bool
    {
        return false;
    }

    /** And gives no time with it, which is exactly why a Shipment Event carries ours. */
    public function reportsItsOwnTime(): bool
    {
        return false;
    }

    /**
     * Steadfast's two: the delivery type and what it is told is inside. It has
     * no notion of a pickup store, so there is none here to ask about.
     *
     * @return array<string, string>
     */
    public function parcelDefaults(): array
    {
        return [
            'deliveryType' => self::DELIVERY_TYPE,
            'itemType' => self::ITEM_TYPE,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function parcelOverrides(): array
    {
        return [
            'deliveryType' => '0',
            'itemType' => 'Fragile glassware',
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    public function assertBookedWithParcelDetails(array $values): void
    {
        Http::assertSent(fn (Request $request): bool => $request->url() === $this->bookingUrl()
            && $request['delivery_type'] === $values['deliveryType']
            && $request['item_description'] === $values['itemType']);
    }

    /** Steadfast collects from one arrangement per account and takes no store at all. */
    public function pickupStoreCount(): int
    {
        return 0;
    }

    /**
     * Steadfast's documented delivery statuses. It has no word for a parcel
     * in transit — it is the one Courier of the three that announces nothing
     * and is only ever asked — and `cancelled` is a parcel that came back.
     *
     * @return list<string>
     */
    public function rawStatusesFor(ShipmentStatus $status): array
    {
        return match ($status) {
            ShipmentStatus::Booked => ['in_review', 'pending'],
            ShipmentStatus::InTransit => [],
            ShipmentStatus::OnHold => ['hold'],
            ShipmentStatus::Delivered => ['delivered', 'delivered_approval_pending'],
            ShipmentStatus::PartlyDelivered => ['partial_delivered', 'partial_delivered_approval_pending'],
            ShipmentStatus::Returned => ['cancelled', 'cancelled_approval_pending'],
            ShipmentStatus::Unknown => ['unknown', 'unknown_approval_pending'],
        };
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

    public function needsAreaResolution(): bool
    {
        return false;
    }

    public function batchLimit(): int
    {
        return 500;
    }

    private function bookingUrl(): string
    {
        return 'https://portal.packzy.com/api/v1/create_order';
    }
}
