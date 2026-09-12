<?php

namespace Tests\Fixtures\Couriers;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Support\Money;
use App\Support\PhoneNumber;
use App\Support\Settings\CourierSettings;
use Illuminate\Http\Client\ConnectionException;
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

    public function courier(): Courier
    {
        return Courier::Steadfast;
    }

    public function makeUsable(): void
    {
        app(CourierSettings::class)->with([
            'steadfastEnabled' => true,
            'steadfastApiKey' => self::API_KEY,
            'steadfastSecretKey' => self::SECRET_KEY,
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

    public function fakeOutage(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException(
            "cURL error 28: Connection timed out after 5000 milliseconds for {$request->url()}"
        ));
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
