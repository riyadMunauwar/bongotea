<?php

namespace App\Filament\Widgets;

use App\Enums\Permission;
use App\Support\Couriers\CourierMoney;
use App\Support\Couriers\CourierMoneyRow;
use App\Support\Money;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Collection;

/**
 * Courier money, full width above Needs Attention: a row per Courier with
 * what that Courier says it is holding beside what its parcels are still out
 * collecting.
 *
 * Which couriers are drawn is `CourierMoney`'s answer and not a question this
 * asks — one that can be used, and one still carrying parcels whether it can
 * be used or not. So an installation that uses a single Courier reads a
 * single row, and a Courier switched off with money still out goes on being
 * listed until it is in.
 *
 * Two columns and never a total. **Balance** is the Courier's own word about
 * its account and only one of the three publishes one at all — the other two
 * say "Not reported" in words, because a blank in a money column is read as a
 * zero and a zero here tells a Merchant their money has been paid over.
 * **COD in Flight** is counted from the Merchant's own Orders, so every
 * Courier has it alike. Adding them would double-count a parcel delivered
 * this morning; they are shown side by side because that is how a Merchant
 * chases a Courier.
 *
 * Both figures are as they stand now, not as they stood over a Reporting
 * Window, which the subheading says for the reason Needs Attention's does:
 * drawn under that filter, a figure silently read as filtered is a figure a
 * Merchant trusts wrongly.
 *
 * The counting and the asking are `CourierMoney`'s; this labels and formats
 * and does neither. Lazy, like every widget on this screen, so the shell
 * renders before a Courier is called.
 */
class CourierMoneyTable extends TableWidget implements GatedOnDashboard
{
    /**
     * Above Needs Attention, so the three full-width tables sit together at
     * the foot of the screen and none of them pushes the tiles and charts
     * down it.
     */
    protected static ?int $sort = 65;

    /**
     * A Merchant's figure, drawn on the Dashboard behind `view reports`
     * (#161): it is courier *money*, and money is the Merchant's to read. It
     * sits among the window-ignoring widgets by position, but it is gated
     * with the figures rather than with the to-do list beside it. The
     * Dashboard alone draws it, so the gate has no other screen to be inert
     * on.
     */
    public static function dashboardPermission(): Permission
    {
        return Permission::ViewReports;
    }

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Courier money')
            ->description('What each Courier says it is holding, beside what its parcels are still out collecting. As things stand now, whatever the Reporting Window above says.')

            /**
             * A row per Courier and the registry holds three, so there is no
             * second page to reach and a paginator under three rows is
             * furniture. Every other collection-backed widget table returns a
             * paginator because its rows are counted from Orders and can run
             * on; these are counted from the enum.
             */
            ->paginated(false)
            ->records(fn (): Collection => $this->rows())
            ->columns([
                TextColumn::make('courier')
                    ->label('Courier')
                    ->weight('medium'),

                TextColumn::make('balance')
                    ->label('Balance (courier-reported)')
                    ->description(fn (array $record): ?string => $record['balance_note'])
                    ->color(fn (array $record): ?string => $record['has_balance'] ? null : 'gray')
                    ->alignEnd(),

                TextColumn::make('cod_in_flight')
                    ->label('COD in Flight')
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd(),
            ])

            /**
             * Nobody has set a Courier up and none is carrying anything, which
             * is a Merchant who has not started rather than one whose money has
             * gone: the heading says so and points at the screen that fixes it.
             */
            ->emptyStateHeading('No Couriers set up')
            ->emptyStateDescription('Switch a Courier on in Settings → Couriers and what it is holding appears here. One switched off with parcels still out stays listed until they are in.');
    }

    /**
     * The module's rows as table rows, keyed by Courier.
     *
     * The balance column carries a sentence where it carries no figure, so
     * that nothing in it is ever blank: a Courier that publishes no balance
     * says so, and one that could not be asked says that instead. A figure
     * carries when it was read and, where a later read failed, what went
     * wrong — a figure held from an earlier read goes on being shown, and a
     * Merchant reading this morning's money is owed both its age and the fact
     * that the Courier has said nothing since.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        return collect(app(CourierMoney::class)->rows())->map(fn (CourierMoneyRow $row): array => [
            ArrayRecord::getKeyName() => $row->courier->value,
            'courier' => $row->courier->getLabel(),
            'has_balance' => $row->balance->isReported(),
            'balance' => match (true) {
                $row->balance->isReported() => Money::format((int) $row->balance->amount),
                $row->balance->isUnavailable() => 'Unavailable',
                default => 'Not reported',
            },
            'balance_note' => match (true) {
                $row->balance->isReported() => 'Read '.$row->balance->readAt?->diffForHumans().($row->balance->error === null ? '' : ' — not refreshed since: '.$row->balance->error),
                $row->balance->isUnavailable() => $row->balance->error,
                default => $row->courier->getLabel().' publishes no balance.',
            },
            'cod_in_flight' => $row->codInFlight,
        ]);
    }
}
