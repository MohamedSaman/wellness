// Lays out sale receipts with the till's code (resources/js/lib/receipt), for
// ReceiptLayoutParityTest: reads {cases: [{shop, sale}]} as JSON on stdin and
// writes [{lines, escpos}] as JSON. Run with node --experimental-strip-types.
import { readFileSync } from 'node:fs';
import { encodeEscPos } from '../../resources/js/lib/receipt/escpos.ts';
import { saleLayout } from '../../resources/js/lib/receipt/layout.ts';

const input = JSON.parse(readFileSync(0, 'utf8'));
const output = input.cases.map(({ shop, sale }) => {
    const lines = saleLayout(shop, sale);

    return { lines, escpos: encodeEscPos(lines) };
});

process.stdout.write(JSON.stringify(output));
