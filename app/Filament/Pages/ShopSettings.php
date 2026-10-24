<?php

namespace App\Filament\Pages;

use App\Filament\Support\BrandingForm;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Shop name, address and phone (the receipt's header), currency, tax and the
 * cashier discount limit; what receipts print is on Receipt settings;
 * with the pharmacy module, the expiry rules for batches and the pharmacy
 * checkout (prescriber, OPD bills, bank transfer reference); with offline
 * tills, how far a till's clock may be out; with purchasing and several
 * locations, where goods are received; the branding (logo, favicon, brand
 * colour, docs/ARCHITECTURE.md "Branding").
 * Currency decimals are deliberately not editable: stored amounts are in
 * minor units, so changing them would change what every price means.
 */
class ShopSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Shop settings';

    protected static ?string $title = 'Shop settings';

    protected static ?string $slug = 'shop-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('settings.manage');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $shop = $this->shop();

        $this->getSchema('form')->fill([
            'name' => $shop->name,
            'address' => $shop->address,
            'phone' => $shop->phone,
            'currency' => $shop->currency,
            'tax_enabled' => (bool) $shop->tax_enabled,
            'tax_rate' => $shop->tax_rate,
            'prices_include_tax' => (bool) $shop->prices_include_tax,
            'max_cashier_discount_percent' => $shop->setting('max_cashier_discount_percent'),
            'require_open_shift' => (bool) $shop->setting('require_open_shift'),
            'min_days_to_expiry_for_sale' => $shop->setting('min_days_to_expiry_for_sale'),
            'near_expiry_warning_days' => $shop->setting('near_expiry_warning_days'),
            'receive_at_default_location' => (bool) $shop->setting('receive_at_default_location'),
            'default_prescriber' => $shop->setting('default_prescriber'),
            'rx_prescriber' => $shop->setting('rx_prescriber'),
            'controlled_register' => (bool) $shop->setting('controlled_register'),
            'opd_consultation_variant_id' => $shop->setting('opd_consultation_variant_id'),
            'bank_transfer_reference_required' => (bool) $shop->setting('bank_transfer_reference_required'),
            'opd_below_list_warning_percent' => $shop->setting('opd_below_list_warning_percent'),
            'till_clock_tolerance_minutes' => $shop->setting('till_clock_tolerance_minutes'),
            'logo_path' => $shop->logo_path,
            'favicon_path' => $shop->setting('favicon_path'),
            'brand_color' => $shop->setting('brand_color'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Shop')
                    ->description('Printed at the top of every receipt. Header lines, footer and paper are on Receipt settings.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('address')->maxLength(255),
                        TextInput::make('phone')->tel()->maxLength(50),
                    ]),
                Section::make('Branding')
                    ->description('Your logo, the browser tab icon and the colour of the admin panel and the till.')
                    ->columns(['default' => 1, 'lg' => 3])
                    ->schema([
                        BrandingForm::logo(),
                        BrandingForm::favicon(),
                        Grid::make(1)->schema([BrandingForm::color(), BrandingForm::preview()]),
                    ]),
                Section::make('Money and tax')->columns(2)->schema([
                    TextInput::make('currency')
                        ->required()
                        ->length(3)
                        ->alpha()
                        ->dehydrateStateUsing(fn (string $state): string => strtoupper($state))
                        ->helperText('Three-letter code, such as LKR.'),
                    TextInput::make('max_cashier_discount_percent')
                        ->label('Cashier discount limit (%)')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->maxValue(100)
                        ->helperText('Cashiers cannot discount a sale by more than this.'),
                    Toggle::make('tax_enabled')->live(),
                    TextInput::make('tax_rate')
                        ->label('Tax rate (%)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->required(fn (Get $get): bool => (bool) $get('tax_enabled'))
                        ->visible(fn (Get $get): bool => (bool) $get('tax_enabled')),
                    Toggle::make('prices_include_tax')
                        ->label('Prices already include tax')
                        ->visible(fn (Get $get): bool => (bool) $get('tax_enabled')),
                ]),
                Section::make('Till')
                    ->schema([
                        Toggle::make('require_open_shift')
                            ->label('Sell only with an open shift')
                            ->helperText('The till asks the cashier to open a shift (with the cash float) before the first sale; "Sell without a shift" is not offered. Sales a till made offline are always accepted.'),
                    ]),
                Section::make('Batches and expiry')
                    ->visible(fn (): bool => $this->shop()->hasModule('pharmacy'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('min_days_to_expiry_for_sale')
                            ->label('Stop selling days before expiry')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(3650)
                            ->helperText('0 = sell up to and including the expiry day. A medicine can have its own number.'),
                        TextInput::make('near_expiry_warning_days')
                            ->label('Short expiry: within (days)')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(3650)
                            ->helperText('Receiving stock that expires sooner needs the owner to tick "Accept short expiry"; the Batches list shows it in amber.'),
                    ]),
                // docs/PHARMACY.md §4.10 and §9, branch 8.
                Section::make('Pharmacy checkout')
                    ->visible(fn (): bool => $this->shop()->hasModule('pharmacy'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('default_prescriber')
                            ->label('Default prescriber')
                            ->maxLength(150)
                            ->helperText('The clinic doctor, pre-filled as the prescriber.'),
                        Radio::make('rx_prescriber')
                            ->label('Prescriber on prescription-only medicines')
                            ->options([
                                'required' => 'Required: the till cannot sell without it',
                                'prompt' => 'Ask only: the till may sell without it',
                            ])
                            ->required(),
                        Select::make('opd_consultation_variant_id')
                            ->label('OPD consultation item')
                            ->options(fn (): array => $this->consultationOptions())
                            ->placeholder('None')
                            ->helperText('The fee line an OPD bill starts with. Only services (items that do not track stock) are listed.'),
                        TextInput::make('opd_below_list_warning_percent')
                            ->label('Warn when the OPD Medicines amount is below (% of list value)')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->helperText('The till shows the medicines\' list value next to the Medicines amount and warns, without blocking, below this share of it. 100 = any amount below list; 0 = no warning. The sale records it.'),
                        Toggle::make('bank_transfer_reference_required')
                            ->label('Bank transfer needs a reference')
                            ->helperText('The till asks for the transfer reference before taking a bank transfer.'),
                        Toggle::make('controlled_register')
                            ->label('Controlled-drug register')
                            ->helperText("Off by default; the register report comes later. On: a controlled drug needs the patient's name, and cannot be sold while the till is offline."),
                    ]),
                Section::make('Offline tills')
                    ->visible(fn (): bool => $this->shop()->hasModule('offline_sync'))
                    ->schema([
                        TextInput::make('till_clock_tolerance_minutes')
                            ->label('Till clock tolerance (minutes)')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(1)
                            ->maxValue(1440)
                            ->helperText("Offline sales from a till whose clock is further out than this are flagged for review, and reports count them at the server's time."),
                    ]),
                Section::make('Purchasing')
                    ->visible(fn (): bool => $this->shop()->hasModule('purchasing') && $this->shop()->hasModule('multi_location'))
                    ->schema([
                        Toggle::make('receive_at_default_location')
                            ->label(fn (): string => 'Receive goods at '.$this->shop()->defaultLocation()->name)
                            ->helperText('New purchase orders and goods received notes start at the default location (stock then goes to the others by transfer). Off: at the user\'s own location. Either can be changed on the document.'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save settings')->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var array<string, mixed> $data */
        $data = $this->getSchema('form')->getState();
        $shop = $this->shop();

        BrandingForm::saveLogo($shop, $data['logo_path'] ?? null);

        $shop->update([
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
            'currency' => $data['currency'],
            'tax_enabled' => (bool) $data['tax_enabled'],
            'tax_rate' => $data['tax_enabled'] ? $data['tax_rate'] : $shop->tax_rate,
            'prices_include_tax' => $data['tax_enabled'] ? (bool) ($data['prices_include_tax'] ?? true) : $shop->prices_include_tax,
            'settings' => [
                ...($shop->settings ?? []),
                'max_cashier_discount_percent' => (float) $data['max_cashier_discount_percent'],
                'require_open_shift' => (bool) ($data['require_open_shift'] ?? false),
                ...(array_key_exists('min_days_to_expiry_for_sale', $data) ? [
                    'min_days_to_expiry_for_sale' => (int) $data['min_days_to_expiry_for_sale'],
                    'near_expiry_warning_days' => (int) $data['near_expiry_warning_days'],
                ] : []),
                ...(array_key_exists('receive_at_default_location', $data) ? [
                    'receive_at_default_location' => (bool) $data['receive_at_default_location'],
                ] : []),
                ...(array_key_exists('rx_prescriber', $data) ? [
                    'default_prescriber' => filled($data['default_prescriber'] ?? null) ? trim((string) $data['default_prescriber']) : null,
                    'rx_prescriber' => (string) $data['rx_prescriber'],
                    'controlled_register' => (bool) $data['controlled_register'],
                    'opd_consultation_variant_id' => $this->consultationVariantId($data['opd_consultation_variant_id'] ?? null),
                    'bank_transfer_reference_required' => (bool) $data['bank_transfer_reference_required'],
                    'opd_below_list_warning_percent' => max(0, min(100, (int) $data['opd_below_list_warning_percent'])),
                ] : []),
                ...(array_key_exists('till_clock_tolerance_minutes', $data) ? [
                    'till_clock_tolerance_minutes' => (int) $data['till_clock_tolerance_minutes'],
                ] : []),
                'favicon_path' => BrandingForm::replaceFavicon($shop, $data['favicon_path'] ?? null),
                'brand_color' => BrandingForm::normalize($data['brand_color'] ?? null),
            ],
        ]);

        Notification::make()->title('Settings saved')->success()->send();
    }

    /**
     * Active services (no stock) that can be the OPD consultation line, as
     * "SKU — name"; the saved one stays listed even if it no longer qualifies.
     *
     * @return array<int, string>
     */
    private function consultationOptions(): array
    {
        $saved = $this->shop()->setting('opd_consultation_variant_id');

        return ProductVariant::query()
            ->with('product')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->whereHas('product', fn (Builder $product) => $product->where('track_stock', false)->where('is_active', true)))
                ->when(filled($saved), fn (Builder $query) => $query->orWhereKey((int) $saved)))
            ->get()
            ->sortBy(fn (ProductVariant $variant): string => $variant->product->name.' '.$variant->sku)
            ->mapWithKeys(fn (ProductVariant $variant): array => [(int) $variant->getKey() => trim("{$variant->sku} — {$variant->product->name} ".($variant->name ?? ''))])
            ->all();
    }

    /** The chosen consultation variant, only if it is one of the options; else none. */
    private function consultationVariantId(mixed $id): ?int
    {
        return filled($id) && array_key_exists((int) $id, $this->consultationOptions()) ? (int) $id : null;
    }

    private function shop(): Shop
    {
        return app(CurrentShop::class)->getOrFail();
    }
}
