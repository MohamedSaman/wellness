<?php

namespace App\Services\Data;

use App\Models\StockBatch;
use App\Models\StockMovement;

/**
 * What StockService::issue() took for one line: one piece per batch, each
 * with its own movement, in the order they were taken. When a proposal was
 * given (a pharmacist's pick, an offline till's plan), $differences says
 * where the result differs from it. $shortfall is what went to the
 * unallocated batch (offline oversell, or negative stock allowed).
 */
final readonly class BatchAllocation
{
    /**
     * @param  list<array{batch: StockBatch, quantity: float, movement: StockMovement}>  $pieces
     * @param  list<string>  $differences
     */
    public function __construct(
        public array $pieces,
        public float $shortfall = 0.0,
        public array $differences = [],
        public bool $expiredProposed = false,
    ) {}

    public function proposalChanged(): bool
    {
        return $this->differences !== [];
    }
}
