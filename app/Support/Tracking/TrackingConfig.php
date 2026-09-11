<?php

namespace App\Support\Tracking;

use App\Enums\TrackingProvider;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Everything one Page decides about its own tracking: where it reports, which
 * of each Provider's events it fires there, and whether what it reports may
 * name what was bought.
 *
 * Self-contained and per Page like a Checkout Config and an Order Complete
 * Config, and a third thing beside them rather than a part of either. Nothing
 * here is a figure, which is why it is deliberately absent from the Pricing
 * Fingerprint: switching an event off must never turn away an Order somebody
 * is half-way through typing.
 *
 * Every decision is expressed as "say nothing and inherit". A blank
 * Destination is the default for each Provider; an event with no word said
 * about it is whatever the tracking settings say; and content detail is sent
 * unless this Page asks otherwise. That is what lets a Provider gain a fifth
 * event, or an installation change its mind globally, with no data migration
 * and no edit to a single Page.
 *
 * @implements Arrayable<string, mixed>
 */
class TrackingConfig implements Arrayable
{
    /**
     * @param  array<string, array<string, bool>>  $events  what this Page says about a Provider's events, by Provider and then by event name; an event absent here is inherited
     */
    private function __construct(
        public readonly ?int $destinationId,
        public readonly array $events,
        public readonly bool $suppressesContentDetail,
    ) {}

    /**
     * What a Page starts with, and what every Page from before the column was
     * backfilled to: the default Destination for each Provider, every event
     * as the installation has it, and the whole basket reported.
     */
    public static function default(): self
    {
        return self::fromArray([]);
    }

    /**
     * Rebuild a Config from its stored shape.
     *
     * Total on purpose, like the two Configs beside it: a missing key keeps
     * its default, a key nothing knows is dropped, and a value that is not
     * the shape it should be reads as nothing having been said. A Customer
     * loading a Page is owed the Page, never a 500, whichever route wrote the
     * Config — and a Merchant's switches are read the same way on the tab as
     * they are on the path of a Moment.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            destinationId: self::identifier($stored['destination_id'] ?? null),
            events: self::switches($stored['events'] ?? null),
            suppressesContentDetail: (bool) ($stored['suppress_content_detail'] ?? false),
        );
    }

    /**
     * What this Page says about one of a Provider's events: true for on,
     * false for off, and null where it says nothing and the installation's
     * own default stands.
     *
     * An event this Page holds a word about that no Provider declares any
     * more is simply never asked for. The word is kept as stored rather than
     * dropped, so a Provider that regains an event finds the Merchant's
     * decision still here.
     */
    public function choiceFor(TrackingProvider $provider, string $event): ?bool
    {
        return $this->events[$provider->value][$event] ?? null;
    }

    /**
     * The Destination this Page picked, where it picked one.
     *
     * Not a foreign key and deliberately not resolved here: a Config is read
     * where no Destination may exist any more, and a Page pointing at one
     * that has been deleted falls back to the default rather than breaking.
     * Where that resolution happens is `TrackingPlans::forPage()`.
     */
    private static function identifier(mixed $stored): ?int
    {
        if (is_int($stored)) {
            return $stored > 0 ? $stored : null;
        }

        return is_string($stored) && ctype_digit($stored) && (int) $stored > 0 ? (int) $stored : null;
    }

    /**
     * The switches as stored: an answer that is actually yes or no, and
     * nothing else. A string, a nested list or a null left behind by a form
     * is nothing said, which is what an absent switch means anyway.
     *
     * @return array<string, array<string, bool>>
     */
    private static function switches(mixed $stored): array
    {
        return EventSwitches::read($stored, fn (mixed $choice): ?bool => is_bool($choice) ? $choice : null);
    }

    /**
     * @return array{destination_id: ?int, events: array<string, array<string, bool>>, suppress_content_detail: bool}
     */
    public function toArray(): array
    {
        return [
            'destination_id' => $this->destinationId,
            'events' => $this->events,
            'suppress_content_detail' => $this->suppressesContentDetail,
        ];
    }
}
