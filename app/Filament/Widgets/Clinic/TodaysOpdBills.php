<?php

namespace App\Filament\Widgets\Clinic;

use App\Enums\BillType;
use App\Enums\SaleStatus;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Support\ShopMoney;
use App\Filament\Widgets\Concerns\ClinicWidget;
use App\Models\Sale;
use App\Support\CurrentShop;
use App\Support\ReportPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Today's OPD bills, newest first: the time, the bill number, the
 * consultation fee (the bill's lines for services, as the reports count
 * them), the Medicines amount typed at the till, and Completed or Voided.
 */
class TodaysOpdBills extends TableWidget
{
    use ClinicWidget;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = ['default' => 1, 'xl' => 8];

    public static function canView(): bool
    {
        return self::clinicUserCan('reports.view');
    }

    /** @return Builder<Sale> */
    public static function query(): Builder
    {
        $today = ReportPeriod::today(app(CurrentShop::class)->getOrFail());

        return Sale::query()
            ->where('bill_type', BillType::Opd->value)
            ->where('reported_at', '>=', $today->startUtc())
            ->where('reported_at', '<', $today->endUtc())
            // One subquery for the fee, so the table runs one query however many bills.
            ->addSelect(['consultation_fee' => DB::table('sale_items')
                ->join('product_variants', 'product_variants.id', '=', 'sale_items.product_variant_id')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->whereColumn('sale_items.sale_id', 'sales.id')
                ->where('products.track_stock', false)
                ->selectRaw('COALESCE(SUM(sale_items.line_total), 0)')])
            ->latest('reported_at')
            ->latest('id');
    }

    public function table(Table $table): Table
    {
        $timezone = (string) (app(CurrentShop::class)->getOrFail()->timezone ?: config('app.timezone'));

        return $table
            ->heading("Today's OPD bills")
            ->query(fn (): Builder => self::query())
            ->columns([
                TextColumn::make('reported_at')->label('Time')->dateTime('H:i', $timezone),
                TextColumn::make('invoice_no')->label('Bill no.')->searchable(),
                TextColumn::make('consultation_fee')
                    ->label('Consultation fee')
                    ->formatStateUsing(fn ($state): string => (string) ShopMoney::format((int) $state))
                    ->alignEnd(),
                TextColumn::make('opd_medicines_amount')
                    ->label('Medicines')
                    ->formatStateUsing(fn ($state): string => (string) ShopMoney::format((int) $state))
                    ->placeholder(ShopMoney::format(0))
                    ->alignEnd(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SaleStatus $state): string => $state === SaleStatus::Voided ? 'Voided' : 'Completed')
                    ->color(fn (SaleStatus $state): string => $state === SaleStatus::Voided ? 'danger' : 'success'),
            ])
            ->recordUrl(fn (Sale $record): ?string => SaleResource::canView($record) ? SaleResource::getUrl('view', ['record' => $record]) : null)
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25])
            ->emptyStateHeading('No OPD bills yet today')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }
}
