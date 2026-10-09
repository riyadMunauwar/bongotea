<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * One thing a User may do, and the whole list of them.
 *
 * This enum is the catalogue (ADR-0026): nothing declares a Permission
 * anywhere else. A gated Resource, Page, widget or Action names a case rather
 * than a string, and the Roles screen builds its ticks from `cases()` when it
 * arrives — never from the `permissions` table, which would put a Permission
 * shipped in a release out of reach on an installation that never ran
 * `permission:sync`. A rename is then a fatal at every reference site instead
 * of a screen that quietly stops being reachable.
 *
 * Two kinds of case, which is what `group()` says. A working area is a part
 * of the admin somebody is given to work in; an act that cannot be undone is
 * a single verb held back from the person who works there all day — writing
 * a parcel off, voiding a delivery, spending a Slug that is baked into live
 * ad creatives. The split is the Merchant's reading of what they are really
 * granting, not a technical one.
 *
 * Names follow `manage settings`, the first Permission this system had:
 * lowercase, verb first, spaces rather than dots or colons. Keys are TitleCase
 * per the project's PHP conventions. The name is what is stored in the
 * `permissions` row and what `can()` is asked, so it is never restyled.
 *
 * An Owner holds none of these and passes every check regardless (ADR-0024).
 *
 * The whole list is declared here at once, ahead of the gates that will read
 * it, because the list is the design: what a Merchant may divide the admin
 * into is decided in one place and read off one screen, not discovered a case
 * at a time as each surface is gated. Today only `ManageSettings` is consumed.
 */
enum Permission: string implements HasLabel
{
    case WorkOrders = 'work orders';
    case AuthorPages = 'author pages';
    case ManageCatalogue = 'manage catalogue';
    case CountStock = 'count stock';
    case ViewReports = 'view reports';
    case ManageSettings = 'manage settings';

    case AbandonParcels = 'abandon parcels';
    case AnswerPartlyDelivered = 'answer partly delivered';
    case SwitchToCashOnDelivery = 'switch to cash on delivery';
    case VoidSupplies = 'void supplies';
    case DeleteOrders = 'delete orders';
    case DeletePages = 'delete pages';
    case OverridePrices = 'override prices';

    /** The heading a working area sits under on the Roles screen. */
    public const WORKING_AREAS = 'Working areas';

    /** The heading an irreversible act sits under on the Roles screen. */
    public const IRREVERSIBLE_ACTS = 'Acts that cannot be undone';

    public function getLabel(): string
    {
        return match ($this) {
            self::WorkOrders => 'Work Orders',
            self::AuthorPages => 'Author Pages',
            self::ManageCatalogue => 'Manage catalogue',
            self::CountStock => 'Count stock',
            self::ViewReports => 'View reports',
            self::ManageSettings => 'Manage settings',
            self::AbandonParcels => 'Abandon a parcel',
            self::AnswerPartlyDelivered => 'Answer a part-delivered parcel',
            self::SwitchToCashOnDelivery => 'Switch an Order to Cash on Delivery',
            self::VoidSupplies => 'Void a Supply',
            self::DeleteOrders => 'Delete an Order',
            self::DeletePages => 'Delete a Page',
            self::OverridePrices => 'Override a price on an Order',
        };
    }

    /**
     * The heading this Permission is drawn under when a Role is edited.
     *
     * Two headings and no more: what somebody is given to work in, and what
     * they are trusted to do once and never take back.
     */
    public function group(): string
    {
        return match ($this) {
            self::WorkOrders,
            self::AuthorPages,
            self::ManageCatalogue,
            self::CountStock,
            self::ViewReports,
            self::ManageSettings => self::WORKING_AREAS,
            self::AbandonParcels,
            self::AnswerPartlyDelivered,
            self::SwitchToCashOnDelivery,
            self::VoidSupplies,
            self::DeleteOrders,
            self::DeletePages,
            self::OverridePrices => self::IRREVERSIBLE_ACTS,
        };
    }

    /**
     * Every Permission as the name it is stored and asked under.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }
}
