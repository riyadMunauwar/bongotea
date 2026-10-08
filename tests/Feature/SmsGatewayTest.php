<?php

use App\Enums\SmsGateway;
use App\Models\Setting;
use App\Support\Settings\SmsSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\Sms\SmsGatewayScript;

/**
 * An SMS Gateway's one call, through the faked HTTP client, in the gateway's
 * own documented shapes. A send answers rather than throws: whatever the
 * gateway says, the caller is handed an outcome, because a text that cannot
 * go out must never stop what it was telling somebody about.
 */
it('sends the text to the number in international form and keeps the gateway\'s reference', function (SmsGatewayScript $script) {
    $script->makeUsable();
    $script->fakeAccepted();

    $outcome = $script->gateway()->driver()->send('01712-345678', 'আপনার অর্ডার পেয়েছি');

    expect($outcome->isSent())->toBeTrue()
        ->and($outcome->reference)->toBe($script->acceptedReference());

    $script->assertSent('8801712345678', 'আপনার অর্ডার পেয়েছি');
})->with('sms gateways');

it('reads a refused credential as a refusal not worth retrying', function (SmsGatewayScript $script) {
    $script->makeUsable();
    $script->fakeAuthFailure();

    $outcome = $script->gateway()->driver()->send('01712345678', 'Hello');

    expect($outcome->isSent())->toBeFalse()
        ->and($outcome->isWorthRetrying())->toBeFalse()
        ->and($outcome->reason)->toContain('401');
})->with('sms gateways');

it('reads a refused text as a refusal in the gateway\'s own words', function (SmsGatewayScript $script) {
    $script->makeUsable();
    $script->fakeValidationError();

    $outcome = $script->gateway()->driver()->send('01712345678', 'Hello');

    expect($outcome->isSent())->toBeFalse()
        ->and($outcome->isWorthRetrying())->toBeFalse()
        ->and($outcome->reason)->toContain($script->validationMessage());
})->with('sms gateways');

it('reads an outage, a server error or a rate limit as silence worth retrying, and never throws', function (SmsGatewayScript $script, string $failure) {
    $script->makeUsable();
    $script->{$failure}();

    $outcome = $script->gateway()->driver()->send('01712345678', 'Hello');

    expect($outcome->isSent())->toBeFalse()
        ->and($outcome->isWorthRetrying())->toBeTrue();
})->with('sms gateways')->with(['fakeOutage', 'fakeServerError', 'fakeRateLimited']);

it('asks nothing of the gateway for a number the dialling rules cannot read', function (SmsGatewayScript $script) {
    $script->makeUsable();
    $script->fakeAccepted();

    $outcome = $script->gateway()->driver()->send('12', 'Hello');

    expect($outcome->isSent())->toBeFalse()
        ->and($outcome->isWorthRetrying())->toBeFalse();

    Http::assertNothingSent();
})->with('sms gateways');

it('refuses to be used until every credential is filled', function (SmsGatewayScript $script) {
    expect($script->gateway()->driver()->refusal())->not->toBeNull();

    $script->makeUsable();

    expect($script->gateway()->driver()->refusal())->toBeNull();
})->with('sms gateways');

it('writes the text to the log and calls nobody when the log-only gateway sends', function () {
    Log::spy();

    $outcome = SmsGateway::LogOnly->driver()->send('01712345678', 'Hello');

    expect($outcome->isSent())->toBeTrue()
        ->and(SmsGateway::LogOnly->driver()->refusal())->toBeNull();

    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $context['to'] === '8801712345678' && $context['message'] === 'Hello');
});

it('keeps the gateway credential sealed in the row', function () {
    app(SmsSettings::class)->with(['giosmsApiToken' => 'gio_secret'])->save();

    expect(json_encode(Setting::query()->find('sms')->value))->not->toContain('gio_secret')
        ->and(SmsSettings::load()->giosmsApiToken)->toBe('gio_secret');
});
