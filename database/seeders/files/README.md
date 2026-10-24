# Sample import files

Filled-in import templates for the demo clinic, for trying Administration → Imports by hand. They are not loaded by any seeder; `ImportSampleFilesTest` checks that they import cleanly into a fresh demo clinic.

Import them in this order (each one finds what the one before created):

| File                           | Import type               | What it shows                                                                                                                                                                                                                 |
| ------------------------------ | ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `clinic-suppliers.xlsx`        | Suppliers                 | Six suppliers with payment terms. Hemas and SPC are already in the dev database (the demo seeders add them), so there they are skipped, or updated if you choose "Update".                                                    |
| `clinic-medicines.xlsx`        | Medicines and products    | 26 items: tablets and capsules in boxes of 16, 30 and 100, syrups in ml with a Bottle pack (loose ml at DOC only), a suspension, injections, a cream, eye drops, an inhaler, sundries. Erythromycin has no prices: a warning. |
| `clinic-opening-stock.xlsx`    | Opening stock             | 29 rows at GEN and DOC, in packs or base units, found by SKU, barcode or name. Omeprazole batch OM8820 expires within 90 days: a short-expiry warning.                                                                        |
| `clinic-opening-invoices.xlsx` | Supplier opening invoices | Six unpaid invoices at go-live; two are already overdue.                                                                                                                                                                      |
| `clinic-price-list.csv`        | Medicines and products    | The clinic's own layout (Item Code, Description, MRP, Pack size ...): the column step matches it, strengths like "150mg" are split.                                                                                           |

Importing a file a second time creates nothing twice: rows already on file are skipped (or updated).
