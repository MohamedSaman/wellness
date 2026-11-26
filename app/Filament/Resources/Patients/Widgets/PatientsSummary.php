<?php

namespace App\Filament\Resources\Patients\Widgets;

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\ChartColors;
use App\Filament\Support\ShopMoney;
use App\Filament\Widgets\Summary\ListSummaryWidget;
use App\Models\User;
use App\Services\Data\PatientFilters;
use App\Services\Data\PatientSummary;
use App\Services\PatientSummaryService;
use App\Support\CurrentShop;

/**
 * The summary above the Patients list (docs/PHARMACY.md, branch 20): what
 * the list shows as filtered and searched; today's figures when nothing is
 * set (the list itself still shows every patient). Whoever may see the list
 * sees it; the money only with reports.view (PatientSummaryService).
 */
class PatientsSummary extends ListSummaryWidget
{
    /** Under the money cards. */
    public const MONEY_NOTE = 'Totals can differ from the Daily summary: it counts a refund on the day it was given, and bills without a patient.';

    private ?PatientSummary $summary = null;

    public static function canView(): bool
    {
        return PatientResource::canAccess();
    }

    protected function foldName(): string
    {
        return 'patients-summary';
    }

    protected function title(): string
    {
        return $this->summary()->title;
    }

    private function summary(): PatientSummary
    {
        $user = auth()->user();

        return $this->summary ??= app(PatientSummaryService::class)->summary(
            PatientFilters::fromTableState(app(CurrentShop::class)->getOrFail(), $this->tableFilters ?? []),
            is_string($this->tableSearch) ? $this->tableSearch : null,
            $user instanceof User ? $user : null,
        );
    }

    protected function getStats(): array
    {
        $summary = $this->summary();
        $ages = $summary->ages;
        $share = fn (int $count): string => $summary->visits === 0 ? '0%' : round($count / $summary->visits * 100).'%';
        $locations = array_map(fn (array $row): string => $row['location'].' '.number_format($row['visits']), $summary->locations);

        $stats = [
            self::stat('Patients', number_format($summary->patients), 'Each counted once'),
            self::stat('Visits', number_format($summary->visits), 'Bills, voided ones left out'),
            self::stat('Visits per patient', $summary->average() === null ? '–' : number_format($summary->average(), 1), 'On average'),
            $summary->new === null || $summary->returning === null
                ? self::stat('New / Returning', '–', 'Choose a period or a shift')
                : self::stat('New / Returning', self::pair($summary->new, $summary->returning), 'New: first visit ever in this '.($this->byShift() ? 'shift' : 'period')),
            self::stat('OPD / Pharmacy', self::pair($summary->opd, $summary->pharmacy), $share($summary->opd).' OPD · '.$share($summary->pharmacy).' pharmacy bills'),
            self::stat('By location', $summary->locations === [] ? '–' : self::pair(...array_column($summary->locations, 'visits')), $locations === [] ? 'No visits' : implode(' · ', $locations)),
            self::stat('Child / Adult / Senior', self::pair($ages['child'], $ages['adult'], $ages['senior']), 'Under 12 · 12–59 · 60 and over'.($ages['unknown'] > 0 ? ' · '.number_format($ages['unknown']).' not known' : '')),
        ];

        if ($summary->income !== null) {
            $stats[] = self::stat('Consultations & services', ShopMoney::format($summary->income['services']), 'Lab tests too, less refunds');
            $stats[] = self::stat('Medicines', ShopMoney::format($summary->income['medicines']), 'Less refunds');
        }

        return $stats;
    }

    protected function note(): ?string
    {
        return $this->summary()->income === null ? null : self::MONEY_NOTE;
    }

    protected function charts(): array
    {
        $summary = $this->summary();
        $ages = ['Child' => $summary->ages['child'], 'Adult' => $summary->ages['adult'], 'Senior' => $summary->ages['senior']];

        if ($summary->ages['unknown'] > 0) {
            $ages['Not known'] = $summary->ages['unknown'];
        }

        return [
            [
                'name' => 'visits',
                'columns' => 2,
                'heading' => 'Visits over time',
                'description' => ['hour' => 'Per hour', 'day' => 'Per day', 'month' => 'Per month'][$summary->unit],
                'labels' => array_column($summary->timeline, 'label'),
                'series' => [
                    ['label' => 'OPD', 'color' => ChartColors::SERVICES, 'data' => array_column($summary->timeline, 'opd')],
                    ['label' => 'Pharmacy', 'color' => ChartColors::GOODS, 'data' => array_column($summary->timeline, 'pharmacy')],
                ],
            ],
            [
                'name' => 'ages',
                'columns' => 1,
                'heading' => 'Age groups',
                'description' => 'By age at the latest visit',
                'labels' => $summary->patients === 0 ? [] : array_keys($ages),
                'series' => [['label' => 'Patients', 'color' => ChartColors::NEUTRAL, 'data' => array_values($ages)]],
            ],
        ];
    }

    private function byShift(): bool
    {
        return filled($this->tableFilters['shift']['value'] ?? null);
    }
}
