# Till PCs

Setting up each counter PC of a client on the cloud install ([CLOUD.md](CLOUD.md)): the clinic's **T1** (General Pharmacy) and **T2** (Doctor's Medicines / OPD). Written for the Webxkey technician at the clinic; it takes about an hour per PC. Tick each PC off on the [handover checklist](../handover/handover-checklist.md).

Replace `https://pos.clinic.lk` with the client's address.

## What each PC needs

| Item            | Notes                                                                                                       |
| --------------- | ----------------------------------------------------------------------------------------------------------- |
| PC              | Windows 10/11 or Ubuntu 24.04+, 8 GB RAM, on a UPS                                                          |
| Chrome          | Up to date (it updates itself)                                                                              |
| Receipt printer | 80 mm thermal (USB or network), with its Windows/Linux driver, **set as the PC's default printer**          |
| Cash drawer     | Plugged into the receipt printer's drawer port (RJ11/RJ12); opened by the printer driver                    |
| Barcode scanner | USB, keyboard mode, Enter after each code                                                                   |
| Label printer   | Only where labels are printed (usually the owner's back-office PC, not a till), [below](#label-printer)     |
| Internet        | The clinic's broadband with 4G failover ([CLOUD.md, What the clinic needs](CLOUD.md#what-the-clinic-needs)) |

## 1. Windows (or Ubuntu) settings

1. A local account for the till (e.g. `till1`) that signs in by itself at start-up (Windows: `netplwiz`, untick "Users must enter a user name and password"). The cashiers then sign in to the POS, not to Windows.
2. **Date and time: set automatically**, time zone **(UTC+05:30) Sri Jayawardenepura**. A wrong clock makes offline sales get flagged "clock" and dated by the server's time.
3. Power: never sleep, never turn off the screen while plugged in. USB selective suspend off (the scanner and printer must not fall asleep).
4. Windows Update: active hours over the clinic's opening hours, so it never restarts during the day.

On Ubuntu: automatic login in Settings → Users, automatic date and time on, Power → Screen Blank: Never, Automatic Suspend: Off.

## 2. Receipt printer and cash drawer

1. Install the printer's driver (Epson TM, Xprinter, Star …) and print the driver's own test page.
2. In the driver's **printing preferences**:
    - paper: **80 mm roll** (often "80 × Receipt" or "Roll Paper 80 × 297 mm"; on the Xprinter XP-80C driver **80(72.1) x 297 mm**), the page length set by the document;
    - margins: **none** / minimum;
    - **cash drawer: open at the end of each document** (Epson: "Cash Drawer: Open after printing"; other makes call it drawer kick). The app sends no drawer command of its own: the driver opens it with every receipt.
3. Make it the **default printer**: Chrome's silent printing always uses the default printer.

## 3. Barcode scanner

1. Plug it in (it is a keyboard to the PC). Scan the setup codes in its manual for: **USB keyboard (HID)**, **suffix: Enter (CR)**, no prefix, the right keyboard country (US).
2. Test in Notepad: each scan types the code and goes to the next line.

## 4. Chrome: the till, silent printing and start-up

1. Open Chrome normally and go to `https://pos.clinic.lk`. Sign in as the **owner** (two-factor code), then **Open POS**.
2. **Choose the till** (T1 on the General Pharmacy PC, T2 on the OPD PC).
3. Approve the till for offline sales: press **Set up offline sales** in the top bar (or turn on **Offline sales allowed** for this till on Administration → Tills, then reload). The top bar says **Preparing offline...** while it downloads the catalog and a block of invoice numbers, then **Ready for offline**: this browser can now bill when the internet is down. It may take a minute. See [Offline ready by default](#offline-ready-by-default).
4. **Install the app**: the install icon at the right of the address bar, or menu ⋮ → **Cast, save and share → Install page as app** ("POS Checkout"). This keeps the till's offline files on the PC, so the till opens even if the PC restarts during an outage.
5. Print once **with the dialog**, to set Chrome's print settings for silent printing: ring up a cheap test item (the OPD's consultation, or an item to void afterwards), pay, **Print receipt**. In the print dialog: printer = the receipt printer, **Margins: None**, **Scale: Default (100)**, **Headers and footers: off**, **Background graphics: on**. Print. Chrome remembers these. Void the test sale in the admin panel afterwards (owner).
6. **Sign out** of the owner's account.
7. Make the start-up shortcut. Windows: a shortcut in `shell:startup` (Win+R) with the target

    ```
    "C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --kiosk-printing https://pos.clinic.lk/pos
    ```

    Ubuntu: `~/.config/autostart/pos-till.desktop`:

    ```ini
    [Desktop Entry]
    Type=Application
    Name=POS till
    Exec=google-chrome --kiosk --kiosk-printing https://pos.clinic.lk/pos
    X-GNOME-Autostart-enabled=true
    ```

    `--kiosk` fills the screen with the till (no address bar, tabs or menus); `--kiosk-printing` prints every receipt straight to the default printer with the settings from step 5, no dialog. The receipt window closes itself once printed. To leave kiosk mode: **Alt+F4**.

8. Restart the PC: it should sign in to Windows, open the till full screen at the sign-in page. A cashier signs in and the till remembers which till it is.
9. Run the [first print test on the real printer](#first-print-test-on-the-real-printer).

Use the **same Windows account and Chrome profile** for all of this: the offline registration, the saved sales waiting to upload and the print settings live in that profile.

## First print test on the real printer

Do this on each till PC, once, and again whenever the printer, its driver or Chrome's print settings change. The automatic tests print to PDF, so only this proves the paper. Keep the slips for the handover.

Print three things:

1. **Online receipt:** a test sale, **Print receipt**.
2. **Offline receipt:** unplug the network, wait for the amber **Offline** badge, a test sale, **Print receipt**. Plug the network back in.
3. **Refund slip:** refund one item of the online sale (owner, Sales screen) and print the slip.

On each slip, check:

- [ ] **Width:** nothing cut off on either side; the amounts' last digits and the rule of dashes are whole.
- [ ] **Darkness:** text and logo solid black, no grey or sparse dots. The total stands out, bold and double height.
- [ ] **No header or footer:** no page title, address, date or page number anywhere.
- [ ] **Page length and cut:** one slip, no long blank tail, cut just below the last line.
- [ ] **Same paper:** the online and offline receipts are the same width and text size.

Then void the test sales (owner, admin panel).

**If it goes wrong:**

| What you see                                                                                                          | What to change                                                                                                                                                                                                                                                                                                                               |
| --------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Chrome ignores the page length: shrunk text, or a long blank tail, or a cut in the middle of the slip (XP-80C driver) | In the driver's printing preferences set the paper to **80(72.1) x 297 mm**, close Chrome and print again. That is the fallback: a page length the driver knows. A long blank tail on a driver with no "page length set by the document" option can also be cut short with the driver's own paper-saving or "cut at end of document" option. |
| Text small, or the slip narrower than the paper                                                                       | Chrome is scaling: in the print dialog **Scale: Default (100)**, **Margins: None**; in the driver the 80 mm paper, not A4 or Letter.                                                                                                                                                                                                         |
| Left or right edge cut off                                                                                            | The driver's paper is wider than the printer's: choose the 80 mm paper. Margins none.                                                                                                                                                                                                                                                        |
| Page title or address printed                                                                                         | The page's margins are not being honoured: check that the print went through the till's **Print receipt**, not Ctrl+P on the till page.                                                                                                                                                                                                      |
| Print pale overall                                                                                                    | The printer's own density: raise it in the driver's preferences (Density / Darkness), and check the paper is thermal paper the right way up.                                                                                                                                                                                                 |

## 5. Check offline billing

With a cashier signed in, the shift open, on each till:

1. Check the top bar says **Ready for offline**. Unplug the network cable (or turn Wi-Fi off). Within seconds the badge turns amber: **Offline**.
2. Ring up a sale and pay cash. **Sale complete** says **Saved on this till. It will sync when the connection is back.** The receipt prints and the drawer opens.
3. The badge shows **Offline – 1 sale waiting to sync**.
4. Plug the network back in. Within a minute the badge shows **Online** with nothing waiting (**Refresh now** does it at once).
5. On the owner's PC, **Sales**: the sale is there with its till number (T1-…); **Administration → System health**, Tills: that till shows **Offline sales waiting 0** and its **Clock: On time**.
6. Void the test sale (owner, admin panel).

The same check runs automatically in the end-to-end tests (`tests/e2e/offline-sale.spec.ts` and `offline-ready.spec.ts`, Chrome and Firefox; `offline-print.spec.ts`, Chrome), but only the check on the real PC proves the printer, drawer and network.

While a till holds sales waiting to sync: don't sign out, don't clear Chrome's data, don't reinstall Chrome. A till that was signed out during an outage cannot sign in until the internet is back.

## Label printer

Silent printing sends everything to the default printer, so print labels from a Chrome window **without** `--kiosk-printing` (the owner's back-office PC, or a normal Chrome window on a till PC), where the print dialog lets you pick the label printer.

1. Install the label printer's driver (Zebra, TSC, Xprinter …) and calibrate it to the labels (the driver's or printer's "calibrate" / "media sensing").
2. In the driver, a **page size equal to one label**: the template's size, e.g. **38 × 25**, **50 × 25**, **50 × 30**, **40 × 30** or **58 × 40 mm** (the rolls the app has templates for), margins none; the printer's resolution (203 or 300 dpi) as set on the template.
3. **Administration → Label templates**: the template with the same size and dpi. Print a test label from a product; scan it to check.
4. In Chrome's print dialog the first time: the label printer, **Margins: None**, **Scale: Default**, headers and footers off. A4 sticker sheets (24, 40 or 65 a sheet) go to an ordinary A4 printer the same way.

## Offline ready by default

An approved till gets ready for offline sales by itself, whoever is signed in: nobody has to press anything, and a cashier who forgets cannot leave it unprepared.

**The owner's approval** is **Offline sales allowed** on Administration → Tills, one till at a time, or **Set up offline sales** pressed by the owner at the till. It is off for a new till. Tills that already had an offline registration were approved when this arrived.

**An approved till, open and online**, without any button: takes its sync token (`POST /pos/till/prepare`), downloads the catalog and reserves a block of invoice numbers. It keeps these fresh on load, whenever the connection returns and every 5 minutes, and keeps trying every 20 seconds until it is ready. Waiting sales upload the same way. **Refresh now** does it at once, and also retries sales the server refused.

**One browser for each till.** The server gives an approved till's token to the first browser that asks and refuses any other, so a cashier's sign-in on another PC never gets a copy of the till.

What the top bar shows:

| The top bar says                                       | Meaning                                                                                             |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------------------- |
| **Ready for offline**                                  | This till can sell if the connection drops.                                                         |
| **Preparing offline...**                               | Getting there; nothing to do.                                                                       |
| **Not ready for offline - reconnect**                  | The connection dropped before it was ready. It finishes when the connection is back.                |
| **Offline not available on this till - ask the owner** | Never approved, revoked, or set up on another browser. The owner approves it, or resets it (below). |

**Revoking** (**Revoke offline access**) deletes the till's tokens and takes the approval away, so the browser cannot register itself again. At its next sync the till stops selling offline; a till that is offline at that moment cannot know until it reconnects. Sales it holds stay there.

## When a PC is replaced or Chrome is reset

- On the owner's **Administration → Tills**, press **Reset offline on this till**. It frees the till's token and keeps it approved, in one step (audited). The next time the till is opened, on the new PC or the reset Chrome, it sets itself up and shows **Ready for offline**, with fresh offline invoice numbers. On a new PC, repeat 1, 2 and 4 of [section 4](#4-chrome-the-till-silent-printing-and-start-up) as well.
- On the owner's **Administration → Tills**, revoke the offline access of a lost or stolen PC (**Revoke offline access**), so it can no longer upload.
- Sales still waiting on an old PC upload when it is next online and signed in; don't wipe it until System health shows 0 waiting for it.
