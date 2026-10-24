<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\StorageCondition;
use App\Enums\TaxCategory;
use App\Filament\Support\LabelActions;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Generic;
use App\Models\Product;
use App\Support\CurrentShop;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['category', 'brand', 'variants.prices'])
                ->when(self::pharmacy(), fn (Builder $query) => $query->with(['drugDetail', 'generics'])))
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Product $record): ?string => self::pharmacy()
                        ? self::medicineLine($record)
                        : ($record->part_number ? "Part no. {$record->part_number}" : null))
                    ->wrap()
                    ->extraAttributes(['style' => 'min-width: 12rem'])
                    ->sortable()
                    // One search over name, part number, OEM numbers, variant SKU and barcode,
                    // and in a pharmacy generic and brand names too.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->scopes(['search' => [$search, self::pharmacy()]])),
                TextColumn::make('drugDetail.schedule')
                    ->label('Schedule')
                    ->badge()
                    ->formatStateUsing(fn (?DrugSchedule $state): ?string => $state?->shortLabel())
                    ->color(fn (?DrugSchedule $state): string => match ($state) {
                        DrugSchedule::Controlled => 'danger',
                        DrugSchedule::PrescriptionOnly => 'warning',
                        default => 'gray',
                    })
                    ->visible(fn (): bool => self::pharmacy()),
                TextColumn::make('drugDetail.dosage_form')
                    ->label('Form')
                    ->formatStateUsing(fn (?DosageForm $state): ?string => $state?->label())
                    ->toggleable()
                    ->visible(fn (): bool => self::pharmacy()),
                TextColumn::make('drugDetail.registration_no')
                    ->label('NMRA reg. no.')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (): bool => self::pharmacy()),
                TextColumn::make('tax_category')
                    ->label('Tax')
                    ->formatStateUsing(fn (?TaxCategory $state): string => $state === TaxCategory::Exempt ? 'Exempt' : 'Standard')
                    ->toggleable()
                    ->visible(fn (): bool => self::pharmacy()),
                TextColumn::make('category.name')->sortable()->toggleable(),
                TextColumn::make('brand.name')
                    ->label(fn (): string => self::pharmacy() ? 'Manufacturer' : 'Brand')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('variants_count')->counts('variants')->label('Variants')->toggleable(),
                TextColumn::make('from_price')
                    ->label('Price')
                    ->state(function (Product $record): ?string {
                        $priceListId = app(CurrentShop::class)->getOrFail()->defaultPriceList()->getKey();
                        $lowest = $record->variants->flatMap->prices->where('price_list_id', $priceListId)->min('price');

                        return $lowest === null ? null : app(CurrentShop::class)->getOrFail()->formatMoney((int) $lowest);
                    }),
                TextColumn::make('review')
                    ->label('Review')
                    ->state(fn (Product $record): ?string => $record->needs_review ? 'Needs review' : null)
                    ->badge()
                    ->color('warning')
                    ->tooltip('Created while receiving goods: it can be received, but is not for sale until an admin reviews it and sets its prices.'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('category_id')->label('Category')->options(fn () => Category::query()->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('brand_id')
                    ->label(fn (): string => self::pharmacy() ? 'Manufacturer' : 'Brand')
                    ->options(fn () => Brand::query()->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('schedule')
                    ->options(DrugSchedule::options())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('drugDetail', fn (Builder $drug) => $drug->where('schedule', $data['value'])))
                    ->visible(fn (): bool => self::pharmacy()),
                SelectFilter::make('dosage_form')
                    ->label('Dosage form')
                    ->options(DosageForm::options())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('drugDetail', fn (Builder $drug) => $drug->where('dosage_form', $data['value'])))
                    ->visible(fn (): bool => self::pharmacy()),
                SelectFilter::make('storage_condition')
                    ->label('Storage')
                    ->options(StorageCondition::options())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereHas('drugDetail', fn (Builder $drug) => $drug->where('storage_condition', $data['value'])))
                    ->visible(fn (): bool => self::pharmacy()),
                SelectFilter::make('tax_category')
                    ->label('Tax')
                    ->options([TaxCategory::Exempt->value => 'Exempt', TaxCategory::Standard->value => 'Standard'])
                    ->visible(fn (): bool => self::pharmacy()),
                TernaryFilter::make('is_active')->label('Active'),
                // Items created while receiving by someone who can't manage products (not for sale yet).
                Filter::make('needs_review')
                    ->label('Items to review')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('needs_review', true)),
                // Items or packs that scan with an in-store barcode or none (labels module):
                // the ones that need printed labels.
                Filter::make('without_manufacturer_barcode')
                    ->label('Without a manufacturer barcode')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('variants', fn (Builder $variant) => $variant
                        ->where('is_active', true)
                        ->where(fn (Builder $q) => $q
                            ->scopes(['withoutManufacturerBarcode'])
                            ->orWhereHas('units', fn (Builder $unit) => $unit
                                ->where('is_base', false)
                                ->where('is_active', true)
                                ->scopes(['withoutManufacturerBarcode'])))))
                    ->visible(fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('labels')),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    LabelActions::productsBulkAction(),
                    LabelActions::giveBarcodesBulkAction(),
                ]),
            ]);
    }

    private static function pharmacy(): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule('pharmacy');
    }

    /** "Paracetamol · 500 mg · Panadol" under a medicine's name. */
    private static function medicineLine(Product $product): ?string
    {
        if ($product->drugDetail === null) {
            return null;
        }

        $line = implode(' + ', $product->generics->map(fn (Generic $generic): string => $generic->name)->all());

        return implode(' · ', array_filter([$line, $product->drugDetail->strength(), $product->drugDetail->brand_name], fn (?string $part): bool => filled($part)));
    }
}
