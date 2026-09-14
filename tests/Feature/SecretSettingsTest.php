<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\Settings\SecretSettings;
use Tests\Fixtures\Settings\SecretSettingsPage;
use Tests\Fixtures\Settings\SecretTextareaPage;

const STORED_TOKEN = 'tok_live_4f9a7c1e2b';

/**
 * Nothing binds this fixture group: the container gives every `SettingsGroup`
 * subclass the same per-request seam the moment it is first resolved, which
 * is what lets a real group ship as one class plus one page.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->managingSettings()->create());
});

/**
 * Save a token the way a Merchant already did, and forget the request's copy
 * so the next read comes from the row.
 */
function storeToken(string $token = STORED_TOKEN): void
{
    $group = app(SecretSettings::class);
    $group->apiToken = $token;
    $group->save();

    app()->forgetInstance(SecretSettings::class);
}

it('seals a secret in the stored JSON, leaves the plain value readable, and hands the secret back whole', function () {
    $group = app(SecretSettings::class);
    $group->apiToken = STORED_TOKEN;
    $group->label = 'Pathao';
    $group->save();

    $row = (string) DB::table('settings')->where('key', SecretSettings::key())->value('value');

    expect($row)->not->toContain(STORED_TOKEN)
        ->and($row)->toContain('"label":"Pathao"');

    app()->forgetInstance(SecretSettings::class);

    expect(app(SecretSettings::class)->apiToken)->toBe(STORED_TOKEN)
        ->and(app(SecretSettings::class)->label)->toBe('Pathao');
});

it('renders a stored secret as a blank password field with a hint, and never puts the value in state or HTML', function () {
    storeToken();

    $component = Livewire::test(SecretSettingsPage::class)
        ->assertSet('data.apiToken', null)
        ->assertSet('data.label', 'plain')
        ->assertSee('A value is stored')
        /** A revealable password input binds its type in Alpine, so the marker is the class, not `type="password"`. */
        ->assertSee('fi-revealable', escape: false)
        ->assertDontSee(STORED_TOKEN);

    expect(json_encode($component->instance()->all()))->not->toContain(STORED_TOKEN);
});

it('renders no hint when no secret has been stored', function () {
    Livewire::test(SecretSettingsPage::class)
        ->assertSet('data.apiToken', null)
        ->assertDontSee('A value is stored');
});

it('keeps the stored secret when the field is saved blank, and still saves the plain value beside it', function () {
    storeToken();

    Livewire::test(SecretSettingsPage::class)
        ->fillForm(['label' => 'Steadfast'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    app()->forgetInstance(SecretSettings::class);

    expect(app(SecretSettings::class)->apiToken)->toBe(STORED_TOKEN)
        ->and(app(SecretSettings::class)->label)->toBe('Steadfast')
        ->and(Setting::count())->toBe(1);
});

it('replaces the stored secret when a new value is typed', function () {
    storeToken();

    Livewire::test(SecretSettingsPage::class)
        ->fillForm(['apiToken' => 'tok_live_replaced'])
        ->call('save')
        ->assertHasNoFormErrors()
        /** Once stored, the typed value leaves Livewire state like the old one never entered it. */
        ->assertSet('data.apiToken', null)
        ->assertSee('A value is stored')
        ->assertDontSee('tok_live_replaced');

    app()->forgetInstance(SecretSettings::class);

    expect(app(SecretSettings::class)->apiToken)->toBe('tok_live_replaced');
});

it('logs a changed secret by name only, with neither the old nor the new value', function () {
    storeToken();

    Livewire::test(SecretSettingsPage::class)
        ->fillForm(['apiToken' => 'tok_live_replaced', 'label' => 'Steadfast'])
        ->call('save')
        ->assertHasNoFormErrors();

    $entry = Activity::sole();

    expect($entry->attribute_changes->get('old'))->toBe(['apiToken' => null, 'label' => 'plain'])
        ->and($entry->attribute_changes->get('attributes'))->toBe(['apiToken' => null, 'label' => 'Steadfast'])
        ->and(json_encode($entry->properties))->not->toContain(STORED_TOKEN)
        ->and(json_encode($entry->properties))->not->toContain('tok_live_replaced');
});

/** The page throws a `LogicException` while the form is built; Blade wraps it in a `ViewException` on the way out. */
it('refuses a page that declares a secret as anything but a text input', function () {
    Livewire::test(SecretTextareaPage::class);
})->throws(ViewException::class, 'The secret [apiToken] on Tests\Fixtures\Settings\SecretTextareaPage must be a TextInput');

/**
 * What an `APP_KEY` rotation without re-encrypting looks like from inside:
 * the ciphertext is still in the row, and only the current key is missing.
 * Editing an unrelated value must not destroy what a re-encrypt command
 * could still recover (ADR-0012).
 */
it('keeps a secret it cannot decrypt sealed in the row across a save, says a value is stored, and logs the failure', function () {
    Log::spy();

    $sealedUnderAnotherKey = 'eyJpdiI6Im5vdC1hLXJlYWwtaXYiLCJ2YWx1ZSI6Im5vdC1yZWFsIiwibWFjIjoiMDAifQ==';

    Setting::query()->create([
        'key' => SecretSettings::key(),
        'value' => ['apiToken' => $sealedUnderAnotherKey, 'label' => 'Pathao'],
    ]);

    Livewire::test(SecretSettingsPage::class)
        ->assertSet('data.apiToken', null)
        ->assertSee('A value is stored')
        ->fillForm(['label' => 'Steadfast'])
        ->call('save')
        ->assertHasNoFormErrors();

    $row = Setting::query()->findOrFail(SecretSettings::key())->value;

    expect($row['apiToken'])->toBe($sealedUnderAnotherKey)
        ->and($row['label'])->toBe('Steadfast');

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'could not be decrypted')
            && $context['group'] === SecretSettings::key()
            && $context['property'] === 'apiToken')
        ->atLeast()->once();
});

it('replaces a secret it cannot decrypt when a new value is typed', function () {
    Log::spy();

    Setting::query()->create([
        'key' => SecretSettings::key(),
        'value' => ['apiToken' => 'eyJpdiI6Im5vdC1hLXJlYWwtaXYiLCJ2YWx1ZSI6Im5vdC1yZWFsIiwibWFjIjoiMDAifQ==', 'label' => 'Pathao'],
    ]);

    Livewire::test(SecretSettingsPage::class)
        ->fillForm(['apiToken' => 'tok_live_replaced'])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(SecretSettings::class);

    expect(app(SecretSettings::class)->apiToken)->toBe('tok_live_replaced');
});

it('logs nothing for a secret saved blank, since nothing changed', function () {
    storeToken();

    Livewire::test(SecretSettingsPage::class)->call('save')->assertHasNoFormErrors();

    expect(Activity::count())->toBe(0);
});

/**
 * Every field's key must name the schema it is in, because the browser calls
 * back with that key and `getSchemaComponent()` reads the schema name off the
 * front of it. The base page walks the fields to make the secrets write-only,
 * and reading a component's key freezes it — so if the schema is not named
 * first, every field is frozen under the state path instead and every callback
 * the browser makes answers null. Nothing on the screen says so: a Select with
 * closure options simply opens empty.
 */
it('gives every field a key the browser can call back with', function () {
    $page = Livewire::test(SecretSettingsPage::class)->instance();

    $fields = $page->getSchema('form')->getFlatFields(withHidden: true);

    expect($fields)->not->toBeEmpty();

    foreach ($fields as $field) {
        expect($field->getKey())->toStartWith('form.')
            ->and($page->getSchemaComponent($field->getKey()))->not->toBeNull();
    }
});
