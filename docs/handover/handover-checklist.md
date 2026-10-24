# Handover checklist

Work through this list with the owner before the clinic goes live. Tick each item when it is done and checked. Keep the signed copy at the clinic; Webxkey keeps a copy.

Clinic: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Web address: https://\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

## 1. Accounts

- [ ] Owner account created; the owner can sign in.
- [ ] Two-factor authentication turned on for the owner (Settings → Security), and a test sign-in done with the code.
- [ ] Owner's 2FA recovery codes printed and stored with the recovery key.
- [ ] A cashier account for each cashier, each with their own email and password.
- [ ] General Pharmacy cashier: **Allowed locations** = General Pharmacy.
- [ ] OPD cashier: **Allowed locations** = Doctor's Medicines.
- [ ] Demo and test accounts removed or set to not **Active**.

## 2. Settings

- [ ] Shop name, address and phone checked on a printed receipt.
- [ ] Tax rate 0 (no VAT on sales); cashier discount limit 0.
- [ ] **Sell only with an open shift** on (Shop settings → Till); cashiers see no Discount button.
- [ ] Administration → Security: admin panel signs out after 30 minutes idle; tills never sign out by themselves (0).
- [ ] Short expiry 90 days; stop selling days before expiry 0.
- [ ] Default prescriber, prescriber required, OPD consultation item and its fee set.
- [ ] Bank transfer needs a reference: on.
- [ ] Receipt: batch printed, expiry not printed, prescriber printed; OPD medicines listed without prices (or totals only, as the owner chose).
- [ ] OPD below-list warning percentage agreed with the owner.

## 3. Tills

- [ ] T1 set up: location General Pharmacy, **OPD bills** off.
- [ ] T2 set up: location Doctor's Medicines, **OPD bills** on.
- [ ] T1 registered for offline sales (**Set up offline sales** pressed on the T1 PC; **Sync now** shows).
- [ ] T2 registered for offline sales.
- [ ] Offline test on each till: internet unplugged, a sale made and printed, internet back, the sale synced and seen in **Sales**.
- [ ] Chrome on each till starts on the till page, in kiosk printing mode (no print box appears).

## 4. Printers and scanners

- [ ] T1 receipt printer: 80 mm receipt prints in full, clear, and cuts.
- [ ] T2 receipt printer: 80 mm receipt prints in full, clear, and cuts.
- [ ] T1 cash drawer opens when a receipt prints.
- [ ] T2 cash drawer opens when a receipt prints.
- [ ] Label printer: a test label prints at the right size, and the till scans its barcode back.
- [ ] T1 barcode scanner: a maker's barcode and a clinic label both scan into the search box.
- [ ] T2 barcode scanner: as above.

## 5. Test sales (on the staging copy, not the live system)

- [ ] General Pharmacy sale: a box and loose tablets, cash with change, receipt printed.
- [ ] OPD bill: consultation, two medicines (one syrup in ml), Medicines amount, card payment, receipt printed.
- [ ] Bank transfer payment with its reference.
- [ ] Reprint from **Recent sales** shows **\*\*\* REPRINT \*\*\***.
- [ ] Return of one item by the owner, refund slip printed.
- [ ] Void of a whole bill by the owner, void slip printed.
- [ ] Stock request from T2, sent from the admin panel, stock arrives at Doctor's Medicines.
- [ ] Shift opened and closed on each till, shift summary printed.
- [ ] Daily summary report checked against the test sales.

## 6. Data

- [ ] Medicines, packs and prices loaded and checked by the owner.
- [ ] Suppliers and opening balances loaded and checked.
- [ ] Opening stock loaded with batches and expiry dates, and checked by a count.
- [ ] Items without a maker's barcode given a clinic barcode and labelled.

## 7. Backups

- [ ] Daily encrypted backup running; **Administration → System health** shows the last backup within a day.
- [ ] Weekly verify running; the last verify passed.
- [ ] A full restore tested from a backup into a separate database, and the data checked.
- [ ] The owner can download the latest encrypted backup.

## 8. Recovery key

- [ ] Backup recovery key printed on paper and copied to a USB stick.
- [ ] Recovery key handed to the owner. It is not on the server, in email or in a phone photo.

Received by (owner): \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Signature: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Date: \_\_\_\_\_\_\_\_

Handed over by (Webxkey): \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Signature: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Date: \_\_\_\_\_\_\_\_

## 9. Data protection

- [ ] Data-protection note (what patient data is kept, where, for how long, who can see it) approved by the owner.

Approved by (owner): \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Signature: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Date: \_\_\_\_\_\_\_\_

## 10. Guides and training

- [ ] Guides printed: General Pharmacy cashier, OPD counter, Owner / Admin.
- [ ] Quick-reference sheets printed and placed next to T1 and T2.

Training done (each person signs):

| Name | Role / counter | Guide used               | Date | Signature |
| ---- | -------------- | ------------------------ | ---- | --------- |
|      | Owner (Admin)  | Owner / Admin            |      |           |
|      | Cashier, T1    | General Pharmacy cashier |      |           |
|      | Cashier, T2    | OPD counter              |      |           |
|      |                |                          |      |           |
|      |                |                          |      |           |

Trainer (Webxkey): \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Signature: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Date: \_\_\_\_\_\_\_\_

## 11. Support contacts

| Who                | Name | Phone | When to call                                 |
| ------------------ | ---- | ----- | -------------------------------------------- |
| Owner (Admin)      |      |       | Returns, voids, prices, stock, user accounts |
| Webxkey support    |      |       | The system, tills, printers, backups         |
| Internet provider  |      |       | The internet is down                         |
| Printer / hardware |      |       | A printer, drawer or scanner is broken       |

Support: Webxkey — phone \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_

## Sign-off

The clinic accepts the system as handed over.

Owner: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Signature: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Date: \_\_\_\_\_\_\_\_

Webxkey: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Signature: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Date: \_\_\_\_\_\_\_\_
