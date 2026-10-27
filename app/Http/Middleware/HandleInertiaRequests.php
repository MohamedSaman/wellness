<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\BrandingService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * The admin panel (Filament) is not an Inertia app. When an Inertia request
     * is redirected there (signing in as the owner, /dashboard, ...), tell the
     * browser to do a full page load; following the redirect inside Inertia
     * would show the whole panel in Inertia's error modal.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);

        if ($request->header('X-Inertia') && $response instanceof RedirectResponse && $this->isAdminPanelUrl($response->getTargetUrl())) {
            return Inertia::location($response->getTargetUrl());
        }

        return $response;
    }

    private function isAdminPanelUrl(string $url): bool
    {
        $panelPath = '/'.trim(Filament::getPanel('admin')->getPath(), '/');
        $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path === $panelPath || str_starts_with($path, $panelPath.'/');
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // The shop's name and logo (the tab title, the sign-in page, the sidebar), signed in or not.
            'name' => fn (): string => app(BrandingService::class)->name(),
            'brand' => fn (): array => app(BrandingService::class)->pageBrand(),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Seconds without activity before the page signs the user out (lib/idleLogout.ts); 0 = never.
            'idleTimeout' => fn (): int => $request->user() instanceof User ? LogOutIdleUsers::limitSeconds($request->user(), $request) : 0,
        ];
    }
}
