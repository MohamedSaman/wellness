<?php

namespace App\Filament\Resources\ImportRuns\Pages;

use App\Enums\ImportExisting;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Exceptions\PosException;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
use App\Models\Shop;
use App\Models\User;
use App\Services\Import\ImportField;
use App\Services\Import\ImportService;
use App\Services\Import\SpreadsheetReader;
use App\Support\CurrentShop;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Importing one file, step by step (ImportService): choose the type and
 * the file; match its columns to the fields (suggested from the headings
 * or the shop's saved mapping); check it (the dry run: counts, errors and
 * warnings per row, the error report); confirm, and watch it import (it
 * runs on the queue, in one transaction). The run's uuid is in the URL, so
 * the page can be left and opened again from the import log.
 */
class ImportFile extends Page
{
    protected static string $resource = ImportRunResource::class;

    protected static ?string $title = 'Import a file';

    protected static ?string $breadcrumb = 'Import a file';

    #[Url]
    public ?string $run = null;

    /** @var array<string, mixed>|null */
    public ?array $upload = [];

    /** @var array<string, mixed>|null */
    public ?array $columns = [];

    /**
     * The last check's counts and issues (issues are not stored: on a
     * reload the counts come from the run and the details from the report).
     *
     * @var array{counts: array<string, int>, issues: list<array{row: int, errors: list<string>, warnings: list<string>}>, before: string|null}|null
     */
    public ?array $preview = null;

    private ?ImportRun $loaded = null;

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('imports.manage');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $run = $this->importRun();

        if ($this->run !== null && $run === null) {
            abort(404);
        }

        if ($run === null) {
            $this->getSchema('uploadForm')->fill(['type' => array_key_first(ImportType::options($this->shop()))]);

            return;
        }

        $this->fillMapping($run);
    }

    public function getSubheading(): ?string
    {
        $run = $this->importRun();

        return $run === null
            ? 'Download the template from the import log, or use your own file: you match its columns next. Nothing is written until you have checked the file and confirmed.'
            : $run->type->labelFor($this->shop()).' · '.$run->file_name.' · '.$run->status->label();
    }

    /** The run, read once per request (the sections all ask); forgetRun() after changing it. */
    public function importRun(): ?ImportRun
    {
        if ($this->run === null) {
            return null;
        }

        if ($this->loaded?->uuid !== $this->run) {
            $this->loaded = ImportRun::query()->with('user')->where('uuid', $this->run)->first();
        }

        return $this->loaded;
    }

    private function forgetRun(): void
    {
        $this->loaded = null;
    }

    public function uploadForm(Schema $schema): Schema
    {
        $shop = $this->shop();

        return $schema
            ->statePath('upload')
            ->components([
                Select::make('type')
                    ->label('What is in the file')
                    ->options(ImportType::options($shop))
                    ->required()
                    ->selectablePlaceholder(false)
                    ->helperText('Best in this order: suppliers, items, opening stock, opening invoices.'),
                FileUpload::make('file')
                    ->label('File')
                    ->required()
                    ->storeFiles(false)
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream',
                    ])
                    ->maxSize(app(ImportService::class)->maxFileKb())
                    ->helperText('Excel (.xlsx) or CSV. The first row must be the column headings.'),
            ]);
    }

    public function mappingForm(Schema $schema): Schema
    {
        $run = $this->importRun();

        if ($run === null) {
            return $schema->statePath('columns')->components([]);
        }

        $headings = [];

        foreach ($run->headings ?? [] as $index => $heading) {
            $headings[$index] = SpreadsheetReader::columnLetter($index).' · '.$heading;
        }

        $selects = array_map(fn (ImportField $field): Select => Select::make('map.'.self::safe($field->key))
            ->label($field->label)
            ->options($headings)
            ->placeholder('Not in the file')
            ->required($field->required)
            ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: $field->help), app(ImportService::class)->fieldsFor($run));

        return $schema
            ->statePath('columns')
            ->components([
                Grid::make(2)->schema([
                    Radio::make('existing')
                        ->label('Rows already on file')
                        ->options(ImportExisting::options())
                        ->visible($run->type->canUpdateExisting())
                        ->required()
                        ->helperText($run->type === ImportType::Items ? 'An item is on file when its SKU, barcode or (with neither) exact name is.' : 'A supplier is on file when its name is.'),
                    Toggle::make('remember')
                        ->label('Remember these columns for the next file')
                        ->helperText('A file with the same headings is matched the same way.'),
                ]),
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])->schema($selects),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('1. Choose the file')
                ->visible(fn (): bool => $this->importRun() === null)
                ->schema([
                    Form::make([EmbeddedSchema::make('uploadForm')])
                        ->id('uploadForm')
                        ->livewireSubmitHandler('uploadFile')
                        ->footer([Actions::make([
                            Action::make('uploadFile')->label('Upload and match the columns')->submit('uploadFile'),
                        ])]),
                ]),
            Section::make('2. Match the columns')
                ->description('Each field the import understands, and the column of your file that holds it. Columns not chosen are ignored.')
                ->visible(fn (): bool => $this->editable())
                ->collapsible()
                ->collapsed(fn (): bool => $this->preview !== null)
                ->schema([
                    Form::make([EmbeddedSchema::make('mappingForm')])
                        ->id('mappingForm')
                        ->livewireSubmitHandler('check')
                        ->footer([Actions::make([
                            Action::make('check')->label('Check the file')->submit('check'),
                        ])]),
                ]),
            Section::make('3. Check')
                ->visible(fn (): bool => $this->importRun()?->status === ImportStatus::Previewed)
                ->schema([
                    View::make('filament.imports.preview')->viewData(fn (): array => $this->previewData()),
                    Actions::make([
                        Action::make('confirmImport')
                            ->label(fn (): string => 'Import '.$this->toWrite().' rows')
                            ->icon(Heroicon::OutlinedArrowUpTray)
                            ->requiresConfirmation()
                            ->modalHeading('Import this file?')
                            ->modalDescription(fn (): string => $this->toWrite().' rows will be written, all in one go. Rows on file are '.($this->importRun()?->existing === ImportExisting::Update ? 'updated' : 'skipped').'. Warnings you leave are accepted (short expiry, missing prices).')
                            ->modalSubmitActionLabel('Import')
                            ->disabled(fn (): bool => ! $this->canConfirm())
                            ->action(fn () => $this->confirmImport()),
                        Action::make('report')
                            ->label('Download the error report')
                            ->color('gray')
                            ->icon(Heroicon::OutlinedDocumentArrowDown)
                            ->url(fn (): string => route('imports.report', $this->run))
                            ->visible(fn (): bool => $this->importRun()?->error_report_path !== null),
                        Action::make('another')
                            ->label('Upload a corrected file')
                            ->color('gray')
                            ->url(fn (): string => self::getUrl()),
                    ]),
                ]),
            Section::make(fn (): string => $this->importRun()?->status->label() ?? '')
                ->visible(fn (): bool => in_array($this->importRun()?->status, [ImportStatus::Queued, ImportStatus::Running, ImportStatus::Completed, ImportStatus::Failed], true))
                ->schema([
                    View::make('filament.imports.progress')->viewData(fn (): array => $this->progressData()),
                    Actions::make([
                        Action::make('log')->label('Back to the import log')->color('gray')->url(ImportRunResource::getUrl()),
                        Action::make('next')->label('Import another file')->url(fn (): string => self::getUrl()),
                    ]),
                ]),
        ]);
    }

    /** Step 1: keep the file and read its headings. */
    public function uploadFile(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var array{type: string, file: TemporaryUploadedFile|array<TemporaryUploadedFile>} $data */
        $data = $this->getSchema('uploadForm')->getState();
        $file = is_array($data['file']) ? reset($data['file']) : $data['file'];

        if (! $file instanceof TemporaryUploadedFile) {
            Notification::make()->title('Choose a file to upload.')->danger()->send();

            return;
        }

        try {
            $run = app(ImportService::class)->upload(ImportType::from($data['type']), (string) $file->getRealPath(), $file->getClientOriginalName(), $this->user());
        } catch (PosException $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

            return;
        } finally {
            $file->delete();
        }

        $this->run = $run->uuid;
        $this->preview = null;
        $this->forgetRun();
        $this->fillMapping($run);
    }

    /** Step 2: the dry run with the columns as matched. */
    public function check(): void
    {
        $run = $this->importRun() ?? abort(404);

        /** @var array{map?: array<string, int|string|null>, existing?: string|null, remember?: bool} $state */
        $state = $this->getSchema('mappingForm')->getState();
        $mapping = [];

        foreach ($state['map'] ?? [] as $safe => $index) {
            $mapping[self::unsafe($safe)] = $index === null || $index === '' ? null : (int) $index;
        }

        try {
            $preview = app(ImportService::class)->preview(
                $run,
                $mapping,
                ImportExisting::tryFrom((string) ($state['existing'] ?? '')) ?? ImportExisting::Skip,
                $this->user(),
                (bool) ($state['remember'] ?? true),
            );
        } catch (PosException $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

            return;
        }

        $this->forgetRun();
        $before = $preview->importedBefore;

        $this->preview = [
            'counts' => $preview->counts,
            'issues' => $preview->issues,
            'before' => $before === null ? null : 'This file was imported before, on '.$before->finished_at?->setTimezone($this->shop()->timezone)->format('d/m/Y H:i').' by '.($before->user->name ?? 'someone').'. Rows already on file are '.($preview->run->existing === ImportExisting::Update ? 'updated' : 'skipped').', never created twice.',
        ];
    }

    /** Step 3: queue the write. */
    public function confirmImport(): void
    {
        $run = $this->importRun() ?? abort(404);

        try {
            app(ImportService::class)->confirm($run, $this->user());
        } catch (PosException $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

            return;
        }

        $this->forgetRun();
        $run->refresh();

        if ($run->status === ImportStatus::Completed) {
            Notification::make()->title('Imported: '.$run->countsSummary())->success()->send();
        } elseif ($run->status === ImportStatus::Failed) {
            Notification::make()->title('Nothing was imported')->body($run->message)->danger()->persistent()->send();
        }
    }

    /** Polled while the import runs. */
    public function refreshProgress(): void
    {
        $run = $this->importRun();

        if ($run !== null && $run->status === ImportStatus::Completed) {
            Notification::make()->title('Imported: '.$run->countsSummary())->success()->send();
        }
    }

    /** @return array<string, mixed> */
    private function previewData(): array
    {
        $run = $this->importRun();

        return [
            'counts' => $this->preview['counts'] ?? $run->preview_counts ?? [],
            'issues' => $this->preview['issues'] ?? null,
            'before' => $this->preview['before'] ?? null,
            'limit' => (int) config('pos.imports.preview_issue_limit', 200),
            'reportUrl' => $run?->error_report_path === null ? null : route('imports.report', $run),
        ];
    }

    /** @return array<string, mixed> */
    private function progressData(): array
    {
        $run = $this->importRun();

        return [
            'run' => $run,
            'done' => $run === null ? 0 : app(ImportService::class)->progress($run),
            'counts' => $run->result_counts ?? [],
            'reportUrl' => $run?->error_report_path === null ? null : route('imports.report', $run),
        ];
    }

    private function fillMapping(ImportRun $run): void
    {
        $map = [];

        foreach ((array) $run->mapping as $key => $index) {
            $map[self::safe($key)] = $index;
        }

        $this->getSchema('mappingForm')->fill([
            'map' => $map,
            'existing' => $run->existing->value,
            'remember' => true,
        ]);
    }

    private function editable(): bool
    {
        return in_array($this->importRun()?->status, [ImportStatus::Uploaded, ImportStatus::Previewed, ImportStatus::Failed], true);
    }

    private function toWrite(): int
    {
        $counts = $this->importRun()->preview_counts ?? [];

        return (int) ($counts['created'] ?? 0) + (int) ($counts['updated'] ?? 0);
    }

    private function canConfirm(): bool
    {
        $counts = $this->importRun()->preview_counts ?? [];

        return ($counts['errors'] ?? 1) === 0 && $this->toWrite() > 0;
    }

    /** Field keys like "sell_loose:GEN" as form state keys. */
    private static function safe(string $key): string
    {
        return str_replace(':', '__', $key);
    }

    private static function unsafe(string $key): string
    {
        return str_replace('__', ':', $key);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function shop(): Shop
    {
        return app(CurrentShop::class)->getOrFail();
    }
}
