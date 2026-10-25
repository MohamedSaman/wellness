<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\ShopSettings;
use App\Services\BrandingService;
use App\Support\BrandPalette;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\View\Components\ColorMaps\ButtonComponentColorMap;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Branding (Shop settings → Branding, branch feat/dashboard-charts-branding):
 * one brand colour becomes a full shade scale, readable in light and dark
 * mode; it is the admin panel's primary colour and the till's accent, never
 * a status colour. "Suggest from logo" reads the logo's dominant colour
 * here, with GD. The clinic no longer seeds one (it starts on the Clinic
 * Teal theme, ThemePresetTest); these tests give it the teal it used to
 * start with, as an owner would.
 */
class BrandingTest extends PharmacyTestCase
{
    /** Colours that test the scale: the clinic's default, pale, saturated, dark, greys, pure white and black. */
    private const COLOURS = ['#0f766e', '#ffeb3b', '#f59e0b', '#00ffff', '#22c55e', '#e11d48', '#7c3aed', '#1e3a8a', '#0d9488', '#808080', '#ffffff', '#000000', '#ff00ff', '#c0ffee'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->shop->update(['settings' => [...($this->shop->settings ?? []), 'brand_color' => '#0f766e']]);
    }

    /** A PNG with a white background, a block of $color and a thin black frame. */
    private function logo(string $color, bool $transparent = false): string
    {
        $image = imagecreatetruecolor(80, 40);
        imagesavealpha($image, true);
        $background = $transparent ? imagecolorallocatealpha($image, 0, 0, 0, 127) : imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, (int) $background);
        [$red, $green, $blue] = sscanf($color, '#%02x%02x%02x');
        imagefilledrectangle($image, 20, 10, 59, 29, (int) imagecolorallocate($image, (int) $red, (int) $green, (int) $blue));
        imagerectangle($image, 0, 0, 79, 39, (int) imagecolorallocate($image, 0, 0, 0));
        imagefilledrectangle($image, 2, 2, 4, 4, (int) imagecolorallocate($image, 220, 30, 30)); // a speck of red
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_every_shade_scale_keeps_text_readable_in_light_and_dark_mode(): void
    {
        foreach (self::COLOURS as $colour) {
            $palette = BrandPalette::from($colour);
            $shades = $palette->shades;

            $this->assertSame(BrandPalette::SHADES, array_keys($shades), $colour);
            // Lighter to darker, every step.
            $lightness = array_map(fn (string $hex): float => BrandPalette::toOklch($hex)[0], array_values($shades));
            for ($i = 1; $i < count($lightness); $i++) {
                $this->assertLessThan($lightness[$i - 1], $lightness[$i], "{$colour}: shade ".BrandPalette::SHADES[$i]);
            }

            // Buttons in both modes: light text on 600; links on white; dark mode text on the dark surface; the till's dark button.
            $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($shades[50], $shades[600]), "{$colour}: 50 on 600");
            $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast('#ffffff', $shades[600]), "{$colour}: 600 on white");
            $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($shades[400], BrandPalette::DARK_SURFACE), "{$colour}: 400 on dark");
            $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($shades[950], $shades[400]), "{$colour}: 950 on 400");

            // The brand itself is in the scale unless its own shade had to move.
            if (! in_array($palette->anchor, $palette->adjusted, true)) {
                $this->assertSame(strtolower($colour), $shades[$palette->anchor], $colour);
            }

            // What Filament draws on its primary buttons is readable, light and dark.
            $oklch = $palette->forFilament();
            $button = ButtonComponentColorMap::make($oklch)
                ->minContrastRatio(Color::WCAG_AA_TEXT)
                ->lightBackground(bg: 600, hover: 500)
                ->lightBackground(bg: 400, hover: 300, alternateHover: 500)
                ->darkBackground(bg: 600, hover: 500, alternateHover: 700)
                ->get();
            foreach (['' => 'bg', 'dark:' => 'dark:bg'] as $mode => $background) {
                $text = $button[$mode.'text'] === 0 ? '#ffffff' : $oklch[$button[$mode.'text']];
                $this->assertGreaterThanOrEqual(4.5, Color::calculateContrastRatio($oklch[$button[$background]], $text), "{$colour}: {$mode}button");
            }
        }
    }

    public function test_a_colour_that_reads_well_is_kept_exactly_and_one_that_does_not_is_darkened(): void
    {
        $teal = BrandPalette::from('#0f766e');
        $this->assertSame(700, $teal->anchor);
        $this->assertSame('#0f766e', $teal->shades[700]);

        // Amber is too light for white text: its button shade is darkened, the amber stays at 400.
        $amber = BrandPalette::from('#F59E0B');
        $this->assertSame('#f59e0b', $amber->shades[400]);
        $this->assertContains(600, $amber->adjusted);

        $this->assertFalse(BrandPalette::isValid('red'));
        $this->expectException(\InvalidArgumentException::class);
        BrandPalette::from('#12345');
    }

    public function test_the_dominant_colour_of_a_logo_skips_background_outlines_and_specks(): void
    {
        $this->assertSame('#1e88e5', BrandingService::dominantColor($this->logo('#1e88e5')));
        $this->assertSame('#0f766e', BrandingService::dominantColor($this->logo('#0f766e', transparent: true)));
        // A grey logo has no colour to suggest; nor has something that is not an image.
        $this->assertNull(BrandingService::dominantColor($this->logo('#777777')));
        $this->assertNull(BrandingService::dominantColor('<svg xmlns="http://www.w3.org/2000/svg"/>'));
    }

    public function test_the_teal_brand_colour_is_read_back(): void
    {
        $this->assertSame('#0f766e', $this->shop->setting('brand_color'));
        $this->assertSame('#0f766e', app(BrandingService::class)->color($this->shop));
    }

    public function test_the_owner_sets_the_brand_colour_favicon_and_logo_and_can_take_a_suggestion_from_the_logo(): void
    {
        Storage::fake('public');
        $this->actingAs($this->owner);

        $page = Livewire::test(ShopSettings::class)
            ->assertFormSet(['brand_color' => '#0f766e'])
            ->assertSee('data-test="brand-preview"', escape: false)
            ->fillForm([
                'logo_path' => UploadedFile::fake()->createWithContent('clinic.png', $this->logo('#7c3aed')),
                'favicon_path' => UploadedFile::fake()->createWithContent('icon.png', $this->logo('#7c3aed')),
            ])
            ->callAction(TestAction::make('suggestFromLogo')->schemaComponent('brand_color', 'form'))
            ->assertFormSet(['brand_color' => '#7c3aed'])
            ->assertNotified('Suggested #7c3aed');

        // The owner prefers a darker shade, typed in short.
        $page->fillForm(['brand_color' => '#53C'])
            ->call('save')
            ->assertHasNoFormErrors();

        $shop = $this->shop->fresh();
        $this->assertSame('#5533cc', $shop->setting('brand_color'));
        Storage::disk('public')->assertExists((string) $shop->logo_path);
        $favicon = (string) $shop->setting('favicon_path');
        Storage::disk('public')->assertExists($favicon);

        // A new favicon replaces the old file; an empty colour means the standard colours.
        // (The owner removes the old icon, then uploads the new one.)
        Livewire::test(ShopSettings::class)
            ->fillForm(['favicon_path' => []])
            ->fillForm(['favicon_path' => UploadedFile::fake()->createWithContent('new.png', $this->logo('#0f766e')), 'brand_color' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $shop = $this->shop->fresh();
        $this->assertNull($shop->setting('brand_color'));
        Storage::disk('public')->assertMissing($favicon);
        Storage::disk('public')->assertExists((string) $shop->setting('favicon_path'));
    }

    public function test_a_colour_that_is_not_hex_is_refused_and_a_logo_without_colour_suggests_nothing(): void
    {
        Storage::fake('public');
        $this->actingAs($this->owner);

        Livewire::test(ShopSettings::class)
            ->fillForm(['brand_color' => 'teal'])
            ->call('save')
            ->assertHasFormErrors(['brand_color']);
        $this->assertSame('#0f766e', $this->shop->fresh()->setting('brand_color'));

        Livewire::test(ShopSettings::class)
            ->fillForm(['logo_path' => UploadedFile::fake()->createWithContent('grey.png', $this->logo('#777777'))])
            ->callAction(TestAction::make('suggestFromLogo')->schemaComponent('brand_color', 'form'))
            ->assertFormSet(['brand_color' => '#0f766e'])
            ->assertNotified('No colour found in the logo');
    }

    public function test_the_brand_colour_is_the_panels_primary_and_never_a_status_colour(): void
    {
        // On the classic theme the status colours are Filament's own (a themed preset sets them: ThemePresetTest).
        $this->shop->update(['settings' => [...($this->shop->settings ?? []), 'theme_preset' => 'classic']]);
        $palette = BrandPalette::from('#0f766e')->forFilament();

        $html = $this->actingAs($this->owner)->get(Filament::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString("--primary-600:{$palette[600]};", (string) $html);
        $this->assertStringContainsString("--primary-50:{$palette[50]};", (string) $html);
        foreach (['danger' => Color::Red, 'warning' => Color::Amber, 'success' => Color::Green] as $name => $status) {
            $this->assertStringContainsString("--{$name}-600:{$status[600]};", (string) $html, $name);
        }
    }

    public function test_the_brand_colour_and_favicon_reach_the_till(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/icon.png', $this->logo('#0f766e'));
        $this->shop->update(['settings' => [...($this->shop->settings ?? []), 'favicon_path' => 'branding/icon.png']]);
        $palette = BrandPalette::from('#0f766e');

        $html = (string) $this->actingAs($this->owner)->get(route('pos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('html:root{--primary:'.$palette->shades[600].';--primary-foreground:'.$palette->shades[50].';', $html);
        $this->assertStringContainsString('html.dark:root{--primary:'.$palette->shades[400].';--primary-foreground:'.$palette->shades[950].';', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="'.$palette->shades[600].'">', $html);
        $this->assertStringContainsString('<link rel="icon" href="'.Storage::disk('public')->url('branding/icon.png').'">', $html);
        $this->assertStringContainsString('<link rel="icon" href="'.Storage::disk('public')->url('branding/icon.png').'" />', (string) $this->get(Filament::getUrl())->getContent());
    }

    public function test_only_the_owner_reaches_branding(): void
    {
        $this->actingAs($this->cashier);

        $this->assertFalse(ShopSettings::canAccess());
        $this->get(ShopSettings::getUrl())->assertForbidden();
    }
}
