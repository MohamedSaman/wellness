# Go-live runbook

The days around a client's first real sale on the cloud install ([CLOUD.md](CLOUD.md)), written for the Webxkey people doing it, with the clinic's owner. Times are suggestions. Tick the [handover checklist](../handover/handover-checklist.md) as you go.

## A week before

- [ ] Production (on the production VPS) and staging (on the staging VPS) are added ([CLOUD.md, Adding a client](CLOUD.md#adding-a-client)) and the [staging rehearsal](CLOUD.md#rehearsal-on-a-staging-vps) is done, on the release that goes live; DNS and certificates work; System health is all OK.
- [ ] Backups: `pos:backup` and `pos:backup:verify` succeed on production; the recovery key is printed, on two USB sticks, one set with the owner (signed for) and one in Webxkey's safe. The `APP_KEY`s are in the password manager.
- [ ] The uptime monitor watches `https://<domain>/up`.
- [ ] The clinic's broadband and 4G failover router work (pull the broadband cable: the PCs stay online on 4G).
- [ ] The data-protection note ([DATA-PROTECTION.md](DATA-PROTECTION.md)) is approved and signed by the owner.
- [ ] The import files from the clinic are ready: items (medicines, packs, prices, barcodes), suppliers; and, for go-live morning, the opening stock (counted, with batches and expiries) and the unpaid supplier invoices.

## Staging rehearsal (2–3 days before)

On **staging**, as the clinic will work:

1. Import the items and suppliers (Administration → Imports): the dry run shows no errors.
2. Import the opening stock and the supplier invoices.
3. Set up as for production (below): users, settings, receipt, branding.
4. Run a day: on both tills, OPD bills and General Pharmacy sales, a prescription, card and bank transfer payments, a return and a void (owner), receiving a GRN, a transfer GEN → DOC, a stock request from the OPD till, an expiry list, closing both shifts and printing their summaries.
5. Pull the network cable on a till for a few sales ([TILLS.md, Check offline billing](TILLS.md#5-check-offline-billing)); plug it back; check the sales arrived.
6. Read the day's reports with the owner (Daily summary, per counter). Fix what the rehearsal shows; repeat if needed.
7. Restore drill: restore last night's production backup into staging ([CLOUD.md, Restore](CLOUD.md#restore)).

## The day before go-live, on production

1. **Imports:** items and suppliers (the final files). Check a sample of prices and packs on screen with the owner.
2. **Users:** the owner (two-factor on, recovery codes printed and kept with the recovery key), each cashier with their own email and password (10+ characters; the owner 12+); **allowed locations** (the OPD cashier and the doctor at DOC, the GEN cashier at GEN).
3. **Settings:** Shop settings (name, address and phone for the receipt, **Sell only with an open shift** on, cashier discount 0 (no Discount button at the tills), tax off, prescriber, OPD consultation item, bank transfer reference, till clock tolerance), Receipt settings (header, footer, 80 mm, expiry hidden, batch and prescriber printed), **Branding** (logo, colour), Administration → Security (admin panel 30 minutes, tills 0: they never sign out by themselves; leave the address list empty unless agreed).
4. **Tills:** T1 at General Pharmacy, T2 at Doctor's Medicines with **OPD bills** on. Each till PC set up and registered for offline sales ([TILLS.md](TILLS.md)).
5. **Printers:** a receipt and the drawer on each till; labels from the owner's PC.
6. **Test sale and void** on each till (a real item, cash), receipt checked with the owner, then voided by the owner. These go in tomorrow's reset.
7. Tell the staff when to come in, and that tomorrow starts with the reset.

## Go-live morning (before opening)

1. **Every till online and uploaded:** System health → Tills: **Offline sales waiting 0** on every till. A till that holds sales stops the reset.
2. **The reset** (on the server; the owner types their password):

    ```bash
    cd /srv/pos/clinic/current
    sudo -u pos-clinic php artisan pos:go-live-reset --include-stock
    ```

    It shows what it will remove, asks for an owner's email and password, then for the shop code and `RESET`, makes a `pre-go-live` backup, and removes the rehearsal: sales, returns, voids, shifts, their stock movements and the invoice numbering (every till starts at `T1-000001`), and with `--include-stock` all stock and supplier documents too (opening stock, goods received, orders, transfers, requests, write-offs, counts, supplier payments, balances and invoices). It keeps products, units, prices, barcodes, suppliers, users, locations, tills, settings and the label and import templates. Without `--include-stock` the stock received stays, and only what the test sales moved is put back.

3. **On each till PC:** clear Chrome's data for the site (menu ⋮ → Settings → Privacy → Third-party cookies → See all site data → the POS's address → Delete), sign in as the owner, choose the till, **Set up offline sales** again (the reset revoked the old registration), sign out.
4. **Opening stock:** import the counted opening stock (with batches and expiries) at GEN (and DOC if it holds stock). Check the totals against the count sheets with the owner: the Stock levels screen per location, a few items by batch.
5. **Supplier invoices:** import the unpaid supplier invoices; check each supplier's balance against the clinic's records.
6. **Cash float:** each cashier signs in and opens the shift with the counted float.
7. **First real sale** on each till; check the receipt and that it is on the Sales screen.
8. Note the time and the first invoice numbers in the handover checklist.

## First week

Each day:

- [ ] System health: backup within the day, nothing failing; each till 0 waiting and on time.
- [ ] Sales → **Needs review**: offline sales the server flagged (a price or stock difference, a clock out); review each with the owner.
- [ ] The daily summary per counter against the cash counted at each shift close.
- [ ] Stock requests and transfers GEN → DOC done; the expiry digest read.
- [ ] Anything the staff found confusing: note it for the training follow-up.

At the end of the week:

- [ ] The weekly backup check ran on Sunday (System health: **Last backup check** OK).
- [ ] A short spot count of ten items at each location against the system.
- [ ] Feedback session with the owner and cashiers; changes go into the next release (CHANGELOG).
- [ ] The handover checklist signed.
