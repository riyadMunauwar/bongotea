<?php

namespace App\Filament\Resources\Enquiries\Tables;

use App\Enums\EnquiryStatus;
use App\Filament\Resources\Enquiries\Actions\MoveEnquiryAction;
use App\Models\Enquiry;
use App\Support\PhoneNumber;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every question, newest first, with the number to call on each row. No
 * bulk actions and no delete: an Enquiry is closed, never erased, so the
 * record of who asked stays, and the status column names who handled it
 * last.
 */
class EnquiriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Enquiry $record): string => PhoneNumber::asDialled($record->phone) ?? $record->phone),

                /** Searched as stored, so the canonical +880 form; the name is what is usually typed. */
                TextColumn::make('phone')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('topic')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                TextColumn::make('message')
                    ->limit(80)
                    ->wrap()
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->description(fn (Enquiry $record): ?string => $record->handledBy?->name),

                TextColumn::make('created_at')
                    ->label('Sent')
                    ->dateTime()
                    ->sortable()
                    ->description(fn (Enquiry $record): string => $record->created_at->diffForHumans()),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('handledBy'))
            ->filters([
                SelectFilter::make('status')
                    ->options(EnquiryStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(MoveEnquiryAction::all()),
            ]);
    }
}
