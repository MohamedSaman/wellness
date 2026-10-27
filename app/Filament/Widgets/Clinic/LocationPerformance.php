<?php

namespace App\Filament\Widgets\Clinic;

use App\Filament\Widgets\Concerns\ClinicWidget;
use Filament\Widgets\Widget;

/**
 * Location performance: this month's net sales split between the selling
 * counters (General Pharmacy, the doctor's), and the top four categories by
 * net sales with their share.
 */
class LocationPerformance extends Widget
{
    use ClinicWidget;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.clinic.location-performance';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return self::clinicUserCan('reports.view');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return $this->figures()->locationPerformance();
    }
}
