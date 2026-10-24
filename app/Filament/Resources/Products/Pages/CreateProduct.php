<?php

namespace App\Filament\Resources\Products\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\Products\ProductFormData;
use App\Filament\Resources\Products\ProductResource;
use App\Models\User;
use App\Services\Data\DrugData;
use App\Services\ProductCatalogService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $input = ProductFormData::toServiceInput($data);

        try {
            /** @var User $user */
            $user = auth()->user();

            return app(ProductCatalogService::class)->create(
                $input['product'],
                $input['variants'],
                $input['productAttributes'],
                $user,
                $input['fitmentIds'],
                $input['drug'] instanceof DrugData ? $input['drug'] : null,
            );
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
