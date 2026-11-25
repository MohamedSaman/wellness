<?php

namespace App\Filament\Widgets\Summary;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Reactive;

/**
 * One bar chart of a list's summary (ListSummaryWidget), drawn from the
 * figures the summary hands it, so a summary reads its figures once however
 * many charts it has. Whole numbers, stacked series, the dashboard charts'
 * look.
 */
class SummaryChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    /** @var array{heading: string, description: string|null, labels: list<string>, series: list<array{label: string, color: string, data: list<int>}>} */
    #[Reactive]
    public array $chart = ['heading' => '', 'description' => null, 'labels' => [], 'series' => []];

    public static function canView(): bool
    {
        return auth()->check();
    }

    public function getHeading(): string
    {
        return $this->chart['heading'];
    }

    public function getDescription(): ?string
    {
        return $this->chart['description'];
    }

    protected function getMaxHeight(): ?string
    {
        return '220px';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> none (Filament's empty state) when there is nothing to draw */
    protected function getData(): array
    {
        if ($this->chart['labels'] === []) {
            return [];
        }

        return [
            'datasets' => array_map(fn (array $series): array => [
                'label' => $series['label'],
                'backgroundColor' => $series['color'],
                'data' => $series['data'],
            ], $this->chart['series']),
            'labels' => $this->chart['labels'],
        ];
    }

    public function getEmptyStateHeading(): string
    {
        return 'No visits';
    }

    /** The bars in words, for a screen reader: "09:00: OPD 1, Pharmacy 2". */
    public function getChartAssistiveContent(): ?string
    {
        $bars = [];

        foreach ($this->chart['labels'] as $index => $label) {
            $values = array_filter(array_map(fn (array $series): ?string => ($series['data'][$index] ?? 0) === 0 ? null : $series['label'].' '.$series['data'][$index], $this->chart['series']));

            if ($values !== []) {
                $bars[] = $label.': '.implode(', ', $values);
            }
        }

        return $bars === [] ? null : implode('; ', $bars);
    }

    protected function getOptions(): RawJs
    {
        // Inside an HTML attribute (x-data="..."): JS strings in single quotes only.
        return RawJs::make(<<<'JS'
            {
                borderRadius: 4,
                borderWidth: 0,
                datasets: { bar: { maxBarThickness: 32, borderSkipped: 'start' } },
                plugins: {
                    legend: { labels: { boxWidth: 12 }, display: (context) => context.chart.data.datasets.length > 1 },
                    tooltip: { mode: 'index', intersect: false },
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                    y: { stacked: true, beginAtZero: true, grid: { display: true }, ticks: { precision: 0, maxTicksLimit: 5 } },
                },
            }
        JS);
    }
}
