<?php

namespace App\Support\Reporting;

/**
 * How finely a Reporting Window is sliced for a series: by the hour when
 * the window is one calendar day, so a day is not a single dot, and by the
 * day otherwise.
 */
enum BucketSize
{
    case Hour;
    case Day;
}
