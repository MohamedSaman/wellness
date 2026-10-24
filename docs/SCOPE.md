# Phase 1 scope

**Client:** retail shop selling helmets, riding jackets and vehicle spare parts.

## In Phase 1

- **Users:** Owner (everything) and Cashier (checkout, product search, view stock).
- **Products and variants:** shop-defined attributes (Size, Colour, Helmet Type, Material ...), SKU and barcode per variant, cost and selling price, reorder level, shelf/bin location. Spare parts have a part number, OEM numbers and vehicle fitment (make, model, years).
- **Stock ledger:** opening stock, stock in, sale, return, adjustments with a reason, void reversals. Full history per item, low-stock alerts and stock valuation.
- **Checkout:** scan or search, quantity, line and sale discounts (with a cashier limit), cash/card/split payment, change, receipts, invoice numbers per till.
- **Returns and voids:** returns put stock back; voiding a sale is Owner only.
- **Shifts:** opening float, closing count, difference.
- **Reports:** sales by day/cashier, best sellers, profit (Owner), low stock, stock value.
- **Settings:** shop details, receipt header and footer, currency, tax.

## Built in but switched off (feature flags)

Suppliers, Purchase Orders, Label printing, Customers and credit, Multiple branches, Wholesale pricing, Offline sync API.

## Foundation status

| Area                                                                        | Status                    |
| --------------------------------------------------------------------------- | ------------------------- |
| Database schema (all Phase 1 tables)                                        | Done                      |
| Tenancy, roles, permissions, modules, audit log                             | Done                      |
| Stock, checkout, return, void and shift logic, with tests                   | Done                      |
| Admin screens (Filament): products, stock in/adjust, sales, users, settings | Done                      |
| POS checkout screen (Vue) + receipt printing                                | Next                      |
| Offline PWA + sync API                                                      | After the checkout screen |
| Reports                                                                     | After the admin screens   |
