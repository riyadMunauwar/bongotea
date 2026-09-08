<?php

namespace Tests\Fixtures\Settings;

use App\Support\Settings\SettingsGroup;

/**
 * A group with one secret and one plain property, so the suite can prove the
 * secret mechanism before any real group needs it. The General group has no
 * secrets.
 */
class SecretSettings extends SettingsGroup
{
    /** A courier or payment credential: sealed at rest, write-only in the form. */
    public string $apiToken = '';

    /** A plain value beside it, to show the two are stored differently. */
    public string $label = 'plain';

    public static function key(): string
    {
        return 'test-secret';
    }

    /** @return list<string> */
    public static function secrets(): array
    {
        return ['apiToken'];
    }
}
