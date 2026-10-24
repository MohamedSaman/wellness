# Data protection note

**For:** \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ (the clinic) **From:** Webxkey **Version:** 1, 2026-10-22

This note says where the clinic's POS and pharmacy system keeps its data, how it is protected, who can reach it, how it is backed up and how long it is kept. Please read it, ask about anything unclear, and sign at the end. It describes the technical set-up; it is not legal advice. The clinic remains responsible for its own obligations (in Sri Lanka, the Personal Data Protection Act, No. 9 of 2022), and may want its adviser to read it too.

## 1. Roles

- **The clinic** decides what data is entered and why (the _controller_).
- **Webxkey** runs the system and its servers for the clinic and handles the data only to do that (the _processor_). Webxkey does not use, sell or share the clinic's data for anything else.
- **Hosting providers:** the server company (\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_, region \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_) and the backup storage company (\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_, region \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_). They store the data but cannot read the backups (section 4).

## 2. What data the system holds

| Kind                          | Examples                                                                                                   |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------- |
| Sales                         | Bills, items, prices, payments (cash, card, bank transfer with its reference), returns and voids           |
| Patient details on some sales | The patient's name typed on a bill, the prescriber's name and the prescription reference on medicine lines |
| Stock and purchasing          | Medicines, batches and expiries, suppliers, orders, deliveries, supplier invoices and payments             |
| Staff accounts                | Name, email, role, encrypted password, sign-in times, two-factor settings                                  |
| Audit log                     | Who changed what and when (prices, costs, users, settings), with the address and browser used              |

The system holds **no clinical records** (diagnoses, notes, results) and no card numbers (card payments are taken on the bank's terminal; only an optional reference is typed).

## 3. Where it is stored

- **The live system:** one server at \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ in \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ (Singapore or Mumbai). The clinic's data is in its own database, used by its own program account; other Webxkey clients on the same server cannot reach it.
- **Backups:** at a second company, in a different place from the server (section 5).
- **Till PCs:** each till keeps a copy of the item list (names, prices, stock) and, while the internet is down, the bills made since, until they are sent to the server. Nothing else stays on the PCs.

## 4. How it is protected

- **In transit:** every connection between the clinic and the server is encrypted (HTTPS, with a certificate renewed automatically).
- **Backups:** encrypted on the server **before** they leave it (age encryption). They can be opened only with the clinic's **recovery key**, which is kept offline on paper and USB by the owner and in Webxkey's safe, never on the server; and by the server itself for the weekly restore check.
- **Passwords** are stored only as one-way hashes; at least 12 characters for the owner and anyone with back-office access, 10 for till staff, and never one known from a public data breach. The owner's account **must** use two-factor authentication (a code from a phone app); staff may too.
- **Sign-in protection:** repeated wrong passwords are slowed down; the back office signs out after 30 minutes without activity (adjustable). The tills do not sign out by themselves, so billing never stops during an internet outage; staff sign out when they leave the counter.
- **The server:** only the web ports and SSH (by key, not password) are open; the database is not reachable from the internet; security updates install every night; the server company's disk encryption: \_\_\_\_\_\_\_\_ (yes / no).
- **Optional:** the back office can be limited to the clinic's own internet address(es).

## 5. Backups

- Every night (and before every update), the whole database and the uploaded files.
- Kept: one for each of the last **14 days** and one for each of the last **12 months**; older ones are deleted automatically.
- Every week the newest backup is restored on the server into a test database and checked automatically. Webxkey is alerted at once if a backup or check fails.
- The owner can download the newest backup (encrypted) from the system at any time.

## 6. Who can see the data

| Who               | What they can see                                                                                                                                                                                                       |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The owner (Admin) | Everything, including costs, profit and the audit log                                                                                                                                                                   |
| Cashiers          | The till, their own sales, items and stock; nothing in the back office                                                                                                                                                  |
| Webxkey support   | The server and database, to run, update, back up and fix the system: named staff only (\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_), each with their own key. On request, Webxkey tells the clinic who accessed the server and why |
| Hosting providers | Encrypted backups (unreadable to them); the server's disk under their own security terms                                                                                                                                |

The owner adds and removes staff accounts; a deactivated account is signed out at once.

## 7. How long data is kept

- **Sales, stock and purchasing records:** for as long as the clinic uses the system, so reports and stock stay correct. They are not edited or deleted (corrections are new entries), as required for accounting. Retention period agreed with the clinic: \_\_\_\_\_\_\_\_ years.
- **Audit log:** \_\_\_\_\_\_\_\_ days (set in the system; 0 = kept for as long as the system is used).
- **Backups:** at most 12 months.
- **When the service ends:** Webxkey gives the clinic a final export and the newest backup, then deletes the server copy and, within \_\_\_\_\_\_\_\_ days, the backups, and confirms it in writing.

## 8. If something goes wrong

If Webxkey learns of unauthorised access to the clinic's data, or loss of it, Webxkey tells the owner within \_\_\_\_\_\_\_\_ hours, with what is known, and helps the clinic with what it must do next. The backups and the audit log help find out what happened.

## 9. The clinic's part

- Keep the recovery key and the owner's two-factor recovery codes safe and private.
- Give each staff member their own account; deactivate it on the day they leave.
- Type only what the bill needs about a patient (name, prescriber, prescription reference).
- Keep the till PCs locked away from the public when the clinic is closed.

## Approval

Approved for the clinic:

Name: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Signature: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Date: \_\_\_\_\_\_\_\_

For Webxkey:

Name: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Signature: \_\_\_\_\_\_\_\_\_\_\_\_\_\_\_\_ Date: \_\_\_\_\_\_\_\_
