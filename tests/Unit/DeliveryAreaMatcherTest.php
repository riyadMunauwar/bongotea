<?php

use App\Support\Couriers\DeliveryArea;
use App\Support\Couriers\DeliveryAreaMatcher;

/**
 * The guess at which of a Courier's own delivery areas an Order is going to.
 *
 * Tested directly, which nothing else in this feature is: a booking is proved
 * end to end through the send dialog and the Courier's own faked answers
 * (`CourierBookingTest`), because that is where a parcel's story is. This one
 * piece is a table of cases rather than a conversation — an address, a
 * District and a list, in and out, with no HTTP, no database and no screen
 * anywhere near it — and the cases that matter are the ones where it must
 * answer with nothing. Driving each of those through a Livewire dialog would
 * prove the same thing six times as slowly and say less.
 *
 * The rule every case below is an instance of: it guesses, and it never
 * decides. A parcel sent to a guessed depot is a parcel in the wrong city and
 * a Merchant paying for it twice, so anything this cannot settle answers with
 * nothing and leaves it to the User who has the Customer on the phone.
 */
function areasServed(): array
{
    return [
        new DeliveryArea('1230', 'Banani', 'Dhaka'),
        new DeliveryArea('1231', 'Banani DOHS', 'Dhaka'),
        new DeliveryArea('1215', 'Uttara', 'Dhaka'),
        new DeliveryArea('1216', 'Mirpur 1', 'Dhaka'),
        new DeliveryArea('1217', 'Mirpur 10', 'Dhaka'),
        new DeliveryArea('1801', 'Sadar', 'Gazipur'),
        new DeliveryArea('9001', 'Sadar', 'Khulna'),
    ];
}

it('matches an address that is the area and nothing else', function () {
    expect(DeliveryAreaMatcher::guess('Banani', 'Dhaka', areasServed())?->id)->toBe('1230');
});

it('finds the area buried in the middle of an address a customer typed', function () {
    /** What an address actually looks like: a house, a road, the area, a post code. */
    expect(DeliveryAreaMatcher::guess('House 4, Road 12, Banani, Dhaka-1213', 'Dhaka', areasServed())?->id)->toBe('1230');
});

it('gives way to the area whose name spells the other one out in full', function () {
    /** Banani DOHS is a different depot from Banani, and an address reading "Banani DOHS" said which. */
    expect(DeliveryAreaMatcher::guess('House 9, Road 3, Banani DOHS', 'Dhaka', areasServed())?->id)->toBe('1231');
});

it('falls back to the district where the address names no area at all', function () {
    /** The address is a landmark, which happens: the District the Customer agreed is the only thing left to go on. */
    expect(DeliveryAreaMatcher::guess('Beside the big mosque, opposite the bank', 'Gazipur', areasServed())?->id)->toBe('1801');
});

it('settles two areas of the same name by the district the customer agreed', function () {
    /** Every District has a Sadar, which is exactly why an Order carries a District. */
    expect(DeliveryAreaMatcher::guess('Sadar Road, near the college', 'Khulna', areasServed())?->id)->toBe('9001');
});

it('guesses nothing where an address names two different areas', function () {
    /**
     * An address reading "Banani, opposite Uttara Tower" did not say nothing —
     * it said two things, and which of them is the door is a question for the
     * User, not a coin toss between two depots. Nor does it fall back to the
     * District: the address was not silent.
     */
    expect(DeliveryAreaMatcher::guess('House 4, Banani, opposite Uttara Tower', 'Dhaka', areasServed()))->toBeNull();
});

it('guesses nothing between two areas an address names, however long their names are', function () {
    /**
     * The rule that gives way to a longer name is containment and never
     * length: "Banani DOHS" spells Banani out, and "Mirpur 10" does not spell
     * out Banani at all — so an address naming both is two answers and not the
     * wordier one.
     */
    expect(DeliveryAreaMatcher::guess('House 4, Banani, behind the Mirpur 10 roundabout', 'Dhaka', areasServed()))->toBeNull();
});

it('guesses nothing from a near miss, rather than correcting what the customer typed', function () {
    /** "Bonani" is not Banani and "Mirpur 11" is not Mirpur 1: a guess that corrects a spelling invents a destination. */
    expect(DeliveryAreaMatcher::guess('House 4, Road 12, Bonani', 'Dhaka', areasServed()))->toBeNull()
        ->and(DeliveryAreaMatcher::guess('Block C, Mirpur 11', 'Dhaka', areasServed()))->toBeNull();
});

it('guesses nothing where neither the address nor the district is one of the courier\'s areas', function () {
    expect(DeliveryAreaMatcher::guess('Station Road, near the ferry ghat', 'Barishal', areasServed()))->toBeNull();
});

it('guesses nothing when there is nothing to go on, rather than reaching for the first area in the list', function () {
    expect(DeliveryAreaMatcher::guess(null, null, areasServed()))->toBeNull()
        ->and(DeliveryAreaMatcher::guess('   ', '', areasServed()))->toBeNull()
        ->and(DeliveryAreaMatcher::guess('House 4, Road 12, Banani', 'Dhaka', []))->toBeNull();
});
