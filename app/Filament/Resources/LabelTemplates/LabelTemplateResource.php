<?php

namespace App\Filament\Resources\LabelTemplates;

use App\Enums\LabelField;
use App\Enums\LabelKind;
use App\Filament\Resources\LabelTemplates\Pages\CreateLabelTemplate;
use App\Filament\Resources\LabelTemplates\Pages\EditLabelTemplate;
use App\Filament\Resources\LabelTemplates\Pages\ListLabelTemplates;
use App\Filament\Resources\PermissionResource;
use App\Models\LabelTemplate;
use App\Services\Labels\LabelLayout;
use App\Services\Labels\LabelService;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Label templates (labels module, docs/PHARMACY.md §9, branch 7b), for the
 * owner (labels.manage): the label's size (a thermal roll or an A4 sticker
 * sheet, from a preset or custom, with margins and gaps), the printer's
 * resolution, and which fields it prints, with a live preview of sample
 * labels. A template is saved only when the samples fit: readable text and
 * a barcode wide enough to scan (LabelLayout).
 */
class LabelTemplateResource extends PermissionResource
{
    protected static ?string $model = LabelTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Label templates';

    protected static ?string $modelLabel = 'label template';

    protected static ?int $navigationSort = 12;

    public static function canAccess(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('labels') ?? false) && parent::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        $sheet = fn (Get $get): bool => $get('kind') === LabelKind::Sheet->value;
        $custom = fn (Set $set) => $set('preset', 'custom');
        $mm = fn (string $name, string $label) => TextInput::make($name)
            ->label($label)
            ->numeric()
            ->step(0.01)
            ->suffix('mm')
            ->required()
            ->live(debounce: 600);

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Label')->schema([
                    TextInput::make('name')->required()->maxLength(100),
                    Toggle::make('is_default')
                        ->label('Default template')
                        ->helperText('Chosen first when printing.'),
                    Select::make('preset')
                        ->label('Size')
                        ->options(fn (): array => [...LabelService::presetOptions(), 'custom' => 'Custom size'])
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            if ($state === null || $state === 'custom') {
                                return;
                            }

                            foreach (LabelService::presetAttributes($state) as $key => $value) {
                                $set($key, $value);
                            }
                        }),
                    Radio::make('kind')
                        ->label('Printed on')
                        ->options(LabelKind::options())
                        ->required()
                        ->live()
                        ->afterStateUpdated($custom),
                    Grid::make(2)->schema([
                        $mm('label_width', 'Label width')->afterStateUpdated($custom),
                        $mm('label_height', 'Label height')->afterStateUpdated($custom),
                    ]),
                    Grid::make(['default' => 2, 'md' => 4])
                        ->visible($sheet)
                        ->schema([
                            TextInput::make('columns')->label('Across')->integer()->minValue(1)->maxValue(20)->required()->live(debounce: 600)->afterStateUpdated($custom),
                            TextInput::make('rows')->label('Down')->integer()->minValue(1)->maxValue(40)->required()->live(debounce: 600)->afterStateUpdated($custom),
                            $mm('page_width', 'Page width')->afterStateUpdated($custom),
                            $mm('page_height', 'Page height')->afterStateUpdated($custom),
                            $mm('margin_top', 'Top margin')->afterStateUpdated($custom),
                            $mm('margin_left', 'Left margin')->afterStateUpdated($custom),
                            $mm('gap_x', 'Gap across')->afterStateUpdated($custom),
                            $mm('gap_y', 'Gap down')->afterStateUpdated($custom),
                        ]),
                    Grid::make(2)->schema([
                        $mm('padding', 'Blank edge')->helperText('Inside each label, all round.'),
                        Select::make('dpi')
                            ->label('Printer resolution')
                            ->options([
                                203 => '203 dpi (most thermal label printers)',
                                300 => '300 dpi',
                                600 => '600 dpi (office and laser printers)',
                            ])
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live()
                            ->helperText('Barcode bars are drawn in whole printer dots.'),
                    ]),
                ]),
                Section::make('What each label prints')->schema([
                    ...array_map(
                        fn (LabelField $field) => Toggle::make("fields.{$field->value}")
                            ->label($field->label())
                            ->helperText(match ($field) {
                                LabelField::Expiry => 'Off by default, as on receipts (the doctor\'s request, 2026-10-21).',
                                LabelField::Price => 'The price of that unit or pack on the default price list when the labels print.',
                                default => null,
                            })
                            ->visible(fn (): bool => ! $field->pharmacyOnly() || self::pharmacy())
                            ->live(),
                        LabelField::cases(),
                    ),
                    TextInput::make('shop_name')
                        ->label('Shop name on labels')
                        ->placeholder(fn (): string => app(CurrentShop::class)->getOrFail()->name)
                        ->helperText('A short name fits better. Empty: the shop\'s name.')
                        ->maxLength(40)
                        ->visible(fn (Get $get): bool => (bool) $get('fields.'.LabelField::ShopName->value))
                        ->live(debounce: 600),
                ]),
            ]),
            Section::make('Preview')
                ->description('Sample labels with these settings (not saved yet), shown at twice their size. Long names shrink, then wrap to two lines, then are cut short.')
                ->schema([
                    View::make('filament.label-preview')->viewData(fn (Get $get): array => self::preview($get)),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * The form's values as an unsaved template, its sample labels laid out,
     * and what stops it from saving.
     *
     * @return array<string, mixed>
     */
    private static function preview(Get $get): array
    {
        $fields = [];

        foreach (LabelField::cases() as $field) {
            $fields[$field->value] = (bool) $get("fields.{$field->value}");
        }

        $service = app(LabelService::class);
        $template = $service->makeTemplate([
            'name' => $get('name'),
            'kind' => $get('kind'),
            'label_width' => $get('label_width'),
            'label_height' => $get('label_height'),
            'columns' => $get('columns'),
            'rows' => $get('rows'),
            'page_width' => $get('page_width'),
            'page_height' => $get('page_height'),
            'margin_top' => $get('margin_top'),
            'margin_left' => $get('margin_left'),
            'gap_x' => $get('gap_x'),
            'gap_y' => $get('gap_y'),
            'padding' => $get('padding'),
            'dpi' => $get('dpi'),
            'fields' => $fields,
            'shop_name' => $get('shop_name'),
        ]);

        $layout = new LabelLayout($template);
        $problems = $service->problems($template);

        return [
            'template' => $template,
            'labels' => $service->preview($template),
            'problems' => $problems,
            'dot' => $layout->dot(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->description(fn (LabelTemplate $record): ?string => $record->is_default ? 'Default' : null)
                    ->searchable(),
                TextColumn::make('size')->label('Size')->state(fn (LabelTemplate $record): string => $record->sizeLabel()),
                TextColumn::make('kind')->label('Printed on')->formatStateUsing(fn (LabelKind $state): string => $state->label()),
                TextColumn::make('dpi')->label('Printer')->suffix(' dpi')->toggleable(),
                TextColumn::make('fields')
                    ->label('Prints')
                    ->state(fn (LabelTemplate $record): string => implode(', ', array_map(
                        fn (LabelField $field): string => $field->label(),
                        array_filter(LabelField::cases(), fn (LabelField $field): bool => $record->prints($field) && (! $field->pharmacyOnly() || self::pharmacy())),
                    )))
                    ->wrap(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    private static function pharmacy(): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule('pharmacy');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLabelTemplates::route('/'),
            'create' => CreateLabelTemplate::route('/create'),
            'edit' => EditLabelTemplate::route('/{record}/edit'),
        ];
    }
}
