<?php

namespace App\Services\Receipts;

use App\Support\Money;
use Illuminate\Support\Str;

/**
 * The thermal receipt as lines of fixed-width text (docs/PHARMACY.md §9,
 * branch 9): 48 characters on 80 mm paper, 32 on 58 mm. The same lines go
 * to an ESC/POS printer (EscPos::encode()) and to the browser, which prints
 * them in a monospaced font at the paper's width, so both look the same.
 *
 * It works on the plain receipt payloads of ReceiptService (sale(), refund(),
 * voidSlip()), which are built from the sale's own rows: nothing here reads
 * a product, so a reprint prints what was sold. What to show under each
 * medicine (batch, expiry, prescriber) and the paper width come from
 * $shop['receipt'], read when printing.
 *
 * Offline receipts are laid out on the till by resources/js/lib/receipt/
 * layout.ts, a mirror of sale(): change both together
 * (ReceiptLayoutParityTest compares them).
 *
 * Text is folded to ASCII: thermal printers' code pages have no "’".
 * Amounts are always right-aligned in the last columns; with tax on, each
 * amount keeps two more columns for the " E" (tax exempt) marker.
 */
final class ReceiptLayout
{
    public const COLUMNS = [80 => 48, 58 => 32];

    private int $width;

    private bool $showBatch;

    private bool $showExpiry;

    private bool $showPrescriber;

    private int $decimals;

    /** Tax on for the document being laid out: amounts keep two columns for " E". */
    private bool $taxOn = false;

    /** @var list<array{text: string, bold: bool, tall: bool}> */
    private array $lines = [];

    /**
     * @param  array<string, mixed>  $shop  ReceiptService::shop()
     */
    public function __construct(private readonly array $shop)
    {
        /** @var array<string, mixed> $options */
        $options = $shop['receipt'] ?? [];
        $this->width = self::columns((int) ($options['paperWidth'] ?? 80));
        $this->showBatch = (bool) ($options['showBatch'] ?? true);
        $this->showExpiry = (bool) ($options['showExpiry'] ?? true);
        $this->showPrescriber = (bool) ($options['showPrescriber'] ?? false);
        $this->decimals = (int) ($shop['decimals'] ?? 2);
    }

    /** Characters a line for a paper width in mm (80 unless 58). */
    public static function columns(int $paperWidth): int
    {
        return self::COLUMNS[$paperWidth] ?? self::COLUMNS[80];
    }

    /**
     * A sale receipt: an itemised bill, or an OPD bill (the services, then
     * one Medicines amount with the medicines by name or not at all). A
     * reprint is marked REPRINT at the top and says when and by whom at the
     * bottom; a voided sale says VOIDED.
     *
     * @param  array<string, mixed>  $sale  ReceiptService::sale()
     * @return list<array{text: string, bold: bool, tall: bool}>
     */
    public function sale(array $sale): array
    {
        $this->start($sale);

        if (($sale['reprint'] ?? null) !== null) {
            $this->center('*** REPRINT ***', bold: true, tall: true);
        }

        $this->header();

        if (($sale['status'] ?? null) === 'voided') {
            $this->center('*** VOIDED ***', bold: true, tall: true);
        }

        $this->billDetails($sale, [
            ['Bill no.', (string) $sale['invoiceNo']],
            ['Date', self::date((string) $sale['soldAt'])],
        ]);
        $this->rule();
        $this->items($sale, fn (array $item): ?int => $item['lineTotal'] ?? null, withReturns: true);
        $this->rule();
        $this->totals($sale);
        $this->rule();
        $this->payments($sale);
        $this->rows([
            ['Change', $this->money((int) $sale['changeDue'])],
            ...((int) ($sale['refundedTotal'] ?? 0) > 0 ? [['Refunded', $this->money(-(int) $sale['refundedTotal'])]] : []),
        ]);
        $this->footer();
        $this->reprinted($sale['reprint'] ?? null);

        return $this->lines;
    }

    /**
     * A refund slip: the lines that came back, what each refunds (an OPD
     * bill's medicines as one Medicines amount, as on the bill), the refund
     * total and how it was paid back.
     *
     * @param  array<string, mixed>  $refund  ReceiptService::refund()
     * @return list<array{text: string, bold: bool, tall: bool}>
     */
    public function refund(array $refund): array
    {
        /** @var array<string, mixed> $sale */
        $sale = $refund['sale'];
        $this->start($sale);

        if (($refund['reprint'] ?? null) !== null) {
            $this->center('*** REPRINT ***', bold: true, tall: true);
        }

        $this->header();
        $this->center('*** REFUND ***', bold: true, tall: true);
        $this->billDetails($sale, [
            ['Refund no.', (string) $refund['returnNo']],
            ['Date', self::date((string) $refund['returnedAt'])],
            ['Bill no.', (string) $sale['invoiceNo']],
            ['Bill date', self::date((string) $sale['soldAt'])],
        ], cashier: ['Refunded by', (string) $refund['user']]);
        $this->rule();
        $this->items([...$sale, 'items' => $refund['items'], 'medicinesTotal' => $refund['medicinesRefund']], fn (array $item): ?int => $item['refund'] ?? null, withReturns: false);
        $this->rule();
        $this->row('REFUND TOTAL '.$this->shop['currency'], $this->money((int) $refund['refundTotal']), bold: true);
        $this->rule();
        $this->payments(['payments' => [['method' => $refund['method'], 'amount' => (int) $refund['refundTotal'], 'reference' => $refund['reference'] ?? null]]]);
        $this->text('Reason: '.$refund['reason']);

        if (filled($refund['note'] ?? null)) {
            $this->text('Note: '.$refund['note']);
        }

        $this->footer();
        $this->reprinted($refund['reprint'] ?? null);

        return $this->lines;
    }

    /**
     * A void slip: the bill as it was rung up, marked VOID, with when, by
     * whom and why, and the amount to give back.
     *
     * @param  array<string, mixed>  $sale  ReceiptService::sale() with 'void' => {voidedAt, voidedBy, reason}
     * @return list<array{text: string, bold: bool, tall: bool}>
     */
    public function voidSlip(array $sale): array
    {
        $this->start($sale);
        /** @var array<string, mixed> $void */
        $void = $sale['void'];

        if (($sale['reprint'] ?? null) !== null) {
            $this->center('*** REPRINT ***', bold: true, tall: true);
        }

        $this->header();
        $this->center('*** VOID ***', bold: true, tall: true);
        $this->billDetails($sale, [
            ['Bill no.', (string) $sale['invoiceNo']],
            ['Bill date', self::date((string) $sale['soldAt'])],
            ['Voided', self::date((string) $void['voidedAt'])],
            ['Voided by', (string) $void['voidedBy']],
        ]);
        $this->rule();
        $this->items($sale, fn (array $item): ?int => $item['lineTotal'] ?? null, withReturns: false);
        $this->rule();
        $this->totals($sale, totalLabel: 'TOTAL VOIDED');
        $this->rule();
        $this->text('Paid');
        $this->payments($sale);
        $this->rows([['Change', $this->money((int) $sale['changeDue'])]]);
        $this->row('TO GIVE BACK '.$this->shop['currency'], $this->money((int) $sale['grandTotal']), bold: true);
        $this->text('Reason: '.$void['reason']);
        $this->footer();
        $this->reprinted($sale['reprint'] ?? null);

        return $this->lines;
    }

    /** "2026-10-21 14:05" as printed: "21/10/2026 14:05". */
    public static function date(string $value): string
    {
        return (string) preg_replace('/^(\d{4})-(\d{2})-(\d{2})/', '$3/$2/$1', $value);
    }

    /**
     * @param  array<string, mixed>  $sale
     */
    private function start(array $sale): void
    {
        $this->lines = [];
        $this->taxOn = (float) ($sale['taxRate'] ?? 0) > 0;
    }

    private function header(): void
    {
        $this->center(mb_strtoupper((string) $this->shop['name']), bold: true, tall: true);

        foreach ([
            $this->shop['address'] ?? null,
            filled($this->shop['phone'] ?? null) ? 'Tel '.$this->shop['phone'] : null,
            filled($this->shop['taxNumber'] ?? null) ? 'Tax no. '.$this->shop['taxNumber'] : null,
            ...preg_split('/\R/', (string) ($this->shop['header'] ?? '')) ?: [],
        ] as $text) {
            if (filled($text)) {
                $this->center((string) $text);
            }
        }

        $this->rule('=');
    }

    /**
     * The bill's numbers, then the till, its location and the cashier.
     *
     * @param  array<string, mixed>  $sale
     * @param  list<array{0: string, 1: string}>  $rows
     * @param  array{0: string, 1: string}|null  $cashier
     */
    private function billDetails(array $sale, array $rows, ?array $cashier = null): void
    {
        /** @var array{code: string, name?: string|null, location?: string|null}|null $till */
        $till = $sale['till'] ?? null;

        if ($till !== null) {
            $name = trim((string) ($till['name'] ?? ''));
            $rows[] = ['Till', trim($till['code'].($name !== '' && $name !== $till['code'] ? ' '.$name : ''))];

            if (filled($till['location'] ?? null)) {
                $rows[] = ['Location', (string) $till['location']];
            }
        }

        $rows[] = ['Cashier', (string) $sale['cashier']];

        if ($cashier !== null) {
            $rows[] = $cashier;
        }

        foreach ($rows as [$label, $value]) {
            $this->row($label, $value, amount: false);
        }
    }

    /**
     * The lines of a bill. An OPD bill prints its services, then one
     * Medicines line with the medicines under it by name ('names') or not at
     * all ('totals'). Lines from several locations are grouped by location.
     *
     * @param  array<string, mixed>  $sale
     * @param  callable(array<string, mixed>): ?int  $amount  what a line prints on the right
     */
    private function items(array $sale, callable $amount, bool $withReturns): void
    {
        /** @var list<array<string, mixed>> $items */
        $items = $sale['items'];
        $opd = ($sale['billType'] ?? 'standard') === 'opd';
        $services = $opd ? array_values(array_filter($items, fn (array $item): bool => ! ($item['isOpdMedicine'] ?? false))) : $items;
        $medicines = $opd ? array_values(array_filter($items, fn (array $item): bool => (bool) ($item['isOpdMedicine'] ?? false))) : [];
        $locations = array_values(array_unique(array_filter(array_map(fn (array $item): ?string => $item['location'] ?? null, $services))));
        $grouped = count($locations) > 1;
        $location = null;

        foreach ($services as $item) {
            if ($grouped && ($item['location'] ?? null) !== $location) {
                $location = $item['location'] ?? null;
                $this->text('-- '.($location ?? 'Other').' --', bold: true);
            }

            $this->itemised($item, $amount($item), $withReturns);
        }

        if ($medicines === []) {
            return;
        }

        $this->row('Medicines', $this->money((int) ($sale['medicinesTotal'] ?? 0), exempt: false), bold: true);

        if (($sale['opdReceiptMedicines'] ?? 'names') === 'totals') {
            return;
        }

        foreach ($medicines as $item) {
            $this->text(self::itemName($item).($withReturns && $this->fullyReturned($item) ? ' (RETURNED)' : ''), indent: 2, hang: 2);
            $this->text($this->quantity((string) $item['quantity'], $item['unitName'] ?? null), indent: 4, hang: 2);
            $this->details($item, 4, $withReturns);
        }
    }

    /**
     * A priced line: its name, "2 Box x 180.00" with the line total, any
     * discount, then its batches, prescriber and what came back.
     *
     * @param  array<string, mixed>  $item
     */
    private function itemised(array $item, ?int $amount, bool $withReturns): void
    {
        $this->text(self::itemName($item).($withReturns && $this->fullyReturned($item) ? ' (RETURNED)' : ''), hang: 2);

        $quantity = $this->quantity((string) $item['quantity'], $item['unitName'] ?? null);
        $left = array_key_exists('unitPrice', $item) && $item['unitPrice'] !== null
            ? $quantity.' x '.Money::format((int) $item['unitPrice'], $this->decimals)
            : $quantity;

        $this->row($left, $amount === null ? '' : $this->money($amount, (bool) ($item['taxExempt'] ?? false)), indent: 2, hang: 2);

        if ((int) ($item['discount'] ?? 0) > 0) {
            $this->row('Discount', $this->money(-(int) $item['discount']), indent: 2);
        }

        $this->details($item, 2, $withReturns);
    }

    /**
     * Under a line: its batches (batch number and expiry, each as the shop
     * prints them), the prescriber and Rx reference, and a part return.
     *
     * @param  array<string, mixed>  $item
     */
    private function details(array $item, int $indent, bool $withReturns): void
    {
        /** @var list<array{batchNo: string|null, expiry: string|null, quantity: string}> $batches */
        $batches = $item['batches'] ?? [];

        foreach ($batches as $batch) {
            $parts = [];

            if ($this->showBatch) {
                $parts[] = $batch['batchNo'] === null ? 'Batch to be confirmed' : 'Batch '.$batch['batchNo'];
            }

            if ($this->showExpiry && $batch['expiry'] !== null) {
                $parts[] = 'Exp '.$batch['expiry'];
            }

            if ($parts !== [] && count($batches) > 1) {
                $parts[] = 'x '.$this->quantity($batch['quantity'], $item['baseUnitName'] ?? null);
            }

            if ($parts !== []) {
                $this->text(implode(' ', $parts), indent: $indent, hang: 2);
            }
        }

        if ($this->showPrescriber && filled($item['prescriber'] ?? null)) {
            $this->text('Prescriber: '.$item['prescriber'], indent: $indent, hang: 2);
        }

        if ($this->showPrescriber && filled($item['prescriptionRef'] ?? null)) {
            $this->text('Rx ref: '.$item['prescriptionRef'], indent: $indent, hang: 2);
        }

        if ($withReturns && filled($item['returnedQuantity'] ?? null) && ! $this->fullyReturned($item)) {
            $this->text('Returned '.$this->returned($item), indent: $indent, hang: 2);
        }
    }

    /**
     * Subtotal, discounts and tax (only when there are any: otherwise the
     * subtotal is the total), the total, and the tax-exempt key.
     *
     * @param  array<string, mixed>  $sale
     */
    private function totals(array $sale, string $totalLabel = 'TOTAL'): void
    {
        $discount = (int) $sale['discountTotal'];

        if ($discount > 0 || $this->taxOn) {
            $this->row('Subtotal', $this->money((int) $sale['subtotal']));
        }

        if ($discount > 0) {
            $this->row('Discount', $this->money(-$discount));
        }

        if ($this->taxOn) {
            $rate = rtrim(rtrim(number_format((float) $sale['taxRate'], 2, '.', ''), '0'), '.');
            $this->row('Tax '.$rate.'%'.(($sale['pricesIncludeTax'] ?? false) ? ' (incl.)' : ''), $this->money((int) $sale['taxTotal']));
        }

        $this->row($totalLabel.' '.$this->shop['currency'], $this->money((int) $sale['grandTotal']), bold: true);

        /** @var list<array<string, mixed>> $items */
        $items = $sale['items'];

        if ($this->taxOn && array_filter($items, fn (array $item): bool => (bool) ($item['taxExempt'] ?? false)) !== []) {
            $this->text('E = tax exempt');
        }
    }

    /**
     * @param  array<string, mixed>  $sale
     */
    private function payments(array $sale): void
    {
        /** @var list<array{method: string, amount: int, reference?: string|null}> $payments */
        $payments = $sale['payments'];

        foreach ($payments as $payment) {
            $this->row((string) $payment['method'], $this->money((int) $payment['amount']));

            if (filled($payment['reference'] ?? null)) {
                $this->text('Ref '.$payment['reference'], indent: 2, hang: 2);
            }
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows
     */
    private function rows(array $rows): void
    {
        foreach ($rows as [$label, $value]) {
            $this->row($label, $value);
        }
    }

    private function footer(): void
    {
        $footer = trim((string) ($this->shop['footer'] ?? ''));

        if ($footer === '') {
            return;
        }

        $this->rule();

        foreach (preg_split('/\R/', $footer) ?: [] as $text) {
            $this->center($text);
        }
    }

    /**
     * @param  array{printedAt: string|null, printedBy: string}|null  $reprint
     */
    private function reprinted(?array $reprint): void
    {
        if ($reprint === null) {
            return;
        }

        $this->rule('=');
        $this->center('REPRINT '.self::date((string) $reprint['printedAt']), bold: true);
        $this->center('by '.$reprint['printedBy']);
    }

    /**
     * "Panadol 500 mg Tablets": the line's description without the pack it
     * was sold in (that is printed with the quantity), and its strength when
     * the name does not already say it.
     *
     * @param  array<string, mixed>  $item
     */
    public static function itemName(array $item): string
    {
        $name = (string) $item['description'];
        $unit = (string) ($item['packName'] ?? $item['unitName'] ?? '');
        $suffix = ' ('.$unit.')';

        if ($unit !== '' && str_ends_with($name, $suffix)) {
            $name = substr($name, 0, -strlen($suffix));
        }

        $strength = trim((string) ($item['strength'] ?? ''));
        $squash = fn (string $text): string => strtolower((string) preg_replace('/\s+/', '', $text));

        if ($strength !== '' && ! str_contains($squash($name), $squash($strength))) {
            $name .= ' '.$strength;
        }

        return $name;
    }

    private function quantity(string $quantity, ?string $unit): string
    {
        return filled($unit) ? $quantity.' '.$unit : $quantity;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function fullyReturned(array $item): bool
    {
        return filled($item['returnedQuantity'] ?? null) && (float) $item['returnedQuantity'] >= (float) $item['quantity'];
    }

    /**
     * What came back of a line: in the unit it was sold in when that is a
     * whole number of them ("1 Box"), else in base units ("10 Tablet").
     *
     * @param  array<string, mixed>  $item
     */
    private function returned(array $item): string
    {
        $returned = (string) $item['returnedQuantity'];

        if (filled($item['returnedBaseQuantity'] ?? null) && ! preg_match('/^\d+$/', $returned)) {
            return $this->quantity((string) $item['returnedBaseQuantity'], $item['baseUnitName'] ?? null);
        }

        return $this->quantity($returned, $item['unitName'] ?? null);
    }

    /** An amount for the right-hand column; with tax on, followed by " E" (exempt) or two spaces. */
    private function money(int $minor, bool $exempt = false): string
    {
        $text = ($minor < 0 ? '-' : '').Money::format(abs($minor), $this->decimals);

        return $this->taxOn ? $text.($exempt ? ' E' : '  ') : $text;
    }

    private function rule(string $character = '-'): void
    {
        $this->push(str_repeat($character, $this->width));
    }

    private function center(string $text, bool $bold = false, bool $tall = false): void
    {
        foreach ($this->wrap(self::ascii($text), $this->width) as $line) {
            $line = trim($line);
            $this->push(str_repeat(' ', intdiv($this->width - strlen($line), 2)).$line, $bold, $tall);
        }
    }

    private function text(string $text, int $indent = 0, int $hang = 0, bool $bold = false): void
    {
        $this->row($text, '', $indent, $hang, $bold, amount: false);
    }

    /**
     * $left wrapped at the paper's width (continuation lines indented by
     * $hang more), with $right on the last line, right-aligned. When the last
     * line has no room for it, its last words move down until it does.
     */
    private function row(string $left, string $right = '', int $indent = 0, int $hang = 0, bool $bold = false, bool $amount = true): void
    {
        $left = self::ascii($left);
        $right = self::ascii($right);

        // A value (not an amount) that is too long for one line goes under its label.
        if (! $amount && $right !== '' && strlen($left) + 1 + strlen($right) > $this->width) {
            $this->row($left, '', $indent, $hang, $bold, false);
            $this->row('', $right, $indent, $hang, $bold, false);

            return;
        }

        $lines = $this->wrap($left, $this->width, $indent, $indent + $hang);

        if ($right === '') {
            foreach ($lines as $line) {
                $this->push($line, $bold);
            }

            return;
        }

        $last = array_pop($lines) ?? str_repeat(' ', $indent);

        if (strlen($last) + 1 + strlen($right) > $this->width) {
            $lastIndent = $lines === [] ? $indent : $indent + $hang;
            $more = $this->wrap(ltrim($last), max(1, $this->width - strlen($right) - 1), $lastIndent, $indent + $hang);
            $last = (string) array_pop($more);
            array_push($lines, ...$more);

            if (strlen($last) + 1 + strlen($right) > $this->width) {
                $lines[] = $last;
                $last = '';
            }
        }

        foreach ($lines as $line) {
            $this->push($line, $bold);
        }

        $this->push(str_pad($last, $this->width - strlen($right)).$right, $bold);
    }

    /**
     * Words filled into lines of $width characters, the first indented by
     * $indent and the rest by $hang; a word longer than a line is cut.
     *
     * @return list<string>
     */
    private function wrap(string $text, int $width, int $indent = 0, int $hang = 0): array
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $line = '';
        $pad = str_repeat(' ', $indent);

        foreach ($words as $word) {
            $room = $width - strlen($pad);

            if ($line !== '' && strlen($line) + 1 + strlen($word) <= $room) {
                $line .= ' '.$word;

                continue;
            }

            if ($line !== '') {
                $lines[] = $pad.$line;
                $pad = str_repeat(' ', $hang);
                $room = $width - strlen($pad);
            }

            while (strlen($word) > max(1, $room)) {
                $lines[] = $pad.substr($word, 0, max(1, $room));
                $word = substr($word, max(1, $room));
                $pad = str_repeat(' ', $hang);
                $room = $width - strlen($pad);
            }

            $line = $word;
        }

        if ($line !== '') {
            $lines[] = $pad.$line;
        }

        return $lines;
    }

    private function push(string $text, bool $bold = false, bool $tall = false): void
    {
        $this->lines[] = ['text' => rtrim($text), 'bold' => $bold, 'tall' => $tall];
    }

    /** Printable ASCII only: thermal printers' code pages have no curly quotes or accents. */
    public static function ascii(string $text): string
    {
        $text = Str::ascii(str_replace(["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}"], ["'", "'", '"', '"', '-', '-'], $text));

        return (string) preg_replace('/[^\x20-\x7E]/', '?', str_replace(["\t", "\r", "\n"], ' ', $text));
    }
}
