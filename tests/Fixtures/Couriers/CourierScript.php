<?php

namespace Tests\Fixtures\Couriers;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Support\Couriers\DeliveryArea;
use App\Support\Settings\CourierSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * One Courier's documented request and response shapes, scripted for the
 * faked HTTP client, so that the courier tests run once per Courier as a
 * dataset and a later Courier adds a row rather than a file.
 *
 * Every method here speaks in the Courier's own terms: what it answers when a
 * parcel is booked, what it says when it refuses, and which of its own words
 * it reports a state with. What the system makes of those is what the tests
 * assert — mirroring `Tests\Fixtures\Payments\GatewayScript` and
 * `Tests\Fixtures\Tracking\ProviderScript` exactly.
 *
 * The couriers' asymmetries live here as declarations rather than as
 * branching inside a test: a test asks the script what this Courier supports,
 * never which Courier it is, exactly as the application asks the driver.
 */
abstract class CourierScript
{
    abstract public function courier(): Courier;

    /** Switch the Courier on for the installation with its credentials filled, the way a Merchant would. */
    abstract public function makeUsable(): void;

    /**
     * Retype every one of this Courier's credentials as a second account's,
     * the way a Merchant moving to one would — the Courier is still on and
     * still usable, and nothing else about the installation changes.
     *
     * Written once for every Courier rather than per script, because the
     * credentials a Courier has are already declared (`credentialsFor()`) and
     * what they are worth is not the point: the point is that they are not
     * the ones the last answer was read with.
     */
    public function makeUsableWithOtherCredentials(): void
    {
        $settings = app(CourierSettings::class);

        $settings->with(array_reduce(
            $settings->credentialsFor($this->courier()),
            fn (array $carry, string $property): array => $carry + [$property => 'second-account-'.$property],
            [],
        ))->save();

        app()->forgetInstance(CourierSettings::class);
    }

    /** The Courier's own identifier for a parcel it accepted, as it spells one. */
    abstract public function consignmentReference(): string;

    /** The code a Customer follows the parcel by, as this Courier spells one. */
    abstract public function trackingCode(): string;

    /** Fake the Courier accepting a parcel and answering with a Consignment. */
    abstract public function fakeBooked(): void;

    /**
     * Fake the Courier accepting a parcel and reporting it in one of its own
     * status words, so a test can prove that word is translated.
     */
    abstract public function fakeBookedReporting(string $rawStatus): void;

    /** Fake the Courier answering and refusing the parcel. */
    abstract public function fakeBookingRefused(): void;

    /** What the Courier says when it refuses, so a test can prove the operator is told it verbatim. */
    abstract public function refusalWording(): string;

    /**
     * Fake the Courier being unreachable for every call.
     *
     * The one thing every Courier does identically, because silence has no
     * vocabulary: a connection that will not open is the same fact whoever was
     * being called, and it reaches the driver as the same exception. A Courier
     * with a stranger outage overrides this.
     */
    public function fakeOutage(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException(
            "cURL error 28: Connection timed out after 5000 milliseconds for {$request->url()}"
        ));
    }

    /** Assert a booking went out for this Shipment carrying every field the Courier calls mandatory, filled. */
    abstract public function assertBookedFor(Shipment $shipment): void;

    /*
    |--------------------------------------------------------------------------
    | Being handed a batch
    |--------------------------------------------------------------------------
    |
    | The couriers disagree here as sharply as they do about tracking. One
    | takes five hundred parcels in a single call and answers with a line per
    | parcel; two take one parcel per call and answer about that parcel alone.
    | Every one of those differences is declared and asked, never branched on
    | by name — `batchLimit()` is the whole of what a caller needs to know, and
    | a Courier with no bulk endpoint declares 1 rather than declaring "no
    | bulk" (ADR-0020).
    |
    */

    /**
     * Fake the Courier taking every parcel it is offered, each with its own
     * Consignment.
     *
     * Answered off the request rather than from a fixed body, because the
     * whole question a batch raises is whether each answer reached the parcel
     * it was about: a blanket answer would pass however badly the matching
     * was done.
     */
    abstract public function fakeBatchBooked(): void;

    /** Fake the Courier refusing every parcel it is offered, in its own words. */
    abstract public function fakeBatchRefused(): void;

    /**
     * Fake the Courier taking the parcels carrying these references of ours
     * and refusing every other one it is offered, which is what a batch that
     * partly failed actually looks like.
     *
     * @param  list<string>  $accepting  our own invoice references.
     */
    abstract public function fakeBatchPartlyBooked(array $accepting): void;

    /**
     * Fake the Courier taking the parcels carrying these references of ours
     * and then settling nothing else: the batch is cut off partway with real
     * parcels already created.
     *
     * The failure ADR-0020 is written around, and the reason the rows are
     * written before anything is dispatched. What it looks like differs by
     * Courier and that is the script's business — a Courier sent one parcel a
     * call answers for those and then the line drops; one sent the whole batch
     * in a single call answers about those and says nothing about the rest.
     * Either way the same thing is true of the system: what was settled is
     * written down, and what was not is still waiting.
     *
     * @param  list<string>  $accepting  our own invoice references.
     */
    abstract public function fakeBatchCutShortAfter(array $accepting): void;

    /**
     * The Consignment this Courier answers with for the parcel carrying this
     * reference of ours, under any of the batch fakes above.
     */
    abstract public function consignmentFor(string $invoiceReference): string;

    /**
     * How many parcels each booking call carried, in the order the calls went
     * out.
     *
     * The observable form of `batchLimit()`, and the reason it is asked of the
     * script: read off the driver, "the limit was honoured" would pass against
     * any number at all.
     *
     * @return list<int>
     */
    abstract public function batchSizes(): array;

    /**
     * How many booking calls it takes this Courier to be handed this many
     * parcels at once.
     *
     * Derived from the limit the script declares rather than declared again:
     * the sizes are what a test asserts, and this is only the count of them.
     */
    public function bookingCallsFor(int $parcels): int
    {
        return (int) ceil($parcels / $this->batchLimit());
    }

    /*
    |--------------------------------------------------------------------------
    | Being asked where a parcel is
    |--------------------------------------------------------------------------
    |
    | The couriers disagree about this more than about anything else. One
    | keeps a whole history and times every line of it; one answers with a
    | single word and no time at all; one answers with a word and the moment
    | it last changed. Every one of those differences is declared here and
    | asked, never branched on by name.
    |
    */

    /**
     * Fake the Courier answering a question about a parcel with these, its
     * own, status words.
     *
     * A Courier that keeps a history answers with all of them, oldest first;
     * one that keeps none answers with the last of them, which is where the
     * parcel stands now. So one call scripts either shape and a test never
     * asks which it got.
     */
    abstract public function fakeTracking(string ...$rawStatuses): void;

    /** Fake the Courier answering and refusing to account for the parcel at all. */
    abstract public function fakeTrackingRefused(): void;

    /** What the Courier says when it will not account for a parcel, so a test can prove it reaches the timeline verbatim. */
    abstract public function trackingRefusalWording(): string;

    /** Assert the Courier was asked about this parcel, by the name the Courier knows it under. */
    abstract public function assertTrackedFor(Shipment $shipment): void;

    /** Assert the Courier was asked where a parcel is exactly this many times since the last fake was laid. */
    abstract public function assertPollsSent(int $count): void;

    /**
     * How long this Courier says a parcel may be left alone before it is
     * asked about again.
     *
     * Deliberately a second transcription of the driver's own constant, for
     * the reason `rawStatusesFor()` is one: read off the driver, a test that
     * the interval is honoured would pass against any number at all,
     * including the one global constant this is here to prove does not exist.
     */
    abstract public function reAskIntervalSeconds(): int;

    /**
     * Whether this Courier answers with everything that has happened to a
     * parcel rather than only with where it stands now.
     *
     * True for exactly one of the three, and the whole reason a Shipment
     * Event is deduplicated by a unique key rather than by a check at the
     * call site (ADR-0019).
     */
    abstract public function tracksWholeHistory(): bool;

    /**
     * Whether this Courier says when a thing happened, or only that it did.
     *
     * The asymmetry a Shipment Event's two timestamps exist for: a Courier
     * that gives no times of its own leaves `occurred_at` null on every row
     * and the timeline is drawn from ours.
     */
    abstract public function reportsItsOwnTime(): bool;

    /**
     * How many timeline entries this Courier's answer to one question makes,
     * where it was scripted with this many status words.
     *
     * One for a Courier that only ever says where a parcel is now, whatever
     * it was scripted with — which is what lets a test say "it re-imported
     * the same history" without knowing that only RedEx has one.
     */
    public function timelineEntriesFor(string ...$rawStatuses): int
    {
        return $this->tracksWholeHistory() ? count($rawStatuses) : 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Announcing, on its own
    |--------------------------------------------------------------------------
    |
    | Two of the three announce and one does not, so everything below is
    | defaulted to "nothing" rather than left abstract — the same shape
    | `fakeAreas()` takes for the two couriers that read a free-text address.
    | A test asks `supportsWebhook()` before it reaches for any of it, and the
    | dataset does the asking for it.
    |
    */

    /**
     * The body this Courier posts when it says a parcel's state has moved, in
     * its own field names and its own status word.
     *
     * Named by our invoice reference and never by ours-and-theirs: every one
     * of these couriers echoes back what the booking told it, which is what
     * makes an announcement name one attempt rather than an Order. A test
     * hands a reference it holds no Shipment for to script the announcement
     * that must be ignored.
     *
     * @return array<string, mixed>
     */
    public function statusAnnouncement(string $invoiceReference, string $rawStatus): array
    {
        return [];
    }

    /**
     * The body this Courier posts when it has a line of prose about a parcel
     * and no state to report — the one thing a webhook is believed for.
     *
     * @return array<string, mixed>
     */
    public function narrationAnnouncement(string $invoiceReference): array
    {
        return [];
    }

    /** What that line says, so a test can prove it reaches the timeline verbatim. */
    public function narrationWording(): string
    {
        return '';
    }

    /**
     * What this Courier asks about a parcel beyond the recipient, by the name
     * its driver declares, and the value `makeUsable()` left in Settings as
     * the installation's default for it.
     *
     * What the send dialog should open pre-filled with. Empty for a Courier
     * that asks nothing.
     *
     * @return array<string, string>
     */
    abstract public function parcelDefaults(): array;

    /**
     * The same questions answered differently, as a User overriding them for
     * one unusual parcel would. Every value differs from `parcelDefaults()`.
     *
     * @return array<string, string>
     */
    abstract public function parcelOverrides(): array;

    /**
     * Assert the booking that went out carried these answers to this Courier's
     * own questions, in the Courier's own field names.
     *
     * @param  array<string, string>  $values
     */
    abstract public function assertBookedWithParcelDetails(array $values): void;

    /**
     * The whole of what is written onto the attempt when the User answered
     * this Courier's questions this way — their answers, plus whatever this
     * Courier asks that nobody is ever shown, in the order the driver declares
     * them.
     *
     * Usually just the answers back. It is a question at all because a
     * question with one possible answer is left out of the dialog and still
     * sent (`ParcelField::isWorthAsking()`), so a Merchant with one pickup
     * store has a row holding a field they were never asked about — and what
     * the row says was sent must be exactly what was sent, no more and no
     * fewer keys.
     *
     * @param  array<string, string>  $answers
     * @return array<string, string>
     */
    public function parcelRowFor(array $answers): array
    {
        return $answers;
    }

    /**
     * How many pickup stores this Courier gives the Merchant a choice between
     * on an installation `makeUsable()` has set up.
     *
     * Zero for a Courier with no notion of a store at all, one for a Merchant
     * with a single warehouse — and either way nobody is asked which store the
     * parcel leaves from, which is what this is here to prove.
     */
    abstract public function pickupStoreCount(): int;

    /** Assert the Courier was asked to take a parcel exactly this many times since the last fake was laid. */
    abstract public function assertBookingsSent(int $count): void;

    /**
     * This Courier's own words for a canonical state — every one of them, so
     * each Courier proves its own vocabulary rather than the abstract proving
     * one on everybody's behalf.
     *
     * Empty where this Courier never reports that state, which is expected
     * and not a gap: Steadfast never says a parcel is in transit and RedEx
     * never says Unknown (ADR-0018). Empty for every Courier where the state
     * is Awaiting dispatch — that one is the system's own word for a row
     * written and not yet handed over, and no Courier has a word for a parcel
     * it has never been offered.
     *
     * Deliberately a second transcription of the same documentation the
     * driver's own map is written from, and never read off the driver: two
     * independent readings of a Courier's status table are what catch a word
     * mistyped into one of them, which is the whole failure this is here for.
     *
     * @return list<string>
     */
    abstract public function rawStatusesFor(ShipmentStatus $status): array;

    /**
     * What this Courier calls a parcel that came back. It maps to Returned
     * and never to anything resembling the Order's own Cancelled — which is
     * asserted in each Courier's own words, because two of the three say
     * "cancelled" for exactly this.
     */
    public function returnedWording(): string
    {
        return $this->rawStatusesFor(ShipmentStatus::Returned)[0];
    }

    abstract public function supportsBulk(): bool;

    abstract public function supportsWebhook(): bool;

    abstract public function supportsBalance(): bool;

    /**
     * Fake this Courier answering with what it is holding of the Merchant's
     * money.
     *
     * Nothing at all for a Courier that publishes no balance, because there
     * is no call to fake — and a test where one goes out anyway fails on a
     * stray request rather than on an assertion, which is what proves such a
     * Courier is never asked.
     */
    public function fakeBalance(): void {}

    /**
     * Fake this Courier answering the balance question and settling nothing:
     * a bad morning at its end, which is a different fact from a line that
     * would not open.
     *
     * It answers, so the call is recorded — which is what lets a test prove
     * the Courier was asked again rather than infer it. A connection that
     * never opens records nothing at all (the client records a pair only for
     * a fulfilled promise), so `fakeOutage()` cannot prove that and must not
     * be used for it.
     */
    public function fakeBalanceUnavailable(): void {}

    /**
     * What that answer comes to in minor units, or null for a Courier that
     * publishes no balance at all.
     */
    public function balanceReported(): ?int
    {
        return null;
    }

    /**
     * Where this Courier says what it is holding, or null for one that
     * publishes no such figure.
     */
    public function balanceUrl(): ?string
    {
        return null;
    }

    /**
     * Assert this Courier was asked what it is holding exactly this many
     * times since the last fake was laid.
     *
     * A Courier with nowhere to ask is held to a stricter reading of the same
     * sentence: nothing may have gone out anywhere at all, which is the only
     * honest form of "it was not asked" for a Courier with no such endpoint.
     */
    public function assertBalanceAsked(int $count): void
    {
        $url = $this->balanceUrl();

        $asked = $url === null
            ? Http::recorded()->count()
            : Http::recorded(fn (Request $request): bool => $request->url() === $url)->count();

        expect($asked)->toBe($count, "{$this->courier()->getLabel()} was asked what it is holding other than {$count} times.");
    }

    abstract public function needsAreaResolution(): bool;

    abstract public function batchLimit(): int;

    /**
     * Fake this Courier answering with the delivery areas it serves.
     *
     * Nothing for a Courier that reads an address in free text and is never
     * asked for a list — which is the honest answer rather than a gap, and
     * what lets a test that must offer every Courier at once say "and each of
     * them answers with its areas" without naming the one that has any.
     */
    public function fakeAreas(): void {}

    /**
     * Which of this Courier's own areas its list resolves the address
     * `orderReadyToSend()` builds — "House 4, Road 12, Banani", in Dhaka — to,
     * and a second area a User might correct that guess to.
     *
     * Both nothing for a Courier that resolves the destination itself, which
     * is what a test asks before expecting either.
     */
    public function guessedArea(): ?DeliveryArea
    {
        return null;
    }

    public function correctedArea(): ?DeliveryArea
    {
        return null;
    }
}
