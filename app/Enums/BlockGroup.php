<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a Block kind is filed in the editor's Block picker.
 *
 * Only a way of finding a kind among the rest: a group says nothing about
 * which Page a Block may stand on, and the order of the cases here is the
 * order the picker shows the groups in.
 */
enum BlockGroup: string implements HasLabel
{
    case Banners = 'banners';
    case Catalogue = 'catalogue';
    case Selling = 'selling';
    case Trust = 'trust';
    case Information = 'information';
    case ContactAndAccount = 'contact_and_account';

    public function getLabel(): string
    {
        return match ($this) {
            self::Banners => 'Banners',
            self::Catalogue => 'Catalogue',
            self::Selling => 'Selling',
            self::Trust => 'Trust',
            self::Information => 'Information',
            self::ContactAndAccount => 'Contact & account',
        };
    }
}
