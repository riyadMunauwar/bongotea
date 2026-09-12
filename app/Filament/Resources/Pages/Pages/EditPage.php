<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\Concerns\FinishesPageSave;
use App\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

/**
 * The Page edit screen: the form a Merchant opened it for, and nothing in
 * front of it.
 *
 * It used to carry that Page's report above the form. The report has since
 * grown past what a screen about authoring can hold, and a Merchant who
 * came to change a headline was scrolling past a chart to reach it on every
 * visit — so the figures moved to `PageReport`, which is the one place they
 * live and therefore the one that is authoritative.
 *
 * What is left in the header is a way back to them: Report beside Preview,
 * because how is this Page doing and what does it look like are asked
 * together and are answered from the same row of buttons.
 */
class EditPage extends EditRecord
{
    use FinishesPageSave;

    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('report')
                ->icon('heroicon-o-chart-bar')
                ->url(fn (Page $record): string => PageResource::getUrl('report', ['record' => $record])),
            Action::make('preview')
                ->icon('heroicon-o-eye')
                ->url(fn (Page $record): string => route('pages.preview', $record))
                ->openUrlInNewTab(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $this->invalidateAndWarn();
    }
}
