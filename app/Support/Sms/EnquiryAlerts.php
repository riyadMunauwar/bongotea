<?php

namespace App\Support\Sms;

use App\Models\Enquiry;
use App\Support\PhoneNumber;
use App\Support\Settings\SmsSettings;
use Illuminate\Support\Facades\DB;

/**
 * The Staff Alert a new Enquiry raises: somebody asked a question, and here
 * is who to call.
 *
 * Like `OrderTexts`, nothing here sends and nothing here throws into its
 * caller — the alert is worded and queued once the Enquiry has committed,
 * and a failure to do so never refuses the buyer's question.
 */
class EnquiryAlerts
{
    /** What the alert's wording may say, each filled from the Enquiry. */
    public const PLACEHOLDERS = ['{name}', '{phone}', '{topic}', '{message}'];

    /**
     * Queue the new-Enquiry Staff Alert to every one of the Merchant's
     * numbers, if it is switched on.
     */
    public function alertStaffToNewEnquiry(Enquiry $enquiry): void
    {
        DB::afterCommit(fn () => rescue(function () use ($enquiry): void {
            $settings = app(SmsSettings::class);

            if (! $settings->newEnquiryAlertEnabled) {
                return;
            }

            $alert = $this->word($settings->newEnquiryAlertTemplate, $enquiry);

            foreach ($settings->staffNumbers as $number) {
                SendText::dispatch($number, $alert);
            }
        }));
    }

    private function word(string $template, Enquiry $enquiry): string
    {
        return str_replace(self::PLACEHOLDERS, [
            $enquiry->name,
            PhoneNumber::asDialled($enquiry->phone) ?? $enquiry->phone,
            (string) $enquiry->topic,
            (string) $enquiry->message,
        ], $template);
    }
}
