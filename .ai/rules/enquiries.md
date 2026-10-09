---
paths:
  - app/Models/Enquiry.php
  - app/Enums/EnquiryStatus.php
  - app/Http/Controllers/EnquiryController.php
  - app/Http/Requests/EnquiryRequest.php
  - app/Observers/EnquiryAlertObserver.php
  - app/Support/Sms/EnquiryAlerts.php
  - app/Support/Pages/Blocks/ContactForm.php
  - 'app/Filament/Resources/Enquiries/**'
---

# Enquiries

## An Enquiry's Staff Alert comes from its observer, and a honeypot hit is thanked like a real question
`EnquiryAlertObserver` (created) → `EnquiryAlerts::alertStaffToNewEnquiry()` is the only trigger for the new-Enquiry Staff Alert; it queues after commit inside `rescue()`, like `OrderTexts`, and reads `SmsSettings::newEnquiryAlertEnabled/Template` (placeholders `EnquiryAlerts::PLACEHOLDERS`). `EnquiryController` answers a filled `website` honeypot with the same `{sent: true}` and stores nothing, so a script learns nothing. Limits: 3 per canonical phone per hour (RateLimiter `enquiry:{phone}`) plus `throttle:enquiry` (20/hour per IP). Status moves only through `Enquiry::moveTo()`: Replied/Closed stamp `handled_by_id`/`handled_at`, while New (reopen) clears them. Never delete an Enquiry; the resource has no delete.
