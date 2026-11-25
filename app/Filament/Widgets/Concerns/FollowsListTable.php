<?php

namespace App\Filament\Widgets\Concerns;

use Livewire\Attributes\Reactive;

/**
 * A header widget on a list page that follows the table's filters and
 * search (the page uses ExposesListFilters). Lighter than Filament's
 * InteractsWithPageTable: the widget reads the filters itself, so the
 * page's own query (its sort, its per-row subqueries, its count) is not
 * built again.
 */
trait FollowsListTable
{
    /** @var array<string, mixed>|null */
    #[Reactive]
    public ?array $tableFilters = null;

    /** @var string|null */
    #[Reactive]
    public $tableSearch = '';
}
