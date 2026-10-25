<?php

namespace App\Filament\Widgets\Clinic;

use App\Filament\Widgets\Concerns\ClinicWidget;
use Filament\Widgets\Widget;

/**
 * Stock value: what the stock at General Pharmacy and the doctor's cost
 * (weighted-average cost), as a ring with the largest share in the middle,
 * and the value of each and the items in stock below it. Cost: cost.view,
 * as the stock valuation report.
 */
class StockValueDonut extends Widget
{
    use ClinicWidget;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.clinic.stock-value-donut';

    protected int|string|array $columnSpan = ['default' => 1, 'xl' => 3];

    public static function canView(): bool
    {
        return self::clinicUserCan('reports.view', 'cost.view');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return $this->figures()->stockValue();
    }
}
