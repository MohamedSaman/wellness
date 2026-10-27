<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ThemePresetRequest;
use App\Services\BrandingService;
use App\Services\ThemeService;
use App\Support\CurrentShop;
use App\Support\ThemePresets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Appearance: light, dark or system for this browser (anyone),
 * and the shop's colour theme (the owner only).
 */
class AppearanceController extends Controller
{
    public function edit(Request $request, ThemeService $theme, BrandingService $branding): Response
    {
        return Inertia::render('settings/Appearance', [
            'theme' => Gate::allows('change-theme') ? [
                'current' => $theme->preset(),
                'presets' => $theme->pickerOptions(),
                'hasBrandColor' => $branding->color() !== null,
            ] : null,
        ]);
    }

    /**
     * The shop's colour theme. Choosing one clears the brand colour (Shop
     * settings → Branding), so the shop looks as the preview showed.
     */
    public function updateTheme(ThemePresetRequest $request, CurrentShop $currentShop): RedirectResponse
    {
        $shop = $currentShop->getOrFail();
        $preset = (string) $request->validated('preset');

        $shop->update([
            'settings' => [
                ...($shop->settings ?? []),
                'theme_preset' => $preset,
                'brand_color' => null,
            ],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Colour theme set to :theme.', ['theme' => ThemePresets::options()[$preset]])]);

        return to_route('appearance.edit');
    }
}
