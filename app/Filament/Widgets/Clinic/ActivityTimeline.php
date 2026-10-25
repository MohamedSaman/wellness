<?php

namespace App\Filament\Widgets\Clinic;

use App\Filament\Widgets\Concerns\ClinicWidget;
use Filament\Widgets\Widget;

/** Activity: the last six things that happened, sales to stock counts, with their time. */
class ActivityTimeline extends Widget
{
    use ClinicWidget;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.clinic.activity-timeline';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return self::clinicUserCan('reports.view');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['events' => $this->figures()->activity()];
    }
}
