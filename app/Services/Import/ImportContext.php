<?php

namespace App\Services\Import;

use App\Enums\ImportExisting;
use App\Models\ImportRun;
use App\Models\Shop;
use App\Models\User;

/** Who is importing what, into which shop, and what to do with rows already on file. */
final readonly class ImportContext
{
    public function __construct(
        public Shop $shop,
        public User $user,
        public ImportExisting $existing,
        public ?ImportRun $run = null,
    ) {}

    public function currencyDecimals(): int
    {
        return (int) $this->shop->currency_decimals;
    }

    /** "Imported from stock-count.xlsx" for notes on what the import writes. */
    public function note(): string
    {
        return 'Imported from '.($this->run->file_name ?? 'a file');
    }
}
