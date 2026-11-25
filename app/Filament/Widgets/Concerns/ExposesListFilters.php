<?php

namespace App\Filament\Widgets\Concerns;

/** For a list page: hands its table's applied filters and search to its header widgets (FollowsListTable). */
trait ExposesListFilters
{
    /** @return array<string, mixed> */
    public function getWidgetData(): array
    {
        return [
            'tableFilters' => $this->tableFilters,
            'tableSearch' => $this->getTableSearch(),
        ];
    }
}
