<?php

namespace App\Filament\Resources\Enquiries\Schemas;

use App\Models\Enquiry;
use App\Support\PhoneNumber;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One question as the buyer sent it, and where it stands.
 */
class EnquiryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The question')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),

                        TextEntry::make('phone')
                            ->formatStateUsing(fn (string $state): string => PhoneNumber::asDialled($state) ?? $state)
                            ->url(fn (Enquiry $record): string => 'tel:'.$record->phone)
                            ->copyable()
                            ->copyableState(fn (Enquiry $record): string => PhoneNumber::asDialled($record->phone) ?? $record->phone),

                        TextEntry::make('topic')
                            ->placeholder('—'),

                        TextEntry::make('created_at')
                            ->label('Sent')
                            ->dateTime(),

                        TextEntry::make('message')
                            ->placeholder('No message')
                            ->columnSpanFull(),
                    ]),

                Section::make('Where it stands')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('status')
                            ->badge(),

                        TextEntry::make('handledBy.name')
                            ->label('Handled by')
                            ->placeholder('Nobody yet')
                            ->helperText(fn (Enquiry $record): ?string => $record->handled_at?->diffForHumans()),
                    ]),
            ]);
    }
}
