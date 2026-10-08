<?php

use Tests\Fixtures\Sms\GioSmsScript;

/**
 * Every SMS Gateway that speaks over HTTP answers the same four ways — took
 * the text, refused the credentials, refused the text, said nothing — so the
 * gateway tests run once per gateway over its documented shapes. A later
 * gateway is a new script and a new row here, nothing more.
 */
dataset('sms gateways', [
    'GioSMS' => [new GioSmsScript],
]);
