<?php

namespace App\Policies;

/** The stock levels screen lists variants: viewing needs stock.view, and nothing is edited there directly. */
class ProductVariantPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'stock.view';

    protected ?string $managePermission = null;
}
