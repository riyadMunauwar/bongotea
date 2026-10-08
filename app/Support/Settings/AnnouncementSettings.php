<?php

namespace App\Support\Settings;

/**
 * The dark bar above the storefront header: a short pill and one line of
 * offer, switched on or off as a whole.
 */
class AnnouncementSettings extends SettingsGroup
{
    public bool $enabled = true;

    /** The gold tag before the message. Blank draws the message alone. */
    public string $pill = 'অফার';

    public string $message = '৫১+ কেজিতে প্রতিটি চায়ে সর্বোচ্চ ছাড়';

    public static function key(): string
    {
        return 'announcement';
    }

    /**
     * Whether the bar is drawn: switched on and saying something.
     */
    public function isShown(): bool
    {
        return $this->enabled && filled($this->message);
    }
}
