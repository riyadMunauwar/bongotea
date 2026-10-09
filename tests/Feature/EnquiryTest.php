<?php

use App\Enums\EnquiryStatus;
use App\Models\Enquiry;
use App\Support\Settings\SmsSettings;
use Tests\Fixtures\Sms\GioSmsScript;

/**
 * The contact form: a buyer's question kept as a New Enquiry, and the
 * new-Enquiry Staff Alert it raises — observed at the SMS Gateway, through
 * its faked HTTP client, as the Order Texts are.
 */
beforeEach(function () {
    $this->gateway = new GioSmsScript;
    $this->gateway->makeUsable();
    $this->gateway->fakeAccepted();

    app(SmsSettings::class)->with([
        'newEnquiryAlertTemplate' => 'enquiry {name} {phone} {topic} {message}',
        'staffNumbers' => ['01811111111', '01922222222'],
    ])->save();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function enquiry(array $overrides = []): array
{
    return [
        'name' => 'Rahim Uddin',
        'phone' => '০১৭১২-৩৪৫৬৭৮',
        'topic' => 'দাম',
        'message' => '৫০ কেজির দাম কত?',
        'website' => '',
        ...$overrides,
    ];
}

it('keeps the question as a New Enquiry under the canonical number', function () {
    $this->postJson(route('enquiry.store'), enquiry())
        ->assertOk()
        ->assertJson(['sent' => true]);

    $kept = Enquiry::query()->sole();

    expect($kept->name)->toBe('Rahim Uddin')
        ->and($kept->phone)->toBe('+8801712345678')
        ->and($kept->topic)->toBe('দাম')
        ->and($kept->message)->toBe('৫০ কেজির দাম কত?')
        ->and($kept->status)->toBe(EnquiryStatus::New)
        ->and($kept->handled_by_id)->toBeNull();
});

it('takes a question with no topic and no message', function () {
    $this->postJson(route('enquiry.store'), enquiry(['topic' => '', 'message' => '']))->assertOk();

    expect(Enquiry::query()->sole())
        ->topic->toBeNull()
        ->message->toBeNull();
});

it('refuses a question without a name or a number that can be one', function (array $overrides, string $field) {
    $this->postJson(route('enquiry.store'), enquiry($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Enquiry::query()->count())->toBe(0)
        ->and($this->gateway->sent())->toBe([]);
})->with([
    'no name' => [['name' => '  '], 'name'],
    'a short number' => [['phone' => '12345'], 'phone'],
]);

it('alerts every staff number to a new Enquiry, and never the buyer', function () {
    $this->postJson(route('enquiry.store'), enquiry())->assertOk();

    $alert = 'enquiry Rahim Uddin 01712345678 দাম ৫০ কেজির দাম কত?';

    expect($this->gateway->sent())->toBe([
        ['to' => '8801811111111', 'message' => $alert],
        ['to' => '8801922222222', 'message' => $alert],
    ]);
});

it('keeps the Enquiry but sends no alert when the Staff Alert is switched off', function () {
    app(SmsSettings::class)->with(['newEnquiryAlertEnabled' => false])->save();

    $this->postJson(route('enquiry.store'), enquiry())->assertOk();

    expect(Enquiry::query()->count())->toBe(1)
        ->and($this->gateway->sent())->toBe([]);
});

it('thanks a script that filled the honeypot, however wrong the rest, and keeps nothing', function (array $overrides) {
    $this->postJson(route('enquiry.store'), enquiry(['website' => 'https://spam.example', ...$overrides]))
        ->assertOk()
        ->assertExactJson(['sent' => true]);

    expect(Enquiry::query()->count())->toBe(0)
        ->and($this->gateway->sent())->toBe([]);
})->with([
    'an otherwise good question' => [[]],
    'no name and no number' => [['name' => '', 'phone' => '']],
]);

it('takes three questions an hour from one number, whatever it is typed as', function () {
    foreach (['01712345678', '+8801712345678', '০১৭১২৩৪৫৬৭৮'] as $phone) {
        $this->postJson(route('enquiry.store'), enquiry(['phone' => $phone]))->assertOk();
    }

    $this->postJson(route('enquiry.store'), enquiry())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    expect(Enquiry::query()->count())->toBe(3);

    $this->travel(61)->minutes();

    $this->postJson(route('enquiry.store'), enquiry())->assertOk();
});

it('refuses one address more than twenty questions an hour, across numbers', function () {
    foreach (range(10, 29) as $suffix) {
        $this->postJson(route('enquiry.store'), enquiry(['phone' => "017123456{$suffix}"]))->assertOk();
    }

    $this->postJson(route('enquiry.store'), enquiry(['phone' => '01812345678']))->assertTooManyRequests();

    expect(Enquiry::query()->count())->toBe(20);
});
