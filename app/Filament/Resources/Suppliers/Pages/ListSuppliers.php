<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use App\Models\User;
use App\Services\SupplierService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSuppliers extends ListRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add supplier')
                ->using(function (array $data, Action $action): Supplier {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    return SupplierResource::attempt($action, fn (): Supplier => app(SupplierService::class)->create($data, $user));
                }),
        ];
    }
}
