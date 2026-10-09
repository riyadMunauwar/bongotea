<?php

namespace App\Observers;

use App\Models\Enquiry;
use App\Support\Sms\EnquiryAlerts;

/**
 * The Staff Alert a new Enquiry raises, from wherever it was created — the
 * same reason `OrderTextObserver` is an observer rather than a call in the
 * controller.
 */
class EnquiryAlertObserver
{
    public function __construct(private readonly EnquiryAlerts $alerts) {}

    public function created(Enquiry $enquiry): void
    {
        $this->alerts->alertStaffToNewEnquiry($enquiry);
    }
}
