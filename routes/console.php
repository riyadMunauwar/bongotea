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

/*
| The sweep over the tracking outbox, beside the payment one and on the same
| cadence: no web request ever calls an ad platform, so this is the only
| thing that delivers what a Moment wrote down. Never two at once, because
| two sweeps draining one Destination is two batches reporting the same
| events.
*/

Schedule::command('tracking:sweep')->everyFiveMinutes()->withoutOverlapping();

/*
| The prune over what tracking has accumulated: once a day, in the quiet hour
| before the Merchant's morning, because it is the only scheduled thing here
| that deletes. Never two at once — two prunes deleting the same batch is two
| long statements for one result.
|
| Nothing Pending is touched, so this may run while the sweep is behind: a
| week of events waiting on a broken cron is still waiting after it, which is
| the outbox's whole promise. The Visit aggregate is not touched either, and
| needs no rollup first — it is written as the Visits happen.
*/

Schedule::command('tracking:prune')->dailyAt('03:10')->withoutOverlapping();
