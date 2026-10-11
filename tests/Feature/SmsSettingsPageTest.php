<?php

use App\Enums\SmsGateway;
use App\Filament\Clusters\Settings\Pages\Sms;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\User;
use App\Support\Settings\SmsSettings;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->managingSettings()->create());
});

it('sits in the Settings cluster at navigation sort 8, and is forbidden to a User who cannot manage settings', function () {
    expect(Sms::getNavigationSort())->toBe(8)
        ->and(Sms::getCluster())->toBe(SettingsCluster::class);

    $this->get(Sms::getUrl())->assertOk()->assertSeeLivewire(Sms::class);

    $this->actingAs(User::factory()->create());

    $this->get(Sms::getUrl())->assertForbidden();
});

it('opens on the log-only gateway, every text switched on and worded in Bangla', function () {
    Livewire::test(Sms::class)
        ->assertSchemaStateSet([
            'gateway' => SmsGateway::LogOnly->value,
            'giosmsApiToken' => null,
            'placedEnabled' => true,
            'confirmedEnabled' => true,
            'sentEnabled' => true,
            'cancelledEnabled' => true,
            'newOrderAlertEnabled' => true,
            'newEnquiryAlertEnabled' => true,
            'staffNumbers' => [],
        ])
        ->assertSee('{order_number}');
});

it('switches the new-Enquiry Staff Alert off and words it, and refuses it switched on with no words', function () {
    Livewire::test(Sms::class)
        ->fillForm(['newEnquiryAlertEnabled' => true, 'newEnquiryAlertTemplate' => ''])
        ->call('save')
        ->assertHasFormErrors(['newEnquiryAlertTemplate'])
        ->fillForm(['newEnquiryAlertEnabled' => false, 'newEnquiryAlertTemplate' => 'প্রশ্ন {name} {phone}'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSee('{topic}');

    app()->forgetInstance(SmsSettings::class);
    $settings = app(SmsSettings::class);

    expect($settings->newEnquiryAlertEnabled)->toBeFalse()
        ->and($settings->newEnquiryAlertTemplate)->toBe('প্রশ্ন {name} {phone}');
});

it('chooses GioSMS with its token sealed, and saves the staff numbers and the Merchant\'s wording', function () {
    Livewire::test(Sms::class)
        ->fillForm([
            'gateway' => SmsGateway::GioSms->value,
            'giosmsApiToken' => 'gio_live_token_1',
            'giosmsSenderId' => 'BongoTea',
            'staffNumbers' => ['01811111111'],
            'confirmedTemplate' => 'অর্ডার {order_number} নিশ্চিত',
            'cancelledEnabled' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('data.giosmsApiToken', null)
        ->assertDontSee('gio_live_token_1');

    expect((string) DB::table('settings')->where('key', 'sms')->value('value'))->not->toContain('gio_live_token_1');

    app()->forgetInstance(SmsSettings::class);
    $settings = app(SmsSettings::class);

    expect($settings->gateway())->toBe(SmsGateway::GioSms)
        ->and($settings->giosmsApiToken)->toBe('gio_live_token_1')
        ->and($settings->giosmsSenderId)->toBe('BongoTea')
        ->and($settings->staffNumbers)->toBe(['01811111111'])
        ->and($settings->confirmedTemplate)->toBe('অর্ডার {order_number} নিশ্চিত')
        ->and($settings->cancelledEnabled)->toBeFalse()
        ->and(SmsGateway::GioSms->driver()->refusal())->toBeNull();
});

it('refuses GioSMS without a sender id, a staff number nobody can dial, and a switched-on text with no words', function () {
    Livewire::test(Sms::class)
        ->fillForm([
            'gateway' => SmsGateway::GioSms->value,
            'giosmsApiToken' => 'gio_live_token_1',
            'giosmsSenderId' => '',
            'staffNumbers' => ['12'],
            'placedEnabled' => true,
            'placedTemplate' => '',
        ])
        ->call('save')
        ->assertHasFormErrors(['giosmsSenderId', 'staffNumbers', 'placedTemplate']);

    expect(DB::table('settings')->where('key', 'sms')->exists())->toBeFalse();
});
