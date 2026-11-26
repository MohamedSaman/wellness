<?php

namespace App\Services\Data;

/**
 * The figures above the Patients list (docs/PHARMACY.md, branch 20), for
 * the bills behind the list as filtered and searched. Everything is a count
 * except the two money figures (minor units).
 */
final readonly class PatientSummary
{
    /**
     * @param  string  $title  what the figures cover: "Today · Doctor's Medicines · Shift T2 08:02"
     * @param  int  $patients  patients with at least one matching bill
     * @param  int  $visits  the matching bills
     * @param  int|null  $new  patients whose first visit ever is in the period or shift; null without one
     * @param  int|null  $returning  patients who had visited before it; null without one
     * @param  list<array{location: string, visits: int}>  $locations  in the shop's order, those with visits
     * @param  array{child: int, adult: int, senior: int, unknown: int}  $ages  patients by their age at their latest matching visit
     * @param  'hour'|'day'|'month'  $unit  what one bar of the chart is
     * @param  list<array{bucket: string, label: string, opd: int, pharmacy: int}>  $timeline
     * @param  array{services: int, medicines: int}|null  $income  net of refunds on these bills; null without reports.view
     */
    public function __construct(
        public string $title,
        public int $patients,
        public int $visits,
        public ?int $new,
        public ?int $returning,
        public int $opd,
        public int $pharmacy,
        public array $locations,
        public array $ages,
        public string $unit,
        public array $timeline,
        public ?array $income,
    ) {}

    /** Visits per patient, to one place; null without patients. */
    public function average(): ?float
    {
        return $this->patients === 0 ? null : round($this->visits / $this->patients, 1);
    }
}
