import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { VitePWA } from 'vite-plugin-pwa';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.ts',
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                // The themed presets' font (App\Support\ThemePresets). Not
                // preloaded: a classic (retail) page never asks for it.
                bunny('Inter', {
                    weights: [400, 500, 600, 700],
                    preload: false,
                }),
            ],
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
        // Offline checkout: resources/sw/sw.ts becomes public/sw.js (scope "/")
        // and precaches the built scripts, styles and fonts. The checkout page
        // registers it; the manifest is the static public/manifest.webmanifest.
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/sw',
            filename: 'sw.ts',
            outDir: 'public',
            injectRegister: false,
            manifest: false,
            injectManifest: {
                globDirectory: 'public',
                globPatterns: [
                    'build/assets/**/*.{js,css,woff,woff2,ttf,svg,png}',
                    'manifest.webmanifest',
                    'favicon.svg',
                    'pwa-*.png',
                ],
                maximumFileSizeToCacheInBytes: 5 * 1024 * 1024,
            },
            devOptions: { enabled: false },
        }),
    ]),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
