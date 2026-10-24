<?php

namespace App\Filament\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\LabelPrints\LabelPrintResource;
use App\Filament\Support\LabelActions;
use App\Models\LabelQueueItem;
use App\Models\LabelTemplate;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\Labels\LabelService;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * The print queue (labels module, docs/PHARMACY.md §9, branch 7b): labels a
 * user collected from products, deliveries, batches and the product list,
 * to print in one go on one template. Each user has their own queue.
 * Copies can be changed and lines removed; printing logs the print, empties
 * the queue and opens the print preview.
 */
class PrintLabels extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Print labels';

    protected static ?string $title = 'Print labels';

    protected static ?string $slug = 'print-labels';

    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        return LabelActions::canPrint();
    }

    public static function getNavigationBadge(): ?string
    {
        $user = self::user();
        $count = $user === null ? 0 : app(LabelService::class)->queuedLabels($user);

        return $count > 0 ? (string) $count : null;
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Labels you collected from products, deliveries and batches, printed together. Add more with "Print labels" on a product, a delivery, the Batches screen or the product list.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $pharmacy = fn (): bool => app(CurrentShop::class)->getOrFail()->hasModule('pharmacy');

        return $table
            ->query(fn (): Builder => LabelQueueItem::query()
                ->where('user_id', self::user()?->getKey() ?? 0)
                ->with(['variant.product', 'variant.units', 'unit']))
            ->columns([
                TextColumn::make('variant.product.name')
                    ->label('Item')
                    ->description(fn (LabelQueueItem $record): string => trim($record->variant->sku.' '.($record->variant->name ?? '')))
                    ->wrap(),
                TextColumn::make('unit')
                    ->label('Label')
                    ->state(fn (LabelQueueItem $record): ?string => $record->unit === null
                        ? $record->variant->units->firstWhere('is_base', true)?->name
                        : VariantUnit::describe($record->unit->name, $record->unit->factor)),
                TextColumn::make('barcode')
                    ->label('Barcode')
                    ->state(fn (LabelQueueItem $record): ?string => $record->unit === null ? $record->variant->barcode : $record->unit->barcode)
                    ->placeholder('In-store barcode when printed')
                    ->fontFamily('mono'),
                TextColumn::make('batch_no')
                    ->label('Batch')
                    ->state(fn (LabelQueueItem $record): ?string => $record->batch_no === null ? null : $record->batch_no.($record->expiry_date === null ? '' : ' · exp '.$record->expiry_date->format('m/Y')))
                    ->placeholder('-')
                    ->visible($pharmacy),
                TextColumn::make('source_label')
                    ->label('From')
                    ->state(fn (LabelQueueItem $record): string => LabelService::SOURCES[$record->source].($record->source_label === null ? '' : ": {$record->source_label}"))
                    ->toggleable(),
                TextInputColumn::make('quantity')
                    ->label('Copies')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:1', 'max:'.(int) config('pos.labels.max_copies', 1000)])
                    ->extraInputAttributes(['min' => 1, 'style' => 'width: 6rem'])
                    ->alignEnd(),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->action(fn (LabelQueueItem $record) => $record->delete()),
            ])
            ->toolbarActions([
                BulkAction::make('removeSelected')
                    ->label('Remove selected')
                    ->icon(Heroicon::OutlinedXMark)
                    ->action(fn (Collection $records) => LabelQueueItem::query()
                        ->where('user_id', self::user()?->getKey() ?? 0)
                        ->whereKey($records->modelKeys())
                        ->delete())
                    ->deselectRecordsAfterCompletion(),
            ])
            ->headerActions([$this->printQueueAction(), $this->emptyQueueAction(), $this->printLogAction()])
            ->defaultSort('id')
            ->paginated(false)
            ->emptyStateHeading('No labels waiting')
            ->emptyStateDescription('Use "Print labels" on a product, a delivery, the Batches screen or the product list, then "Add to print queue".');
    }

    private function printQueueAction(): Action
    {
        return Action::make('print')
            ->label(function (): string {
                $queued = app(LabelService::class)->queuedLabels(self::user() ?? abort(403));

                return "Print {$queued} ".Str::plural('label', $queued);
            })
            ->icon(Heroicon::OutlinedPrinter)
            ->visible(fn (): bool => app(LabelService::class)->queuedLabels(self::user() ?? abort(403)) > 0)
            ->authorize(fn (): bool => LabelActions::canPrint())
            ->modalHeading('Print the queue')
            ->modalDescription('Every label in the queue prints with this template. The queue is emptied and the print is in the print log.')
            ->modalSubmitActionLabel('Print')
            ->fillForm(fn (): array => ['label_template_id' => app(LabelService::class)->defaultTemplate()->getKey()])
            ->schema([
                Select::make('label_template_id')
                    ->label('Template')
                    ->options(fn (): array => app(LabelService::class)->templateOptions())
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->action(function (array $data, Action $action): void {
                $labels = app(LabelService::class);

                try {
                    $template = LabelTemplate::query()->find((int) $data['label_template_id']) ?? $labels->defaultTemplate();
                    $print = $labels->printQueue(self::user() ?? abort(403), $template);
                } catch (PosException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                LabelActions::open($print, $this);
            });
    }

    private function emptyQueueAction(): Action
    {
        return Action::make('clear')
            ->label('Empty the queue')
            ->icon(Heroicon::OutlinedTrash)
            ->color('gray')
            ->visible(fn (): bool => app(LabelService::class)->queuedLabels(self::user() ?? abort(403)) > 0)
            ->requiresConfirmation()
            ->modalDescription('Every label waiting in your queue is removed. Nothing is printed.')
            ->action(function (): void {
                app(LabelService::class)->clearQueue(self::user() ?? abort(403));

                Notification::make()->title('Print queue emptied')->success()->send();
            });
    }

    private function printLogAction(): Action
    {
        return Action::make('log')
            ->label('Print log')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->url(fn (): string => LabelPrintResource::getUrl('index'));
    }
}
