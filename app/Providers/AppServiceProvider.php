<?php

namespace App\Providers;

use App\Support\PasswordRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Behind Caddy (docs/deploy/CLOUD.md): trust its X-Forwarded-* headers only.
        TrustProxies::at(config('pos.security.trusted_proxies'));

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Where the user is not known: the stricter rule of App\Support\PasswordRules
        // (admin users); each form that knows the user asks PasswordRules itself.
        Password::defaults(fn (): Password => PasswordRules::forAdmin(true));
    }
}
