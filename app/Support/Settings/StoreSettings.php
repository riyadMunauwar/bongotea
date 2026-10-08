<?php

namespace App\Support\Settings;

use Illuminate\Support\Facades\Storage;

/**
 * Who the store is: the name and tagline beside the logo, the numbers and
 * addresses a buyer reaches it on, and what a shared link says about it.
 *
 * Every value is set once and drawn everywhere — the header, the footer, the
 * mobile drawer and every storefront page's title and share tags — so a
 * phone number lives here and nowhere else. The defaults are the mockup's, so
 * a fresh install opens looking like Bongo Tea.
 *
 * The logo and the share image are paths on the public `local` disk
 * (ADR-0011); blank draws the drawn mark and no share image respectively.
 */
class StoreSettings extends SettingsGroup
{
    /** Where uploaded logos and share images are stored. */
    public const IMAGE_DISK = 'local';

    public const IMAGE_DIRECTORY = 'store';

    public string $name = 'Bongotea';

    public string $tagline = 'ব্যবসার জন্য পাইকারি চা';

    /** What a search result or a shared link says when a page says nothing of its own. */
    public string $description = 'চায়ের দোকান, রেস্টুরেন্ট ও ব্যবসার জন্য বাছাই করা চা। বেশি নিলে প্রতি কেজির দাম কম।';

    public ?string $logo = null;

    /** As the Merchant typed it; dialled through `PhoneNumber`. */
    public string $hotline = '01700000000';

    public string $whatsapp = '01700000000';

    public string $email = 'hello@bongotea.com';

    public string $address = 'শ্রীমঙ্গল, মৌলভীবাজার';

    public string $hours = 'সকাল ৯টা – রাত ৯টা';

    /** The image a shared link shows when a page has none of its own. */
    public ?string $shareImage = null;

    public static function key(): string
    {
        return 'store';
    }

    public function logoUrl(): ?string
    {
        return self::imageUrl($this->logo);
    }

    public function shareImageUrl(): ?string
    {
        return self::imageUrl($this->shareImage);
    }

    private static function imageUrl(?string $path): ?string
    {
        return blank($path) ? null : url(Storage::disk(self::IMAGE_DISK)->url($path));
    }
}
