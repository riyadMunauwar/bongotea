<?php

use App\Enums\TrackingProvider;
use App\Filament\Resources\Destinations\DestinationResource;
use App\Filament\Resources\Destinations\Pages\CreateDestination;
use App\Filament\Resources\Destinations\Pages\EditDestination;
use App\Filament\Resources\Destinations\Pages\ListDestinations;
use App\Models\Destination;
use App\Models\TrackingEvent;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The Destination screens, driven the way a Merchant drives them.
 *
 * A Destination is a model with a Resource rather than a Settings group,
 * because a group holds one row of flat JSON and cannot express an unbounded
 * user-created list where each item carries its own secret — but the rules
 * about that secret are the same ones Settings established (ADR-0012), and
 * they are what most of this file is about.
 */
beforeEach(function (): void {
    $this->actingAs(User::factory()->managingSettings()->create());
});

it('creates a destination with a label, a dataset and an access token, and seals only the token', function () {
    Livewire::test(CreateDestination::class)
        ->fillForm([
            'label' => 'Brand Ads',
            'provider' => TrackingProvider::Facebook->value,
            'dataset' => '110022003300440',
            'access_token' => 'EAA-very-secret-token',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $destination = Destination::sole();

    expect($destination->label)->toBe('Brand Ads')
        ->and($destination->provider)->toBe(TrackingProvider::Facebook)
        ->and($destination->dataset)->toBe('110022003300440')
        ->and($destination->access_token)->toBe('EAA-very-secret-token');

    /** The rest of the row stays readable in SQL; only the credential does not. */
    $row = (array) DB::table('destinations')->sole();

    expect($row['access_token'])->not->toContain('EAA-very-secret-token')
        ->and($row['label'])->toBe('Brand Ads')
        ->and($row['dataset'])->toBe('110022003300440');
});

it('loads the token blank with a hint, and puts it in neither livewire state nor the html', function () {
    $destination = Destination::factory()->create(['access_token' => 'EAA-very-secret-token']);

    Livewire::test(EditDestination::class, ['record' => $destination->getKey()])
        ->assertSet('data.access_token', null)
        ->assertSet('data.dataset', $destination->dataset)
        ->assertSee('A value is stored')
        ->assertDontSee('EAA-very-secret-token');
});

it('keeps the stored token when the field is left blank, and replaces it when one is typed', function () {
    $destination = Destination::factory()->create(['access_token' => 'EAA-very-secret-token']);

    Livewire::test(EditDestination::class, ['record' => $destination->getKey()])
        ->fillForm(['label' => 'Renamed', 'access_token' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($destination->refresh()->label)->toBe('Renamed')
        ->and($destination->access_token)->toBe('EAA-very-secret-token');

    Livewire::test(EditDestination::class, ['record' => $destination->getKey()])
        ->fillForm(['access_token' => 'EAA-rotated-token'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($destination->refresh()->access_token)->toBe('EAA-rotated-token');
});

it('makes the first destination for a provider its default, so a merchant who adds one has configured something', function () {
    $first = Destination::factory()->create(['label' => 'Brand Ads']);

    expect($first->is_default)->toBeTrue();
});

it('unmarks the current default when a second destination is marked default', function () {
    $first = Destination::factory()->create(['label' => 'Brand Ads']);
    $second = Destination::factory()->notDefault()->create(['label' => "A client's account"]);

    expect($second->is_default)->toBeFalse();

    Livewire::test(EditDestination::class, ['record' => $second->getKey()])
        ->fillForm(['is_default' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($second->refresh()->is_default)->toBeTrue()
        ->and($first->refresh()->is_default)->toBeFalse()
        ->and(Destination::query()->where('provider', TrackingProvider::Facebook)->where('is_default', true)->count())->toBe(1);
});

it('gives the default straight back when it is unticked, because unticking is not a way to report nowhere', function () {
    $only = Destination::factory()->create(['label' => 'Brand Ads']);

    Livewire::test(EditDestination::class, ['record' => $only->getKey()])
        ->fillForm(['is_default' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    /** Switching it off is how reporting is paused; leaving a Provider with no default is not. */
    expect($only->refresh()->is_default)->toBeTrue()
        ->and($only->enabled)->toBeTrue();
});

it('hands the default on when the one holding it is deleted, so a tidy-up never silently stops reporting', function () {
    $first = Destination::factory()->create(['label' => 'Brand Ads']);
    $second = Destination::factory()->notDefault()->create(['label' => "A client's account"]);

    Livewire::test(EditDestination::class, ['record' => $first->getKey()])
        ->callAction(TestAction::make('delete'));

    expect(Destination::find($first->getKey()))->toBeNull()
        ->and($second->refresh()->is_default)->toBeTrue();
});

it('takes its undeliverable outbox rows with it when it is deleted', function () {
    $destination = Destination::factory()->create();
    $row = TrackingEvent::factory()->create(['destination_id' => $destination->getKey()]);

    $destination->delete();

    expect(TrackingEvent::find($row->getKey()))->toBeNull();
});

it('says why a destination is unusable in the providers own wording, once', function () {
    $missingToken = Destination::factory()->withoutCredentials()->create(['label' => 'Brand Ads']);
    $switchedOff = Destination::factory()->notDefault()->disabled()->create(['label' => 'Paused account']);
    $usable = Destination::factory()->notDefault()->create(['label' => 'Client account']);

    expect($missingToken->refusal())->toBe('Brand Ads has no access token.')
        ->and($switchedOff->refusal())->toBe('Paused account is switched off.')
        ->and($usable->refusal())->toBeNull()
        ->and($usable->isUsable())->toBeTrue();

    /** The list prints the same sentence, rather than restating the rule. */
    Livewire::test(ListDestinations::class)
        ->assertSee('Brand Ads has no access token.')
        ->assertSee('Paused account is switched off.');
});

it('hides the destinations from a User without the settings permission and forbids their URL', function () {
    $this->actingAs(User::factory()->create());

    expect(DestinationResource::canAccess())->toBeFalse();

    $this->get(DestinationResource::getUrl())->assertForbidden();
});
