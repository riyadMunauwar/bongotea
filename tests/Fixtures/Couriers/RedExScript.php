<?php

namespace Tests\Fixtures\Couriers;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Support\Couriers\DeliveryArea;
use App\Support\Couriers\ParcelField;
use App\Support\Money;
use App\Support\PhoneNumber;
use App\Support\Settings\CourierSettings;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * RedEx's create-parcel call, as its documentation describes it: the
 * Merchant's access token in RedEx's own header, a JSON body naming the
 * recipient, the delivery area by id *and* by name, a weight in grams and a
 * declared value, and an answer carrying the one tracking id RedEx knows a
 * parcel by.
 *
 * RedEx is the one Courier of the three that must be asked where it delivers
 * before a parcel can be addressed at all, so every fake here lays its area
 * list beside whatever it is really about — the same reason `PathaoScript`
 * lays the token grant's answer beside everything. A test that stubbed only
 * the booking would have the area list refused as a stray request, and a
 * dialog that could not be drawn would read as a defect in the thing being
 * tested.
 *
 * The list is deliberately tiny and its wording is deliberately awkward:
 * `orderReadyToSend()`'s address names Banani and nothing else, one area
 * shares a name across two Districts, and the area a User corrects to
 * (`correctedArea()`) is one no address in the suite mentions — so a guess
 * that were merely "the first area in the list" could not pass.
 */
final class RedExScript extends CourierScript
{
    private const HOST = 'https://openapi.redx.com.bd/v1.0.0-beta';

    /** The one credential RedEx has: a static token the Merchant pastes, and the whole of its authentication. */
    private const ACCESS_TOKEN = 'redex_access_token_test_1';

    /** RedEx issues one word for a parcel: the Consignment it answers to and the code a Customer follows are the same. */
    private const TRACKING_ID = '21D8AJTHGYIU';

    /** What RedEx says when it will not take a parcel, in the shape its errors arrive in. */
    private const REFUSAL = 'Delivery area is not serviceable.';

    /** What RedEx says when it has no record of the tracking id it was asked about. */
    private const TRACKING_REFUSAL = 'Parcel not found.';

    /**
     * When the first line of a scripted history happened, with each later one
     * an hour after the last. Fixed rather than relative to now, because a
     * test that travels in time must still find the same history — RedEx's
     * times are RedEx's and do not move when ours do.
     */
    private const HISTORY_BEGAN = '2026-09-12T06:00:00Z';

    /**
     * When RedEx says the thing it is calling back about happened. Its own
     * time, as every line it writes carries one, and not one of the history's
     * — a callback is RedEx speaking about the moment, not re-reading its
     * list.
     */
    private const ANNOUNCED_AT = '2026-09-12T11:30:00Z';

    /**
     * What a RedEx callback with nothing to report says. RedEx names a state
     * on almost everything it sends, so this is the unusual shape rather than
     * the ordinary one — and it must still move nothing at all.
     */
    private const NARRATION = 'Rider could not reach the customer by phone';

    /**
     * What RedEx is told about an ordinary parcel of this Merchant's, set the
     * way a Merchant filling in Settings sets it — deliberately not the
     * shipped config defaults, so a test proving the dialog opens on the
     * Merchant's decision cannot pass by accident on ours.
     */
    private const PICKUP_STORE = '98765';

    /** Kilograms here and in Settings; RedEx is told grams, which is the driver's conversion to make. */
    private const PARCEL_WEIGHT = 1.2;

    /** Major units, as RedEx takes a declared value. */
    private const DECLARED_VALUE = 2500.0;

    public function courier(): Courier
    {
        return Courier::RedEx;
    }

    /**
     * RedEx switched on and supplied — and answering with its areas, because
     * an installation that cannot be told where a parcel is going is not one
     * a Merchant could send anything from.
     */
    public function makeUsable(): void
    {
        app(CourierSettings::class)->with([
            'redexEnabled' => true,
            'redexAccessToken' => self::ACCESS_TOKEN,
            'redexPickupStore' => self::PICKUP_STORE,
            'redexParcelWeight' => self::PARCEL_WEIGHT,
            'redexDeclaredValue' => self::DECLARED_VALUE,
        ])->save();

        app()->forgetInstance(CourierSettings::class);

        $this->fakeAreas();
    }

    public function consignmentReference(): string
    {
        return self::TRACKING_ID;
    }

    /** RedEx issues no second code: the tracking id is both. */
    public function trackingCode(): string
    {
        return self::TRACKING_ID;
    }

    public function fakeBooked(): void
    {
        $this->fakeBookedReporting('pickup-pending');
    }

    public function fakeBookedReporting(string $rawStatus): void
    {
        $this->fake([$this->bookingUrl() => Http::response([
            'tracking_id' => self::TRACKING_ID,
            'status' => $rawStatus,
            'message' => 'Parcel created successfully',
        ])]);
    }

    public function fakeBookingRefused(): void
    {
        $this->fake([$this->bookingUrl() => Http::response([
            'message' => self::REFUSAL,
            'statusCode' => 400,
        ], 400)]);
    }

    public function refusalWording(): string
    {
        return self::REFUSAL;
    }

    /**
     * RedEx unreachable for the parcel, and still answering with its areas.
     *
     * The asymmetry is the point and is not a convenience: the area list is
     * held for a day (`RedExAreas`), so a RedEx that has just gone quiet is a
     * RedEx this installation still knows the areas of. An outage that took
     * the list with it would test "the dialog could not be drawn" rather than
     * "the parcel could not be sent", which is a different fact.
     *
     * One callback deciding both, rather than a list answer stubbed beside a
     * catch-all that throws: the faked client invokes *every* stub it holds on
     * every request and takes the first answer, so a stub that throws throws
     * for calls another stub had already answered.
     */
    public function fakeOutage(): void
    {
        Http::fake(fn (Request $request) => $request->url() === $this->areasUrl()
            ? Http::response($this->areaList())
            : throw new ConnectionException(
                "cURL error 28: Connection timed out after 5000 milliseconds for {$request->url()}"
            ));
    }

    /**
     * Every field RedEx documents as required, filled: the recipient's name,
     * an eleven-digit phone, the address, the delivery area as both the id it
     * routes on and the name it prints, the merchant's own reference, a whole
     * number of taka to collect, a weight in grams, a declared value and the
     * store the parcel leaves from. The Merchant's token travels in RedEx's
     * own header.
     *
     * The area is held against the attempt rather than against a constant,
     * because what went out must be what the row says went out — a guess the
     * User corrected has to reach RedEx corrected.
     */
    public function assertBookedFor(Shipment $shipment): void
    {
        Http::assertSent(fn (Request $request): bool => $request->url() === $this->bookingUrl()
            && $request->method() === 'POST'
            && $request->hasHeader('API-ACCESS-TOKEN', 'Bearer '.self::ACCESS_TOKEN)
            && $request['customer_name'] === $shipment->recipient_name
            && $request['customer_phone'] === PhoneNumber::asDialled($shipment->recipient_phone)
            && $request['customer_address'] === $shipment->recipient_address
            && $request['delivery_area'] === $shipment->parcelDetail(DeliveryArea::NAME)

            /** Read back as the word it is rather than as the number RedEx takes it for, so a blank or a nought cannot pass for an id. */
            && (string) $request['delivery_area_id'] === $shipment->parcelDetail(DeliveryArea::ANSWER)
            && filled($request['delivery_area'])
            && filled($request['delivery_area_id'])
            && $request['merchant_invoice_id'] === $shipment->invoice_reference
            && $request['cash_collection_amount'] === (string) (int) round(Money::toMajor($shipment->collect_amount))
            && $request['parcel_weight'] > 0
            && $request['value'] > 0
            && $request['pickup_store_id'] === (int) self::PICKUP_STORE);
    }

    /**
     * Bookings only. The area list is a call to RedEx as well, and counting it
     * here would make "one parcel went out" depend on whether the list
     * happened to still be held.
     */
    public function assertBookingsSent(int $count): void
    {
        expect(Http::recorded(fn (Request $request): bool => $request->url() === $this->bookingUrl())->count())
            ->toBe($count, "RedEx was asked to take a parcel other than {$count} times.");
    }

    /**
     * RedEx answering where a parcel is: its whole history, oldest first,
     * every line of it timed, which is what it answers with on every poll
     * however many times it has been asked before.
     */
    public function fakeTracking(string ...$rawStatuses): void
    {
        $at = CarbonImmutable::parse(self::HISTORY_BEGAN);

        $this->fake([$this->trackingUrl() => Http::response([
            'tracking' => array_values(array_map(
                fn (string $rawStatus, int $step): array => [
                    'message_en' => ucwords(str_replace('-', ' ', $rawStatus)),
                    'time' => $at->addHours($step)->toIso8601ZuluString(),
                    'status' => $rawStatus,
                ],
                $rawStatuses,
                array_keys($rawStatuses),
            )),
        ])]);
    }

    /** RedEx answering about a tracking id it has no record of. */
    public function fakeTrackingRefused(): void
    {
        $this->fake([$this->trackingUrl() => Http::response([
            'message' => self::TRACKING_REFUSAL,
            'statusCode' => 404,
        ], 404)]);
    }

    public function trackingRefusalWording(): string
    {
        return self::TRACKING_REFUSAL;
    }

    /** Asked by the one id RedEx knows a parcel under, with the Merchant's token in RedEx's own header. */
    public function assertTrackedFor(Shipment $shipment): void
    {
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::HOST.'/parcel/track/'.$shipment->consignment_reference
            && $request->hasHeader('API-ACCESS-TOKEN', 'Bearer '.self::ACCESS_TOKEN));
    }

    /**
     * Questions about a parcel only. The area list is a call to RedEx as
     * well, and counting it here would make "one parcel was asked about"
     * depend on whether the list happened to still be held.
     */
    public function assertPollsSent(int $count): void
    {
        expect(Http::recorded(fn (Request $request): bool => str_starts_with($request->url(), self::HOST.'/parcel/track/'))->count())
            ->toBe($count, "RedEx was asked where a parcel is other than {$count} times.");
    }

    /**
     * RedEx calling back to say a parcel has moved: our own invoice reference
     * echoed back, the one id it knows the parcel under, its own status word
     * and its own time.
     *
     * Nothing here carries the token RedEx authenticates a callback with,
     * because that token rides in the URL's query string — which is exactly
     * why nothing about this body is believed (ADR-0019).
     *
     * @return array<string, mixed>
     */
    public function statusAnnouncement(string $invoiceReference, string $rawStatus): array
    {
        return [
            'merchant_invoice_id' => $invoiceReference,
            'tracking_id' => self::TRACKING_ID,
            'status' => $rawStatus,
            'message_en' => ucwords(str_replace('-', ' ', $rawStatus)),
            'time' => self::ANNOUNCED_AT,
        ];
    }

    /**
     * RedEx calling back with a line about the parcel and no state word in
     * it.
     *
     * @return array<string, mixed>
     */
    public function narrationAnnouncement(string $invoiceReference): array
    {
        return [
            'merchant_invoice_id' => $invoiceReference,
            'tracking_id' => self::TRACKING_ID,
            'message_en' => self::NARRATION,
            'time' => self::ANNOUNCED_AT,
        ];
    }

    public function narrationWording(): string
    {
        return self::NARRATION;
    }

    /**
     * Four hours, because RedEx calls back as a parcel moves and this is only
     * the backstop against a callback that never arrived.
     */
    public function reAskIntervalSeconds(): int
    {
        return 14400;
    }

    /** RedEx re-imports its whole history on every poll, which is the one this constraint exists for (ADR-0019). */
    public function tracksWholeHistory(): bool
    {
        return true;
    }

    /** And times every line of it, which is why a RedEx timeline reads like a courier's own tracking page. */
    public function reportsItsOwnTime(): bool
    {
        return true;
    }

    /**
     * The two questions RedEx puts in front of a User. The store is the third
     * thing it is told and is never asked about while the Merchant has one;
     * the delivery area is the fourth and is asked of every Courier that needs
     * one rather than being RedEx's own question.
     *
     * @return array<string, string>
     */
    public function parcelDefaults(): array
    {
        return [
            'parcelWeight' => (string) self::PARCEL_WEIGHT,
            'declaredValue' => (string) self::DECLARED_VALUE,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function parcelOverrides(): array
    {
        return [
            'parcelWeight' => '3.25',
            'declaredValue' => '4999',
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    public function assertBookedWithParcelDetails(array $values): void
    {
        Http::assertSent(fn (Request $request): bool => $request->url() === $this->bookingUrl()

            /** Grams, which is RedEx's own unit and not the kilograms every Merchant default is written in. */
            && $request['parcel_weight'] === (int) round(((float) $values['parcelWeight']) * 1000)
            && $request['value'] === (int) round((float) $values['declaredValue'])

            /** Never asked about and sent regardless: a question with one answer is still answered. */
            && $request['pickup_store_id'] === (int) self::PICKUP_STORE);
    }

    /**
     * The area leads and the store follows, because that is the order the
     * attempt's row is built in: where the parcel is going, then what the
     * Courier's own questions were answered with.
     *
     * @param  array<string, string>  $answers
     * @return array<string, string>
     */
    public function parcelRowFor(array $answers): array
    {
        return $this->guessedArea()->asParcelDetails()
            + [ParcelField::PICKUP_STORE => self::PICKUP_STORE]
            + $answers;
    }

    /** RedEx insists on a store, and this Merchant has registered exactly one. */
    public function pickupStoreCount(): int
    {
        return 1;
    }

    /**
     * RedEx's documented parcel statuses, spelled as RedEx spells them.
     *
     * `agent-returning` leads the returned words because it is the one worth
     * getting wrong: a parcel a rider is carrying back reads as Returned and
     * never as In Transit, so a dead parcel is not hidden among the moving
     * ones for a day. A pickup that never happened is On Hold — that parcel
     * never left the Merchant's hands — and RedEx names every state it
     * reports, so nothing here is Unknown.
     *
     * @return list<string>
     */
    public function rawStatusesFor(ShipmentStatus $status): array
    {
        return match ($status) {
            ShipmentStatus::Written => [],
            ShipmentStatus::Booked => ['pickup-pending', 'pickup-assigned'],
            ShipmentStatus::InTransit => ['picked-up', 'received-warehouse', 'in-transit', 'ready-for-delivery', 'agent-assigned', 'delivery-in-progress'],
            ShipmentStatus::OnHold => ['hold', 'pickup-failed', 'delivery-failed'],
            ShipmentStatus::Delivered => ['delivered'],
            ShipmentStatus::PartlyDelivered => ['partial-delivered'],
            ShipmentStatus::Returned => ['agent-returning', 'returned'],
            ShipmentStatus::Unknown => [],
        };
    }

    public function supportsBulk(): bool
    {
        return false;
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
        return true;
    }

    /** One parcel to a call, declared as a limit rather than as an absence. */
    public function batchLimit(): int
    {
        return 1;
    }

    /*
    |--------------------------------------------------------------------------
    | RedEx's own delivery areas
    |--------------------------------------------------------------------------
    |
    | Nothing below is shared with another Courier: RedEx alone refuses an
    | address in free text, and the list it insists on being answered out of
    | is the one piece of a Courier's machinery a Merchant never sees.
    |
    */

    /** RedEx answering with the areas it delivers to. Laid beside every other fake, and on its own where a test only draws the dialog. */
    public function fakeAreas(): void
    {
        Http::fake([$this->areasUrl() => Http::response($this->areaList())]);
    }

    /**
     * What RedEx's own list resolves `orderReadyToSend()`'s address — "House
     * 4, Road 12, Banani", in Dhaka — to.
     */
    public function guessedArea(): DeliveryArea
    {
        return new DeliveryArea('1230', 'Banani', 'Dhaka');
    }

    /**
     * A different area of RedEx's own, as a User correcting a wrong guess
     * picks one: named by no address anywhere in the suite, so a booking that
     * carries it carries it because a User chose it.
     */
    public function correctedArea(): DeliveryArea
    {
        return new DeliveryArea('1215', 'Uttara Sector 4', 'Dhaka');
    }

    public function assertAreasFetched(int $count): void
    {
        expect(Http::recorded(fn (Request $request): bool => $request->url() === $this->areasUrl())->count())
            ->toBe($count, "RedEx was asked for its delivery areas other than {$count} times.");
    }

    public function areasUrl(): string
    {
        return self::HOST.'/areas';
    }

    /**
     * RedEx's area list in the shape its documentation gives, including the
     * post code and division this system reads nothing of.
     *
     * Sadar appears twice under two Districts on purpose: it is the shape
     * that makes a guess from an address alone impossible, and the reason the
     * Order's District is a tie-breaker rather than decoration.
     *
     * @return array<string, mixed>
     */
    private function areaList(): array
    {
        return [
            'areas' => [
                ['id' => 1230, 'name' => 'Banani', 'post_code' => 1213, 'district_name' => 'Dhaka', 'division_name' => 'Dhaka'],
                ['id' => 1215, 'name' => 'Uttara Sector 4', 'post_code' => 1230, 'district_name' => 'Dhaka', 'division_name' => 'Dhaka'],
                ['id' => 1801, 'name' => 'Sadar', 'post_code' => 1700, 'district_name' => 'Gazipur', 'division_name' => 'Dhaka'],
                ['id' => 9001, 'name' => 'Sadar', 'post_code' => 9100, 'district_name' => 'Khulna', 'division_name' => 'Khulna'],
            ],
        ];
    }

    /**
     * Whatever this fake is really about, with RedEx's area list laid beside
     * it: a parcel cannot be addressed without one, and an unstubbed list
     * would read as an outage rather than as the thing being tested.
     *
     * `Http::fake()` merges stubs and the first to answer wins, so the areas
     * go first and a later call laying its own list replaces nothing.
     *
     * @param  array<string, mixed>  $stubs
     */
    private function fake(array $stubs): void
    {
        Http::fake(array_merge(
            [$this->areasUrl() => Http::response($this->areaList())],
            $stubs,
        ));
    }

    /** Any parcel: the tracking id is the last segment, so the stub is matched on the prefix. */
    private function trackingUrl(): string
    {
        return self::HOST.'/parcel/track/*';
    }

    /**
     * RedEx is offered one parcel per call — it has no bulk endpoint at all —
     * so a batch is answered one call at a time, each answer built from the
     * reference that call carried.
     */
    public function fakeBatchBooked(): void
    {
        $this->fakeEachParcel(fn (string $invoice): array => [[
            'tracking_id' => $this->consignmentFor($invoice),
            'status' => 'pickup-pending',
            'message' => 'Parcel created successfully',
        ], 200]);
    }

    public function fakeBatchRefused(): void
    {
        $this->fakeEachParcel(fn (string $invoice): array => $this->refusedInBatch());
    }

    /**
     * @param  list<string>  $accepting
     */
    public function fakeBatchPartlyBooked(array $accepting): void
    {
        $this->fakeEachParcel(fn (string $invoice): array => in_array($invoice, $accepting, true)
            ? [[
                'tracking_id' => $this->consignmentFor($invoice),
                'status' => 'pickup-pending',
                'message' => 'Parcel created successfully',
            ], 200]
            : $this->refusedInBatch());
    }

    /**
     * RedEx takes the parcels it is named and then the line drops — the shape
     * a batch cut short takes for a Courier sent one parcel a call.
     *
     * The stub is keyed to the booking address, so the area list beside it
     * still answers: what is being scripted is RedEx going quiet about
     * parcels, not an installation that has no idea where it delivers.
     *
     * @param  list<string>  $accepting
     */
    public function fakeBatchCutShortAfter(array $accepting): void
    {
        $this->fake([$this->bookingUrl() => function (Request $request) use ($accepting): PromiseInterface {
            $invoice = (string) ($request['merchant_invoice_id'] ?? '');

            if (! in_array($invoice, $accepting, true)) {
                throw new ConnectionException(
                    "cURL error 28: Connection timed out after 5000 milliseconds for {$request->url()}"
                );
            }

            return Http::response([
                'tracking_id' => $this->consignmentFor($invoice),
                'status' => 'pickup-pending',
                'message' => 'Parcel created successfully',
            ]);
        }]);
    }

    /** RedEx spells a parcel's id as a code of its own, so one derived for a batch is one too. */
    public function consignmentFor(string $invoiceReference): string
    {
        return self::TRACKING_ID.strtoupper(substr(md5($invoiceReference), 0, 4));
    }

    /**
     * One parcel to a call, which for RedEx is not a choice: it publishes no
     * bulk endpoint.
     *
     * @return list<int>
     */
    public function batchSizes(): array
    {
        return array_fill(
            0,
            Http::recorded(fn (Request $request): bool => $request->url() === $this->bookingUrl())->count(),
            1,
        );
    }

    /**
     * Answer each booking call with what this case says about the parcel that
     * call named.
     *
     * @param  callable(string): array{array<string, mixed>, int}  $answer
     */
    private function fakeEachParcel(callable $answer): void
    {
        $this->fake([$this->bookingUrl() => function (Request $request) use ($answer): PromiseInterface {
            [$body, $status] = $answer((string) ($request['merchant_invoice_id'] ?? ''));

            return Http::response($body, $status);
        }]);
    }

    /**
     * @return array{array<string, mixed>, int}
     */
    private function refusedInBatch(): array
    {
        return [['message' => self::REFUSAL, 'statusCode' => 400], 400];
    }

    private function bookingUrl(): string
    {
        return self::HOST.'/parcel';
    }
}
