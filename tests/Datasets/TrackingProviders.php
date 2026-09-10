<?php

use Tests\Fixtures\Tracking\FacebookScript;

/**
 * Every Provider walks the same road — a Moment writes rows, the sweep
 * batches them, one request delivers each batch — so the tracking tests run
 * once per Provider over its documented shapes. TikTok and Google Ads are a
 * new script and a new row here, and no new test file.
 */
dataset('tracking providers', [
    'Facebook' => [new FacebookScript],
]);
