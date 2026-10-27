<?php

namespace App\Providers;

use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\LogOutIdleUsers;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use App\Models\DeviceToken;
use App\Models\Shop;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\CurrentShop;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;

class PosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request / job (safe with queues and Octane).
        $this->app->scoped(CurrentShop::class);
    }

    public function boot(Router $router, Kernel $kernel): void
    {
        $this->configureModels();
        Sanctum::usePersonalAccessTokenModel(DeviceToken::class);
        $this->registerMiddleware($router, $kernel);
        $this->registerModules();
        $this->registerAuditing();
        $this->registerRateLimiters();

        // The shop's colour theme (Settings → Appearance) is the owner's alone: a role, not a
        // permission, so no other role can be given it (feat/clinic-theme-dashboard).
        Gate::define('change-theme', fn (User $user): bool => $user->hasRole('owner'));

        // /up, for the external uptime monitor (docs/deploy/CLOUD.md): down when the
        // database is, not only when PHP is.
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::connection()->select('select 1');
        });

        Event::listen(Login::class, function (Login $event): void {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        });
    }

    private function configureModels(): void
    {
        // In development and tests: throw on N+1 lazy loading, on assigning
        // attributes that are not allowed, and on reading missing attributes.
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    private function registerMiddleware(Router $router, Kernel $kernel): void
    {
        $kernel->pushMiddleware(SecurityHeaders::class);

        // Through the kernel (not only the router) so a later re-sync of
        // middleware groups can't silently drop it.
        // In this order: a disabled user is signed out first, then an idle one,
        // and only a user still signed in is sent to set up two-factor
        // authentication (docs/deploy/CLOUD.md, Security). The admin panel has
        // its own middleware list (AdminPanelProvider) with the same three.
        foreach ([EnsureUserIsActive::class, LogOutIdleUsers::class, RequireTwoFactor::class] as $middleware) {
            if ($kernel instanceof HttpKernel) {
                $kernel->appendMiddlewareToGroup('web', $middleware);
            } else {
                $router->pushMiddlewareToGroup('web', $middleware);
            }
        }
        $router->aliasMiddleware('module', EnsureModuleEnabled::class);
    }

    /**
     * Hidden modules: every module in config('pos.modules') is a Pennant
     * feature "module:{key}", OFF by default, switched on per shop with
     *   php artisan pos:module enable purchasing --shop=1
     *
     * vehicle_fitment starts ON for retail shops, so shops that existed
     * before the flag keep their vehicle screens with no data migration.
     * Pennant stores the resolved value on first check; after that only the
     * stored value counts.
     */
    private function registerModules(): void
    {
        Feature::resolveScopeUsing(fn () => $this->app->make(CurrentShop::class)->get());

        $defaults = [
            // A shop created in this request has no business_type attribute
            // until reloaded; the column defaults to retail.
            'vehicle_fitment' => fn (Shop $shop): bool => ($shop->business_type ?? 'retail') === 'retail',
        ];

        foreach (array_keys(config('pos.modules', [])) as $module) {
            Feature::define("module:{$module}", $defaults[$module] ?? fn () => false);
        }
    }

    private function registerRateLimiters(): void
    {
        // Live search fires while typing; scanners fire fast too. Generous, but per user.
        RateLimiter::for('pos-search', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->getKey() ?: $request->ip()));

        // A till syncing after a long outage sends many batches in a row; limit per till.
        RateLimiter::for('pos-sync', fn (Request $request) => Limit::perMinute(120)->by('till:'.($request->attributes->get('device')?->getKey() ?? $request->ip())));
    }

    private function registerAuditing(): void
    {
        foreach (config('pos.audited_models', []) as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
