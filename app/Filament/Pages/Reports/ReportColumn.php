<?php

namespace App\Filament\Pages\Reports;

/** One column of a report, shared by the on-screen table and the CSV export. */
final readonly class ReportColumn
{
    public const TEXT = 'text';

    public const MONEY = 'money';

    public const INTEGER = 'integer';

    public const QUANTITY = 'quantity';

    public const PERCENT = 'percent';

    public function __construct(
        public string $key,
        public string $label,
        public string $type = self::TEXT,
        /** Shown only to users with reports.profit (cost, profit, margin). */
        public bool $profitOnly = false,
        /** Added up in the report's total row. */
        public bool $total = false,
        /** Shown only to users with cost.view (unit cost, stock value at cost). */
        public bool $costOnly = false,
        /** The user can show or hide it; hidden columns are left out of the CSV too. */
        public bool $toggleable = false,
        public bool $hiddenByDefault = false,
    ) {}

    /** The same column, but the user may hide it (or show it, if hidden by default). */
    public function toggleable(bool $hiddenByDefault = false): self
    {
        return new self($this->key, $this->label, $this->type, $this->profitOnly, $this->total, $this->costOnly, true, $hiddenByDefault);
    }

    /** The same column, only for users with cost.view. */
    public function costOnly(): self
    {
        return new self($this->key, $this->label, $this->type, $this->profitOnly, $this->total, true, $this->toggleable, $this->hiddenByDefault);
    }

    public static function text(string $key, string $label): self
    {
        return new self($key, $label);
    }

    public static function money(string $key, string $label, bool $total = true, bool $profitOnly = false): self
    {
        return new self($key, $label, self::MONEY, $profitOnly, $total);
    }

    public static function integer(string $key, string $label, bool $total = true): self
    {
        return new self($key, $label, self::INTEGER, total: $total);
    }

    public static function quantity(string $key, string $label, bool $total = true): self
    {
        return new self($key, $label, self::QUANTITY, total: $total);
    }

    public static function percent(string $key, string $label, bool $profitOnly = false): self
    {
        return new self($key, $label, self::PERCENT, $profitOnly);
    }

    public function isNumeric(): bool
    {
        return $this->type !== self::TEXT;
    }
}
