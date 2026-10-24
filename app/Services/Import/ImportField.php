<?php

namespace App\Services\Import;

/**
 * One column an import understands: its key (stable, saved in mappings),
 * the heading the template gives it, whether every row needs it, other
 * headings it is recognised by in a shop's own file, what to enter, and
 * for fixed values the list the template offers as an Excel dropdown.
 */
final readonly class ImportField
{
    /**
     * @param  list<string>  $aliases  other headings that mean this column ("Qty", "Exp")
     * @param  list<string>|null  $options  the values offered in the template's dropdown
     * @param  bool  $text  a code or date the template keeps as text (barcodes keep their leading zeros, "03/2027" stays a month)
     * @param  bool  $strictList  the dropdown refuses other values (false: a warning, e.g. supplier names)
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $required = false,
        public array $aliases = [],
        public string $help = '',
        public ?array $options = null,
        public bool $text = false,
        public float $width = 16,
        public bool $strictList = true,
    ) {}

    /** "Generic name(s)" and "generic names" are the same heading. */
    public static function normalizeHeading(string $heading): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($heading)));
    }

    /** @return list<string> normalized headings that match this field */
    public function matches(): array
    {
        return array_values(array_unique(array_map(
            fn (string $heading): string => self::normalizeHeading($heading),
            [$this->label, $this->key, ...$this->aliases],
        )));
    }
}
