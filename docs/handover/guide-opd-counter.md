# Guide: OPD counter (T2, Doctor's Medicines)

This guide is for the OPD cashier at the Doctor's Medicines counter, till **T2**, and for the doctor when he uses the till.

At this counter a bill is an **OPD bill**: the **consultation fee** plus **one Medicines amount** for all the medicines given. The medicines are still entered one by one, so the stock is right, but they are not priced one by one on the bill.

The basics (signing in, opening the shift, searching, payment, receipts, offline working, closing the shift) are the same as at General Pharmacy. They are repeated here in short. The [General Pharmacy guide](guide-gen-cashier.md) has more detail on each.

Buttons and messages are written here **in bold**, exactly as they appear on the screen.

## 1. Start of day

1. Open Chrome on the T2 PC and go to https://\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
2. **Log in to your account**: **Email address**, **Password**, **Log in**.
3. First time on this PC only: on **Choose a till**, press **T2**.
4. **Open a shift on T2**: count the drawer, type it in **Opening cash (LKR)**, press **Open shift**.
5. Check the top bar shows **T2**, **Doctor's Medicines**, **Shift open** and **Online**.

Always open a shift: the till does not sell without one.

The till does not sign you out by itself. **Sign out** when you leave the counter (but not while sales are waiting to sync, section 10), so nobody sells under your name.

## 2. Keyboard shortcuts

| Key                         | What it does                                                |
| --------------------------- | ----------------------------------------------------------- |
| F2                          | Go to the search box                                        |
| F3                          | Switch this bill between **OPD bill** and **Itemised bill** |
| F6                          | Recent sales (reprint a receipt)                            |
| F7                          | Prescription details                                        |
| F8                          | Go to the **Medicines amount** box                          |
| Enter (in Medicines amount) | Back to the search box                                      |
| F9 or Ctrl + Enter          | Pay                                                         |
| Ctrl + Enter                | In the payment box: **Complete sale**                       |

## 3. An OPD bill, step by step

Every new bill at T2 starts as an **OPD bill**. The heading above the bill says **OPD bill**, and the **Consultation** line is already on it.

### Step 1: the consultation fee

1. The **Consultation** line shows a **Price** box filled in with the usual fee.
2. If the doctor charges a different fee for this patient, type it in **Price**. It must be more than zero.

### Step 2: add the medicines given

1. Scan each medicine, or search by brand, generic name or strength (F2), as at General Pharmacy.
2. Set the quantity and the unit on each line:
    - tablets and capsules: loose tablets or a box,
    - syrups: **loose ml** can be sold at this counter (for example 60 ml), or a whole bottle,
    - injections: a single vial or a box.
3. Whole numbers only: never half a tablet.

Medicine lines on an OPD bill show **In Medicines** instead of a price. Each line still shows its batch and expiry, for example **Batch PN2611 exp 03/2027**. The system takes stock **earliest expiry first**.

Add every medicine the patient takes away, even when the doctor charges a small amount for them. This keeps the Doctor's Medicines stock right.

### Step 3: the Medicines amount

1. Press **F8**, or click the **Medicines amount** box.
2. Type the one amount the doctor charges for all the medicines, for example `850`.
3. Press **Enter** to go back to the search box.

Under the box the till shows **List value of the medicines**: what these medicines would cost at their normal prices. Use it as a guide.

If the Medicines amount is less than the list value, the till shows an amber note:

> **Below the medicines' list value. Check the amount; you can still take payment.**

This is only a warning. Check the amount with the doctor. If it is right, carry on. **Pay** still works. (The Admin can change when this warning shows; it may say a percentage, for example "Below 80% of the medicines' list value".)

### Step 4: check the totals

The totals show:

- **Consultation**: the fee,
- **Medicines**: the Medicines amount,
- **Total**: what the patient pays.

OPD bills take **no discounts**. There is no Discount button on an OPD bill.

### Step 5: prescription details

If any medicine is prescription-only or controlled, a **Prescription** button (F7) appears.

1. Press **Prescription** (or F7).
2. **Prescriber**: usually already filled in with the clinic's doctor. Change it only if the prescription is from someone else.
3. **Rx reference (optional)**: for example the OPD number.
4. Press **Done**.

The prescriber is required. If it is missing, **Pay** opens this box first. Fill it in and press **Continue to payment**.

### Step 6: take payment and print

1. Press **Pay** (or F9).
2. Take cash, card or bank transfer, exactly as at General Pharmacy:
    - Cash: type the cash given, or press **Exact** or a note button.
    - Card: **Add card**; the amount fills in; card slip number in **Reference** if you have one.
    - Bank transfer: **Add bank transfer**; type the transfer reference in **Reference (required)**.
3. Give the **Change due** from the drawer.
4. Press **Complete sale**.
5. On **Sale complete**, press **Print receipt**, then **New sale**.

The next bill starts as an OPD bill again, with the consultation line.

### What the OPD receipt shows

- The clinic's name, address and phone, the bill number, date, till and cashier.
- The consultation with its fee.
- One **Medicines** line with the Medicines amount.
- Under it, the medicines given by name and quantity, with their batch numbers, **without prices**. (The Admin can choose to print totals only.)
- The total, the payment and its reference, and the change.

The expiry date is not printed. This is the clinic's choice.

## 4. Messages on an OPD bill

| Message                                      | What it means                                                                                |
| -------------------------------------------- | -------------------------------------------------------------------------------------------- |
| **Enter the Medicines amount.**              | There are medicines on the bill but no Medicines amount. Press F8 and type it.               |
| **Add the medicines dispensed.**             | A Medicines amount is typed but no medicines are on the bill. Add them, or clear the amount. |
| **The Medicines amount cannot be negative.** | Type the amount again.                                                                       |
| **OPD bills take no discounts.**             | Remove any discount.                                                                         |
| **Below the medicines' list value. ...**     | A warning only. Check the amount with the doctor. You can still take payment.                |

A **consultation only** bill (no medicines) is fine: leave the Medicines amount empty.

## 5. An itemised bill at T2

Sometimes a bill at T2 should be priced item by item, at the normal prices, with no consultation (for example a repeat purchase the doctor does not charge a lump sum for).

1. Press **Itemised bill** above the bill (or F3). The heading changes to **Current sale** and each line shows its price.
2. Ring it up and take payment as normal.
3. Press **OPD bill** (or F3) to switch back. After **New sale**, T2 starts with an OPD bill again.

Ask the Admin which bills should be itemised at T2.

## 6. Picking a batch (doctor only)

The system always takes the batch with the **earliest expiry first**. The doctor's account can choose another batch on a line: under the line, the **Batch** list shows **First expiry first (auto)** and the other batches in date. Cashiers do not see this list and do not need it.

## 7. Ask General Pharmacy for stock

All stock is received at General Pharmacy and moved to Doctor's Medicines. When the OPD runs low, ask for stock from the till.

1. Press **Stock requests** in the top bar. (It is only there while the till is **Online**.)
2. Under **Ask for stock**, check **For** is **Doctor's Medicines** and **From** is **General Pharmacy**.
3. Add the items:
    - type in **Search by name, SKU or barcode** and press an item. Each result shows how much is **Here** and how much General Pharmacy has, or
    - press **From low stock** to add every item here at or below its reorder level, topped up to its usual level.
4. For each line choose the **Unit** (for example **Box of 30**, or loose tablets) and the **Quantity**. Remove a line with the bin button.
5. Add a note if needed (optional).
6. Press **Send request to General Pharmacy**.

Your requests are listed under **Requests** with their number (for example **RQ-000001**) and status:

| Status          | Meaning                                             |
| --------------- | --------------------------------------------------- |
| **Requested**   | Waiting at General Pharmacy                         |
| **Partly sent** | Some of it has been sent, the rest is still to come |
| **Sent**        | Everything has been sent                            |
| **Cancelled**   | The request was cancelled                           |

Each line shows what was **sent** and what is **to come**. Press **Print** to print a request. Press **Cancel** to cancel a request while nothing has been sent yet.

The Admin sends the stock from the admin panel. When it is sent, it is at Doctor's Medicines straight away. Press **Back to the till** to return to selling.

## 8. Recent sales and reprints

1. Press **Recent sales** (F6).
2. Find the bill (type the invoice number, for example `T2-000045`).
3. Press **Reprint**. The copy is marked **\*\*\* REPRINT \*\*\*** and logged.

Not available while the till is offline.

## 9. Returns and cancelled bills

Cashiers cannot do returns, refunds or cancel (void) a bill. **Ask the Admin.**

## 10. When the internet goes down

- The top bar shows **Offline** (amber), and how many sales are waiting, for example **Offline – 3 sales waiting to sync**.
- Keep selling. OPD bills, the Medicines amount, prescriptions, payment and receipts all work offline.
- **Sale complete** says **Saved on this till. It will sync when the connection is back.**
- When the internet is back the sales go up by themselves. You can press **Sync now**.

Not available offline: **Recent sales** and reprints, **Stock requests**, and **Close shift** (until every sale is sent).

While sales are waiting: do not sign out, do not press **Change till**, and do not clear Chrome's data. If you are signed out while the internet is down, you cannot sign in again until it is back.

## 11. End of day

1. Make sure the top bar says **Online** and no sales are waiting. Press **Sync now** if needed.
2. Press **Close shift**.
3. Count the drawer and type it in **Counted cash (LKR)**.
4. Check the **Difference**. Count again if it is red.
5. Press **Close shift**.
6. Press **Print the shift summary**, hand in the drawer with it.
7. Press **Done**, then **Sign out**.

## 12. Common problems

| Problem                                               | What to do                                                                                                                   |
| ----------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| The bill does not start as an OPD bill                | Press F3 or **OPD bill**. If the button is not there, tell the Admin: this till or your account is not set up for OPD bills. |
| The consultation fee is wrong for this patient        | Type the right fee in the **Price** box on the Consultation line.                                                            |
| **Pay** is greyed out                                 | Read the red message above it, usually **Enter the Medicines amount.**                                                       |
| A syrup will not sell by the ml                       | Check the unit list on the line. If ml is not offered, tell the Admin.                                                       |
| A medicine shows **Out of stock** but is on the shelf | Tell the Admin. Then send a stock request to General Pharmacy.                                                               |
| **Stock requests** button is missing                  | The till is offline. Wait until it is **Online**.                                                                            |
| The receipt does not print                            | Check paper and power. Reprint from **Recent sales**. If a print box appears on screen, call support.                        |
| A patient wants to return something                   | Ask the Admin.                                                                                                               |

Support: Webxkey — phone \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_
