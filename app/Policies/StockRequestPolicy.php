<?php

namespace App\Policies;

use App\Models\StockRequest;
use App\Models\User;
use App\Services\StockRequestService;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock requests (multi_location): stock.transfer (the owner, who sends the
 * stock) sees and fulfils them all; stock.request (a cashier at the OPD)
 * makes them and sees those for the locations the user may use. Changed
 * only through StockRequestService (fulfil, cancel), never edited.
 */
class StockRequestPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'stock.request';

    protected ?string $managePermission = 'stock.request';

    public function viewAny(User $user): bool
    {
        return $user->can('stock.request') || $user->can('stock.transfer');
    }

    public function view(User $user, Model $record): bool
    {
        return $record instanceof StockRequest && app(StockRequestService::class)->mayView($record, $user);
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }
}
