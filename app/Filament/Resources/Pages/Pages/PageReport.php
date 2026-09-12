<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Concerns\HasReportingWindow;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\PaymentMethodMixChart;
use App\Filament\Widgets\SalesOverTimeChart;
use App\Filament\Widgets\TopProductsTable;
use App\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One Page's own figures, on a screen of their own.
 *
 * It answers over a single Page the questions the Dashboard asks of every
 * Page at once, which is why every widget on it is one of the Dashboard's
 * own classes rather than a copy: each takes the record through
 * `ScopedToPage`, which Filament fills from
 * `InteractsWithRecord::getWidgetData()`, and asks `ReportingQuery` for the
 * same figures over that Page alone. They stay lazy, so opening the screen
 * is not held up by four aggregate queries, and a Page that never took an
 * Order reads as zeros and an empty chart rather than as an error. A
 * retired Page still reports — `PageResource` resolves a trashed record,
 * and what it earned before it was taken down is still worth reading.
 *
 * The Reporting Window sits across the top as a filter form, exactly as it
 * does on the Dashboard, because a Merchant opens this screen when they
 * want figures: the control belongs in front of them rather than behind a
 * button. `HasReportingWindow` supplies the components and the screen
 * state, and Filament keeps the chosen window in the session under this
 * class's key — one key for the screen, not one per Page, so a window
 * picked while reading one Page is the window the next Page opens on.
 *
 * There is no Delete here, nor Force Delete nor Restore. A screen about a
 * Page's past must not be able to destroy the Page; the header offers Edit,
 * which returns to the form, and Preview, which opens the live Page.
 */
class PageReport extends ResourcePage
{
    use HasFiltersForm;
    use HasReportingWindow;
    use InteractsWithRecord;

    protected static string $resource = PageResource::class;

    /**
     * The trail reads Pages → Report. The heading names the Page itself,
     * so the breadcrumb is free to say which view of it this is.
     */
    protected static ?string $breadcrumb = 'Report';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    /**
     * The Page's own title, which is also the browser tab's: a Merchant
     * comparing three Pages in three tabs tells them apart by it. The
     * resource has no record title attribute — that would rename the edit
     * screen too — so the title is read off the record here.
     */
    public function getTitle(): string|Htmlable
    {
        /** @var Page $page */
        $page = $this->getRecord();

        return $page->title;
    }

    /**
     * The Reporting Window across the top, then the report under it.
     *
     * Written out rather than left to the header-widget slot, because that
     * slot draws above the page content and the control has to come before
     * the figures it governs.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedSchema::make('filtersForm'),
                Grid::make(2)
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getWidgets())),
            ]);
    }

    /**
     * The Reporting Window control itself. Its components are
     * `HasReportingWindow`'s, so this screen offers the same presets and
     * the same two date pickers the Dashboard does.
     */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components($this->reportingWindowComponents());
    }

    /**
     * The report: the stat tiles and Sales over time full width, then Top
     * Products beside the Payment Method mix.
     *
     * Named here rather than discovered and sorted, because the first thing
     * asked of a single Page is which of its Products carries it — the
     * Dashboard, whose widgets these are, orders the two the other way
     * round for a catalogue-wide ranking that answers a different question.
     *
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            OverviewStats::class,
            SalesOverTimeChart::class,
            TopProductsTable::class,
            PaymentMethodMixChart::class,
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('preview')
                ->icon('heroicon-o-eye')
                ->url(fn (Page $record): string => route('pages.preview', $record))
                ->openUrlInNewTab(),
        ];
    }
}
