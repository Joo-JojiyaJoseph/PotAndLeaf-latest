# Pot & Leaf ERP — all fixes (27 Oct 2026, update 26)

This package holds **every file changed** from the uploaded project (362 files + this note).
It replaces all earlier packages; install only this one.

## Install

1. Copy each file over the same path in your project.
2. Backend: **back up the database first**, then `php artisan migrate` (13 new migrations — update 12 adds 3, one of them removes soft delete for good; updates 14, 18, 19 and 20 add 1 each).
3. Frontend: `npm run build`.

## Verified

| Check | Result |
|---|---|
| Backend test suite (Pest, PHP 8.4) | 455 passed, 0 failed, 16 skipped (before: 379 passed) |
| Frontend build (Vite) + unit tests (Vitest) | builds · 23 passed |
| Browser check of every changed screen | no script errors, no failed API calls |
| API run-through: purchase → batch/barcode → damage → purchase return; sale (cash/UPI/credit/walk-in) → confirm; transfer between companies → receive; bulk split | all OK; batch totals = stock |

## 00. Update 2 — one location per company, simpler commission

**Pop-up windows cover the whole screen (update 26)** — frontend only
- Pop-ups (New supplier, New customer, Import, Add user, Set staff rate, Approve, Receive…) were drawn inside the page area, so the top bar stayed bright and clickable above the dimmed page and the window could sit under it. Every pop-up is now drawn on top of the whole screen: the top bar is dimmed too and the window is always fully visible and centred. Same for the enlarged picture on the Help page.
- Checked on 13 screens with pop-ups at 1920, 1366 and phone (390) widths: every window inside the screen, nothing on top of it; all 85 pages open without errors (a list warning on the product page was also fixed).

**Help page smaller (update 25)** — frontend only
- Guide text is a little smaller (13.5 px instead of 15 px) with tighter spacing; headings and tables smaller to match.
- Pictures are shown at most 680 px wide and centred instead of filling the whole width. Click a picture to see it full size, as before.

**Users list "Couldn't load users" fixed, role permissions can be seen (update 24)** — no migration
- **Fixed:** for anyone except the super admin (or the super admin filtering one company), the users list asked the database for a column called "pivot" that does not exist. On MySQL this stopped the list with an error ("Couldn't load users"); on SQLite the list opened but every user's **Role** showed "—". Users now load and show their role in that company. The same mistake in the role check `hasRole()` is fixed too.
- If the list ever fails again, the reason from the server is shown under "Couldn't load users".
- **Roles & Permissions:** click a role's name or **Permissions** to see exactly what it can do, grouped by area (e.g. Sales: View sales, Create sales, Confirm sales). Roles with full access say so. Everyone who can see roles can open this; only the super admin sees **Change permissions** / edit / delete.
- New test `tests/Feature/UserListRolesTest.php`.

**Help menu with the user guide (update 23)** — frontend only, no migration
- New **Help & User Guide** item at the bottom of the menu (every user sees it) and a **?** button in the top bar. It opens the full guide inside the app: chapter list, a search box that shows only the chapters with your word (highlighted), and pictures you can click to see bigger. Works in dark mode and on phones.
- The guide is shipped with the app: `public/help/guide.md` and 55 screenshots in `public/help/img/` (about 5 MB). To update it later, replace those files — no code change needed.
- The guide was brought up to date with updates 13–22: a "What's new — 27 October 2026" chapter, the new stock request steps (request → approve → dispatch → receive), "Left in batch" with a worked example, page numbers, dark mode, supplier page boxes, product category/subcategory, supplier rule on purchases, reorder PDF, and deleting backups. Every screenshot was taken again on the current screens.

**Page numbers on every long list (update 22)** — no migration
- Lists now show 25 rows at a time with page buttons ("26–50 of 59"): Stock levels, Valuation, Fast/slow/dead, Batches & barcodes, Production, Locations, Roles, Backups, Activity log, Companies, Master data, Request stock, Barcode labels, Commission payouts and WhatsApp log, supplier payments / advance, and the long report tables.
- **Fixed: rows were missing.** Stock levels showed only the first 20 products, and some reports (rental, production, transfers, loyalty customers, WhatsApp) showed only their first 50 rows with no way to see more. All rows can now be reached.
- Production search now searches every order, not only the ones loaded; Export still downloads every matching row.
- Batches: the column is now "Left in batch", with "of 30 received · 11 gone out" under it. Batches made by receiving a stock transfer now say "Transfer TRF-…" instead of "Opening".
- New test `tests/Feature/BatchSourceAndListSearchTest.php`.

**Dark mode made readable (update 21)** — frontend only
- Table headings no longer sit on a pale grey strip; labels are clearly visible. The top bar is dark instead of grey.
- Barcodes always have a white background, so they show (and scan) in dark mode.
- Yellow notices, warning tags, pop-ups (darker backdrop, solid panel), green buttons and coloured status text all have enough contrast. Light grey hint text is a little brighter.
- Date pickers, dropdown lists and scrollbars use the dark style.
- Checked: every screen opened in dark mode with an automatic contrast check.

**Batches: "received" figure on the opening-stock batch (update 20)** — 1 new migration
- Editing a product's opening stock down and back up (e.g. 150 → 0 → 150) used to count each raise as new stock received, so the batch showed "150 of 300 received" (or 450). Lowering opening stock now also lowers the opening batch's received figure, because it is a correction, not a sale. It shows "150 of 150".
- If part of the opening batch is already sold, only what is left in it is taken off; the rest comes off other batches, oldest first.
- Migration `2026_10_27_000007` repairs existing opening batches: received = the product's opening stock (never less than what is still left). Stock on hand is not changed. New test `tests/Feature/OpeningBatchCorrectionTest.php`.

**Stock transfers are now stock REQUESTS (update 19)** — 1 new migration
- **Request stock** (Stock transfers → *Request stock*): you see every other company's products and how many they can give. Type how many you need, click **Add**, then **Send request**. Asking two companies makes two requests (one each). You cannot ask for more than they have free.
- Steps: **you request → admin approves → that company clicks Dispatch → you click Receive**. On Receive the stock is added to your company and a **new barcode batch** is made; the barcode shows on the transfer under "New barcode".
- **Approve**: the admin can pick **which company sends** the stock — the approve window lists every company that has the product and whether it has enough. The company that asked cannot approve its own request; it can cancel it before approval.
- **Fixed: "couldn't load" and missing Receive button for the receiving company.** The page used the *sending* company for the login check, which a branch user does not belong to. Branch users now always open transfers under their own company, so Receive shows for them, not only for the admin.
- **Fixed: received stock landing on a different product.** Every company numbers its products SKU-00001, SKU-00002…, so matching by SKU picked the wrong product (e.g. Areca Palm stock added to Rose Pot). Products are now matched by **name**. If the receiving company doesn't have it, a new product is made with a new SKU (never one already used there).
- List: clear statuses (Waiting approval, To dispatch, On the way, Received) and a "Coming to us" / "We send" tag. The old push form stays for the admin at *Send stock directly*.
- Migration `2026_10_27_000006` adds who asked (`requested_by`) and the batch made on receipt. New APIs `GET /api/transfers/request-data`, `POST /api/transfers/request`, `GET /api/transfers/{id}/sources`; new test `tests/Feature/TransferStockRequestTest.php`. Checked in the browser with three different logins (requesting branch, admin, sending branch).

**Supplier page: payments and advance shown separately (update 18)** — 1 new migration
- The mixed "Advance and payments" table is replaced by:
  - three boxes under the supplier's name: **You owe this supplier**, **Advance with this supplier**, **Paid so far**;
  - **Payments made** — each payment with date, mode and reference, and what it was used for: "Paid bill GRN-… · ₹…", "Paid as advance", or "Extra kept as advance" (click a bill to open it). Using advance on a bill is not shown here, because no new money was paid;
  - **Advance** — where the advance came from (advance paid, extra paid on a bill, goods returned beyond what you owed) and which bills it was used on, with "Left ₹…" after each line. The last line always equals the supplier's advance balance; advance from before this was recorded shows as one "Earlier advance" line.
- Buttons: **Pay supplier** and **Use advance on a bill** open Pay Suppliers. The Outstanding / Advance lines were removed from "Credit terms" (now in the boxes).
- Lists stack neatly on phones (no sideways scrolling).
- Migration `2026_10_27_000005` adds `advance_credit` to purchase returns, so a return's credit beyond what was owed is recorded from now on.
- New API `GET /api/suppliers/{id}/account`; new test `tests/Feature/SupplierAccountTest.php`.

**Category on the product page (update 17)** — no migration
- A product saved under a subcategory showed the subcategory's name as "Category", and the main category was missing. The product page now shows **Category** (main) and **Subcategory** separately, and the heading reads e.g. "SKU · Live Plants › Palms". Product cards in the list show the same.
- Filtering the product list by a main category now also shows products saved under its subcategories (before they were left out).
- New test `tests/Feature/ProductCategoryDetailTest.php`.

**Reorder report PDF shows the right supplier (update 16)** — no migration
- The PDF was built only from suppliers saved on the product, so a supplier picked on the Reorder screen showed as "Supplier Not Assigned". Export PDF / Supplier-wise PDF now send what is on screen — supplier, order qty and the Include tick — so the PDF matches the screen exactly.
- A product with no supplier linked now uses the supplier it was last bought from (shown on screen as "From the last purchase of this product"). Only products never bought and never linked stay "Supplier not assigned".
- The supplier box lists the product's own suppliers first, then every other supplier of the company. The "Suppliers" card now counts what is on screen (it said 0 before).
- New test `tests/Feature/ReorderSupplierTest.php`.

**HSN code hidden on products (update 15)** — no migration
- The HSN code box is removed from Add product and Edit product, and from the product detail page. The product import template and export no longer have an HSN column.
- HSN codes already saved are kept: the form no longer sends the field, so editing a product never wipes its code. Sale and purchase bills still print a product's HSN when it has one (GST invoices may need it).
- New test `tests/Feature/ProductHsnHiddenTest.php`.

**Delete backups (update 14)** — 1 new migration
- Backups screen: a **Delete** (bin) button on each backup, after "Delete … for good? This cannot be undone. Your current data is not affected — only this saved copy is removed."
- Only real backup files (`backup_….sqlite`) in the backups folder can be deleted. The last remaining backup cannot be deleted (button greyed out), so there is always one copy to restore from.
- New permission "Delete database backups" (`backup.delete`); migration `2026_10_27_000004` gives it to every role that can already restore backups. Super admin always has it.
- New test `tests/Feature/BackupDeleteTest.php`.

**Supplier on purchases (update 13)** — no new migration
- A purchase made from a purchase order (Convert to purchase) keeps that order's supplier. The edit form shows the supplier as fixed with "From purchase order PO-…"; the server also refuses a different supplier for it.
- A direct purchase (Purchases → New purchase) picks from the suppliers that already exist ("Select an existing supplier…", searchable when the list is long) and can still be changed while it is a draft. A supplier that doesn't exist is refused.
- New test `tests/Feature/PurchaseFromOrderSupplierTest.php`.

**Update 12 — dropdowns, subcategories, no soft delete, making bonus** (3 new migrations — back up the database before migrating)

*Design*
- The dropdown in a pop-up window no longer runs off the top of the screen. When its box is half hidden it scrolls into view first; if the window content is scrolled so the box goes out of sight, the menu closes instead of floating over the page.
- "Select company first…" style prompts are no longer shown as a highlighted choice in the list (they stay available as "clear" once something is picked). The highlight starts on the current choice.
- Full layout check: 84 screens + detail pages at phone (390), tablet (768) and laptop (1366) — 252 page views, 51 pop-up windows and 48 dropdowns opened — no sideways scrolling, nothing sticking out, no cut-off tables or broken buttons.

*Subcategories for company logins*
- Roles were given category permissions but never subcategory ones, so only the super admin saw the Subcategories tab. Migration `2026_10_27_000001` gives every role with categories.X the matching subcategories.X; the Master data screen also treats category access as subcategory access (the server already did).

*Soft delete removed (deleting is now permanent)*
- Removed from all 25 tables; migration `2026_10_27_000002` drops the `deleted_at` columns.
- Rows that were already "deleted": bills/documents are removed; customers, suppliers, products, categories, units, brands, roles and companies are removed if nothing uses them, otherwise kept and set **Inactive** so no bill or stock history is lost.
- A record that is used anywhere can no longer be deleted (the database would otherwise wipe linked history, e.g. a customer's rentals). The message says where it is used, e.g. *Cannot delete customer "City Garden Dealers": it is used in 3 receipts, 3 points entries, 1 rental, 3 sales. Make it Inactive instead.* Unused records are deleted for good.
- Bill numbers, codes and barcodes now come from the highest number used (not a count), so a number is never given twice after a delete.
- Restore/trash code and wording removed ("It can be restored later", "soft delete"); delete confirmations now say it cannot be undone. Master data and Roles now show the reason when a delete is refused.
- Not changed: a *user* removed from a company is only taken off that company (they keep their login history) and can still be added back from Users → Removed.

*Commission for the person who makes items*
- New on a staff rate: **₹ for each piece made**, paid when the production is completed (once per production; taken back if that production is cancelled). The existing bonus when the piece is sold or sent to another company is unchanged; both can be used together.
- Set it in Commission → Set staff rate → "Grows / makes items" (step 2, box 1), or for a salesperson who also makes items under More options. The monthly statement shows "Bonus for pieces they made" and "Bonus for their pieces sold / sent" separately.
- Production form: "Supervisor" is now "Made by (supervisor or staff)" — any active staff member can be chosen (as before).
- Migration `2026_10_27_000003` adds `production_per_unit` to commission rules. No existing calculation changed.

**Duplicates and wrong values refused (update 11)** — no new migration, no calculation changed

| Where | Now refused |
|---|---|
| Companies | Same name as another company; same GST number; GST not 15 characters in the right pattern (e.g. 123456789); GST state digits that don't match the state code; state code that isn't 2 digits. Applies on Add **and** Edit. |
| Customers | Same GST number as another customer of the company; wrong GST pattern; PIN code that isn't 6 digits (a PIN code box was added to the customer form). |
| Suppliers | Same GST number as another supplier (works even though supplier GST is stored encrypted); wrong GST; wrong PAN (e.g. ABCDE1234F); wrong PIN code. |
| Products | Retail / wholesale / dealer price above the MRP; a GST % that isn't a real rate (allowed: 0, 0.1, 0.25, 1, 1.5, 3, 5, 6, 7.5, 12, 14, 18, 28, 40). |
| Roles | Two roles with the same name. |
| Purchases | The same supplier invoice number entered twice for the same supplier (cancelled bills don't count); a line discount bigger than the line value; a purchase or invoice date in the future. |
| Sales | Amount paid more than the bill (would leave the customer owing a minus amount); line discount bigger than the line; a future date. |
| Other dates | Returns, receipts, supplier payments, damage, stock count, bulk split and transfer dates cannot be in the future (India time). Expected dates on backorders and purchase orders cannot be before the order date. |

- GST, PAN and IFSC are saved in capitals with spaces removed, so `32abcde1234f1z5` is stored as `32ABCDE1234F1Z5`.
- Already in place and re-checked: same name for customers, suppliers, products, categories and units; same SKU; same user email; same-name-different-capitals (MySQL treats them as equal).
- Saved values: every field of companies, customers, suppliers, products, users and purchase bills was saved, read back and edited, then read back again — all matched what was typed.
- New test `tests/Feature/DuplicateAndValidationTest.php` (65 checks) keeps all of this working.
- Note: an existing record with an old wrong GST number (such as the test company with 123456789) will ask for a correct one the next time it is edited.

**Dropdown opening far from its box (update 10)**
- Fixed: when a dropdown sat low on the screen it opened upward but floated far above its box (e.g. Status in *Add company* opened 204 px too high, over the Phone field). It now always opens touching its box. One shared component, so all 39 screens with dropdowns are fixed.
- Checked: every dropdown on 33 screens and pop-up windows was pushed to the bottom of the screen and opened — 70 dropdowns, 17 opened upward, 0 out of place.

**Filters, Export and Import (update 9)** — no calculation changed, no new migration
- **Same filter bar on every list** (sales, purchases, both returns, purchase orders, transfers, rentals, advance orders, backorders, bulk splits, stock counts, money received, supplier payments): a search box plus a date choice — All dates, Today, Yesterday, Last 7 days, This month, Last month or Pick dates. "Clear filters" resets it. Long lists now have pages.
- **Export** button on all those lists and on Products, Customers and Suppliers: downloads *everything that matches the filters* (not only the page on screen) as a CSV file that opens in Excel.
- **Import** for Products, Customers and Suppliers (only for people allowed to add them):
  1. Download the template, fill it in Excel, Save As "CSV UTF-8".
  2. Choose the file and click **Check** — nothing is saved yet; every row is tested with the same rules as the Add form and problems are listed by row number (e.g. "Row 3 — phone format is invalid").
  3. Click **Import good rows**. Bad rows are skipped so you can fix and import them later.
  - Products: category and unit are typed by name; new ones are added automatically (the Check step lists them). Opening stock creates the batch and barcode, same as adding by hand.
  - An exported Products/Customers/Suppliers file uses the same columns, so it can be edited and imported into another company.
  - Up to 2,000 rows per file. A name repeated in the same file, or one that already exists, is refused.

**Easier everyday screens (update 8)** — wording and screens only, no calculation changed
- **What to do next:** draft bills, purchases, returns, transfers, splits, stock counts, rentals and purchase orders show one plain sentence, e.g. "Not final yet. Check quantities and rates, then click Confirm — the stock is added and the amount you owe the supplier is updated."
- **Ask before cancelling / deleting:** Cancel on sale, purchase, returns, purchase order, backorder, booking, rental and transfer; approving a bill cancellation; deleting a rent bill; voiding a customer receipt — each asks first and says what will happen.
- **Plain words:** page descriptions rewritten (no "GRN", "debit note", "posts to the ledger"); menu: Sales / Billing, Purchases (Bills), Money from Customers, Pay Suppliers, Stock Levels, Backorders (Waiting), Journal (Corrections); reports: Customer dues / Supplier dues / Old dues; "Convert to GRN" → "Convert to purchase"; status badges read "in transit", not "in_transit".
- **Empty lists** show a "+ New …" button (only for people allowed to create).
- **Dashboard:** shortcuts for daily jobs first (New sale, Receive money, New purchase, Pay supplier, Stock levels, Add product, Reports); figures no longer cut off on laptops; shortcut descriptions shown in full.

**Tester list 26 Oct (update 7)**

| Issue | Status | What was done |
|---|---|---|
| Retail customer can change the price tier on a sale | **Fixed** | Tier box locked to Retail when a retail customer is chosen; the server also saves Retail for them. |
| Commission payout can be saved without a rule | **Fixed** | Payment is refused (and the button disabled) for staff with no staff rate / manager share; the screen says why. |
| Salesman sees other salesmen's customers and sales | **Fixed** | New permissions "See every customer" and "See every staff member's sales". Without them a user sees only customers they added and bills they made (list, detail, invoice PDF, sale form picker). Migration gives them to every existing role except Salesman. |
| No edit on payouts; adding again overwrites (same in commission) | **Fixed** | Payouts: **Change** button; a second payment for the same person+month is refused with a message (and re-adding after removing no longer crashes). Staff rate: "Set staff rate" never replaces an existing one — people who already have a rate are not listed; use Change. Manager share: no overwrite, plus **Change / Remove**. |
| Manager share lists all users | **Fixed** | Only users with a Manager role are listed. |
| Some bank transactions missing in Bank Book | **Fixed** | Money from **walk-in sales** (no customer) was never posted: UPI/card now go to the Bank Book, cash to the Cash Book; cancelled sales are taken out; older walk-in sales are added automatically the next time a book is opened. |
| Company with data can be deleted | Already fixed (update 5) | Blocked with a list of what it holds; make it Inactive instead. |

**Screens fit every size (update 6)**
- The web-address pill at the top of every page is removed.
- Checked every screen (and every Commission tab and report) at 1440, 1280, 1093 (1366 laptop at 125% zoom), 1024, 768 and 390 px: nothing sticks out and no button label breaks onto two lines.
- Buttons and badges never break their text; page-header buttons move to the next line instead of squashing (fixes "Resend day's WhatsApp").
- Table cells stay on one line; on small screens tables scroll sideways (4 tables that were cut off now scroll).
- Status filter rows (All / Draft / … / Cancelled) scroll sideways on phones; report buttons fit as many per row as the screen allows; company box wide enough for "All Companies".

**Company delete is safe (update 5)**
- A company that has any products, customers, suppliers, bills, payments, stock records, categories or users cannot be deleted; the message lists what it has and says to make it Inactive instead. Only an empty company can be deleted.

**One location per company**
- A company gets its single location automatically when it is created (named after the company). The "Locations" box on the company form, the Locations menu/screen, the "By location" inventory tab/report and the branch filter are gone.
- A second location cannot be added, and the only one cannot be deleted. Transfers are company → company only.
- Migration `2026_10_24_000001`: every company ends up with one active location; stock recorded under extra locations is added to it; old documents keep their history.

**Commission, in plain rupees**
- Tabs renamed: Pay staff · Staff rates · Product offers · Manager share · Production bonus · Earnings log · WhatsApp messages · Care reminders — each with a one-line explanation and an example.
- Staff rate form: "Commission % of their sales", "Monthly sales target", "Bonus ₹ for the monthly target", "Max commission a month", with a live example ("sells ₹1,00,000 → ₹2,000 commission"). "₹ per piece" is shown only for production supervisors (it only ever applied to them); saved fields are the same as before.
- "Tiers" → **Higher % slabs** (with example), "Daily" → **Daily bonus**; product offers show "Extra ₹ per piece / % of price" with an example; manager share no longer asks for a branch.
- Pay commission shows every part: commission, monthly target bonus, daily bonuses, offers, manager share, production bonus, and amounts taken back for returns — so the lines add up to "To pay". Removing a payment asks first.
- **3 simple steps (update 4):** the page is now 1 Set rates → 2 See earnings → 3 Pay staff; product offers, manager share, production bonus and messages sit under "Extras". Setting a rate is a 3-question guide (Who? → How much? → Good-day bonus) with a summary before saving. Calculation unchanged.
- **Simple words (update 3):** a "How commission works" box (4 steps + a worked rupee example, can be hidden), rates shown as sentences ("Gets 2% of what they sell — ₹2 for every ₹100"), and the fixed monthly bonus is offered only for staff with no commission % (the only time the existing calculation pays it).
- **No commission calculation changed in this update** — only wording, examples and layout. The screen now says plainly how the existing rules work, e.g. the monthly target bonus is added only in a month with no per-bill commission (otherwise target rewards come from Daily bonus).

## 0. Tester issue list (13–22 Oct) — status of every item

| # | Issue | Status | What was done / found |
|---|---|---|---|
| 1, 2, 5 | Inactive company / category; supplier advance | Done (tester) | — |
| 3 | Same item ordered again; ordered items still on reorder | **Fixed** | One line per product on a PO. If it is already on an open PO, the screen says which PO and asks "Order again anyway?". Reorder list subtracts stock already on order and shows "+5 on order (PO-…)". |
| 4 | Supplier advance not visible | Already fixed | Shown on Supplier page (Advance balance + payments) and Payment history. |
| 6 | "Couldn't load users" | Not reproducible | Loads for every status and with All companies. |
| 7 | Batches/barcodes after purchase | Works | Created when the purchase is confirmed (not on payment). |
| 8 | Purchase return amount | Works | List, detail and debit note show the correct total. |
| 9, 12 | Batch-wise damage / return / bulk split | Already fixed | All three pick a batch. |
| 10, 24, 57 | Damage + return quantities | Works | Damaged units are offered in the return (capped at the damaged count) and are not taken off stock twice. |
| 11 | Confirmed return can be cancelled | Already fixed | Cancel only on drafts. |
| 13 | Bulk split amounts mismatch | Explained + **improved** | Cost is split exactly (4 × ₹40 = ₹160 = 2 × 26.665 + 2 × 53.335); the screen rounds each to paise, so it can look like ₹160.02. The new check step shows the true total. |
| 16 | Delete product with transactions | Already fixed | Blocked with the reason; deactivate instead. |
| 17 | Changes only after refresh | Already fixed | Every screen reloads data when opened and on window focus. |
| 18, 27, 28 | Transfers | Works | Receiving uses the existing product (matched by SKU/name), quantities match. |
| 19 | Supplier outstanding on edit | Already fixed | Changing opening balance moves outstanding by the difference. |
| 20 | Remove pricing from PO | **Needs your decision** | Rates feed the PO total, CBM value and the "convert to purchase" step. Option: keep rates but hide them on the PO PDF sent to the supplier. |
| 21 | Barcodes not in barcode section | **Fixed** | Barcodes live on batches; the labels page listed only the product barcode (mostly empty). Now one row per batch barcode in stock. |
| 22 | Batch qty not updated | **Fixed** | Rental return, sale cancel, sales return (unscanned) and pooled items now put stock back on the batches. |
| 23 | Wrong time zone | **Fixed** | All times sent with zone and shown in Indian time (also loyalty, activity, split pages). |
| 25 | Confirm bulk split pricing | **Fixed** | "Check prices before confirming" table (qty, cost, selling price; below-cost rows red). |
| 26 | Company user changing "from company" | **Fixed** | Screen already hid it; the server now also refuses another company's stock list / barcode scan. |
| 30 | Sale price not automatic | **Fixed** | Price filled on pick (already); now also switches to wholesale/dealer price when the customer is chosen after the items. |
| 31 | Unable to confirm sale | Not reproducible | Cash, UPI, credit and walk-in sales all confirm. |
| 33, 42 | Inactive rental products / suppliers listed | **Fixed** | Hidden in PO, rental, advance order, backorder, production pickers. |
| 38 | GST as text box | **Fixed** | Type any rate; usual slabs suggested. |
| 39 | Cannot edit stock on hand | By design | Stock changes only by documents; the form now links to Stock count. |
| 40 | Batch/barcode after adding product | **Fixed** | Opening stock gets its own batch + barcode (OPEN-SKU) at once and follows opening-stock edits. |
| 41 | Unwanted "blocked" option | **Fixed** | Removed from suppliers (it behaved like Inactive). |
| 44, 46 | Payment section confusing | **Improved** | Plain words ("Purchase bill" not "GRN"), what Record payment vs Apply advance means, confirm before voiding. New purchase bills are numbered **GRN-…** — they used PO-… like purchase orders, so two documents could share a number. |
| 45 | No need for location | Already done | Location pickers were removed from daily forms. |
| 47, 55 | Stock count not reflected in batches | **Fixed** | Shortage comes off the oldest batches, excess goes on the newest; the count sheet shows "Batches: B-001 −4, B-002 −1" and each stock movement names its batch. |
| 52 | Removed users have no view page | **Fixed** | Users → **Removed** tab (HO only), view page, and **Restore** to a company. |
| 53 | Different price per batch | **Needs your decision** | Today one selling price per product. Option: optional selling price per batch, used when that barcode is scanned. |
| 54 | Stock value uses product cost | **Fixed** | Stock value = each batch × what it actually cost (landed); stock outside batches × product cost. |
| 56 | Product cost becomes latest purchase cost | **Needs your decision** | Keep "latest cost" or switch to weighted average. Stock value and profit no longer depend on it. |

Old data: products whose stock and batches already differ can be corrected with Inventory → Batches → **Generate opening barcodes** (adds a batch for the missing quantity).

## Loyalty "Add rule" form (rebuilt)

The form only sent name, type, ₹/points and tier, so **Product bonus** and **Category bonus** rules could not choose a product or category and never gave points. Now each type shows its own fields (product/category picker, bonus per piece, multiplier, minimum bill, max per bill, dates, priority, customer group), the list explains each rule in words, rules can be removed, and bonus points come **on top of** the normal points.

## 1. Calculation fixes

| Problem | Fix |
|---|---|
| Supplier credit days could not be saved/edited | Accepted, returned, editable; due date = bill date + credit days |
| Cancelled/returned sale took commission off twice (salesman + supervisor) | Totals count original + reversal rows |
| Part-returned then cancelled sale over-reversed commission | Only the open remainder is reversed |
| Daily target bonus could not be re-earned after a same-day return | Fixed |
| Promotion % / product-tier commission subtracted the discount twice | Base = taxable value |
| MySQL "Data too long" on promotion bonus / partial reversal (sale confirm or return failed) | `commission_transactions.source_id` → 191 chars (migration 000001) |
| Booking advance counted as paid but still usable again; extra cash receipt | Advance used up on sale confirm; returned on cancel |
| Rental settlement re-charged months already invoiced | Only un-invoiced rent charged |
| Freight always added to supplier balance | Setting "Freight/loading is paid to the supplier" (default ON); owed amount fixed per purchase (migration 000002) |
| Pooled case cancelled/returned outside the pool | Goes back through the unit pool |
| Customer credit limit never checked | Enforced on credit sale confirm (0 = no limit) |
| Return took back default-rate points, could exceed earned | Pro-rata of points actually earned, capped |
| Return confirmed straight after create missed credit reduction | Full sale reloaded on confirm |
| Profit/margin included GST; profit used today's cost | Before-GST revenue; cost at time of sale |
| GST reconciliation ignored purchase-return GST | Deducted from input tax |
| Batch quantities drifted from stock | Updated on returns, production, counts, rentals, pooled sales |

## 2. Crashes found in testing

| Screen / API | Cause | Fix |
|---|---|---|
| Reports → Production by product | ambiguous `company_id` / `status` columns | Qualified columns |
| Reports → Production batches | missing `ProductBatch::location()` | Relation added |
| 19 reports with "All companies" (sales comparisons, YTD, 12-month, GST, leaderboard, commission, margin, profit, price tier, rental ×3 + exports) | services rejected `null` company | Consolidated across companies |
| 7 Commission lists with "All companies" | unguarded company scope | Fixed |
| Commission statement (monthly / range / daily) with "All companies" | needs one company | Uses the open company |
| Any API link opened while logged out (e.g. PDF in new tab) | redirect to non-existent `login` route → HTTP 500 | Returns 401 JSON |

## 3. Missing requirements added (from the proposal)

| Proposal item | Added |
|---|---|
| Module 08 — Tally-compatible export | Reports → Cash/Bank book, GST, Debtors, Creditors → Export → "Export for Tally (XML)": ledgers + Sales, Purchase, Credit/Debit Note, Receipt, Payment vouchers |
| Module 09 — CBM for import orders | CBM / container-fill panel on New Purchase Order |
| Module 10 — customer notification | WhatsApp on booking (automatic) and "Tell customer stock is in / it's ready" buttons on Backorder and Advance Order screens; all logged |
| Milestone 1 — Finance role | Finance role (payments, receipts, books, reports; view-only on bills/stock) — migration 000003 + seeder |
| Milestone 2 — reorder alerts per location | "Low here" flag and count on Inventory → By location |
| (earlier request) Owner-only internal estimate report | Reports → Internal |

## 4. Ease of use

- Side menu regrouped (Main, Selling, Buying, Stock, Accounts, Setup) and now lists **Reports, Sales Returns, Advance Orders, Locations, Backups, Activity Log**, which were only reachable by typing the address.
- POS: the Disc. box is a **percentage** (it was labelled % but sent as rupees — 10 meant ₹10). Columns now say "Disc. %" / "Disc. ₹".
- Product picker: type, ↑/↓, **Enter** to pick — no mouse needed at the counter.
- Dashboard: figures no longer break mid-number; staff without report access no longer see "0 products · 0 suppliers".
- "No access" page explains what to do and links back to the dashboard.
- Transfer form explains "Save request" vs "Confirm transfer"; purchase form explains when freight is owed to the supplier.
- Advance order screen now shows a message when Cancel/Fulfil fails (it failed silently).

## Not changed (needs your decision or setup)

- Seed data creates 3 companies; the proposal says 4 — add the 4th in Companies.
- WhatsApp/SMS need WATI or Twilio keys in `.env`; without them messages are logged, not sent (the screen says so).
- The proposal names Inertia.js; the build is a React SPA + JSON API (works the same for users).
- Backups use MySQL commands; they were not tested here (test database was SQLite).
