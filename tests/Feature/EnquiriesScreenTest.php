<?php

use App\Enums\EnquiryStatus;
use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Filament\Resources\Enquiries\Pages\ListEnquiries;
use App\Filament\Resources\Enquiries\Pages\ViewEnquiry;
use App\Models\Enquiry;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The Enquiries screens: every question from the contact form, behind
 * `handle enquiries`, moved from New to Replied to Closed in the name of the
 * User who called back.
 */
it('lets a User holding handle enquiries open the list and an Enquiry', function () {
    $this->actingAs(User::factory()->handlingEnquiries()->create());

    $enquiry = Enquiry::factory()->create();

    $this->get(EnquiryResource::getUrl('index'))->assertOk();
    $this->get(EnquiryResource::getUrl('view', ['record' => $enquiry]))
        ->assertOk()
        ->assertSee($enquiry->name)
        ->assertSee('Nobody yet');
});

it('refuses every Enquiries screen to a User without handle enquiries and hides it from the sidebar', function () {
    $this->actingAs(User::factory()->create());

    $enquiry = Enquiry::factory()->create();

    $this->get(EnquiryResource::getUrl('index'))->assertForbidden();
    $this->get(EnquiryResource::getUrl('view', ['record' => $enquiry]))->assertForbidden();

    $labels = collect(Filament::getCurrentOrDefaultPanel()->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item) => $item->getLabel());

    expect($labels)->not->toContain('Enquiries');
});

it('lists the questions newest first, narrows them by status and counts the New ones on the sidebar', function () {
    $this->actingAs(User::factory()->handlingEnquiries()->create());

    $older = Enquiry::factory()->create(['created_at' => now()->subDay()]);
    $newer = Enquiry::factory()->create();
    $closed = Enquiry::factory()->closed()->create();

    Livewire::test(ListEnquiries::class)
        ->assertCanSeeTableRecords([$closed, $newer, $older], inOrder: true)
        ->filterTable('status', EnquiryStatus::New->value)
        ->assertCanSeeTableRecords([$newer, $older])
        ->assertCanNotSeeTableRecords([$closed]);

    expect(EnquiryResource::getNavigationBadge())->toBe('2');
});

it('marks an Enquiry replied and then closed in the name of the User who handled it', function () {
    $handler = User::factory()->handlingEnquiries()->create(['name' => 'Karim']);
    $this->actingAs($handler);

    $enquiry = Enquiry::factory()->create();

    Livewire::test(ViewEnquiry::class, ['record' => $enquiry->getKey()])
        ->assertActionHidden('moveToNew')
        ->callAction('moveToReplied')
        ->assertActionHidden('moveToReplied')
        ->assertActionVisible('moveToClosed');

    expect($enquiry->refresh())
        ->status->toBe(EnquiryStatus::Replied)
        ->handled_by_id->toBe($handler->id)
        ->handled_at->not->toBeNull();

    Livewire::test(ListEnquiries::class)
        ->callTableAction('moveToClosed', $enquiry);

    expect($enquiry->refresh()->status)->toBe(EnquiryStatus::Closed)
        ->and(EnquiryResource::getNavigationBadge())->toBeNull();
});

it('reopens a closed Enquiry as one nobody has called yet', function () {
    $this->actingAs(User::factory()->handlingEnquiries()->create());

    $enquiry = Enquiry::factory()->closed()->create();

    Livewire::test(ViewEnquiry::class, ['record' => $enquiry->getKey()])
        ->assertActionHidden('moveToClosed')
        ->callAction('moveToNew');

    expect($enquiry->refresh())
        ->status->toBe(EnquiryStatus::New)
        ->handled_by_id->toBeNull()
        ->handled_at->toBeNull();
});
