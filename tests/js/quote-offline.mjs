// Prices OPD carts with the till's offline code (resources/js/lib/offline/pricing.ts),
// for OpdListValueTest: reads {catalog: {shop, till, items}, cases: [{lines,
// medicinesAmount, percent}]} as JSON on stdin and writes [{totals, lineKeys}]
// as JSON. Run with node --experimental-strip-types.
import { readFileSync } from 'node:fs';
import { quoteOffline } from '../../resources/js/lib/offline/pricing.ts';

const input = JSON.parse(readFileSync(0, 'utf8'));
const items = new Map(
    input.catalog.items.map((item) => [item.variant_id, item]),
);
const output = input.cases.map(({ lines, medicinesAmount, percent }) => {
    const { lines: priced, ...totals } = quoteOffline(
        lines,
        0,
        items,
        { ...input.catalog.shop, opdBelowListWarningPercent: percent },
        {
            billType: 'opd',
            medicinesAmount,
            till: input.catalog.till,
            isQuote: true,
            cashier: { unlimitedDiscount: false, canOpdBill: true },
        },
    );

    return {
        totals,
        lineKeys: [...new Set(priced.flatMap((line) => Object.keys(line)))],
    };
});

process.stdout.write(JSON.stringify(output));
