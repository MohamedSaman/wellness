<?php

namespace App\Filament\Pages\Reports;

class SalesByBrandReport extends SalesByCategoryReport
{
    protected static ?string $title = 'Sales by brand';

    protected static ?int $navigationSort = 4;

    protected string $groupBy = 'brand';
}
