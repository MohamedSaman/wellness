# Handover pack: clinic POS and pharmacy system

This pack is for the clinic's staff. It explains how to use the system day to day. It is written for people, not for programmers.

The clinic has two counters, each with its own till (a Chrome PC with a receipt printer):

| Till | Counter                  | Stock it sells from      | Who uses it                    |
| ---- | ------------------------ | ------------------------ | ------------------------------ |
| T1   | General Pharmacy (GEN)   | General Pharmacy stock   | The General Pharmacy cashier   |
| T2   | Doctor's Medicines / OPD | Doctor's Medicines stock | The OPD cashier and the doctor |

There are two kinds of user:

- **Cashier**: rings up sales at a till, opens and closes shifts, reprints receipts, asks for stock.
- **Owner (Admin)**: the doctor. Everything a cashier can do, plus the admin panel: medicines, prices, buying, stock, returns, reports, users and settings.

## Which guide is for whom

| Document                                                     | For                      | Print it?                     |
| ------------------------------------------------------------ | ------------------------ | ----------------------------- |
| [Guide: General Pharmacy cashier (T1)](guide-gen-cashier.md) | General Pharmacy cashier | Yes, keep at T1               |
| [Guide: OPD counter (T2)](guide-opd-counter.md)              | OPD cashier, the doctor  | Yes, keep at T2               |
| [Guide: Owner / Admin](guide-owner-admin.md)                 | The owner (doctor)       | Yes, keep in the office       |
| [Quick reference: T1 General Pharmacy](quick-ref-t1-gen.md)  | General Pharmacy cashier | One page, stick it next to T1 |
| [Quick reference: T2 OPD](quick-ref-t2-opd.md)               | OPD cashier              | One page, stick it next to T2 |
| [Handover checklist](handover-checklist.md)                  | Owner and Webxkey        | Yes, sign and keep a copy     |

Read the guide for your counter first. Keep the one-page quick reference next to the till.

## Words used in these guides

| Word                  | Meaning                                                                                                         |
| --------------------- | --------------------------------------------------------------------------------------------------------------- |
| Till                  | The checkout screen on a counter PC. T1 or T2.                                                                  |
| Shift                 | One cashier's working session at a till, from opening float to cash count.                                      |
| Float                 | The cash in the drawer at the start of a shift.                                                                 |
| Batch                 | One delivery of a medicine with its own batch number and expiry date.                                           |
| Earliest expiry first | The system always sells from the batch that expires soonest, so old stock does not go out of date on the shelf. |
| Base unit             | The smallest unit a medicine is counted in: a tablet, a capsule, a vial, or ml for syrup.                       |
| Pack                  | A larger unit made of base units, for example "Box of 30" (30 tablets) or "Bottle" (100 ml).                    |
| OPD bill              | A Doctor's Medicines bill: a consultation fee plus one Medicines amount.                                        |
| Offline               | The internet is down. The tills keep selling and send the sales when it comes back.                             |
| Sync                  | The till sending its offline sales to the server, and fetching the latest items and prices.                     |

## How to print these guides to PDF

Any one of these works. Print on A4.

**From a browser (easiest)**

1. Open the file on GitHub (the repository's `docs/handover` folder). GitHub shows it formatted.
2. Press **Ctrl + P**.
3. Choose **Save as PDF** (or your printer) and print.

**From VS Code**

1. Open the `.md` file.
2. Press **Ctrl + Shift + V** to open the preview.
3. Right-click the preview, open it in the browser (or use a Markdown PDF extension), then **Ctrl + P**.

**From the command line (for Webxkey)**

```bash
npx md-to-pdf docs/handover/*.md
```

This writes a PDF next to each file.

The quick-reference sheets are made to fit on one A4 page. If one runs onto a second page, print at 90% scale.

## Support

Support: Webxkey — phone \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
