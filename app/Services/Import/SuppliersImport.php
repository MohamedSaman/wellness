<?php

namespace App\Services\Import;

use App\Enums\ImportExisting;
use App\Enums\ImportType;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Support\Str;

/**
 * Suppliers (suppliers module): every supplier field, payment terms
 * included. A supplier is matched by name, whatever its case or spacing
 * (names are unique per shop), and then skipped or updated; updating takes
 * only the cells that are filled in, so a blank never wipes a phone number.
 * Written through SupplierService (purchasing.manage, audited).
 */
final class SuppliersImport implements ImportDefinition
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function type(): ImportType
    {
        return ImportType::Suppliers;
    }

    public function sheetName(Shop $shop): string
    {
        return 'Suppliers';
    }

    public function fields(Shop $shop): array
    {
        return [
            new ImportField('name', 'Supplier name', true, ['Supplier', 'Name', 'Company', 'Vendor', 'Vendor name', 'Company name'], 'As it should appear on orders. A supplier already on file with this name is skipped or updated.', width: 30),
            new ImportField('contact_name', 'Contact person', aliases: ['Contact', 'Rep', 'Representative'], help: 'Optional.', width: 20),
            new ImportField('phone', 'Phone', aliases: ['Telephone', 'Mobile', 'Tel'], help: 'Optional.', text: true),
            new ImportField('email', 'Email', aliases: ['E-mail', 'Email address'], help: 'Optional.', width: 26),
            new ImportField('address', 'Address', help: 'Optional.', width: 34),
            new ImportField('tax_number', 'Tax / registration no.', aliases: ['VAT no', 'Tax no', 'TIN', 'Registration no', 'BR no'], help: 'Optional.', text: true, width: 20),
            new ImportField('payment_terms_days', 'Payment terms (days)', aliases: ['Terms', 'Credit days', 'Credit period', 'Payment terms'], help: 'Days of credit, 0 to 365. Invoices are due this many days after their date. Blank: due on the day.'),
            new ImportField('notes', 'Notes', aliases: ['Remarks', 'Comments', 'Note'], help: 'Optional.', width: 30),
            new ImportField('is_active', 'Active', aliases: ['Status'], help: 'Yes or No. Blank: Yes.', options: ['Yes', 'No'], width: 10),
        ];
    }

    public function instructions(Shop $shop): array
    {
        return [
            'One row per supplier. Only the supplier name is required.',
            'Import suppliers before their opening invoices: invoices find their supplier by name.',
            'A supplier already on file (same name, any capitals) is skipped, or updated if you choose "Update" on the upload page; only filled-in cells change it.',
            'The three grey rows are examples: delete them or leave them, they are never imported.',
        ];
    }

    public function examples(Shop $shop): array
    {
        return [
            ['name' => 'Example Pharma Distributors (Pvt) Ltd', 'contact_name' => 'N. Perera', 'phone' => '0112345678', 'email' => 'orders@example-pharma.lk', 'address' => '12 Main Street, Colombo 03', 'tax_number' => '114567890-7000', 'payment_terms_days' => 30, 'notes' => 'Delivers Tuesdays', 'is_active' => 'Yes'],
            ['name' => 'Example Medical Supplies', 'contact_name' => 'K. Silva', 'phone' => '0812233445', 'email' => null, 'address' => 'Peradeniya Road, Kandy', 'tax_number' => null, 'payment_terms_days' => 45, 'notes' => null, 'is_active' => 'Yes'],
            ['name' => 'Example Surgicals', 'contact_name' => null, 'phone' => '0771234567', 'email' => null, 'address' => null, 'tax_number' => null, 'payment_terms_days' => 0, 'notes' => 'Cash on delivery', 'is_active' => 'No'],
        ];
    }

    public function check(array $rows, ImportContext $context): void
    {
        $onFile = Supplier::query()->get()->keyBy(fn (Supplier $supplier): string => self::key($supplier->name));
        $seen = [];

        foreach ($rows as $row) {
            $name = $row->text('name', 150, required: true);
            $email = $row->text('email', 150);

            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $row->error("Email: \"{$email}\" is not an email address.");
            }

            $data = array_filter([
                'contact_name' => $row->text('contact_name', 150),
                'phone' => $row->text('phone', 50),
                'email' => $email,
                'address' => $row->text('address', 1000),
                'tax_number' => $row->text('tax_number', 50),
                'payment_terms_days' => $row->integer('payment_terms_days', 0, 365),
                'notes' => $row->text('notes', 2000),
                'is_active' => $row->yesNo('is_active'),
            ], fn (mixed $value): bool => $value !== null);

            if ($name === null) {
                continue;
            }

            $key = self::key($name);

            if (isset($seen[$key])) {
                $row->error("{$name} is also on row {$seen[$key]}: list each supplier once.");

                continue;
            }

            $seen[$key] = $row->number;
            $existing = $onFile->get($key);
            $row->data = ['name' => $name, 'fields' => $data, 'supplier_id' => $existing?->getKey()];

            if ($existing === null) {
                $row->action = ImportRow::CREATE;
            } elseif ($context->existing === ImportExisting::Update) {
                $row->action = ImportRow::UPDATE;
                $row->warn("{$existing->name} is already on file: it will be updated from this row.");
            } else {
                $row->skip("{$existing->name} is already on file: skipped.");
            }
        }
    }

    public function commit(array $rows, ImportContext $context, \Closure $progress): void
    {
        foreach ($rows as $done => $row) {
            $row->write(function () use ($row, $context): void {
                if ($row->action === ImportRow::CREATE) {
                    $this->suppliers->create(['name' => $row->data['name'], ...$row->data['fields']], $context->user);
                } elseif ($row->action === ImportRow::UPDATE) {
                    /** @var Supplier $supplier */
                    $supplier = Supplier::query()->findOrFail($row->data['supplier_id']);
                    $this->suppliers->update($supplier, [...$supplier->only(['contact_name', 'phone', 'email', 'address', 'tax_number', 'payment_terms_days', 'notes', 'is_active']), ...$row->data['fields']], $context->user);
                }
            });

            $progress($done + 1);
        }
    }

    private static function key(string $name): string
    {
        return Str::lower(Str::squish($name));
    }
}
