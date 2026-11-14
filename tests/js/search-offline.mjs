// Searches a till's offline catalog with the till's own code
// (resources/js/lib/offline/searchCatalog.ts), for TillSearchOfflineParityTest:
// reads {catalog: {shop, items}, day, cases: [{term, scope}]} as JSON on stdin
// and writes [{exact, results}] as JSON. Run with node --experimental-strip-types.
import { readFileSync } from 'node:fs';
import { registerHooks } from 'node:module';

// The till's modules import their neighbours without an extension, as Vite resolves them.
registerHooks({
    resolve(specifier, context, nextResolve) {
        try {
            return nextResolve(specifier, context);
        } catch (error) {
            if (specifier.startsWith('.') && !specifier.endsWith('.ts')) {
                return nextResolve(`${specifier}.ts`, context);
            }

            throw error;
        }
    },
});

const { searchCatalog } =
    await import('../../resources/js/lib/offline/searchCatalog.ts');

const input = JSON.parse(readFileSync(0, 'utf8'));
const output = input.cases.map(({ term, scope }) =>
    searchCatalog(
        input.catalog.items,
        { term, makeId: null, modelId: null, year: null, scope },
        input.catalog.shop,
        input.day,
    ),
);

process.stdout.write(JSON.stringify(output));
