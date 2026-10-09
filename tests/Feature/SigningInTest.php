<?php

use App\Models\Customer;
use App\Models\OneTimeCode;
use App\Models\Product;
use App\Support\Customers\CustomerGuard;
use App\Support\Sms\SendText;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Signing in: a Customer proving with a One-Time Code that a number is
 * theirs. Four digits by SMS, good once and for five minutes, kept only as a
 * hash, sent at most three times an hour to one number. Nothing is created
 * by it, and the browser stays signed in for thirty days.
 */
beforeEach(function () {
    Queue::fake();
});

it('texts a four-digit code to the number and keeps only its hash', function () {
    $code = codeSentTo('০১৭১২-৩৪৫৬৭৮');

    Queue::assertPushed(SendText::class, fn (SendText $text): bool => $text->phone === '+8801712345678');

    $stored = OneTimeCode::query()->sole();

    expect($code)->toMatch('/^\d{4}$/')
        ->and($stored->phone)->toBe('+8801712345678')
        ->and($stored->code_hash)->not->toContain($code)
        ->and(Hash::check($code, $stored->code_hash))->toBeTrue();
});

it('refuses a number that cannot be one', function () {
    $this->postJson(route('sign-in.code'), ['phone' => '12345'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    Queue::assertNothingPushed();
});

it('signs a number in with its code for thirty days and creates nothing', function () {
    $code = codeSentTo('01712345678');

    $response = $this->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => $code])->assertOk();

    $cookie = $response->getCookie(CustomerGuard::COOKIE, decrypt: false);

    expect($cookie->getExpiresTime())->toBe(now()->addDays(30)->getTimestamp())
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and(Customer::query()->count())->toBe(0)
        ->and(OneTimeCode::query()->count())->toBe(0);
});

it('takes the code in Bangla digits', function () {
    $code = codeSentTo('01712345678');

    $this->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => strtr($code, ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'])])
        ->assertOk();
});

it('takes a code only once', function () {
    $code = codeSentTo('01712345678');

    $this->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => $code])->assertOk();

    $this->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => $code])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('takes a code for five minutes and no longer', function (int $secondsLater, bool $taken) {
    $code = codeSentTo('01712345678');

    $this->travel($secondsLater)->seconds();

    $response = $this->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => $code]);

    $taken ? $response->assertOk() : $response->assertUnprocessable()->assertJsonValidationErrors('code');
})->with([
    'inside five minutes' => [299, true],
    'after five minutes' => [301, false],
]);

it('takes only the code sent to that number', function () {
    $code = codeSentTo('01712345678');

    $this->postJson(route('sign-in'), ['phone' => '01812345678', 'code' => $code])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('takes only the latest code sent to a number', function () {
    $first = codeSentTo('01712345678');
    $second = codeSentTo('01712345678');

    if ($first !== $second) {
        $this->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => $first])->assertUnprocessable();
    }

    $this->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => $second])->assertOk();
});

it('throws a code away after five wrong guesses', function () {
    $code = codeSentTo('01712345678');
    $wrong = str_pad((string) (((int) $code + 1) % 10000), 4, '0', STR_PAD_LEFT);

    foreach (range(1, 5) as $guess) {
        $this->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => $wrong])->assertUnprocessable();
    }

    $this->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => $code])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('stops taking codes for a number guessed at ten times in a day, whatever the address', function () {
    foreach (range(1, 2) as $round) {
        $code = codeSentTo('01712345678');
        $wrong = str_pad((string) (((int) $code + 1) % 10000), 4, '0', STR_PAD_LEFT);

        foreach (range(1, 5) as $guess) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$round}{$guess}"])
                ->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => $wrong])
                ->assertUnprocessable();
        }
    }

    $code = codeSentTo('01712345678');

    $this->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => $code])->assertUnprocessable();

    $this->travel(1)->days();
    $code = codeSentTo('01712345678');

    $this->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => $code])->assertOk();
});

it('sends a number at most three codes an hour', function () {
    foreach (range(1, 3) as $send) {
        $this->postJson(route('sign-in.code'), ['phone' => '01712345678'])->assertOk();
    }

    $this->postJson(route('sign-in.code'), ['phone' => '+8801712345678'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    Queue::assertPushed(SendText::class, 3);

    $this->travel(61)->minutes();

    $this->postJson(route('sign-in.code'), ['phone' => '01712345678'])->assertOk();
});

it('limits how many codes one address can ask for, whatever the numbers', function () {
    foreach (range(1, 20) as $send) {
        $this->postJson(route('sign-in.code'), ['phone' => sprintf('017123456%02d', $send)])->assertOk();
    }

    $this->postJson(route('sign-in.code'), ['phone' => '01812345678'])->assertTooManyRequests();
});

it('limits how many codes one address can try', function () {
    foreach (range(1, 10) as $guess) {
        $this->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => '0000']);
    }

    $this->postJson(route('sign-in'), ['phone' => '01712345678', 'code' => '0000'])->assertTooManyRequests();
});

it('keeps the browser signed in for thirty days and no longer', function () {
    signInAs('01712345678');

    $this->travel(29)->days();
    $this->get(route('checkout'))->assertInertia(fn (Assert $page) => $page->where('signedInPhone', '01712345678'));

    $this->travel(2)->days();
    $this->get(route('checkout'))->assertInertia(fn (Assert $page) => $page->where('signedInPhone', null));
});

it('signs the browser out', function () {
    signInAs('01712345678');

    $response = $this->postJson(route('sign-out'))->assertOk();

    expect($response->getCookie(CustomerGuard::COOKIE, decrypt: false)->getExpiresTime())->toBeLessThan(now()->getTimestamp());
});

it('ignores a cookie it cannot read', function () {
    $this->withCookie(CustomerGuard::COOKIE, 'not json')
        ->get(route('checkout'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('signedInPhone', null));
});

it('pre-fills the Checkout with the signed-in number', function () {
    Product::factory()->create();

    $this->get(route('checkout'))->assertInertia(fn (Assert $page) => $page->where('signedInPhone', null));

    signInAs('+880 1712-345678');

    $this->get(route('checkout'))->assertInertia(fn (Assert $page) => $page->where('signedInPhone', '01712345678'));
});
