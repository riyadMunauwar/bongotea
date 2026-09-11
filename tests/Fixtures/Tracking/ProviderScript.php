<?php

namespace Tests\Fixtures\Tracking;

use App\Enums\TrackingProvider;
use App\Models\Destination;

/**
 * One Provider's documented wire shapes, scripted for the faked HTTP client,
 * so that the tracking tests run once per Provider as a dataset and a second
 * platform is a subclass and a row rather than a new test file.
 *
 * Every method here speaks in the Provider's own terms: what it answers when
 * a batch is delivered, what it says when it refuses one, and where in the
 * payload it puts each thing the system claims to have sent. What the system
 * makes of those is what the tests assert.
 *
 * The reader methods take one sent event in the Provider's own shape and hand
 * back the answer in ours, which is what lets one test body ask "what value
 * was reported" of a platform that spells it `custom_data.value` and of one
 * that spells it something else.
 */
abstract class ProviderScript
{
    abstract public function provider(): TrackingProvider;

    /**
     * A usable Destination for this Provider, switched on and default, the
     * way a Merchant's first one is once they have pasted their credentials.
     */
    abstract public function makeDestination(): Destination;

    /** How many events one request to this Provider may carry. */
    abstract public function batchLimit(): int;

    /** How long after a Moment happened this Provider still accepts it. */
    abstract public function acceptanceWindowSeconds(): int;

    /** What this Provider calls the event a completed Order reports. */
    abstract public function purchaseEvent(): string;

    /** What this Provider calls the load of a page, which its base code reports by itself. */
    abstract public function pageViewEvent(): string;

    /**
     * Whether this document carries the browser snippet addressed to this
     * Destination: the Provider's own base code, and the public identifier
     * that says whose it is.
     */
    abstract public function carriesBaseCodeFor(Destination $destination, string $html): bool;

    /**
     * Every event the snippet in this document tells the browser to report,
     * in order, each in the same shape `sentEvents()` hands back — so the
     * readers below answer for the browser's copy exactly as they do for the
     * server's, and one test body can hold the two against each other.
     *
     * @return list<array<string, mixed>>
     */
    abstract public function browserEventsIn(string $html): array;

    /** Fake the Provider accepting every batch. */
    abstract public function fakeAccepted(): void;

    /** Fake the Provider refusing a batch outright — a wrong credential or a payload it will not take. */
    abstract public function fakeRefused(string $message = 'Invalid OAuth access token.'): void;

    /** Fake the Provider answering a server error, which says nothing about the batch. */
    abstract public function fakeServerError(): void;

    /**
     * Fake the Provider rate limiting the caller — which is not a refusal of
     * the batch, however it is dressed up in the answer.
     */
    abstract public function fakeThrottled(): void;

    /** Fake the Provider being unreachable for every call. */
    abstract public function fakeOutage(): void;

    /**
     * End the outage the fake above is standing in for, so the next sweep is
     * answered normally.
     *
     * A flag inside the one fake rather than a second `Http::fake()`: the
     * stubs stack and the first to answer wins, so a fake laid after an
     * outage would never be reached.
     */
    abstract public function endOutage(): void;

    /** Assert the Provider was sent exactly this many batches since the last fake was laid. */
    abstract public function assertBatchesSent(int $count): void;

    /** Assert the Destination the batch went to, as the request addresses it. */
    abstract public function assertAddressedTo(Destination $destination): void;

    /** Assert the credential travelled with the batch. */
    abstract public function assertAuthorisedWith(string $accessToken): void;

    /**
     * Every event the Provider was sent, across every batch, in its own wire
     * shape.
     *
     * @return list<array<string, mixed>>
     */
    abstract public function sentEvents(): array;

    /** How many events rode in each batch, in the order the batches were sent. @return list<int> */
    abstract public function batchSizes(): array;

    /** The name of the event, as this Provider spells it. @param array<string, mixed> $event */
    abstract public function eventNameOf(array $event): ?string;

    /** What the browser copy of this event will carry so the Provider counts one. @param array<string, mixed> $event */
    abstract public function deduplicationIdOf(array $event): ?string;

    /** The Merchant's own reference for the Order. @param array<string, mixed> $event */
    abstract public function orderReferenceOf(array $event): ?string;

    /** Where the Provider is told the Moment happened. @param array<string, mixed> $event */
    abstract public function actionSourceOf(array $event): ?string;

    /** The instant the Moment happened, as a unix timestamp. @param array<string, mixed> $event */
    abstract public function eventTimeOf(array $event): ?int;

    /** The address the Moment happened at. @param array<string, mixed> $event */
    abstract public function sourceUrlOf(array $event): ?string;

    /** What the Order was worth, in major units. @param array<string, mixed> $event */
    abstract public function valueOf(array $event): ?float;

    /** @param array<string, mixed> $event */
    abstract public function currencyOf(array $event): ?string;

    /**
     * What was bought, reduced to the three things every Provider carries.
     *
     * @param  array<string, mixed>  $event
     * @return list<array{id: string, quantity: int, price: float}>
     */
    abstract public function contentsOf(array $event): array;

    /**
     * Whether the event carries the match-key block this Provider requires on
     * every event. Meta refuses a whole batch for one event missing it, so a
     * row with nothing to match on still has to send an empty one.
     *
     * @param  array<string, mixed>  $event
     */
    abstract public function carriesMatchKeyBlock(array $event): bool;

    /**
     * The match keys the Provider expects hashed, by our own name for each.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, string>
     */
    abstract public function hashedMatchKeysOf(array $event): array;

    /**
     * The three things that must reach the Provider un-hashed, because it
     * matches on them itself.
     *
     * @param  array<string, mixed>  $event
     * @return array{ip: string|null, userAgent: string|null, clickIds: array<string, string>}
     */
    abstract public function plainSignalsOf(array $event): array;

    /** This Provider's hash of a value, for asserting a match key was sealed and not sent plain. */
    abstract public function hash(string $value): string;

    /**
     * The value a match key is hashed from, once this Provider's own
     * normalisation has been applied to what the Order holds.
     */
    abstract public function normalise(string $key, string $value): string;
}
