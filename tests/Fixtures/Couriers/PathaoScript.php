<?php

namespace Tests\Fixtures\Couriers;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Support\Couriers\ParcelField;
use App\Support\Money;
use App\Support\PhoneNumber;
use App\Support\Settings\CourierSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Pathao's create-order call, as its documentation describes it: a token
 * grant answering with an access token and a lifetime, a bearer header on the
 * booking itself, a JSON body naming the store and the recipient, and an
 * answer wrapped in Pathao's envelope carrying a consignment id, the status
 * `Pending` and the delivery fee it charged.
 *
 * Pathao is the one Courier of the three that must be authenticated before it
 * can be asked anything, so every fake here lays the token grant's answer
 * beside whatever it is really about: a test that stubbed only the booking
 * would have the grant refused as a stray request and read as an outage.
 */
final class PathaoScript extends CourierScript
{
    private const HOST = 'https://api-hermes.pathao.com';

    private const CLIENT_ID = 'pathao_client_test_1';

    private const CLIENT_SECRET = 'pathao_secret_test_1';

    private const USERNAME = 'merchant@example.com';

    private const PASSWORD = 'pathao_password_test_1';

    /** What the grant answers with, and what every booking must then carry in its Authorization header. */
    private const ACCESS_TOKEN = 'pathao_access_token_test_1';

    /** What Pathao says the token is good for, in seconds — its own documented five days. */
    private const TOKEN_LIFETIME_SECONDS = 432000;

    private const CONSIGNMENT_ID = 'DT250912G8KZP';

    /** Major units, as Pathao quotes a delivery fee at booking. */
    private const DELIVERY_FEE = 60;

    /** What Pathao says when it will not take a parcel, in the shape its errors arrive in. */
    private const REFUSAL = 'The recipient phone must be a valid 11 digit number.';

    /** What Pathao says when it will not issue a token on these credentials. */
    private const TOKEN_REFUSAL = 'The user credentials were incorrect.';

    /**
     * What Pathao is told about an ordinary parcel of this Merchant's, set the
     * way a Merchant filling in Settings sets it — deliberately not the
     * shipped config defaults, so a test proving the dialog opens on the
     * Merchant's decision cannot pass by accident on ours.
     */
    private const PICKUP_STORE = '114983';

    private const DELIVERY_TYPE = '12';

    private const ITEM_TYPE = '1';

    private const PARCEL_WEIGHT = 1.5;

    public function courier(): Courier
    {
        return Courier::Pathao;
    }

    public function makeUsable(): void
    {
        app(CourierSettings::class)->with([
            'pathaoEnabled' => true,
            'pathaoClientId' => self::CLIENT_ID,
            'pathaoClientSecret' => self::CLIENT_SECRET,
            'pathaoUsername' => self::USERNAME,
            'pathaoPassword' => self::PASSWORD,
            'pathaoPickupStore' => self::PICKUP_STORE,
            'pathaoDeliveryType' => self::DELIVERY_TYPE,
            'pathaoItemType' => self::ITEM_TYPE,
            'pathaoParcelWeight' => self::PARCEL_WEIGHT,
        ])->save();

        app()->forgetInstance(CourierSettings::class);
    }

    public function consignmentReference(): string
    {
        return self::CONSIGNMENT_ID;
    }

    /** Pathao issues no second code: the Consignment id is what a Customer follows the parcel by. */
    public function trackingCode(): string
    {
        return self::CONSIGNMENT_ID;
    }

    /**
     * What Pathao quotes for the delivery when it takes the parcel, in major
     * units. Steadfast says nothing about a charge at booking, which is why
     * this is Pathao's own and not the abstract's.
     */
    public function deliveryFee(): int
    {
        return self::DELIVERY_FEE;
    }

    public function fakeBooked(): void
    {
        $this->fakeBookedReporting('Pending');
    }

    public function fakeBookedReporting(string $rawStatus): void
    {
        $this->fake([$this->bookingUrl() => Http::response($this->booked($rawStatus))]);
    }

    public function fakeBookingRefused(): void
    {
        $this->fake([$this->bookingUrl() => Http::response([
            'message' => self::REFUSAL,
            'type' => 'error',
            'code' => 422,
            'errors' => ['recipient_phone' => [self::REFUSAL]],
        ], 422)]);
    }

    public function refusalWording(): string
    {
        return self::REFUSAL;
    }

    /**
     * Every field Pathao documents as required, filled: the store, the
     * merchant's own reference for the parcel, the recipient's name, an
     * eleven-digit phone, the address, how it is to go, what it is, how many
     * and how heavy, and a whole number of taka to collect. The token the
     * driver obtained for itself travels in the Authorization header.
     *
     * And three fields Pathao documents as required that are deliberately not
     * sent at all: the city, the zone and the area. They are integer ids of
     * Pathao's own, nothing in the system holds one, and Pathao resolves the
     * destination from the free-text address instead (ADR-0018). Their
     * absence is asserted rather than assumed, because a blank or a zero
     * quietly added later would send parcels to whatever depot that resolves
     * to.
     */
    public function assertBookedFor(Shipment $shipment): void
    {
        Http::assertSent(fn (Request $request): bool => $request->url() === $this->bookingUrl()
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN)
            && $request['store_id'] === self::PICKUP_STORE
            && $request['merchant_order_id'] === $shipment->invoice_reference
            && $request['recipient_name'] === $shipment->recipient_name
            && $request['recipient_phone'] === PhoneNumber::asDialled($shipment->recipient_phone)
            && $request['recipient_address'] === $shipment->recipient_address
            && filled($request['delivery_type'])
            && filled($request['item_type'])
            && $request['item_quantity'] === 1
            && filled($request['item_weight'])
            && $request['amount_to_collect'] === (int) round(Money::toMajor($shipment->collect_amount))
            && ! array_key_exists('recipient_city', $request->data())
            && ! array_key_exists('recipient_zone', $request->data())
            && ! array_key_exists('recipient_area', $request->data()));
    }

    /**
     * Bookings only. The token grant is a call to Pathao as well, and counting
     * it here would make "one parcel went out" depend on whether a token
     * happened to still be held.
     */
    public function assertBookingsSent(int $count): void
    {
        expect(Http::recorded(fn (Request $request): bool => $request->url() === $this->bookingUrl())->count())
            ->toBe($count, "Pathao was asked to take a parcel other than {$count} times.");
    }

    /**
     * Pathao's three questions a User is actually put in front of. The store
     * is the fourth thing it is told and is never asked about while the
     * Merchant has one — `assertBookedWithParcelDetails()` proves it goes out
     * all the same.
     *
     * @return array<string, string>
     */
    public function parcelDefaults(): array
    {
        return [
            'deliveryType' => self::DELIVERY_TYPE,
            'itemType' => self::ITEM_TYPE,
            'parcelWeight' => (string) self::PARCEL_WEIGHT,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function parcelOverrides(): array
    {
        return [
            'deliveryType' => '48',
            'itemType' => '2',
            'parcelWeight' => '2.5',
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    public function assertBookedWithParcelDetails(array $values): void
    {
        Http::assertSent(fn (Request $request): bool => $request->url() === $this->bookingUrl()
            && $request['delivery_type'] === $values['deliveryType']
            && $request['item_type'] === $values['itemType']
            && $request['item_weight'] === $values['parcelWeight']

            /** Never asked about and sent regardless: a question with one answer is still answered. */
            && $request['store_id'] === self::PICKUP_STORE);
    }

    /**
     * The store leads, because that is the order Pathao's driver declares its
     * fields in and the order the attempt's row is built in.
     *
     * @param  array<string, string>  $answers
     * @return array<string, string>
     */
    public function parcelRowFor(array $answers): array
    {
        return [ParcelField::PICKUP_STORE => self::PICKUP_STORE] + $answers;
    }

    /** Pathao insists on a store, and this Merchant has registered exactly one. */
    public function pickupStoreCount(): int
    {
        return 1;
    }

    /**
     * Pathao's documented order statuses, spelled as Pathao spells them.
     *
     * It narrates a parcel far more finely than Steadfast does, which is why
     * five separate words read as In Transit. A pickup that failed or was
     * called off is On Hold: that parcel never left the Merchant's hands.
     *
     * `Cancelled` leads the returned words because it is the dangerous one —
     * it must never read as the Order's own Cancelled — and Pathao documents
     * no word at all for a state it cannot name, so nothing here reports
     * Unknown.
     *
     * @return list<string>
     */
    public function rawStatusesFor(ShipmentStatus $status): array
    {
        return match ($status) {
            ShipmentStatus::Booked => ['Pending', 'Pickup_Requested', 'Assigned_for_Pickup'],
            ShipmentStatus::InTransit => ['Picked', 'At_the_Sorting_HUB', 'In_Transit', 'Received_at_Last_Mile_HUB', 'Assigned_for_Delivery'],
            ShipmentStatus::OnHold => ['On_Hold', 'Pickup_Failed', 'Pickup_Cancelled', 'Delivery_Failed'],
            ShipmentStatus::Delivered => ['Delivered'],
            ShipmentStatus::PartlyDelivered => ['Partial_Delivery'],
            ShipmentStatus::Returned => ['Cancelled', 'Returned'],
            ShipmentStatus::Unknown => [],
        };
    }

    public function supportsBulk(): bool
    {
        return true;
    }

    public function supportsWebhook(): bool
    {
        return true;
    }

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
        return 100;
    }

    /*
    |--------------------------------------------------------------------------
    | Pathao's own access token
    |--------------------------------------------------------------------------
    |
    | Nothing below is shared with another Courier: Pathao alone has to be
    | authenticated before it can be asked anything, and the token it issues
    | is the one piece of a Courier's machinery a Merchant never sees.
    |
    */

    public function tokenUrl(): string
    {
        return self::HOST.'/aladdin/api/v1/issue-token';
    }

    public function tokenRefusalWording(): string
    {
        return self::TOKEN_REFUSAL;
    }

    /** The word Pathao's grant hands back, so a test can look for it where it must never be. */
    public function issuedAccessToken(): string
    {
        return self::ACCESS_TOKEN;
    }

    /**
     * Pathao accepting a parcel, with the token it issues saying it is good
     * for this many seconds — so a test can watch the token be let go of and
     * asked for again before Pathao's own expiry.
     */
    public function fakeBookedWithTokenLasting(int $expiresIn): void
    {
        $this->fake([$this->bookingUrl() => Http::response($this->booked('Pending'))], $expiresIn);
    }

    /** Pathao answering the grant and refusing to issue a token at all. */
    public function fakeTokenRefused(): void
    {
        Http::fake([
            $this->tokenUrl() => Http::response([
                'message' => self::TOKEN_REFUSAL,
                'type' => 'error',
                'code' => 400,
            ], 400),
        ]);
    }

    /** Pathao answering a booking with the token rejected, as it does once a token stops being recognised. */
    public function fakeTokenRejected(): void
    {
        $this->fake([$this->bookingUrl() => Http::response([
            'message' => 'Unauthenticated.',
            'type' => 'error',
            'code' => 401,
        ], 401)]);
    }

    public function assertTokensIssued(int $count): void
    {
        expect(Http::recorded(fn (Request $request): bool => $request->url() === $this->tokenUrl())->count())
            ->toBe($count, "Pathao was asked for an access token other than {$count} times.");
    }

    /**
     * Whatever this fake is really about, with the token grant's answer laid
     * beside it: a booking cannot be reached without one, and an unstubbed
     * grant would read as an outage rather than as the thing being tested.
     *
     * `Http::fake()` merges stubs and the first to answer wins, so the grant
     * goes first and a later call laying its own token stub replaces nothing.
     *
     * @param  array<string, mixed>  $stubs
     */
    private function fake(array $stubs, int $expiresIn = self::TOKEN_LIFETIME_SECONDS): void
    {
        Http::fake(array_merge(
            [$this->tokenUrl() => Http::response($this->token($expiresIn))],
            $stubs,
        ));
    }

    /**
     * Pathao taking a parcel, in the envelope every one of its answers arrives
     * in.
     *
     * @return array<string, mixed>
     */
    private function booked(string $rawStatus): array
    {
        return [
            'message' => 'Order Created Successfully',
            'type' => 'success',
            'code' => 200,
            'data' => [
                'consignment_id' => self::CONSIGNMENT_ID,
                'merchant_order_id' => 'GIO-1001-1',
                'order_status' => $rawStatus,
                'delivery_fee' => self::DELIVERY_FEE,
            ],
        ];
    }

    /**
     * Pathao's grant answer, in the shape its documentation gives: the token,
     * a refresh token this system deliberately never uses, and a lifetime.
     *
     * @return array<string, mixed>
     */
    private function token(int $expiresIn): array
    {
        return [
            'access_token' => self::ACCESS_TOKEN,
            'refresh_token' => 'pathao_refresh_token_test_1',
            'expires_in' => $expiresIn,
            'token_type' => 'Bearer',
        ];
    }

    private function bookingUrl(): string
    {
        return self::HOST.'/aladdin/api/v1/orders';
    }
}
