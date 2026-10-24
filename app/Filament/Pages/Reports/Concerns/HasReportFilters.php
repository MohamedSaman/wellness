<?php

namespace App\Filament\Pages\Reports\Concerns;

use App\Filament\Pages\Reports\Export\ReportDocument;
use App\Filament\Pages\Reports\Export\ReportExporter;
use App\Filament\Pages\Reports\Export\ReportTable;
use App\Models\Location;
use App\Models\User;
use App\Services\ReportService;
use App\Support\CurrentShop;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What every report page shares (docs/PHARMACY.md §9, branch 11): the
 * date range in the shop's days with its presets (today, yesterday, this
 * week, this month, last month, custom), the location or counter filter
 * (with multi_location), who may open it (reports.view, and
 * reports.profit for profit reports), and the exports: Excel, PDF and a
 * print page, all written from the page's document().
 *
 * The using class declares the static flags $hasDateRange,
 * $hasLocationFilter, $requiresProfitPermission and $defaultRange.
 */
trait HasReportFilters
{
    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url]
    public ?string $location = null;

    /** A ReportPeriod::PRESETS key; "custom" (or none) is the dates typed. */
    #[Url]
    public ?string $range = null;

    /** The report as a document: what Excel, the PDF and the print page are written from. */
    abstract public function document(): ReportDocument;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->can('reports.view')
            && (! static::$requiresProfitPermission || $user->can('reports.profit'))
            && static::isAvailable();
    }

    /** Reports that need a module or a shop setting say so here. */
    protected static function isAvailable(): bool
    {
        return true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->resolveFilters();
    }

    /** Puts the dates in order: a preset gives its days (today's, whenever the page opens), anything else is checked. */
    public function resolveFilters(): void
    {
        if (! static::$hasDateRange) {
            return;
        }

        if ($this->range === null && $this->from === null && $this->to === null) {
            $this->range = static::$defaultRange;
        }

        $preset = ReportPeriod::preset(app(CurrentShop::class)->getOrFail(), $this->range);

        if ($preset === null) {
            $this->range = 'custom';
            $preset = $this->period();
        }

        $this->from = $preset->fromDate();
        $this->to = $preset->toDate();
    }

    /**
     * Sets the filters from a query string (the print page), as the page's
     * own URL would: only its #[Url] properties, as strings or lists.
     *
     * @param  array<string, mixed>  $query
     */
    public function applyQuery(array $query): void
    {
        foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(Url::class) === [] || ! array_key_exists($property->getName(), $query)) {
                continue;
            }

            $value = $query[$property->getName()];
            $type = $property->getType();
            $wantsArray = $type instanceof \ReflectionNamedType && $type->getName() === 'array';

            $this->{$property->getName()} = $wantsArray
                ? array_values(array_map('strval', array_filter((array) $value, 'is_scalar')))
                : (is_scalar($value) ? (string) $value : null);
        }

        $this->resolveFilters();
    }

    /**
     * The filters as a query string, for the print page.
     *
     * @return array<string, mixed>
     */
    public function filterQuery(): array
    {
        $query = [];

        foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getAttributes(Url::class) !== [] && $this->{$property->getName()} !== null && $this->{$property->getName()} !== []) {
                $query[$property->getName()] = $this->{$property->getName()};
            }
        }

        return $query;
    }

    protected function reports(): ReportService
    {
        return app(ReportService::class);
    }

    protected function canSeeProfit(): bool
    {
        return auth()->user()?->can('reports.profit') ?? false;
    }

    protected function canSeeCost(): bool
    {
        return auth()->user()?->can('cost.view') ?? false;
    }

    protected function multiLocation(): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule('multi_location');
    }

    protected function hasPharmacy(): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule('pharmacy');
    }

    /** The location picked in the filter, when it is one of this shop's. */
    public function locationId(): ?int
    {
        if (! static::$hasLocationFilter || ! ctype_digit((string) $this->location) || ! $this->multiLocation()) {
            return null;
        }

        return Location::query()->whereKey((int) $this->location)->exists() ? (int) $this->location : null;
    }

    public function period(): ReportPeriod
    {
        $shop = app(CurrentShop::class)->getOrFail();
        $today = CarbonImmutable::now($shop->timezone ?: 'UTC');

        return ReportPeriod::between(
            $shop,
            $this->validDate($this->from) ?? $today->subDays(29)->format('Y-m-d'),
            $this->validDate($this->to) ?? $today->format('Y-m-d'),
        );
    }

    private function validDate(?string $date): ?string
    {
        return $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            ? $date
            : null;
    }

    /** What the location filter is called: "Location", or "Counter" on the end-of-day report. */
    protected function locationLabel(): string
    {
        return 'Location';
    }

    /** Called after any filter changes (a paged table goes back to page 1). */
    protected function filtersUpdated(): void {}

    /**
     * The filter fields above the report: the date range with its presets
     * and the location, where the report has them. Reports add their own
     * after these.
     *
     * @return list<Component>
     */
    protected function filterFields(): array
    {
        $fields = static::$hasDateRange ? [
            Select::make('range')
                ->label('Period')
                ->options(ReportPeriod::PRESETS)
                ->selectablePlaceholder(false)
                ->live()
                ->afterStateUpdated(function (): void {
                    $this->resolveFilters();
                    $this->filtersUpdated();
                }),
            DatePicker::make('from')->label('From')->required()->live()->afterStateUpdated(function (): void {
                $this->range = 'custom';
                $this->filtersUpdated();
            }),
            DatePicker::make('to')->label('To')->required()->live()->afterStateUpdated(function (): void {
                $this->range = 'custom';
                $this->filtersUpdated();
            }),
        ] : [];

        if (static::$hasLocationFilter && $this->multiLocation()) {
            $fields[] = Select::make('location')
                ->label($this->locationLabel())
                ->options(fn (): array => collect(Location::options())->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])->all())
                ->placeholder('All locations')
                ->live()
                ->afterStateUpdated(fn () => $this->filtersUpdated());
        }

        return $fields;
    }

    public function filters(Schema $schema): Schema
    {
        $fields = $this->filterFields();

        return $schema->components([
            Grid::make(['default' => 1, 'sm' => 2, 'xl' => min(max(count($fields), 1), 5)])->schema($fields),
        ]);
    }

    /**
     * What the document says it covers: the period, the location and any
     * other filter the report adds (documentFilters()).
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function documentMeta(): array
    {
        $meta = [];

        if (static::$hasDateRange) {
            $period = $this->period();
            $preset = $this->range !== null && $this->range !== 'custom' ? ' ('.ReportPeriod::PRESETS[$this->range].')' : '';
            $meta[] = ['Period', $period->label().$preset];
        } else {
            $meta[] = ['As at', CarbonImmutable::now(app(CurrentShop::class)->getOrFail()->timezone ?: 'UTC')->format('d/m/Y H:i')];
        }

        if (static::$hasLocationFilter && $this->multiLocation()) {
            $id = $this->locationId();
            $meta[] = [$this->locationLabel(), $id === null ? 'All locations' : (string) Location::query()->whereKey($id)->value('name')];
        }

        return [...$meta, ...$this->documentFilters()];
    }

    /** @return list<array{0: string, 1: string}> */
    protected function documentFilters(): array
    {
        return [];
    }

    /** "daily-summary_2026-10-21_2026-10-21": the page's slug, then its dates (or today's). */
    protected function documentFilename(): string
    {
        $period = $this->period();

        return static::$hasDateRange
            ? static::getSlug()."_{$period->fromDate()}_{$period->toDate()}"
            : static::getSlug().'_'.CarbonImmutable::now($period->timezone)->format('Y-m-d');
    }

    /** @param  list<ReportTable>  $tables */
    protected function makeDocument(array $tables): ReportDocument
    {
        $title = $this->getTitle();

        return new ReportDocument($title instanceof Htmlable ? strip_tags($title->toHtml()) : $title, $this->documentMeta(), $tables, $this->documentFilename());
    }

    public function exportXlsx(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return app(ReportExporter::class)->xlsxDownload($this->document());
    }

    public function exportPdf(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return app(ReportExporter::class)->pdfDownload($this->document());
    }

    /**
     * Excel, PDF and Print, for the table's or the page's header.
     *
     * @return list<Action>
     */
    protected function exportActions(): array
    {
        return [
            Action::make('exportXlsx')
                ->label('Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportXlsx()),
            Action::make('exportPdf')
                ->label('PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportPdf()),
            Action::make('print')
                ->label('Print')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (): string => route('reports.print', ['report' => static::getSlug(), ...$this->printQuery()]))
                ->openUrlInNewTab(),
        ];
    }

    /** @return array<string, mixed> */
    protected function printQuery(): array
    {
        return [...$this->filterQuery(), 'print' => 1];
    }
}
