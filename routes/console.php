<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| The sweep over Pending Payments: every five minutes, so a Customer who
| paid and closed the browser is Paid within minutes rather than on the next
| call. Never two at once, because two sweeps asking about the same Payment
| is two calls to the gateway for one answer.
*/

Schedule::command('payments:sweep')->everyFiveMinutes()->withoutOverlapping();
