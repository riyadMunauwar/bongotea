<?php

use Tests\Fixtures\Payments\BkashScript;
use Tests\Fixtures\Payments\SslCommerzScript;

/**
 * Every online gateway walks the same road — Capture, the gateway's page, the
 * return, the Verification, a Notification, the sweep — so the online payment
 * tests run once per gateway over its documented shapes. A later gateway is a
 * new script and a new row here, nothing more (ADR-0013).
 */
dataset('online gateways', [
    'SSLCommerz' => [new SslCommerzScript],
    'bKash' => [new BkashScript],
]);
