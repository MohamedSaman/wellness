<?php

namespace App\Services\Data;

/** One batch to write off: the batch, and how much of it in base units (null = all it holds). */
final readonly class WriteOffLine
{
    public function __construct(
        public int $batchId,
        public ?float $quantity = null,
    ) {}
}
