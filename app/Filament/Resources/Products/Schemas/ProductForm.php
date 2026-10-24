<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\AttributeType;
use App\Enums\DosageForm;
use App\Enums\DrugRoute;
use App\Enums\DrugSchedule;
use App\Enums\StorageCondition;
use App\Enums\TaxCategory;
use App\Models\Category;
use App\Models\Generic;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VariantUnit;
use App\Models\VehicleModel;
use App\Support\CurrentShop;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // Pharmacy shops: the product form is a medicine form, drug details first.
            self::medicineSection(),

            Section::make('Product')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->helperText(fn (): ?string => self::pharmacy() ? 'As it should appear on the receipt: brand, strength and form ("Panadol 500 mg Tablets").' : null),
                    Select::make('category_id')
                        ->label('Category')
                        ->relationship('category', 'name')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('product_attributes', []))
                        ->helperText('The category decides which attributes (Size, Colour ...) this product uses.'),
                    // A pharmacy's brands are its manufacturers (GSK, State Pharmaceuticals).
                    Select::make('brand_id')
                        ->label(fn (): string => self::pharmacy() ? 'Manufacturer' : 'Brand')
                        ->relationship('brand', 'name')
                        ->searchable()
                        ->preload(),
                    // Stock is counted in this unit, so it is fixed once stock has moved.
                    TextInput::make('unit')
                        ->label(fn (): string => self::pharmacy() ? 'Base unit' : 'Unit')
                        ->default('pcs')
                        ->required()
                        ->maxLength(20)
                        ->live(onBlur: true)
                        ->disabled(fn (?Product $record): bool => self::baseUnitLocked($record))
                        ->dehydrated()
                        ->helperText(fn (?Product $record): ?string => self::baseUnitLocked($record)
                            ? 'Locked: this product has stock movements, all counted in this unit.'
                            : (self::pharmacy() ? 'Stock is counted in this unit (Tablet, Bottle, Vial). It is locked once stock moves.' : null)),
                    Toggle::make('track_stock')->default(true)->live()->helperText(fn (): string => self::pharmacy()
                        ? 'Turn off for services such as a consultation fee.'
                        : 'Turn off for services such as fitting or labour.'),
                    // Services only: a stock item is always sold at its list price (docs/PHARMACY.md §4.3).
                    Toggle::make('allow_price_override')
                        ->label('Price can be changed at the till')
                        ->default(false)
                        ->visible(fn (Get $get): bool => ! (bool) $get('track_stock'))
                        ->helperText('The till starts from this price and the cashier may type another, e.g. the consultation fee.'),
                    // Pharmacy: stock in batches with expiry dates, sold first expiry first out.
                    Toggle::make('track_batches')
                        ->label('Batches and expiry')
                        ->default(true)
                        ->live()
                        ->visible(fn (Get $get): bool => self::pharmacy() && (bool) $get('track_stock'))
                        ->helperText(fn (?Product $record): string => $record?->exists === true
                            ? 'Stock is received with a batch number and expiry, and sold first expiry first out. Can only be changed while the item has no stock.'
                            : 'Stock is received with a batch number and expiry, and sold first expiry first out. On for every medicine.'),
                    Toggle::make('whole_units_only')
                        ->label('Whole units only')
                        ->default(true)
                        ->live()
                        ->visible(fn (): bool => self::pharmacy())
                        ->helperText('Sold, received, counted and returned in whole units of whatever unit is used: 1 strip or 3 tablets, never half a tablet or half a box.'),
                    Select::make('tax_category')
                        ->label('Tax')
                        ->options(TaxCategory::options())
                        ->default(TaxCategory::Exempt->value)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->visible(fn (): bool => self::pharmacy())
                        ->helperText("Medicines are usually exempt. The shop's tax rate applies to standard items only."),
                    Toggle::make('is_active')->default(true),
                    Textarea::make('description')->columnSpanFull(),
                    Group::make()
                        ->columns(2)
                        ->columnSpanFull()
                        ->schema(fn (Get $get): array => self::productAttributeFields($get('category_id')))
                        ->visible(fn (Get $get): bool => self::attributesFor($get('category_id'), false)->isNotEmpty()),
                ]),

            Section::make('Spare part details')
                ->description('Only for spare parts.')
                ->visible(fn (): bool => ! self::pharmacy())
                ->collapsed()
                ->columns(2)
                ->schema([
                    TextInput::make('part_number')->maxLength(100),
                    TagsInput::make('oem_numbers')->label('OEM / alternative numbers'),
                    Select::make('fitment_ids')
                        ->label('Fits vehicles')
                        ->visible(fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('vehicle_fitment'))
                        ->multiple()
                        ->searchable()
                        ->columnSpanFull()
                        ->getSearchResultsUsing(fn (string $search): array => VehicleModel::query()
                            ->with('make')
                            ->where('name', 'like', "%{$search}%")
                            ->orWhereHas('make', fn ($make) => $make->where('name', 'like', "%{$search}%"))
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (VehicleModel $model) => [$model->getKey() => "{$model->make->name} {$model->name}"])
                            ->all())
                        ->getOptionLabelsUsing(fn (array $values): array => VehicleModel::query()
                            ->with('make')
                            ->whereKey($values)
                            ->get()
                            ->mapWithKeys(fn (VehicleModel $model) => [$model->getKey() => "{$model->make->name} {$model->name}"])
                            ->all()),
                ]),

            Section::make('Variants')
                ->description('Each variant has its own SKU, barcode, price and stock. Leave the SKU or barcode empty to have one generated.')
                ->schema([
                    Repeater::make('variants')
                        ->hiddenLabel()
                        ->minItems(1)
                        ->maxItems(fn (Get $get): ?int => self::attributesFor($get('category_id'), true)->isEmpty() ? 1 : null)
                        ->deletable(fn (Get $get): bool => true)
                        ->addActionLabel('Add variant')
                        ->itemLabel(fn (array $state): ?string => $state['sku'] ?? null)
                        ->columns(4)
                        ->default([[]])
                        ->schema([
                            Hidden::make('id'),
                            Group::make()
                                ->columns(4)
                                ->columnSpanFull()
                                ->schema(fn (Get $get): array => self::variantAxisFields($get('../../category_id')))
                                ->visible(fn (Get $get): bool => self::attributesFor($get('../../category_id'), true)->isNotEmpty()),
                            TextInput::make('sku')->label('SKU')->maxLength(100),
                            TextInput::make('barcode')->maxLength(100),
                            TextInput::make('price')
                                ->label(fn (Get $get): string => (self::pharmacy() ? 'Price per '.($get('../../unit') ?: 'unit') : 'Selling price').' ('.self::currency().')')
                                ->numeric()
                                ->minValue(0)
                                ->required(fn (Get $get): bool => blank($get('id')))
                                ->visible(fn (): bool => auth()->user()?->can('prices.manage') ?? false),
                            // A new variant starts at the cost it is bought at; after that the
                            // average comes from Stock In (or the owner's Revalue), never from here.
                            TextInput::make('cost')
                                ->label(fn (): string => 'Starting cost ('.self::currency().')')
                                ->numeric()
                                ->minValue(0)
                                ->visible(fn (Get $get): bool => blank($get('id')) && (auth()->user()?->can('cost.view') ?? false)),
                            TextInput::make('average_cost')
                                ->label('Average cost')
                                ->helperText('Weighted average. Changes with Stock In or Revalue cost.')
                                ->disabled()
                                ->dehydrated(false)
                                ->visible(fn (Get $get): bool => filled($get('id')) && (auth()->user()?->can('cost.view') ?? false)),
                            TextInput::make('last_cost')
                                ->label('Last cost')
                                ->helperText('Unit cost of the latest delivery.')
                                ->disabled()
                                ->dehydrated(false)
                                ->placeholder('-')
                                ->visible(fn (Get $get): bool => filled($get('id')) && (auth()->user()?->can('cost.view') ?? false)),
                            TextInput::make('reorder_level')->numeric()->minValue(0)->default(0),
                            TextInput::make('bin_location')->label('Shelf / bin')->maxLength(100),
                            TextInput::make('opening_stock')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->helperText('Recorded as the first stock movement.')
                                ->visible(fn (Get $get): bool => blank($get('id'))),
                            // multi_location: which location the opening stock is counted at.
                            Select::make('opening_location_id')
                                ->label('Opening stock at')
                                ->options(fn (): array => Location::options())
                                ->default(fn (): ?int => self::workLocationId())
                                ->selectablePlaceholder(false)
                                ->visible(fn (Get $get): bool => blank($get('id')) && app(CurrentShop::class)->getOrFail()->hasModule('multi_location')),
                            // Batch-tracked items: the batch the opening stock is counted in.
                            TextInput::make('opening_batch_no')
                                ->label('Opening batch no.')
                                ->maxLength(50)
                                ->required(fn (Get $get): bool => self::opensBatch($get) && (float) $get('opening_stock') > 0)
                                ->visible(fn (Get $get): bool => self::opensBatch($get)),
                            TextInput::make('opening_expiry')
                                ->label('Opening batch expiry')
                                ->placeholder('03/2027')
                                ->helperText('The month or date printed on the pack.')
                                ->maxLength(10)
                                ->required(fn (Get $get): bool => self::opensBatch($get) && (float) $get('opening_stock') > 0)
                                ->visible(fn (Get $get): bool => self::opensBatch($get)),
                            Toggle::make('opening_accept_near_expiry')
                                ->label('Accept short expiry')
                                ->helperText('Owner only: receive it even though it expires soon.')
                                ->visible(fn (Get $get): bool => self::opensBatch($get) && (auth()->user()?->can('batches.manage') ?? false)),
                            Toggle::make('is_active')->default(true)->visible(fn (Get $get): bool => filled($get('id'))),
                            Toggle::make('allow_unit_sale')
                                ->label('Sell loose')
                                ->default(true)
                                ->visible(fn (): bool => self::pharmacy())
                                ->helperText(fn (Get $get): string => 'Sell a single '.($get('../../unit') ?: 'unit').' as well as the packs. An item with no packs always sells this way.'),
                            ...self::looseSaleByLocation(),
                            self::packsRepeater(),
                        ]),
                ]),
        ]);
    }

    /**
     * Drug details (docs/PHARMACY.md §4.1), only in pharmacy shops. Turning
     * "Medicine" off makes the product an ordinary item, such as a
     * consultation fee or cotton wool.
     */
    private static function workLocationId(): ?int
    {
        $user = auth()->user();

        return $user instanceof User ? (int) $user->workLocation(app(CurrentShop::class)->getOrFail())->getKey() : null;
    }

    private static function medicineSection(): Section
    {
        $isMedicine = fn (Get $get): bool => (bool) $get('is_medicine');

        return Section::make('Medicine')
            ->description('Generic and brand names are both searchable at the till.')
            ->visible(fn (): bool => self::pharmacy())
            ->columns(3)
            ->schema([
                Toggle::make('is_medicine')
                    ->label('This is a medicine')
                    ->default(true)
                    ->live()
                    ->columnSpanFull()
                    ->afterStateUpdated(function (bool $state, Set $set, ?Product $record): void {
                        // A new non-medicine (consultation, sundries) is taxed like any other item,
                        // and has no batches; every medicine tracks them.
                        if ($record?->exists !== true) {
                            $set('tax_category', ($state ? TaxCategory::Exempt : TaxCategory::Standard)->value);
                            $set('track_batches', $state);
                        }
                    })
                    ->helperText('Turn off for a consultation fee or sundries.'),
                TagsInput::make('drug.generic_names')
                    ->label('Generic name')
                    ->placeholder('Paracetamol')
                    ->helperText('Add each one for a combination (Amoxicillin, Clavulanic acid).')
                    ->suggestions(fn (): array => Generic::query()->orderBy('name')->limit(500)->pluck('name')->all())
                    ->required()
                    ->visible($isMedicine)
                    ->columnSpan(2),
                TextInput::make('drug.brand_name')
                    ->label('Brand name')
                    ->placeholder('Panadol')
                    ->maxLength(150)
                    ->visible($isMedicine),
                TextInput::make('drug.strength_value')
                    ->label('Strength')
                    ->numeric()
                    ->minValue(0)
                    ->rule('gt:0')
                    ->placeholder('500')
                    ->visible($isMedicine),
                TextInput::make('drug.strength_unit')
                    ->label('Strength unit')
                    ->datalist(fn (): array => config('pos.pharmacy.strength_units', []))
                    ->maxLength(20)
                    ->placeholder('mg, mg/5 ml')
                    ->required(fn (Get $get): bool => filled($get('drug.strength_value')))
                    ->visible($isMedicine),
                Select::make('drug.dosage_form')
                    ->label('Dosage form')
                    ->options(DosageForm::options())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Get $get, Set $set, ?Product $record) => self::applyUnitDefaults($state, $get, $set, $record))
                    ->helperText(fn (?Product $record): ?string => $record?->exists === true ? null : 'Fills in the base unit, loose sale and packs below; change them as needed.')
                    ->visible($isMedicine),
                Select::make('drug.schedule')
                    ->label('Schedule')
                    ->options(DrugSchedule::options())
                    ->required()
                    ->visible($isMedicine),
                Select::make('drug.route')
                    ->label('Route')
                    ->options(DrugRoute::options())
                    ->placeholder('Not set')
                    ->visible($isMedicine),
                TextInput::make('drug.min_days_to_expiry')
                    ->label('Stop selling days before expiry')
                    ->numeric()
                    ->rule('integer')
                    ->minValue(0)
                    ->maxValue(3650)
                    ->placeholder(fn (): string => 'Shop setting ('.(int) app(CurrentShop::class)->getOrFail()->setting('min_days_to_expiry_for_sale', 0).')')
                    ->helperText('Blank uses the shop setting. For example 30 for insulin.')
                    ->visible($isMedicine),
                Select::make('drug.storage_condition')
                    ->label('Storage condition')
                    ->options(StorageCondition::options())
                    ->placeholder('Not set')
                    ->helperText('Fridge items are not put back on the shelf when returned, by default.')
                    ->visible($isMedicine),
                TextInput::make('drug.storage_note')
                    ->label('Storage note')
                    ->placeholder('Store below 25 °C, protect from light')
                    ->maxLength(255)
                    ->columnSpan(2)
                    ->visible($isMedicine),
                TextInput::make('drug.registration_no')
                    ->label('NMRA registration no.')
                    ->maxLength(50)
                    ->visible($isMedicine),
            ]);
    }

    /**
     * Picking a dosage form on a new medicine fills in the suggested units
     * (config('pos.pharmacy.unit_defaults')). Packs are only replaced while
     * none has a price or barcode typed in, so nothing entered is lost.
     */
    private static function applyUnitDefaults(?string $form, Get $get, Set $set, ?Product $record): void
    {
        $dosageForm = DosageForm::tryFrom((string) $form);

        if ($dosageForm === null || $record?->exists === true) {
            return;
        }

        $defaults = $dosageForm->unitDefaults();

        $set('unit', $defaults['base_unit']);
        $set('whole_units_only', $defaults['whole_units_only']);

        /** @var array<string, array<string, mixed>> $variants */
        $variants = $get('variants') ?? [];

        $packsTouched = collect($variants)
            ->flatMap(fn (array $variant): array => $variant['packs'] ?? [])
            ->contains(fn (array $pack): bool => filled($pack['price'] ?? null) || filled($pack['barcode'] ?? null));

        foreach ($variants as $key => $variant) {
            $variants[$key]['allow_unit_sale'] = $defaults['allow_unit_sale'];

            if (! $packsTouched) {
                $variants[$key]['packs'] = collect($defaults['packs'])
                    ->mapWithKeys(fn (array $pack): array => [(string) Str::uuid() => [
                        'id' => null,
                        'name' => $pack['name'],
                        'factor' => $pack['factor'],
                        'barcode' => null,
                        'price' => null,
                        'is_sellable' => true,
                        'is_purchasable' => true,
                        'is_active' => true,
                    ]])
                    ->all();
            }
        }

        $set('variants', $variants);
    }

    /**
     * Pack levels of one variant (strip, box, carton). Each holds a number of
     * base units and has its own price; nothing is worked out from another
     * unit's price. Packs are deactivated, never deleted.
     */
    private static function packsRepeater(): Repeater
    {
        return Repeater::make('packs')
            ->label('Packs')
            ->visible(fn (): bool => self::pharmacy())
            ->default([])
            ->columnSpanFull()
            ->columns(4)
            ->addActionLabel('Add pack')
            ->itemLabel(fn (array $state): ?string => filled($state['name'] ?? null)
                ? (filled($state['factor'] ?? null) ? VariantUnit::describe((string) $state['name'], (string) $state['factor']) : (string) $state['name'])
                : null)
            ->schema([
                Hidden::make('id'),
                TextInput::make('name')->required()->maxLength(30)->placeholder('Strip, Box'),
                TextInput::make('factor')
                    ->label('Contains')
                    ->required()
                    ->numeric()
                    // Browsers count steps from min: with step 1 and min 0.001 only 10.001 is valid, not 10.
                    ->minValue(fn (Get $get): int|float => $get('../../../../whole_units_only') ? 1 : 0.001)
                    ->integer(fn (Get $get): bool => (bool) $get('../../../../whole_units_only'))
                    // A hint, not a suffix: in a pack row the suffix left no room to type.
                    ->helperText(fn (Get $get): string => ($get('../../../../unit') ?: 'Base unit').' per pack'),
                TextInput::make('barcode')->maxLength(100),
                TextInput::make('price')
                    ->label(fn (): string => 'Price ('.self::currency().')')
                    ->numeric()
                    ->minValue(0)
                    // Clearing an existing pack's price keeps it; untick "Sold" to stop selling it.
                    ->helperText(fn (Get $get): ?string => blank($get('id')) ? 'Empty: not for sale yet.' : null)
                    ->visible(fn (): bool => auth()->user()?->can('prices.manage') ?? false),
                Toggle::make('is_sellable')->label('Sold')->default(true),
                Toggle::make('is_purchasable')->label('Bought')->default(true),
                Toggle::make('is_active')->default(true)->visible(fn (Get $get): bool => filled($get('id'))),
            ]);
    }

    /** A new variant of a batch-tracked product: its opening stock needs a batch. */
    private static function opensBatch(Get $get): bool
    {
        return blank($get('id')) && self::pharmacy() && (bool) $get('../../track_stock') && (bool) $get('../../track_batches');
    }

    /**
     * Selling loose per location (pharmacy with several locations): Yes, No,
     * or as "Sell loose" above. Syrup: No at General Pharmacy, Yes at
     * Doctor's Medicines. Checkout honours it from branch 8.
     *
     * @return list<Select>
     */
    private static function looseSaleByLocation(): array
    {
        $shop = app(CurrentShop::class)->getOrFail();

        if (! $shop->hasModule('pharmacy') || ! $shop->hasModule('multi_location')) {
            return [];
        }

        return array_values(Location::query()->where('is_active', true)->ordered()->get()->map(
            fn (Location $location): Select => Select::make("loose_at.{$location->getKey()}")
                ->label("Sell loose at {$location->code}")
                ->options(['yes' => 'Yes', 'no' => 'No'])
                ->placeholder('As "Sell loose"')
                ->helperText($location->name),
        )->all());
    }

    private static function pharmacy(): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule('pharmacy');
    }

    /** The base unit can't change once any of the product's variants has a stock movement. */
    private static function baseUnitLocked(?Product $product): bool
    {
        return $product?->exists === true && StockMovement::query()
            ->whereIn('product_variant_id', $product->variants()->withTrashed()->select('id'))
            ->exists();
    }

    private static function currency(): string
    {
        return (string) app(CurrentShop::class)->getOrFail()->currency;
    }

    /**
     * @return Collection<int, ProductAttribute>
     */
    private static function attributesFor(mixed $categoryId, bool $variantAxis): Collection
    {
        if (blank($categoryId)) {
            return new Collection;
        }

        $category = Category::query()->whereKey($categoryId)->first();

        if ($category === null) {
            return new Collection;
        }

        return $category->productAttributes()
            ->wherePivot('is_variant_axis', $variantAxis)
            ->with('options')
            ->get();
    }

    /**
     * @return array<int, Select|TextInput>
     */
    private static function variantAxisFields(mixed $categoryId): array
    {
        return self::attributesFor($categoryId, true)
            ->map(fn (ProductAttribute $attribute) => Select::make("options.{$attribute->getKey()}")
                ->label($attribute->name)
                ->options($attribute->options->pluck('value', 'id')->all())
                ->required()
                ->disabled(fn (Get $get): bool => filled($get('id')))
                ->dehydrated())
            ->all();
    }

    /**
     * @return array<int, Select|TextInput>
     */
    private static function productAttributeFields(mixed $categoryId): array
    {
        return self::attributesFor($categoryId, false)
            ->map(function (ProductAttribute $attribute) {
                $required = (bool) $attribute->pivot?->getAttribute('is_required');

                if ($attribute->type === AttributeType::Select) {
                    return Select::make("product_attributes.{$attribute->getKey()}")
                        ->label($attribute->name)
                        ->options($attribute->options->pluck('value', 'id')->all())
                        ->required($required);
                }

                $input = TextInput::make("product_attributes.{$attribute->getKey()}")
                    ->label($attribute->name)
                    ->required($required)
                    ->maxLength(255);

                if ($attribute->type === AttributeType::Number) {
                    $input->numeric();
                }

                return $attribute->unit ? $input->suffix($attribute->unit) : $input;
            })
            ->all();
    }
}
