<?php

namespace App\Filament\Resources\Products;

use App\Models\Product;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Starts a new product from an existing one: opens Create, pre-filled by
 * ProductFormData::forDuplicate(). Nothing is saved until the form is.
 */
class DuplicateProductAction
{
    public static function make(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->visible(fn (): bool => ProductResource::canCreate())
            ->url(fn (Product $record): string => ProductResource::getUrl('create', ['duplicate' => $record->getRouteKey()]));
    }
}
