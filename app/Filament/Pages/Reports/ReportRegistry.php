<?php

namespace App\Filament\Pages\Reports;

/**
 * Every admin report page, so a report can be found by its slug (the
 * print page, docs/PHARMACY.md §9, branch 11).
 */
final class ReportRegistry
{
    /** @var list<class-string<ReportPage>|class-string<SummaryReportPage>> */
    public const PAGES = [
        DailySummaryReport::class,
        MonthlySalesReport::class,
        ProfitReport::class,
        SalesByDayReport::class,
        SalesByCashierReport::class,
        SalesByCategoryReport::class,
        SalesByBrandReport::class,
        PaymentMethodsReport::class,
        VoidsAndReturnsReport::class,
        ShiftHistoryReport::class,
        StockValuationReport::class,
        LowStockReport::class,
        StockMovementsReport::class,
        ProfitByProductReport::class,
        BatchValuationReport::class,
        ExpiryReport::class,
        SlowMovingStockReport::class,
        StockAdjustmentsReport::class,
        TransfersReport::class,
        ControlledDrugRegisterReport::class,
        PurchasesReport::class,
        SupplierAgingReport::class,
    ];

    /** @return class-string<ReportPage>|class-string<SummaryReportPage>|null */
    public static function find(string $slug): ?string
    {
        foreach (self::PAGES as $page) {
            if ($page::getSlug() === $slug) {
                return $page;
            }
        }

        return null;
    }
}
