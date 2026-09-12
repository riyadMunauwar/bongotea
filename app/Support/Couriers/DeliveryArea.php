<?php

namespace App\Support\Couriers;

/**
 * One of a Courier's own delivery areas, by the id that Courier knows it by.
 *
 * Only one of the three couriers has any of these. Steadfast and Pathao read
 * the Customer's address in free text and resolve the destination themselves
 * (ADR-0018); RedEx will not, and refuses a parcel that does not name an area
 * out of a list only RedEx holds. So an area is not a thing the system knows
 * about the world — it is a word one Courier insists on hearing, cached from
 * that Courier and quoted back to it.
 *
 * Held as a value rather than a row for the same reason: nothing here is the
 * system's own record of anywhere. The District is what an Order holds about
 * where a parcel is going (CONTEXT: District), and it is kept beside the name
 * here only because two areas in different Districts share a name often
 * enough that a guess cannot be settled without it.
 *
 * The two names it is written onto an attempt under are its own, so that what
 * the dialog asked, what the booking recorded and what the driver sent are one
 * spelling and not three.
 */
final class DeliveryArea
{
    /**
     * The name the chosen area is answered under — in the send dialog, in
     * `ParcelDetails`, and on the attempt's `parcel_details`.
     */
    public const ANSWER = 'deliveryArea';

    /**
     * And the name the Courier's own wording for it is recorded under beside
     * the id. Both are sent: RedEx takes the id and the name together, and a
     * name looked up afresh at send time could be a name the cached list has
     * since changed — what the row says was sent has to be what was sent.
     */
    public const NAME = 'deliveryAreaName';

    /** How many areas a search in the dialog offers at once. RedEx knows thousands; a User is choosing one. */
    private const SEARCH_LIMIT = 50;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $district = null,
    ) {}

    /**
     * The area as it is written onto the attempt, and therefore as the driver
     * reads it back.
     *
     * @return array<string, string>
     */
    public function asParcelDetails(): array
    {
        return [
            self::ANSWER => $this->id,
            self::NAME => $this->name,
        ];
    }

    /**
     * The area as a User picks it out of a list, with the District beside it,
     * because a Courier's list holds the same name in several Districts and
     * the name alone does not tell them apart.
     */
    public function label(): string
    {
        return $this->district === null ? $this->name : "{$this->name} — {$this->district}";
    }

    /**
     * The area a Courier's list knows by this id, or nothing where its list
     * has never heard of it.
     *
     * Nothing rather than an exception, because the id arrives from a dialog
     * and from a row: a Courier that has dropped an area since a parcel was
     * booked must read as "choose one again", never as a crash. Anything at
     * all may be asked about for the same reason — a chooser hands back the
     * digits of an id as a number, and an id is a word a Courier chose and
     * not a figure, so it is compared as one however it arrives.
     *
     * @param  list<self>  $areas
     */
    public static function find(mixed $id, array $areas): ?self
    {
        if (! is_scalar($id) || ($id = trim((string) $id)) === '') {
            return null;
        }

        foreach ($areas as $area) {
            if ($area->id === $id) {
                return $area;
            }
        }

        return null;
    }

    /**
     * The areas worth offering for what a User has typed, by id.
     *
     * A plain containment search and nothing cleverer: this is a User who
     * knows the answer looking for it in a list, not the system guessing —
     * the guessing is `DeliveryAreaMatcher`'s, and it happens before the
     * dialog is drawn.
     *
     * @param  list<self>  $areas
     * @return array<string, string>
     */
    public static function search(string $term, array $areas): array
    {
        $term = mb_strtolower(trim($term));
        $found = [];

        foreach ($areas as $area) {
            if ($term !== '' && ! str_contains(mb_strtolower($area->label()), $term)) {
                continue;
            }

            $found[$area->id] = $area->label();

            if (count($found) === self::SEARCH_LIMIT) {
                break;
            }
        }

        return $found;
    }
}
