<!DOCTYPE html>
{{-- The shop's colour theme (Settings → Appearance; ThemeService): a themed preset names itself on <html>, so its styles apply from the first paint. Classic sets nothing. --}}
@php($theme = app(\App\Services\ThemeService::class))
@php($themePreset = $theme->isThemed() ? $theme->preset() : null)
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark']) @if ($themePreset) data-pos-theme="{{ $themePreset }}" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        {{-- The shop's branding (Shop settings → Branding): its favicon and the till's accent colour. --}}
        @php($branding = app(\App\Services\BrandingService::class))
        @if ($favicon = $branding->faviconUrl())
            <link rel="icon" href="{{ $favicon }}">
        @else
            <link rel="icon" href="/favicon.ico" sizes="any">
            <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @endif
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="{{ $branding->tillColors()['theme'] ?? $theme->themeColor() ?? '#ff2d20' }}">
        {!! $theme->appStyle() !!}
        {!! $branding->tillStyle() !!}

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
