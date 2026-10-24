<?php

namespace App\Policies;

/** The stock ledger is read-only. */
class StockMovementPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'stock.view';

    protected ?string $managePermission = null;
}
