<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The owner's main dashboard (/admin): today's figures, the sales trend,
 * best sellers and low stock. One column on phones, two from large screens.
 */
class Dashboard extends BaseDashboard
{
    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
