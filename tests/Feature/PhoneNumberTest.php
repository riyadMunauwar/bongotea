<?php

use App\Support\PhoneNumber;

/**
 * A Customer *is* their phone number, so this class decides whether two orders
 * came from the same person. Getting it wrong does not surface as a bug; it
 * surfaces as two customers.
 */
it('reduces every spelling of one number to the same canonical form', function (string $typed) {
    expect(PhoneNumber::normalise($typed))->toBe('+8801712345678');
})->with([
    'local' => '01712345678',
    'hyphenated' => '01712-345678',
    'spaced' => '017 1234 5678',
    'international' => '+8801712345678',
    'international without plus' => '8801712345678',
    'international spaced' => '+880 1712 345678',
    'bracketed' => '(017) 1234-5678',
]);

it('refuses a number too short or too long to call', function (string $typed) {
    expect(PhoneNumber::normalise($typed))->toBeNull()
        ->and(PhoneNumber::isValid($typed))->toBeFalse();
})->with([
    'empty' => '',
    'letters only' => 'call me',
    'too short' => '01712',
    'too long' => '017123456789012',
]);

it('follows the installation to another country', function () {
    /**
     * Dialling rules are configuration, not constants: this is sold self-hosted
     * to merchants outside Bangladesh, and a hard-coded +880 would silently
     * merge or split their customers.
     */
    config([
        'giopages.phone.country_code' => '44',
        'giopages.phone.trunk_prefix' => '0',
    ]);

    expect(PhoneNumber::normalise('07700 900123'))->toBe('+447700900123')
        ->and(PhoneNumber::normalise('+44 7700 900123'))->toBe('+447700900123');
});
