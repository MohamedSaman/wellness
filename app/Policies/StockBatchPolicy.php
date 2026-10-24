<?php

namespace App\Policies;

/**
 * Batches are read by anyone who sees stock. They change only through
 * stock movements (StockService); the screen's actions check their own
 * permissions (stock.adjust, batches.manage).
 */
class StockBatchPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'stock.view';

    protected ?string $managePermission = null;
}
