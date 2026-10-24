<?php

namespace App\Services\Data;

use App\Enums\PaymentMethod;

final readonly class PaymentInput
{
    public function __construct(
        public PaymentMethod $method,
        public int $amount, // minor units tendered
        public ?string $reference = null,
    ) {}
}
