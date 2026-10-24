<?php

namespace App\Filament\Support;

use App\Models\Shop;
use App\Services\BrandingService;
use App\Support\BrandPalette;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The branding fields (Shop settings → Branding) and the logo upload that
 * Receipt settings shares: one logo, printed on receipts and the A4 invoice
 * and read for "Suggest from logo".
 */
final class BrandingForm
{
    public static function logo(): FileUpload
    {
        return FileUpload::make('logo_path')
            ->label('Logo (optional)')
            ->image()
            ->disk('public')
            ->directory('logos')
            ->visibility('public')
            ->maxSize(1024)
            ->helperText('Printed at the top of receipts, in black and white on a thermal printer.');
    }

    public static function favicon(): FileUpload
    {
        return FileUpload::make('favicon_path')
            ->label('Favicon (optional)')
            ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/vnd.microsoft.icon']) // no SVG: served from this site, it could carry a script
            ->disk('public')
            ->directory('branding')
            ->visibility('public')
            ->maxSize(256)
            ->helperText('The small icon on the browser tab, for the admin panel and the till. A square PNG (64 × 64) or an .ico.');
    }

    public static function color(): ColorPicker
    {
        return ColorPicker::make('brand_color')
            ->label('Brand colour')
            ->hex()
            ->regex('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/')
            ->live(debounce: 300)
            ->helperText('The admin panel\'s main colour and the till\'s buttons. Empty: the standard colours. Red, amber and green for alerts never change.')
            ->hintAction(
                Action::make('suggestFromLogo')
                    ->label('Suggest from logo')
                    ->icon('heroicon-o-sparkles')
                    ->action(function (Get $get, Set $set): void {
                        $suggestion = BrandingService::dominantColor(self::logoData($get('logo_path')) ?? '');

                        if ($suggestion === null) {
                            Notification::make()->title('No colour found in the logo')->body('Upload a logo in colour (PNG or JPEG), or pick the colour yourself.')->warning()->send();

                            return;
                        }

                        $set('brand_color', $suggestion);
                        Notification::make()->title("Suggested {$suggestion}")->body('Change it if you like, then save.')->success()->send();
                    }),
            );
    }

    /** The shade scale the colour gives, with what was adjusted for readability. */
    public static function preview(): View
    {
        return View::make('filament.brand-preview')
            ->viewData(fn (Get $get): array => ['palette' => self::normalize($get('brand_color')) === null ? null : BrandPalette::from((string) self::normalize($get('brand_color')))]);
    }

    /** #rrggbb in lower case (#abc becomes #aabbcc), or null for anything else. */
    public static function normalize(mixed $color): ?string
    {
        if (! is_string($color)) {
            return null;
        }

        $color = strtolower(trim($color));

        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $short) === 1) {
            $color = "#{$short[1]}{$short[1]}{$short[2]}{$short[2]}{$short[3]}{$short[3]}";
        }

        return BrandPalette::isValid($color) ? $color : null;
    }

    /** Saves a new logo path, removing the file it replaces. */
    public static function saveLogo(Shop $shop, mixed $path): void
    {
        $logo = filled($path) ? (string) $path : null;

        if ($shop->logo_path !== null && $shop->logo_path !== $logo) {
            Storage::disk('public')->delete($shop->logo_path);
        }

        $shop->logo_path = $logo;
    }

    /** The favicon path to store, removing the file it replaces. */
    public static function replaceFavicon(Shop $shop, mixed $path): ?string
    {
        $favicon = filled($path) ? (string) $path : null;
        $current = $shop->setting('favicon_path');

        if (is_string($current) && $current !== '' && $current !== $favicon) {
            Storage::disk('public')->delete($current);
        }

        return $favicon;
    }

    /**
     * The logo's bytes from the form's state: a file just uploaded (not
     * saved yet) or the saved one.
     */
    private static function logoData(mixed $state): ?string
    {
        $file = is_array($state) ? (array_values($state)[0] ?? null) : $state;

        if ($file instanceof TemporaryUploadedFile) {
            return (string) $file->get();
        }

        if (is_string($file) && $file !== '' && Storage::disk('public')->exists($file)) {
            return (string) Storage::disk('public')->get($file);
        }

        return null;
    }
}
