# Guide: Owner / Admin

This guide is for the owner of the clinic (the doctor), who has the **Owner** role. The owner can do everything in the system. The admin panel is at **https://\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_/admin**.

Each topic says what it is for, then the steps. Menu paths are written like **Stock → Expiry**: the group in the left-hand menu, then the item.

The left-hand menu has these groups: **Catalog**, **Stock**, **Purchasing**, **Sales**, **Reports** and **Administration**. **Open POS** opens the till.

## Contents

1. Signing in and two-factor authentication
2. Dashboard
3. Medicines, units and packs
4. Barcodes and labels
5. Suppliers
6. Purchase orders
7. Goods received (receiving stock)
8. Supplier payments and payables
9. Transfers and stock requests (GEN to DOC)
10. Expiry lists and write-offs
11. Stock counts
12. Low stock and reorder
13. Sales, returns and voids
14. Offline sales to review
15. Reports and exports
16. Users and allowed locations
17. Tills and offline sales
18. Shop and receipt settings
19. Backups and system health
20. What the owner must keep safe

## 1. Signing in and two-factor authentication

The owner's account is protected by **two-factor authentication** (2FA): after the password, the system asks for a 6-digit code from an app on your phone. This is **required** for the owner. Until it is on, every page sends you to **Settings → Security**.

**Set it up (once):**

1. Install an authenticator app on your phone (for example Google Authenticator or Microsoft Authenticator).
2. Sign in. You are taken to **Security settings** (or open https://\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_/settings/security).
3. Under **Two-factor authentication**, press **Enable 2FA**. Confirm your password if asked.
4. Scan the QR code with the app (or type the setup key), then type the 6-digit code and press **Continue**.
5. Press **2FA recovery codes** to see your recovery codes. **Print them or write them down** and keep them with the recovery key (section 20). Each one lets you in once if you lose your phone.

**Signing in every day:**

1. **Email address**, **Password**, **Log in**.
2. Type the 6-digit code from the app.
3. Lost the phone? Choose **login using a recovery code** and type one of your recovery codes. Then set up 2FA again on the new phone.

The owner goes to the admin panel after signing in. Cashiers go to the till.

**Signed out by itself:** after 30 minutes without a key press or click, the admin panel signs you out (changed under **Administration → Security**, section 18). Sign in again. The tills do not sign out by themselves, so a till never stops billing during an outage; staff sign out when they leave the counter.

If the panel ever says **The admin panel cannot be opened from this network**, call support.

## 2. Dashboard

The first page of the admin panel. It refreshes itself every minute.

- **Tiles**: Sales today and each counter's sales, consultations today, **Gross profit today** (revenue − cost, red below zero, and a note when OPD medicines were charged below cost), this month against last month, **Expired stock**, expiring stock, **Stock requests waiting**, **Overdue to suppliers**, **Due this week**, **Cash expected in open shifts**.
- **Charts**: net sales by counter, consultation fees and medicines, top 10 medicines, payment methods, stock value. Each chart has a period filter.
- **Tables**: best sellers, **Low stock**, **Unallocated stock** (see section 14).

Click a tile to open its report or list.

## 3. Medicines, units and packs

**What for:** every item the clinic sells, its packs and prices.

**Units and packs.** Every medicine is counted in its **base unit**: tablet, capsule, vial, or ml for syrup. A **pack** is made of base units, for example **Box of 30** contains 30 tablets, or **Bottle** contains 100 ml. Each unit and pack has its **own price**. Stock is always kept in base units, so selling a box of 30 takes 30 tablets off.

**Add or change a medicine:**

1. **Catalog → Products**, then **New** (or open a product).
2. **Product**: name, category, brand, **Tax** (medicines are normally exempt), **Shelf / bin**.
3. Turn on **This is a medicine**. Fill in the **Medicine** section: **Generic name**, **Brand name**, **Strength** and **Strength unit**, **Dosage form**, **Schedule** (OTC, prescription only, controlled), **Route**, **Storage condition**, **NMRA registration no.** The form suggests units and packs from the dosage form.
4. **Variants**: SKU, barcode, price of the base unit.
5. **Packs** (**Add pack**): name (for example "Box of 30"), **Contains** (30), barcode, **Price**, **Sold** (sold at the till), **Bought** (bought from suppliers).
6. **Sell loose**: whether the single base unit can be sold. This can differ per counter: for example syrup by the ml at Doctor's Medicines only, bottles only at General Pharmacy.
7. **Whole units only** is on for medicines (no half tablets). **Batches and expiry** is on for medicines.
8. **Stop selling days before expiry**: leave empty to use the shop's rule (sell until the expiry date).
9. Save.

Rules: the base unit cannot be changed once the item has stock movements. Packs can still be added, re-priced or switched off.

**The consultation fee** is a product that does not track stock and has **Price can be changed at the till** turned on. It is set as the **OPD consultation item** in Shop settings (section 18).

**Many items at once:** **Administration → Imports** loads items, suppliers, opening stock and supplier opening invoices from Excel or CSV. **Upload and match the columns**, **Check the file** (nothing is saved yet; it lists errors), then confirm.

## 4. Barcodes and labels

**What for:** items without a maker's barcode get a clinic barcode, and labels to stick on them.

**Give barcodes:**

1. **Catalog → Products**. Filter **Without a manufacturer barcode**.
2. Select the items and choose **Give in-store barcodes**. A barcode is only given to an item or pack that has none. A maker's barcode is never replaced.

**Print labels:**

1. From a product page (**Print labels**), from a posted delivery (**Print labels for this delivery**), from **Stock → Batches**, or from a selection on the product list.
2. Choose the **Template**, the quantities, then **Print** or **Add to print queue**.
3. **Catalog → Print labels** holds the queue: print it all at once, or **Empty the queue**.
4. In the print preview, print with the label printer. In Chrome's print box: the label's paper size, margins **None**, scale 100%.

**Catalog → Label print log** lists every print and can print one again. **Administration → Label templates** sets label sizes and what each label shows (the clinic's **Medicine label** does not print the expiry).

## 5. Suppliers

**What for:** who the clinic buys from, their payment terms, and what is owed.

1. **Purchasing → Suppliers**, then **New**.
2. Name, **Contact person**, phone, email, **Tax / registration no.**, **Payment terms (days)**.
3. Save.

The supplier page shows **Opening balance**, **Goods received**, **Returns**, **Paid**, **Balance owed**, **Unpaid invoices**, **Overdue** and **Credit to allocate**. **Opening balance** and **Opening invoice** record what was owed at go-live. **Statement** prints the supplier's statement, or saves it as PDF or Excel.

## 6. Purchase orders

**What for:** orders sent to suppliers. Optional: you can also receive an invoice without an order.

1. **Purchasing → Purchase orders**, then **New**.
2. Choose the **Supplier** and **Expected on**. Add lines: **Item**, **Unit** (for example the box), quantity, cost. **Create new item** adds a missing item.
3. Save. **Print** to send it.
4. **Mark as sent** when it has gone to the supplier.
5. When the goods arrive, press **Receive goods** (section 7). An order can come in more than one delivery.
6. **Cancel order** if it will not come.

## 7. Goods received (receiving stock)

**What for:** putting delivered stock on the shelf, with its batch, expiry and cost. All stock is received at **General Pharmacy**, then moved to Doctor's Medicines (section 9).

1. **Purchasing → Goods received**, then **New** (or **Receive goods** from an order).
2. **Supplier**, **Received on**, **Supplier invoice no.**, **Invoice date**, **Against order** (if any).
3. For each line: **Item**, **Unit** (the pack it came in, for example Box of 100), **Paid quantity**, **Free** (free goods, if any), cost, **Batch no.** and **Expiry**.
4. **Tax** (VAT on the supplier's invoice) is part of the cost. Enter it **per line**, or **once for the whole invoice**, never both.
5. An invoice discount can be entered too. It is shared over the lines.
6. Check the totals against the invoice. Save as a draft if you are not finished.
7. Press **Post**. Only now does the stock go in, and the invoice becomes owed to the supplier.

**Accept short expiry** (owner only) is needed for a batch that expires very soon.

A posted note never changes. If it was wrong, press **Reverse** (this takes the same stock back out), then enter it again correctly.

After posting, **Print labels for this delivery** prints a label for each pack received.

Goods going back to a supplier: **Purchasing → Supplier returns**.

## 8. Supplier payments and payables

**What for:** paying suppliers and seeing what is due.

**See what is owed:** **Purchasing → Payables** lists every invoice owed, its **Due on** date (invoice date + the supplier's terms), and its status. Overdue invoices are marked. The dashboard shows **Overdue to suppliers** and **Due this week**.

**Pay a supplier:**

1. **Purchasing → Payables** (or the supplier's page), **Record payment**.
2. The **Supplier**, **Paid on** date, amount, method (**Cash**, **Bank transfer**, **Cheque**, **Card**, **Other**) and **Reference**.
3. The payment goes to the oldest invoice first, unless you choose the invoices. One payment can cover several invoices, or part of one.
4. Save.

**Change due date** moves an invoice's due date. **Allocate credit** uses a supplier's unused credit (for example from a return) against an invoice. A payment entered by mistake is undone with **Reverse**, never deleted.

## 9. Transfers and stock requests (GEN to DOC)

**What for:** moving stock from General Pharmacy to Doctor's Medicines.

**When the OPD asks (stock request):**

1. The dashboard shows **Stock requests waiting**. Open **Stock → Stock requests**.
2. Open the request. **Print / pick list** prints what to pick, with the batches to take (earliest expiry first) and the shelf.
3. Press **Send stock**. Send all of it or part of it. The batches are chosen earliest expiry first, unless you pick one.
4. The stock is at Doctor's Medicines at once. The request becomes **Partly sent** or **Sent**.
5. **Cancel request** if it will not be sent.

**Without a request:** **Stock → Transfers**, **New transfer**: **From** (General Pharmacy), **To** (Doctor's Medicines), the items and quantities. Save.

## 10. Expiry lists and write-offs

**What for:** finding stock that is expired or will expire soon, and taking it off the books.

1. **Stock → Expiry**. Two tabs: **Expiring within 90 days** and **Expired**. Each batch shows its location, batch, expiry, days left, quantity and value at cost.
2. A daily message in the bell (top right) lists expired and expiring stock.
3. Select batches, then choose:
    - **Write off**: the stock leaves for good. Choose a reason (expired, damaged or other). A write-off note is made (**WO-000001**) and can be printed for signatures.
    - **Move to location**: move them to **Quarantine / Expired**, off the shelves. Tills never sell from there.
    - **Return to supplier**: starts a supplier return with these batches.

**Stock → Write-offs** lists the write-off notes.

The tills sell until the expiry date and never sell an expired batch.

## 11. Stock counts

**What for:** checking the shelves against the system, for example at month end.

1. **Stock → Stock counts**, **New** (**Start a stock count**): the **Location**, and all items, **Only this category**, or **Only shelves / bins starting with** a code. **Blind count** hides what the system holds from the person counting.
2. **Print count sheet**.
3. Enter what was counted, line by line. **Count in packs** to count boxes. **Add a line** for a batch found on the shelf that is not on the sheet.
4. **Review and post**: each line shows what the system held when it was counted, the count, and the difference with its value.
5. **Post count**. The differences become stock corrections.

Sales carry on while the count is open. What is sold between counting a line and posting is not counted as a difference.

## 12. Low stock and reorder

**What for:** knowing what to buy or move.

1. **Stock → Low stock & reorder**. Choose the location.
2. It lists items at or below their reorder level, with what is coming and a suggested quantity.
3. **Levels**: set an item's reorder level and par level (the level to top up to).
4. **Suggest request**: for Doctor's Medicines, makes a stock request to General Pharmacy.
5. **Suggest purchase orders**: makes **draft** purchase orders, one per supplier (each item's last supplier, in whole packs). Check them and send as usual.

**Stock → Stock levels** shows stock per item and location, with **Stock in**, **Adjust** (with a reason), **Batches**, **Par level** and **Revalue cost**. **Stock → Stock history** shows every movement.

## 13. Sales, returns and voids

**What for:** looking at bills, refunding returns and cancelling bills. Cashiers cannot do returns or voids: they will ask you.

**Find a bill:** **Sales**. Search by invoice number. Filters: status, **Needs review** (section 14) and **OPD medicines below list** (OPD bills charged below the medicines' list value).

The sale page shows every line with its batch and expiry, the prescriber, the payments and references, and for an OPD bill each medicine's share of the Medicines amount.

**Return items (refund):**

1. Open the sale, press **Return items**.
2. For each line, the **Return quantity** (in the unit sold, or in tablets from a box).
3. **Put back into stock?** for each line. It is off by default for fridge items and controlled drugs: decide each time.
4. The **Reason**, and **Refund by** cash, card or bank transfer (with its reference).
5. Confirm. The refund slip prints (**Print refund slip**). The refund is worked out from what the patient paid for that line.

**Void sale (cancel a whole bill):** open the sale, **Void sale**, give the reason. The stock goes back. Print the void slip (**Print void slip**) and give back the money shown.

**Print** on the sale page: **Reprint receipt**, **Reprint refund slip**, **Reprint void slip**, **A4 invoice**.

## 14. Offline sales to review

When a till sells offline, the server checks each sale when it arrives. It never refuses a sale that was paid for, but it **flags** anything unusual for you: a different batch, stock that ran out, a till clock that was wrong, a missing prescriber, and so on.

1. **Sales**, filter **Needs review**.
2. Open each sale and read the reasons.
3. Put right what needs it (for example a stock count), then **Mark reviewed**.

**Unallocated stock** (dashboard table, and **Stock → Batches**): when an offline till sold more than the batches it knew about, the extra is held as unallocated. The next delivery of that item settles it, or press **Settle**. **Correct batch** fixes a batch's number or expiry.

## 15. Reports and exports

**What for:** end-of-day figures, monthly figures, profit, stock and purchases.

1. Open **Reports** and choose a report.
2. Choose the **Period**: **Today**, Yesterday, This week, This month, Last month or **Custom** (**From**, **To**).
3. Choose the location or counter (General Pharmacy, Doctor's Medicines or all).
4. Export with **CSV**, **Excel**, **PDF** or **Print**.

Useful reports:

| Report                                                          | Use it for                                                                                                     |
| --------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| **Daily summary (end of day)**                                  | Each counter's bills, consultations, medicines, payments, cashiers and each shift's cash, expected and counted |
| **Monthly sales**                                               | The month by counter, day, category, schedule, OPD consultations and medicine                                  |
| **Profit**                                                      | Revenue, cost and gross profit by counter, category and medicine                                               |
| **Payment methods**                                             | Cash, card and bank transfer totals                                                                            |
| **Voids and returns**                                           | Every refund and cancelled bill                                                                                |
| **Shift history**                                               | Every shift's float, expected and counted cash                                                                 |
| **Stock valuation**, **Stock by batch**                         | What the stock is worth at cost                                                                                |
| **Expiry**, **Slow-moving and dead stock**                      | Stock to act on                                                                                                |
| **Adjustments and write-offs**, **Transfers between locations** | Stock that moved for reasons other than sales                                                                  |
| **Purchases**, **Supplier aging**                               | What was bought and what is owed, by age                                                                       |

Cashiers cannot see reports.

## 16. Users and allowed locations

**What for:** a login for each person, and which counters they may work at.

1. **Administration → Users**, **New**.
2. **Name**, **Email**, **Password**: at least 12 characters for an Owner, at least 10 for a Cashier, and not a password known from a data breach (the form says which). No special characters needed: a few words make a good one.
3. **Role**: **Owner** (everything) or **Cashier** (the till only).
4. **Default location**.
5. **Allowed locations**: the counters this person may sell at. Leave empty for General Pharmacy only. The OPD cashier: **Doctor's Medicines** only.
6. **Active**: turn off when someone leaves. They are signed out and cannot log in. Never delete a user; their sales stay under their name.

To reset a cashier's password, open the user and type a new **Password**.

## 17. Tills and offline sales

**What for:** each counter PC is a till. A till must be registered before it can sell offline.

**Set up a till:**

1. **Administration → Tills**, **Add till**.
2. **Code** (for example `T1`; printed before every bill number, cannot be changed later), **Name**, **Location** (General Pharmacy or Doctor's Medicines), **Active**.
3. **OPD bills**: on for T2 only.

**Register a till for offline sales (on each till PC, once):**

1. On the till PC, sign in with **your owner account** and press **Open POS**.
2. Choose the till (for example **T1**).
3. In the top bar, press **Set up offline sales**. The button changes to **Sync now**.
4. **Sign out**. The cashier can now sign in; the till stays registered in this browser.

Do this again if Chrome's data is cleared on that PC, or Chrome is reinstalled.

**Administration → Tills** shows **Offline devices** and **Last synced** for each till. **Revoke offline access** stops a browser syncing (for example a stolen or replaced PC). Its unsent sales stay on that PC until it is registered again.

## 18. Shop and receipt settings

**Administration → Shop settings:**

- **Shop**: name, address and phone (printed at the top of receipts).
- **Branding**: logo, favicon and brand colour (**Suggest from logo**).
- **Till**: **Sell only with an open shift** (on for the clinic: no "Sell without a shift").
- **Money and tax**: **Cashier discount limit (%)** (0 for the clinic: cashiers then see no Discount button), **Tax rate (%)** (the clinic charges no VAT on sales).
- **Batches and expiry**: **Stop selling days before expiry** (0: sell until expiry), **Short expiry: within (days)** (90).
- **Pharmacy checkout**: **Default prescriber**, **Prescriber on prescription-only medicines** (Required), **OPD consultation item**, **Warn when the OPD Medicines amount is below (% of list value)** (100; 0 turns the warning off), **Bank transfer needs a reference**, **Controlled-drug register** (off).
- **Offline tills**: **Till clock tolerance (minutes)**.

**Administration → Receipt settings:** **Header lines**, **Footer**, **Paper width** (80 mm), the logo, and **Medicines on receipts**: **Print the batch number** (on), **Print the expiry date** (off), **Print the prescriber on prescription lines** (on), **Medicines on the OPD bill** (names without prices, or totals only). The **Preview** shows a sample bill before you save.

Press **Save settings** after a change.

**Administration → Security:**

- **Sign out when idle**: **Admin panel and settings (minutes)** (30) and **Till (minutes)** (0, off); 0 means never. Leave the till off: a till signed out while the internet is down cannot sign in again until it is back, so it could not bill.
- **Admin panel access**: **Only from these addresses**. Leave it empty unless support suggests it. A wrong list would lock you out, so the page refuses a list without the address you are using.
- **Audit log**: **Keep audit log entries for (days)**. 0 keeps everything.
- **Two-factor authentication**: who has it on. The owner must.

Press **Save security settings** after a change.

## 19. Backups and system health

The system is backed up automatically every day: the whole database and uploaded files, **encrypted**, and copied to a second provider away from the main server. Daily copies are kept for 14 days and monthly copies for 12 months. Once a week the server restores the latest backup into a test database to check it really works.

The dashboard shows three tiles at the bottom: **Last backup**, **Last backup check** and **System health**. Green is good; red means call support. Each one opens **Administration → System health**, which shows:

- **Checks**: each with **OK**, **Check** or **Problem**. The important ones: **Last backup** (within the last day), **Last backup check** (a backup restored as a test within the last week), **Disk**, **Queue worker**, **Scheduler**, **Owner two-factor**.
- **Tills**: for T1 and T2, **Last sync**, **Last offline sale received**, **Offline sales waiting** (should be 0 when the till is online) and **Clock** (should be **On time**).
- **Back up now**: makes an extra backup in the background, for example before a big change.
- **Download latest backup**: the newest backup, encrypted, for you to keep a copy. It can only be opened with the recovery key (section 20).

You also get an email (and a message in the bell) if a backup fails or the server needs attention, once mail is set up. Check the page once a week anyway. If anything shows **Problem**, call support.

## 20. What the owner must keep safe

| What                                      | Where to keep it                                                                                                                                                                                                |
| ----------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Backup recovery key**                   | On paper and on a USB stick, locked away at the clinic or at home. **Never on the server, never in email, never in a photo on the phone.** If the server is lost, this key is the only way to open the backups. |
| **2FA recovery codes**                    | With the recovery key.                                                                                                                                                                                          |
| **Your phone with the authenticator app** | Locked with a PIN.                                                                                                                                                                                              |
| **Your password**                         | Only in your head or a password manager. Do not share it with staff.                                                                                                                                            |

Webxkey keeps a second copy of the recovery key. If yours is lost, tell Webxkey at once so a new key can be made.

Support: Webxkey — phone \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
