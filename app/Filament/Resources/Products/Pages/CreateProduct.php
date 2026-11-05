<?php

namespace App\Filament\Resources\Products\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\Products\Pages\Concerns\ProductFormPage;
use App\Filament\Resources\Products\ProductFormData;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use App\Services\Data\DrugData;
use App\Services\ProductCatalogService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    use ProductFormPage;

    protected static string $resource = ProductResource::class;

    /** Kept between requests, in case a request after opening the page still has the query. */
    public bool $isDuplicateFilled = false;

    /**
     * ?duplicate=<product>: start from a copy of it (DuplicateProductAction).
     * Only on opening the page: "Save & add another" fills the form again on
     * a later request, which carries no query, and leaves it blank.
     */
    protected function fillForm(): void
    {
        $source = request()->query('duplicate');

        if (! is_string($source) || $source === '' || $this->isDuplicateFilled) {
            parent::fillForm();

            return;
        }

        $this->isDuplicateFilled = true;

        /** @var Product|null $product Another shop's product isn't found either. */
        $product = static::getResource()::resolveRecordRouteBinding($source);

        abort_if($product === null, 404);

        $this->callHook('beforeFill');

        $this->form->fill(ProductFormData::forDuplicate($product));

        $this->callHook('afterFill');

        Notification::make()
            ->title("Copied from {$product->name}")
            ->body('SKU, barcodes, cost and opening stock start empty.')
            ->info()
            ->send();
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Save');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()->label('Save & add another');
    }

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
