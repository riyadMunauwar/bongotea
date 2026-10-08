<?php

namespace App\Models;

use Database\Factories\VisitCountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * How many times the storefront was loaded on one day.
 *
 * The whole of the Visit aggregate: a date and a count. It is written by
 * `VisitCounter` alone and read by `ReportingQuery` for the Visits tile and
 * the Conversion Rate.
 *
 * It holds no personal data of any kind, which is what lets it be kept
 * forever while the outbox and the Tracking Contexts beside it are pruned.
 *
 * The date is a plain `Y-m-d` string in the Merchant's timezone and carries
 * no cast: a date cast would hand the database a datetime, which one driver
 * truncates and another stores whole, and the two would disagree about which
 * day a row belongs to. Nothing reads it but a range comparison.
 *
 * No timestamps: a row that is incremented on every load would pay for a
 * column nobody reads.
 */
#[Fillable([
    'visited_on',
    'visits',
])]
class VisitCount extends Model
{
    /** @use HasFactory<VisitCountFactory> */
    use HasFactory;

    public $timestamps = false;
}
