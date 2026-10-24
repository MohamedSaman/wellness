<?php

namespace Tests\Feature\Pos;

use App\Services\Receipts\EscPos;
use Tests\TestCase;

/**
 * ESC/POS bytes for the thermal printer (docs/PHARMACY.md §9, branch 9):
 * initialise, each line with bold and double height switched on and off
 * around it, then feed and cut; a logo as a GS v 0 raster.
 */
class EscPosTest extends TestCase
{
    public function test_lines_become_esc_pos_commands(): void
    {
        $bytes = EscPos::encode([
            ['text' => '   DEMO CLINIC', 'bold' => true, 'tall' => true],
            ['text' => 'Tea’s 1.00', 'bold' => false, 'tall' => false],
            ['text' => 'TOTAL LKR 1.00', 'bold' => true, 'tall' => false],
        ]);

        $this->assertSame(
            "\x1b@"
            ."\x1bE\x01\x1d!\x01   DEMO CLINIC\x1d!\x00\x1bE\x00\n"
            ."Tea???s 1.00\n"
            ."\x1bE\x01TOTAL LKR 1.00\x1bE\x00\n"
            ."\x1dVB\x03",
            $bytes,
        );
    }

    public function test_a_logo_is_a_raster_image_at_most_half_the_paper_wide(): void
    {
        $image = imagecreatetruecolor(1000, 100);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, 499, 99, (int) imagecolorallocate($image, 0, 0, 0)); // left half black
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $raster = (string) EscPos::raster($png, 80);

        // 288 dots (half of 576) by 28 rows: 36 bytes a row.
        $this->assertSame("\x1dv0\x00", substr($raster, 0, 4));
        $this->assertSame([36, 28], array_values(unpack('v2', substr($raster, 4, 4)) ?: []));
        $this->assertSame(4 + 4 + 36 * 28, strlen($raster));
        // A row: the first 18 bytes black, the rest white.
        $this->assertSame(str_repeat("\xFF", 17), substr($raster, 8, 17));
        $this->assertSame(str_repeat("\x00", 17), substr($raster, 8 + 19, 17));

        $this->assertSame(192, unpack('v', substr((string) EscPos::raster($png, 58), 4, 2))[1] * 8);
        $this->assertNull(EscPos::raster('not an image', 80));
        $this->assertStringContainsString("\x1ba\x01\x1dv0\x00", EscPos::encode([], $raster));
    }
}
