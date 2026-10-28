<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Support\PosIcon;
use App\Http\Middleware\AdminIpAllowlist;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\LogOutIdleUsers;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\User;
use App\Services\BrandingService;
use App\Services\ThemeService;
use App\Support\CurrentShop;
use Filament\Actions\ActionGroup;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // Action menus (⋮) float with fixed positioning, so a table's horizontal-scroll
        // wrapper never clips them. Anchored to the button's right edge so they open
        // into the page, and flipped upwards when there is no room below. Row actions
        // are an ordinary last column (not pinned): a pinned cell covered the columns
        // that scrolled under it, the amounts on Payables among them.
        ActionGroup::configureUsing(fn (ActionGroup $group): ActionGroup => $group
            ->dropdownTeleport()
            ->defaultDropdownPlacement('bottom-end'));
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // No login page of the panel's own: /admin sends a signed-out user to
            // Fortify's /login, which asks for the two-factor code (Filament's
            // login would not), and LoginResponse brings the owner back here.
            ->colors([
                'primary' => Color::Amber,
            ])
            // The shop's colour theme (Settings → Appearance; ThemeService): a themed preset
            // replaces the amber with its primary (success too) and sets danger and warning;
            // classic keeps the amber. A brand colour (Shop settings → Branding) still replaces
            // primary on top. Registered as a closure so it is read when the page renders, once
            // the user (and so the shop) is known.
            ->bootUsing(function (): void {
                FilamentColor::register(fn (): array => app(ThemeService::class)->panelColors());
            })
            // Sidebar, page and card colours of a themed preset (scoped to html[data-pos-theme]).
            ->viteTheme('resources/css/filament/admin/theme.css')
            // The shop's name on the tab and the top bar; its favicon, else its logo, else the plain mark.
            ->brandName(fn (): string => app(BrandingService::class)->name())
            ->favicon(fn (): string => app(BrandingService::class)->iconUrl())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->navigationGroups(['Catalog', 'Stock', 'Purchasing', 'Sales', 'Reports', 'Administration'])
            ->navigationItems([
                NavigationItem::make('Open POS')
                    ->url(fn (): string => route('pos.index'))
                    ->icon(PosIcon::cashRegister())
                    ->sort(-10) // above Dashboard (-2)
                    ->visible(fn (): bool => self::canUsePos()),
            ])
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => self::canUsePos() ? view('filament.open-pos-button')->render() : '',
            )
            // First in <head>: the preset's name on <html> and its variables, before anything is drawn.
            ->renderHook(PanelsRenderHook::HEAD_START, fn (): string => app(ThemeService::class)->panelHead())
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => view('filament.idle-logout', ['seconds' => self::idleSeconds()])->render())
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => self::canUsePos() ? view('filament.open-pos-shortcut')->render() : '',
            )
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // The bell, for pharmacy shops: alerts such as stock sold beyond its batches
            // (BatchService::alertUnallocated). Retail shops see the panel as before.
            ->databaseNotifications(fn (): bool => app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false)
            // The dashboard widgets live in app/Filament/Widgets; no Filament defaults.
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureUserIsActive::class,
                LogOutIdleUsers::class,
                RequireTwoFactor::class,
            ])
            // Persistent: also on the panel's Livewire requests, which the web group's
            // middleware covers for everything else.
            ->authMiddleware([
                AdminIpAllowlist::class,
            ], isPersistent: true);
    }

    /** The signed-in user's idle limit in the panel (seconds, 0 = none), for the idle script. */
    private static function idleSeconds(): int
    {
        $user = auth()->user();

        return $user instanceof User ? LogOutIdleUsers::limitSeconds($user, request()) : 0;
    }

    /** The "Open POS" button and menu item are only for users who may sell. */
    private static function canUsePos(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('pos.checkout');
    }
}
