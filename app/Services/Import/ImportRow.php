<?php

namespace App\Services\Import;

use App\Exceptions\PosException;
use App\Support\BatchExpiry;
use App\Support\UnitCost;
use Carbon\CarbonImmutable;

/**
 * One data row of an import file: its row number in the sheet, the cells as
 * read, and the value of each mapped field. The definitions read values
 * through the helpers below, which turn a bad cell into an error on the row
 * (and return null) instead of throwing, so the preview lists every problem
 * of every row at once. A row with any error is never written.
 */
final class ImportRow
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const SKIP = 'skip';

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** An expiry date cell on the 1st of a month was read as that month (expiry()). */
    public bool $expiryReadAsMonth = false;

    /** create, update or skip; null until checked */
    public ?string $action = null;

    /** @var array<string, mixed> what the definition worked out for the commit */
    public array $data = [];

    /**
     * @param  int  $number  the row's number in the sheet (the heading row is usually 1)
     * @param  list<mixed>  $cells  as read, for the error report
     * @param  array<string, mixed>  $values  field key => cell value (mapped fields only)
     * @param  array<string, string>  $labels  field key => heading, for messages
     */
    public function __construct(
        public readonly int $number,
        public readonly array $cells,
        public readonly array $values,
        private readonly array $labels = [],
    ) {}

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function skip(string $why): void
    {
        $this->action = self::SKIP;
        $this->warn($why);
    }

    /**
     * Write this row: a service refusing it (a rule the check could not
     * see) stops the whole import, saying which row.
     *
     * @template T
     *
     * @param  \Closure(): T  $write
     * @return T
     */
    public function write(\Closure $write): mixed
    {
        try {
            return $write();
        } catch (PosException $e) {
            throw new PosException("Row {$this->number}: {$e->getMessage()}", 0, $e);
        }
    }

    public function label(string $key): string
    {
        return $this->labels[$key] ?? $key;
    }

    public function raw(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function filled(string $key): bool
    {
        return self::stringOf($this->raw($key)) !== '';
    }

    /** Whether this field was mapped to a column at all (a blank cell still counts). */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** Text with spaces tidied: "  Panadol   500 mg " is "Panadol 500 mg". */
    public function text(string $key, int $max = 255, bool $required = false): ?string
    {
        $value = (string) preg_replace('/\s+/u', ' ', self::stringOf($this->raw($key)));

        if ($value === '') {
            return $this->missing($key, $required);
        }

        if (mb_strlen($value) > $max) {
            $this->error("{$this->label($key)} is too long (at most {$max} characters).");

            return null;
        }

        return $value;
    }

    /** A barcode, SKU or number as typed: no spaces, and a number read from Excel keeps all its digits. */
    public function code(string $key, int $max = 100, bool $required = false): ?string
    {
        $value = (string) preg_replace('/\s+/u', '', self::stringOf($this->raw($key)));

        if ($value === '') {
            return $this->missing($key, $required);
        }

        if (mb_strlen($value) > $max) {
            $this->error("{$this->label($key)} is too long (at most {$max} characters).");

            return null;
        }

        return $value;
    }

    /**
     * A plain number ("1,250.5" is fine) as a normalized string, with at
     * most $decimals places.
     *
     * @return numeric-string|null
     */
    public function decimal(string $key, bool $required = false, bool $positive = false, int $decimals = 3): ?string
    {
        $value = self::numberString($this->raw($key));

        if ($value === '') {
            return $this->missing($key, $required);
        }

        if (! is_numeric($value) || ! preg_match('/^-?\d+(\.(\d+))?$/', $value, $m)) {
            $this->error("{$this->label($key)}: \"{$this->shown($key)}\" is not a number.");

            return null;
        }

        if (strlen($m[2] ?? '') > $decimals) {
            $this->error("{$this->label($key)}: {$value} has more than {$decimals} decimal places.");

            return null;
        }

        if ($positive ? (float) $value <= 0 : (float) $value < 0) {
            $this->error("{$this->label($key)} must be ".($positive ? 'more than zero' : 'zero or more').'.');

            return null;
        }

        return $value;
    }

    public function integer(string $key, int $min, int $max, bool $required = false): ?int
    {
        $value = $this->decimal($key, $required, decimals: 0);

        if ($value === null) {
            return null;
        }

        if ((int) $value < $min || (int) $value > $max) {
            $this->error("{$this->label($key)} must be a whole number from {$min} to {$max}.");

            return null;
        }

        return (int) $value;
    }

    /** An amount of money in the shop's currency ("1,250.50", "Rs. 120") as minor units. */
    public function money(string $key, int $currencyDecimals, bool $required = false, bool $positive = false): ?int
    {
        $value = $this->decimal($key, $required, $positive, $currencyDecimals);

        return $value === null ? null : (int) bcmul($value, bcpow('10', (string) $currencyDecimals), 0);
    }

    /**
     * A cost, which may be finer than the currency (a tablet at 3.3333), as
     * minor units with 4 places (docs/PHARMACY.md Q9).
     *
     * @return numeric-string|null
     */
    public function cost(string $key, int $currencyDecimals, bool $required = false): ?string
    {
        $value = $this->decimal($key, $required, decimals: $currencyDecimals + UnitCost::SCALE);

        return $value === null ? null : UnitCost::normalize(bcmul($value, bcpow('10', (string) $currencyDecimals), UnitCost::SCALE));
    }

    /** Yes / No (Y, N, True, False, 1, 0); blank is null. */
    public function yesNo(string $key): ?bool
    {
        $value = mb_strtolower(self::stringOf($this->raw($key)));

        return match (true) {
            $value === '' => null,
            in_array($value, ['yes', 'y', 'true', '1', 'on'], true) => true,
            in_array($value, ['no', 'n', 'false', '0', 'off'], true) => false,
            default => $this->invalid($key, 'Yes or No'),
        };
    }

    /**
     * One of a fixed list. $choices maps what may be typed (any case and
     * spacing) to the stored value; $shown is what the message offers.
     *
     * @param  array<string, string>  $choices  normalized text => value
     */
    public function choice(string $key, array $choices, string $shown, bool $required = false): ?string
    {
        $value = self::stringOf($this->raw($key));

        if ($value === '') {
            return $this->missing($key, $required);
        }

        return $choices[ImportField::normalizeHeading($value)] ?? $this->invalid($key, $shown);
    }

    /**
     * A calendar date: a date cell, or typed as 15/10/2026, 15-10-2026,
     * 15.10.2026 or 2026-10-15 (day first, as in Sri Lanka).
     */
    public function date(string $key, bool $required = false): ?CarbonImmutable
    {
        $raw = $this->raw($key);

        if ($raw instanceof \DateTimeInterface) {
            return CarbonImmutable::parse($raw->format('Y-m-d'))->startOfDay();
        }

        $value = self::stringOf($raw);

        if ($value === '') {
            return $this->missing($key, $required);
        }

        $date = match (true) {
            (bool) preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) => [(int) $m[1], (int) $m[2], (int) $m[3]],
            (bool) preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $value, $m) => [(int) $m[3], (int) $m[2], (int) $m[1]],
            default => null,
        };

        if ($date === null || ! checkdate($date[1], $date[2], $date[0])) {
            return $this->invalid($key, 'a date such as 15/10/2026');
        }

        return CarbonImmutable::create($date[0], $date[1], $date[2])->startOfDay();
    }

    /**
     * An expiry as printed on the pack: a month (03/2027, 3/27, 2027-03:
     * the month's last day) or a full date (BatchExpiry), kept as typed when
     * it is text. A date cell on the 1st of a month is most likely a month
     * Excel turned into a date when "03/2027" was typed into a cell not
     * formatted as text, so it is read as that month's end, with a warning
     * on the row: the pack may really show the 1st.
     */
    public function expiry(string $key, bool $required = false): ?CarbonImmutable
    {
        $raw = $this->raw($key);

        if ($raw instanceof \DateTimeInterface) {
            $day = CarbonImmutable::parse($raw->format('Y-m-d'))->startOfDay();

            if ($day->day !== 1) {
                return $day;
            }

            $end = $day->endOfMonth()->startOfDay();
            $this->expiryReadAsMonth = true;
            $this->warn('Expiry read as month '.$day->format('m/Y').' → '.$end->format('d/m/Y').' — confirm, or type the full date as text if the pack shows a day.');

            return $end;
        }

        $value = self::stringOf($raw);

        if ($value === '') {
            return $this->missing($key, $required);
        }

        // 2027.03 and 2027/03 read as 2027-03; 03-2027 and 31.03.2027 as 03/2027 and 31/03/2027.
        $value = preg_match('/^\d{4}[\/.\-]/', $value) === 1 ? str_replace(['/', '.'], '-', $value) : str_replace(['-', '.'], '/', $value);

        try {
            return BatchExpiry::parse($value);
        } catch (PosException) {
            return $this->invalid($key, 'the month or date on the pack, such as 03/2027');
        }
    }

    /** What a cell holds, as text: whole numbers without ".0", dates as 2026-10-15. */
    public static function stringOf(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? 'Yes' : 'No',
            is_float($value) => self::floatString($value),
            is_int($value) => (string) $value,
            is_scalar($value) => trim((string) $value),
            default => '',
        };
    }

    /** 8901234567890.0 is "8901234567890"; 12.5 is "12.5". */
    private static function floatString(float $value): string
    {
        if (floor($value) === $value && abs($value) < 1e15) {
            return sprintf('%.0f', $value);
        }

        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    /** A number as typed, without thousands separators, spaces or a currency prefix (LKR, Rs.). */
    private static function numberString(mixed $value): string
    {
        $text = self::stringOf($value);

        return (string) preg_replace('/^(lkr|rs\.?|usd|\$)\s*/i', '', str_replace([',', ' ', "\u{00A0}"], '', $text));
    }

    private function shown(string $key): string
    {
        return mb_strimwidth(self::stringOf($this->raw($key)), 0, 40, '…');
    }

    private function missing(string $key, bool $required): null
    {
        if ($required) {
            $this->error("{$this->label($key)} is missing.");
        }

        return null;
    }

    private function invalid(string $key, string $expected): null
    {
        $this->error("{$this->label($key)}: \"{$this->shown($key)}\" is not {$expected}.");

        return null;
    }
}
