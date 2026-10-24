<?php

namespace App\Services\Reports;

/**
 * Sale lines (or return lines) of a period added up for one item, stock
 * pool, counter, kind of bill and drug schedule: what ReportService builds
 * every sales and profit grouping from (docs/PHARMACY.md §9, branch 11).
 * Named like the columns it comes from. Money in minor units; revenue
 * (before tax) and cost may have a fraction (a refund's share, a part pack).
 */
final readonly class SalesFact
{
    public function __construct(
        public int $variant_id,
        public int $line_location,     // sale_items.location_id: the stock pool
        public int $bill_location,     // sales.location_id: the counter
        public string $bill_type,
        public string $schedule,       // the line's drug schedule, '' when it is not a medicine
        public float $quantity,        // in the unit sold (or returned)
        public float $base_quantity,
        public int $gross,             // line totals, or refunds
        public float $revenue,         // before tax
        public float $cost,            // the lines' own cost; for returns, what went back on the shelf
    ) {}

    public static function fromRow(\stdClass $row): self
    {
        return new self(
            (int) $row->variant_id,
            (int) $row->line_location,
            (int) $row->bill_location,
            (string) $row->bill_type,
            (string) $row->schedule,
            (float) $row->quantity,
            (float) $row->base_quantity,
            (int) $row->gross,
            (float) $row->revenue,
            (float) $row->cost,
        );
    }
}
