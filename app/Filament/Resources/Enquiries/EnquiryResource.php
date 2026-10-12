<?php

namespace App\Filament\Resources\Enquiries;

use App\Enums\EnquiryStatus;
use App\Enums\Permission;
use App\Filament\Resources\Enquiries\Pages\ListEnquiries;
use App\Filament\Resources\Enquiries\Pages\ViewEnquiry;
use App\Filament\Resources\Enquiries\Schemas\EnquiryInfolist;
use App\Filament\Resources\Enquiries\Tables\EnquiriesTable;
use App\Models\Enquiry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The questions sent through the contact form, until somebody has called
 * each one back and closed it.
 *
 * Read-only apart from where an Enquiry stands: what the buyer wrote is
 * theirs, so there is no create page and no edit page — a question taken
 * down the phone is an Order taken by phone, not an Enquiry.
 */
class EnquiryResource extends Resource
{
    protected static ?string $model = Enquiry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static ?string $recordTitleAttribute = 'name';

    /** Fifth, under Pages: work, but nobody's Order yet. See `Pages\Dashboard` for the whole order. */
    protected static ?int $navigationSort = 4;

    /** Its own working area behind `handle enquiries` (ADR-0025). */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::HandleEnquiries) ?? false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return EnquiryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EnquiriesTable::configure($table);
    }

    /** How many nobody has called yet; nothing when none are waiting. */
    public static function getNavigationBadge(): ?string
    {
        $waiting = static::getModel()::query()->where('status', EnquiryStatus::New)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnquiries::route('/'),
            'view' => ViewEnquiry::route('/{record}'),
        ];
    }
}
