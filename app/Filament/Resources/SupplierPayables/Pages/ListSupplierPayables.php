<?php

namespace App\Filament\Resources\SupplierPayables\Pages;

use App\Filament\Resources\SupplierPayables\SupplierPayableResource;
use App\Filament\Resources\SupplierPayables\Widgets\PayablesAging;
use App\Filament\Support\PayablesForm;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;

/** Every invoice owed, oldest due first, with the aging totals of what the filters show. */
class ListSupplierPayables extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = SupplierPayableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PayablesForm::paymentAction(after: fn () => $this->resetTable()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [PayablesAging::class];
    }
}
