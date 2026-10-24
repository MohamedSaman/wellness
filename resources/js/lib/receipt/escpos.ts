import type { ThermalLine } from './layout';

/**
 * ESC/POS bytes (base64) for a thermal printer, from the lines of
 * layout.ts: a mirror of app/Services/Receipts/EscPos.php (encode(), without
 * the logo) for receipts the till lays out itself. Change both together.
 */
const ESC = '\x1b';
const GS = '\x1d';

export function encodeEscPos(lines: ThermalLine[]): string {
    let bytes = `${ESC}@`;

    for (const line of lines) {
        const text = line.text.replace(/[^\x20-\x7E]/g, '?');
        bytes +=
            (line.bold ? `${ESC}E\x01` : '') +
            (line.tall ? `${GS}!\x01` : '') +
            text +
            (line.tall ? `${GS}!\x00` : '') +
            (line.bold ? `${ESC}E\x00` : '') +
            '\n';
    }

    // Feed 3 lines, then a partial cut.
    bytes += `${GS}VB\x03`;

    return btoa(bytes);
}
