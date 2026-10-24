<?php

namespace App\Filament\Pages;

use App\Filament\Support\BrandingForm;
use App\Filament\Support\ReceiptPreviewSamples;
use App\Models\Shop;
use App\Models\User;
use App\Services\Receipts\ReceiptLayout;
use App\Services\ReceiptService;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * What receipts print (docs/PHARMACY.md §9, branch 9), for the owner
 * (settings.manage): the logo, header lines and footer, the paper width, and
 * in a pharmacy what goes under each medicine (batch, expiry, prescriber)
 * and how an OPD bill lists its medicines, with a live preview of the
 * thermal receipt. The shop's name, address and phone are in Shop settings.
 *
 * Everything here is read when printing, so reprints follow it too; the
 * sale keeps its batches and expiries whatever is printed.
 */
class ReceiptSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Receipt settings';

    protected static ?string $title = 'Receipt settings';

    protected static ?string $slug = 'receipt-settings';

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
            'logo_path' => $shop->logo_path,
            'receipt_header' => $shop->receipt_header,
            'receipt_footer' => $shop->receipt_footer,
            'receipt_paper_width' => ReceiptService::printOptions($shop)['paperWidth'],
            'receipt_show_batch' => ReceiptService::printOptions($shop)['showBatch'],
            'receipt_show_expiry' => ReceiptService::printOptions($shop)['showExpiry'],
            'receipt_show_prescriber' => ReceiptService::printOptions($shop)['showPrescriber'],
            'opd_receipt_medicines' => $shop->setting('opd_receipt_medicines') === 'totals' ? 'totals' : 'names',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $pharmacy = fn (): bool => $this->shop()->hasModule('pharmacy');

        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'lg' => 2])->schema([
                    Section::make('Receipt')->schema([
                        // The same logo as Shop settings → Branding.
                        BrandingForm::logo(),
                        Textarea::make('receipt_header')
                            ->label('Header lines')
                            ->rows(3)
                            ->maxLength(1000)
                            ->live(debounce: 500)
                            ->helperText('Printed under the name, address and phone (Shop settings), one line each.'),
                        Textarea::make('receipt_footer')
                            ->label('Footer')
                            ->rows(3)
                            ->maxLength(1000)
                            ->live(debounce: 500)
                            ->helperText('Printed at the bottom, for example a returns policy.'),
                        Radio::make('receipt_paper_width')
                            ->label('Paper width')
                            ->options([80 => '80 mm (48 characters a line)', 58 => '58 mm (32 characters a line)'])
                            ->required()
                            ->live(),
                    ]),
                    Section::make('Medicines on receipts')
                        ->visible($pharmacy)
                        ->schema([
                            Toggle::make('receipt_show_batch')
                                ->label('Print the batch number')
                                ->live(),
                            Toggle::make('receipt_show_expiry')
                                ->label('Print the expiry date')
                                ->helperText('Off at the doctor\'s request (2026-10-21). The sale, the admin screens, returns and reports keep it either way.')
                                ->live(),
                            Toggle::make('receipt_show_prescriber')
                                ->label('Print the prescriber on prescription lines')
                                ->live(),
                            Radio::make('opd_receipt_medicines')
                                ->label('Medicines on the OPD bill')
                                ->options([
                                    'names' => 'Names and quantities, without prices',
                                    'totals' => 'Totals only (consultation and Medicines amounts)',
                                ])
                                ->required()
                                ->live(),
                        ]),
                ]),
                Section::make('Preview')
                    ->description(fn (): string => $this->shop()->hasModule('pharmacy')
                        ? 'Sample bills as they print with these settings (not saved yet). Reprints, refund and void slips follow the same settings.'
                        : 'Refund and void slips print like this. Sale receipts keep the standard layout.')
                    ->schema([
                        View::make('filament.receipt-preview')->viewData(fn (): array => $this->preview()),
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
            'receipt_header' => filled($data['receipt_header'] ?? null) ? (string) $data['receipt_header'] : null,
            'receipt_footer' => filled($data['receipt_footer'] ?? null) ? (string) $data['receipt_footer'] : null,
            'settings' => [
                ...($shop->settings ?? []),
                'receipt_paper_width' => (int) $data['receipt_paper_width'] === 58 ? 58 : 80,
                ...(array_key_exists('receipt_show_batch', $data) ? [
                    'receipt_show_batch' => (bool) $data['receipt_show_batch'],
                    'receipt_show_expiry' => (bool) $data['receipt_show_expiry'],
                    'receipt_show_prescriber' => (bool) $data['receipt_show_prescriber'],
                    'opd_receipt_medicines' => (string) $data['opd_receipt_medicines'] === 'totals' ? 'totals' : 'names',
                ] : []),
            ],
        ]);

        Notification::make()->title('Receipt settings saved')->success()->send();
    }

    /**
     * The sample receipts with the form's current (unsaved) choices.
     *
     * @return array{columns: int, receipts: array<string, list<array{text: string, bold: bool, tall: bool}>>}
     */
    private function preview(): array
    {
        $shop = $this->shop();
        $data = $this->data ?? [];
        $receiptShop = app(ReceiptService::class)->shop();
        $receiptShop['header'] = $data['receipt_header'] ?? null;
        $receiptShop['footer'] = $data['receipt_footer'] ?? null;
        $receiptShop['receipt'] = [
            'paperWidth' => (int) ($data['receipt_paper_width'] ?? 80) === 58 ? 58 : 80,
            'showBatch' => (bool) ($data['receipt_show_batch'] ?? true),
            'showExpiry' => (bool) ($data['receipt_show_expiry'] ?? true),
            'showPrescriber' => (bool) ($data['receipt_show_prescriber'] ?? false),
        ];
        $opdMedicines = ($data['opd_receipt_medicines'] ?? 'names') === 'totals' ? 'totals' : 'names';
        $layout = fn (): ReceiptLayout => new ReceiptLayout($receiptShop);
        $samples = ReceiptPreviewSamples::for($shop, $opdMedicines);

        return [
            'columns' => ReceiptLayout::columns($receiptShop['receipt']['paperWidth']),
            'receipts' => $shop->hasModule('pharmacy')
                ? ['General Pharmacy bill' => $layout()->sale($samples['gen']), 'OPD bill' => $layout()->sale($samples['opd'])]
                : ['Refund slip' => $layout()->refund($samples['refund'])],
        ];
    }

    private function shop(): Shop
    {
        return app(CurrentShop::class)->getOrFail();
    }
}
