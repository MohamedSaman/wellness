<?php

namespace App\Filament\Resources\Products\Pages\Concerns;

use Filament\Support\Enums\Width;

/** What the product's Create and Edit pages share around the form. */
trait ProductFormPage
{
    protected Width|string|null $maxContentWidth = Width::Full;
}
