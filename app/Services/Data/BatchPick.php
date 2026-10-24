<?php

namespace App\Services\Data;

/**
 * A batch chosen for part of a sale line, in base units: the pharmacist's
 * pick at checkout (pos.batch_override), or an offline till's proposal.
 */
final readonly class BatchPick
{
    public function __construct(
        public int $batchId,
        public float $quantity,
    ) {}

    /**
     * @param  list<array{batch_id: int|string, quantity: int|float|string}>  $rows
     * @return list<self>
     */
    public static function fromArray(array $rows): array
    {
        return array_map(fn (array $row): self => new self((int) $row['batch_id'], (float) $row['quantity']), $rows);
    }

    /**
     * @param  list<self>  $picks
     * @return list<array{batch_id: int, quantity: float}>
     */
    public static function toArray(array $picks): array
    {
        return array_map(fn (self $pick): array => ['batch_id' => $pick->batchId, 'quantity' => $pick->quantity], $picks);
    }
}
