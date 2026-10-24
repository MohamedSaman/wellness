<?php

namespace App\Services\Receipts;

/**
 * ESC/POS bytes for a thermal receipt printer, from the lines of
 * ReceiptLayout (docs/PHARMACY.md §9, branch 9). The lines are already
 * padded to the paper's width, so only bold, double height, the optional
 * logo and the cut are commands. Printers of the Epson TM-T20 / TM-T82
 * family and the usual clones take these.
 *
 * resources/js/lib/receipt/escpos.ts is the same encoder for receipts the
 * till lays out itself (offline sales): change both together.
 */
final class EscPos
{
    private const INIT = "\x1b@";

    private const BOLD_ON = "\x1bE\x01";

    private const BOLD_OFF = "\x1bE\x00";

    private const TALL_ON = "\x1d!\x01";

    private const TALL_OFF = "\x1d!\x00";

    private const CENTER = "\x1ba\x01";

    private const LEFT = "\x1ba\x00";

    /** Feed 3 lines, then a partial cut. */
    private const CUT = "\x1dVB\x03";

    /** Printable dots across: 576 on 80 mm paper, 384 on 58 mm. */
    public const DOTS = [80 => 576, 58 => 384];

    /**
     * @param  list<array{text: string, bold: bool, tall: bool}>  $lines
     * @param  string|null  $logo  raster bytes from raster(), printed centred at the top
     */
    public static function encode(array $lines, ?string $logo = null): string
    {
        $bytes = self::INIT;

        if ($logo !== null && $logo !== '') {
            $bytes .= self::CENTER.$logo."\n".self::LEFT;
        }

        foreach ($lines as $line) {
            $text = (string) preg_replace('/[^\x20-\x7E]/', '?', $line['text']);
            $bytes .= ($line['bold'] ? self::BOLD_ON : '').($line['tall'] ? self::TALL_ON : '')
                .$text
                .($line['tall'] ? self::TALL_OFF : '').($line['bold'] ? self::BOLD_OFF : '')
                ."\n";
        }

        return $bytes.self::CUT;
    }

    /**
     * An image as an ESC/POS raster (GS v 0): scaled to at most half the
     * paper's dots across and 160 dots high, one bit per dot, dark where the
     * image is darker than mid-grey (transparent counts as white). Null when
     * the file is not an image GD can read.
     */
    public static function raster(string $contents, int $paperWidth): ?string
    {
        if (! function_exists('imagecreatefromstring') || $contents === '') {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        $maxWidth = intdiv(self::DOTS[$paperWidth] ?? self::DOTS[80], 2);
        $scale = min(1, $maxWidth / max(1, imagesx($image)), 160 / max(1, imagesy($image)));
        $width = max(8, (int) floor(imagesx($image) * $scale));
        $height = max(1, (int) floor(imagesy($image) * $scale));
        $scaled = imagecreatetruecolor($width, $height);
        imagefill($scaled, 0, 0, (int) imagecolorallocate($scaled, 255, 255, 255));
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        $bytesPerRow = intdiv($width + 7, 8);
        $data = '';

        for ($y = 0; $y < $height; $y++) {
            for ($byte = 0; $byte < $bytesPerRow; $byte++) {
                $bits = 0;

                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $byte * 8 + $bit;

                    if ($x < $width) {
                        $rgb = imagecolorat($scaled, $x, $y);
                        $luminance = (0.299 * (($rgb >> 16) & 0xFF)) + (0.587 * (($rgb >> 8) & 0xFF)) + (0.114 * ($rgb & 0xFF));
                        $bits |= $luminance < 128 ? (0x80 >> $bit) : 0;
                    }
                }

                $data .= pack('C', $bits);
            }
        }

        return "\x1dv0\x00".pack('v', $bytesPerRow).pack('v', $height).$data;
    }
}
