<?php

namespace App\Filament\Support;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\TaxCategory;
use App\Exceptions\PosException;
use App\Exceptions\PossibleDuplicateItem;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Generic;
use App\Models\GoodsReceipt;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Services\Data\DrugData;
use App\Services\Data\QuickItemData;
use App\Services\ReceivingItemService;
use App\Support\CurrentShop;
use Closure;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;
use Livewire\Component as Livewire;

/**
 * "Create new item" on a purchase order or goods received line: the item
 * is not in the catalog yet. A pharmacy gets the medicine essentials
 * (generic, brand, strength, dosage form with its unit and pack defaults,
 * schedule, tax, barcode, packs, optional prices); a retail shop the retail
 * essentials (name, category, unit, barcode, cost). It starts with what was
 * typed or scanned into the item search. Possible duplicates are listed
 * for the user to pick the existing item or create anyway; a barcode
 * already in use is refused. ReceivingItemService enforces it all.
 */
class QuickItemForm
{
    /** The last text typed or scanned into an item search, to start the new item with. */
    public const SEARCH_SESSION_KEY = 'purchasing.item_search';

    /** @return list<Component> */
    public static function schema(): array
    {
        return self::pharmacy() ? self::medicineSchema() : self::retailSchema();
    }

    /**
     * The form's starting state: a scanned barcode (digits only) goes in the
     * barcode, anything else typed is the name.
     *
     * @return array<string, mixed>
     */
    public static function prefill(): array
    {
        $typed = trim((string) session(self::SEARCH_SESSION_KEY, ''));
        $barcode = preg_match('/^\d{6,}$/', $typed) === 1;

        return [
            'is_medicine' => true,
            'name' => $barcode ? null : $typed,
            'barcode' => $barcode ? $typed : null,
            'unit' => self::pharmacy() ? null : 'pcs',
            'allow_unit_sale' => true,
            'tax_category' => ReceivingItemService::defaultTaxCategory(self::pharmacy())->value,
            'packs' => [],
        ];
    }

    /**
     * Create the item (or take the existing one the user picked) and return
     * the variant id the line selects. Refusals keep the form open with the
     * reason.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data, Livewire $livewire): string
    {
        $choice = $data['duplicate_choice'] ?? null;

        if (filled($choice) && $choice !== 'new') {
            // The existing item goes on the line; what was typed is done with.
            session()->forget(self::SEARCH_SESSION_KEY);

            return (string) (int) $choice;
        }

        $user = PurchasingForm::user();
        abort_unless($user !== null, 403);

        $document = $livewire instanceof EditRecord ? $livewire->getRecord() : null;

        try {
            $variant = app(ReceivingItemService::class)->create(
                self::data($data),
                $user,
                $document instanceof GoodsReceipt || $document instanceof PurchaseOrder ? $document : null,
                createAnyway: $choice === 'new',
            );
        } catch (PossibleDuplicateItem $exception) {
            Notification::make()->title('This may already be in the catalog')->body($exception->getMessage())->warning()->send();

            throw new Halt;
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }

        session()->forget(self::SEARCH_SESSION_KEY);

        Notification::make()
            ->title("{$variant->product->name} created")
            ->body($variant->product->needs_review ? 'It can be received now; an admin reviews it and sets its prices before it can be sold.' : null)
            ->success()
            ->send();

        return (string) $variant->getKey();
    }

    /** @param  array<string, mixed>  $state */
    public static function data(array $state): QuickItemData
    {
        $medicine = self::pharmacy() && (bool) ($state['is_medicine'] ?? false);
        $dosageForm = DosageForm::tryFrom((string) ($state['dosage_form'] ?? ''));
        $drug = null;

        if ($medicine && $dosageForm !== null) {
            $drug = new DrugData(
                array_values(array_filter(array_map(fn ($name): string => trim((string) $name), (array) ($state['generic_names'] ?? [])))),
                $dosageForm,
                DrugSchedule::tryFrom((string) ($state['schedule'] ?? '')) ?? throw new PosException('Choose the schedule.'),
                brandName: filled($state['brand_name'] ?? null) ? trim((string) $state['brand_name']) : null,
                strengthValue: filled($state['strength_value'] ?? null) ? (float) $state['strength_value'] : null,
                strengthUnit: filled($state['strength_unit'] ?? null) ? trim((string) $state['strength_unit']) : null,
            );
        }

        return new QuickItemData(
            name: trim((string) ($state['name'] ?? '')),
            drug: $drug,
            unit: trim((string) ($state['unit'] ?? '')) ?: 'pcs',
            barcode: filled($state['barcode'] ?? null) ? trim((string) $state['barcode']) : null,
            allowUnitSale: (bool) ($state['allow_unit_sale'] ?? true),
            packs: array_values(array_map(fn (array $pack): array => [
                'name' => trim((string) ($pack['name'] ?? '')),
                'factor' => $pack['factor'] ?? 0,
                'barcode' => filled($pack['barcode'] ?? null) ? trim((string) $pack['barcode']) : null,
                'price' => filled($pack['price'] ?? null) ? PurchasingForm::toMinor($pack['price']) : null,
            ], (array) ($state['packs'] ?? []))),
            price: filled($state['price'] ?? null) ? PurchasingForm::toMinor($state['price']) : null,
            cost: filled($state['cost'] ?? null) ? PurchasingForm::toMinor($state['cost']) : null,
            categoryId: filled($state['category_id'] ?? null) ? (int) $state['category_id'] : null,
            brandId: filled($state['brand_id'] ?? null) ? (int) $state['brand_id'] : null,
            taxCategory: TaxCategory::tryFrom((string) ($state['tax_category'] ?? '')),
        );
    }

    /**
     * "Use existing: …" for each possible duplicate, and "Create anyway".
     *
     * @return array<string, string>
     */
    public static function duplicateOptions(Get $get): array
    {
        $state = [];

        foreach (['is_medicine', 'name', 'generic_names', 'strength_value', 'strength_unit', 'dosage_form'] as $key) {
            $state[$key] = $get($key);
        }

        // Any schedule will do for the comparison: it doesn't decide a duplicate.
        $state['schedule'] = DrugSchedule::Otc->value;

        if (blank($state['name'])) {
            return [];
        }

        $items = app(ReceivingItemService::class);
        $matches = $items->possibleDuplicates(self::data($state));

        if ($matches->isEmpty()) {
            return [];
        }

        return $matches
            ->mapWithKeys(fn (ProductVariant $variant): array => [(string) $variant->getKey() => 'Use existing: '.$items->label($variant)])
            ->put('new', 'Create anyway: it is a different item')
            ->all();
    }

    /** @return list<Component> */
    private static function medicineSchema(): array
    {
        $medicine = fn (Get $get): bool => (bool) $get('is_medicine');

        return [
            Toggle::make('is_medicine')
                ->label('This is a medicine')
                ->default(true)
                ->live()
                ->afterStateUpdated(fn (bool $state, Set $set) => $set('tax_category', ReceivingItemService::defaultTaxCategory($state)->value)),
            Grid::make(['default' => 1, 'md' => 3])->schema([
                TextInput::make('name')
                    ->label('Item name')
                    ->placeholder('Panadol 500 mg Tablets')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->columnSpan(['md' => 2]),
                TextInput::make('barcode')->maxLength(100)->rule(self::barcodeRule()),
                TagsInput::make('generic_names')
                    ->label('Generic name')
                    ->placeholder('Paracetamol')
                    ->suggestions(fn (): array => Generic::query()->orderBy('name')->limit(500)->pluck('name')->all())
                    ->required($medicine)
                    ->live()
                    ->visible($medicine)
                    ->columnSpan(['md' => 2]),
                TextInput::make('brand_name')->label('Brand name')->placeholder('Panadol')->maxLength(150)->visible($medicine),
                Select::make('brand_id')
                    ->label('Manufacturer')
                    ->options(fn (): array => Brand::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TextInput::make('strength_value')->label('Strength')->numeric()->rule('gt:0')->placeholder('500')->live(onBlur: true)->visible($medicine),
                TextInput::make('strength_unit')
                    ->label('Strength unit')
                    ->datalist(fn (): array => config('pos.pharmacy.strength_units', []))
                    ->maxLength(20)
                    ->placeholder('mg')
                    ->required(fn (Get $get): bool => filled($get('strength_value')))
                    ->live(onBlur: true)
                    ->visible($medicine),
                Select::make('dosage_form')
                    ->label('Dosage form')
                    ->options(DosageForm::options())
                    ->required($medicine)
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Set $set) => self::applyUnitDefaults($state, $set))
                    ->helperText('Fills in the base unit, loose sale and packs.')
                    ->visible($medicine),
                Select::make('schedule')->options(DrugSchedule::options())->required($medicine)->visible($medicine),
                Select::make('tax_category')->label('Tax')->options(TaxCategory::options())->required(),
                TextInput::make('unit')->label('Base unit')->placeholder('Tablet')->required()->maxLength(20),
                Toggle::make('allow_unit_sale')->label('Sell loose')->inline(false),
                PurchasingForm::moneyField('price', 'Price per base unit')
                    ->placeholder('Not for sale yet')
                    ->visible(fn (): bool => self::canPrice()),
            ]),
            Repeater::make('packs')
                ->label('Packs')
                ->addActionLabel('Add pack')
                ->default([])
                ->columns(['default' => 1, 'md' => 4])
                ->schema([
                    TextInput::make('name')->required()->maxLength(30)->placeholder('Box of 30'),
                    TextInput::make('factor')->label('Contains')->numeric()->integer()->minValue(1)->required(),
                    TextInput::make('barcode')->maxLength(100)->rule(self::barcodeRule()),
                    PurchasingForm::moneyField('price', 'Price')->placeholder('Not for sale yet')->visible(fn (): bool => self::canPrice()),
                ]),
            self::duplicateField(),
        ];
    }

    /** @return list<Component> */
    private static function retailSchema(): array
    {
        return [
            Grid::make(['default' => 1, 'md' => 2])->schema([
                TextInput::make('name')->label('Item name')->required()->maxLength(255)->live(onBlur: true)->columnSpanFull(),
                Select::make('category_id')
                    ->label('Category')
                    ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TextInput::make('unit')->default('pcs')->required()->maxLength(20),
                TextInput::make('barcode')->maxLength(100)->rule(self::barcodeRule()),
                PurchasingForm::moneyField('cost', 'Cost')->visible(fn (): bool => PurchasingForm::user()?->can('cost.view') ?? false),
            ]),
            self::duplicateField(),
        ];
    }

    private static function duplicateField(): Radio
    {
        return Radio::make('duplicate_choice')
            ->label('This may already be in the catalog')
            ->options(fn (Get $get): array => self::duplicateOptions($get))
            ->visible(fn (Get $get): bool => self::duplicateOptions($get) !== [])
            ->required(fn (Get $get): bool => self::duplicateOptions($get) !== [])
            ->helperText('Same generic, strength and form, or a very similar name. Pick the existing item for this line, or create the new one anyway.');
    }

    /** A barcode already on an item or pack is refused outright. */
    private static function barcodeRule(): Closure
    {
        return function (): Closure {
            return function (string $attribute, mixed $value, Closure $fail): void {
                $holder = app(ReceivingItemService::class)->barcodeHolder(is_scalar($value) ? (string) $value : null);

                if ($holder !== null) {
                    $fail("This barcode is already on {$holder}: pick that item instead.");
                }
            };
        };
    }

    private static function applyUnitDefaults(?string $form, Set $set): void
    {
        $dosageForm = DosageForm::tryFrom((string) $form);

        if ($dosageForm === null) {
            return;
        }

        $defaults = $dosageForm->unitDefaults();
        $set('unit', $defaults['base_unit']);
        $set('allow_unit_sale', $defaults['allow_unit_sale']);
        $set('packs', collect($defaults['packs'])
            ->mapWithKeys(fn (array $pack): array => [(string) Str::uuid() => ['name' => $pack['name'], 'factor' => $pack['factor'], 'barcode' => null, 'price' => null]])
            ->all());
    }

    private static function pharmacy(): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule('pharmacy');
    }

    /** Prices are typed only by someone who manages products and prices; anyone else's item is priced at review. */
    private static function canPrice(): bool
    {
        $user = PurchasingForm::user();

        return $user !== null && $user->can('products.manage') && $user->can('prices.manage');
    }
}
