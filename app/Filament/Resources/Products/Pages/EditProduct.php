<?php

namespace App\Filament\Resources\Products\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\Products\ProductFormData;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\LabelActions;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductCatalogService;
use App\Services\ReceivingItemService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Print labels of its units and packs (labels module). An item created
     * while receiving by someone who can't manage products is not for sale
     * until reviewed: set its details and prices, save, then mark it as
     * reviewed.
     */
    protected function getHeaderActions(): array
    {
        return [
            LabelActions::productAction(),
            Action::make('markReviewed')
                ->label('Mark as reviewed')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('warning')
                ->visible(fn (Product $record): bool => $record->needs_review && (auth()->user()?->can('products.manage') ?? false))
                ->requiresConfirmation()
                ->modalHeading('Mark as reviewed')
                ->modalDescription(fn (Product $record): string => "{$record->name} was created while receiving goods. Once reviewed, the tills can find and sell it. Save its details and prices first: without a price it still can't be sold.")
                ->action(function (Product $record): void {
                    /** @var User $user */
                    $user = auth()->user();

                    try {
                        app(ReceivingItemService::class)->markReviewed($record, $user);
                    } catch (PosException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title("{$record->name} reviewed: it is for sale")->success()->send();
                }),
        ];
    }

    /**
     * Built from scratch so that cost prices only reach the browser when the
     * user is allowed to see them.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Product $product */
        $product = $this->getRecord();

        return ProductFormData::fromProduct($product);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $input = ProductFormData::toServiceInput($data);

        try {
            /** @var User $user */
            $user = auth()->user();

            /** @var Product $record */
            return app(ProductCatalogService::class)->update(
                $record,
                $input['product'],
                $input['variants'],
                $input['productAttributes'],
                $input['fitmentIds'],
                $user,
                $input['drug'],
            );
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
