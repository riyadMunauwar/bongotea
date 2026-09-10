<?php

namespace App\Support\Reporting;

/**
 * The spans a Merchant may pick a Reporting Window from. Every preset but
 * Custom is cut from "now" in the Merchant timezone; Custom carries its two
 * dates instead. The values are what a filter form stores.
 */
enum ReportingPreset: string
{
    case Today = 'today';
    case Yesterday = 'yesterday';
    case Last7Days = 'last_7_days';
    case Last30Days = 'last_30_days';
    case ThisMonth = 'this_month';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Yesterday => 'Yesterday',
            self::Last7Days => 'Last 7 days',
            self::Last30Days => 'Last 30 days',
            self::ThisMonth => 'This month',
            self::Custom => 'Custom',
        };
    }

    /**
     * The presets as select options, value to label, in the order offered.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $options = [];

        foreach (self::cases() as $preset) {
            $options[$preset->value] = $preset->label();
        }

        return $options;
    }
}
