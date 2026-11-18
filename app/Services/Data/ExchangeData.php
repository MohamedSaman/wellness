<?php

namespace App\Services\Data;

use App\Enums\ReturnReason;
use App\Models\Device;
use App\Models\Sale;
use App\Models\User;

/**
 * Everything the till sends for one return or exchange
 * (ExchangeService::create(), docs/PHARMACY.md branch 19): the bill (null:
 * no bill, the owner's return without a receipt) and its lines coming back,
 * each saying where its stock goes; or, without a bill, the items as
 * NoReceiptLine; the reason, note and doctor; and the new items, priced by
 * the server as any cart, with what the patient pays on top of the credit.
 * No new lines: a plain return.
 */
final readonly class ExchangeData
{
    /**
     * @param  list<ReturnLine>  $returnLines  lines of $original, each with its toLocationId
     * @param  list<NoReceiptLine>  $noReceiptLines  without a bill
     * @param  list<CartLine>  $newLines
     * @param  list<PaymentInput>  $payments  the patient's, for what the new bill costs above the credit
     */
    public function __construct(
        public Device $till,
        public User $user,
        public string $uuid,
        public ?Sale $original,
        public array $returnLines,
        public array $noReceiptLines,
        public ReturnReason $reason,
        public ?string $note,
        public ?int $doctorId,
        public array $newLines = [],
        public int $saleDiscount = 0,
        public array $payments = [],
        // Used when the bill has no patient, or there is no bill; the bill's patient wins.
        public ?PatientInput $patient = null,
    ) {}
}
