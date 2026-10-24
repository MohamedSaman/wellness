<?php

namespace App\Http\Middleware;

use App\Support\CurrentShop;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides a whole module's routes unless it is switched on for the shop.
 *   Route::middleware('module:purchasing')->group(...)
 */
class EnsureModuleEnabled
{
    public function __construct(private readonly CurrentShop $currentShop) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $shop = $this->currentShop->get();

        abort_unless($shop !== null && $shop->hasModule($module), 404);

        return $next($request);
    }
}
