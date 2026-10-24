<?php

namespace App\Services\Import;

use App\Enums\ImportType;
use App\Models\Shop;

/**
 * One kind of import (items, suppliers, opening stock, opening invoices):
 * its columns, its template, how a row is checked (the dry run: errors,
 * warnings and whether it creates, updates or skips) and how the checked
 * rows are written. check() never writes; commit() writes only through the
 * services (ProductCatalogService, StockService, SupplierService) and runs
 * inside the import's one transaction (ImportService::run()).
 */
interface ImportDefinition
{
    public function type(): ImportType;

    /** The template's data sheet. */
    public function sheetName(Shop $shop): string;

    /** @return list<ImportField> */
    public function fields(Shop $shop): array;

    /** @return list<string> lines for the template's Instructions sheet */
    public function instructions(Shop $shop): array;

    /** @return list<array<string, string|int|float|null>> the template's three example rows, field key => value */
    public function examples(Shop $shop): array;

    /**
     * Check every row (all of them together, so duplicates within the file
     * are found): errors, warnings, action and the data commit() needs.
     *
     * @param  list<ImportRow>  $rows
     */
    public function check(array $rows, ImportContext $context): void;

    /**
     * Write the rows that create or update. Rows with errors never reach it.
     *
     * @param  list<ImportRow>  $rows
     * @param  \Closure(int): void  $progress  called with the number of rows done
     */
    public function commit(array $rows, ImportContext $context, \Closure $progress): void;
}
