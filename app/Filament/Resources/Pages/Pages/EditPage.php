<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Concerns\HasReportingWindow;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\Concerns\FinishesPageSave;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\PaymentMethodMixChart;
use App\Filament\Widgets\SalesOverTimeChart;
use App\Filament\Widgets\TopProductsTable;
use App\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * The Page edit screen, which carries that Page's report above the form.
 *
 * The widgets are the Dashboard's own classes, not copies: each takes the
 * record through `ScopedToPage`, which Filament fills from
 * `InteractsWithRecord::getWidgetData()`, and asks `ReportingQuery` for the
 * same figures over that Page alone. They are lazy, so opening a Page to
 * edit it is not held up by four aggregate queries, and a draft Page that
 * never took an Order reads as zeros and an empty chart rather than as an
 * error. A trashed Page still reports: Livewire restores the record without
 * the soft-delete scope.
 *
 * The Reporting Window is a header action rather than a form across the
 * top, because the form below is the reason a Merchant opened the screen
 * and the report is what they glance at. The control inside the modal is
 * the Dashboard's, through `HasReportingWindow`; the chosen window is kept
 * in the session under this screen's own key, and reaches every widget as
 * `pageFilters` because the trait declares the property Filament looks for.
 */
class EditPage extends EditRecord
{
    use FinishesPageSave;
    use HasReportingWindow;

    protected static string $resource = PageResource::class;

    /**
     * The Page's report: the stat tiles and Sales over time full width,
     * then Top Products beside the Payment Method mix. Named here rather
     * than sorted, because what a Merchant asks first of a Page they are
     * editing is which of its Products carries it — the Dashboard, whose
     * widgets these are, orders the two the other way round.
     *
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            OverviewStats::class,
            SalesOverTimeChart::class,
            TopProductsTable::class,
            PaymentMethodMixChart::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->reportingWindowAction(),
            Action::make('preview')
                ->icon('heroicon-o-eye')
                ->url(fn (Page $record): string => route('pages.preview', $record))
                ->openUrlInNewTab(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * The Reporting Window as a modal: filled from what the session
     * remembers, or from the components' own defaults on a first visit, and
     * applied by writing the screen's filters — which is what the widgets
     * read, and what `updatedFilters()` puts back in the session.
     *
     * The chosen window rides on the button as a badge. The session key is
     * this screen's class, so a window picked while looking at one Page is
     * restored on every other Page and days later; with the control shut
     * inside a modal, a Page whose report is silently pinned to Today would
     * otherwise read as a Page that never sold.
     */
    private function reportingWindowAction(): Action
    {
        return Action::make('reportingWindow')
            ->label('Reporting Window')
            ->badge(fn (): string => $this->reportingWindowName())
            ->badgeColor('gray')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel('Apply')
            ->schema($this->reportingWindowComponents())
            ->fillForm(fn (): ?array => $this->filters)
            ->action(function (array $data): void {
                $this->filters = $data;
                $this->updatedFilters();
            });
    }

    protected function afterSave(): void
    {
        $this->invalidateAndWarn();
    }
}
