# Pot & Leaf ERP — User Guide

*Updated 28 October 2026*

This guide shows every screen of Pot & Leaf, step by step, with a picture and a small example sum you can check on a calculator. You do not need to know accounting or computers: follow the numbered steps exactly as written. Read it from top to bottom the first time. Later, jump to the section you need, or type a word in the search box of **Help**.

## What's new — 28 October 2026

The changes since the last version of this guide. Every picture in the guide was taken again on the new screens.

| Area | What changed | Read more |
| --- | --- | --- |
| Stock transfers | You now **ask** another company for stock. Admin approves, that company dispatches, you receive. New barcodes are made when it arrives. | 6.2 |
| Batches | Column is now **Left in batch**, with "of 30 received · 11 gone out". Editing opening stock no longer adds to "received". Transfer batches say "Transfer TRF-…". | 3.5 |
| Page numbers | Every long list shows 25 rows a page with page buttons. Stock levels and some reports were hiding rows before. | Step 5 |
| Help menu | **Help & User Guide** in the menu and the **?** button at the top open this guide inside the app. | Step 6 |
| Dark mode | Tables, notices, pop-ups and barcodes are easy to read in dark mode. | Step 6 |
| Suppliers | Supplier page shows **You owe**, **Advance** and **Paid so far**, then payments and advance in separate lists. | 2.7 |
| Products | HSN code box removed from the product form. Product page shows **Category** and **Subcategory** separately. | 2.5 |
| Purchases | A purchase made from a purchase order keeps that order's supplier. A direct purchase picks from saved suppliers. | 3.3 |
| Reorder report | The PDF shows the supplier you picked on screen; a product with no supplier uses the one it was last bought from. | 3.1 |
| Backups | Old backups can be deleted (the last one is always kept). | 2.9 |
| Settings | Every setting has a one-line explanation under it. "Default reorder alert qty" now fills the reorder level of a new product left blank. Guide section rewritten with examples. | 2.4 |
| Stock count by batch | The count sheet has one row per batch (batch number and barcode). Count each batch; on approval each batch is set to its own count. | 6.4 |
| Selling by batch | On **New sale**, under the product, choose the **Batch** to sell from (or leave "oldest first"). A new purchase at a new price changes **only that batch's** cost, not the product. **Request shortage** shows only when an item is short of stock. | 3.3, 3.5, 4.1 |
| Batch prices | A sale's cost (for profit) now comes from the batch the pieces left, not the latest purchase price. Batches page shows **Cost / pc**. Reports' **This FY** button follows the financial year in Settings. | 3.5 |
| Supplier payments | Money paid on account clears the opening balance, then the oldest bills; bill balances now match what you owe, and you cannot pay more than you owe. | 3.7 |
| Purchase orders | A PO can be turned into a purchase only after **Mark sent**. The reorder report hides its PDF and PO buttons when nothing needs reordering. | 3.2 |
| Stock = batches | Stock on hand, Batches & Barcodes and the sale screen always show the same number. Stock that came back without a batch goes back on its batch, or into a new **ADJ** batch with its own barcode. | 3.5 |
| Plant rental | Rentals show **Rent per day/week/month**, **Total till expected end** and **Billed so far** (list, form and rental page). | 5.3 |
| Request access | Any user can ask for more permissions; an admin approves or rejects; a bell shows the answer. | Step 8, 2.2 |
| My commission | Salespeople see their own rate, earnings and payments. | 8 |
| Customers | "Opening balance" is now called **Outstanding** (what the customer owed you before you started the app). It goes down by itself when they pay it. The total they owe now is shown as **Owes you**. | 2.6, 4.3 |
| Suppliers | "Opening balance" is now called **Outstanding** (what you already owe from before). It **goes down by itself when you pay it**. Pick-lists in forms show only the chosen company's suppliers. | 2.7, 3.7 |
| Supplier payments | The payment box shows **How "You owe" is worked out**. Payment history has no Void/delete button. | 3.7 |
| Commission | Product offers and care reminders have **Change**. A new role changes the existing rate. You cannot pay more than was earned. | 8 |
| Journal | Shows every money entry by itself (receipts, payments, cash sales, commission) with both sides, plus your corrections. | 7.3 |
| Reports | Icon menu with a search box; a one-line note says what the open report shows; every report has **Export → Excel**; Approx. Profit export fixed; **This FY** button. | 10 |

## 1. Getting started

### Three golden rules

1. **Save first, Confirm later.** A saved bill is only a draft, like writing on paper. Nothing changes yet.
2. **Confirm makes it real.** Only when you press **Confirm** do stock, money and points change.
3. **Never delete a confirmed bill to fix a mistake.** Make a return instead. A draft can be cancelled freely.

### Words you will see

| Word | Plain meaning |
| --- | --- |
| Stock | How many pieces you have |
| Batch | One lot of stock that came in together, with its own barcode sticker |
| Outstanding / Dues | Money still owed — by a customer to you, or by you to a supplier |
| Advance | Money paid before the bill, kept aside to use later |
| GST | Government tax added on the bill |
| Taxable value | The amount before GST is added |
| Cost price | What one piece cost you, including transport |
| Credit days | How many days are given to pay a bill |
| Draft / Confirmed / Cancelled | Not final yet / final / undone |

### Step 1 — Sign in

1. Open the app in your browser.
2. Type your **Email** and **Password** (your manager gives these).
3. Click **Sign in**.

![Sign-in screen](img/01-login.jpg#1920x1200)

### Step 2 — The Dashboard

After signing in you see the **Dashboard**. It shows today's key numbers and shortcut buttons for daily jobs.

- **Top cards:** sales of the last 30 days, money customers owe you (receivables), money you owe suppliers (payables) and the value of your stock.
- **Quick actions:** New sale, Receive money, New purchase, Pay supplier, Stock levels, Add product.
- **Yellow strip:** how many products are at or below their reorder level. Click it to see them.
- **Recent sales:** the last bills made.

![Dashboard](img/02-dashboard.jpg#1920x1200)

### Step 3 — The menu on the left

The menu is grouped by job. You only see the items your role allows.

| Group | What is inside |
| --- | --- |
| Main | Dashboard, Reports |
| Selling | Sales / Billing, Sales Returns, Advance Orders, Backorders (Waiting), Plant Rental, Customers, Money from Customers, Loyalty Points, Commission |
| Buying | Purchases (Bills), Purchase Orders, Purchase Returns, Suppliers, Pay Suppliers |
| Stock | Stock Levels, Batches & Barcodes, Stock Transfers, Stock Count, Damage Entry, Bulk Split, Production |
| Accounts | Cash Book, Bank Book, Journal (Corrections) |
| Setup | Products, Categories & Units, Companies, Users, Roles & Permissions, Access Requests (admins), Settings, Backups, Activity Log |
| Help | Help & User Guide (this guide, inside the app), Request Access |

On a phone, tap the three lines (☰) at the top left to open the menu.

### Step 4 — Choose the company

If you look after more than one shop (company), each list has a **Company** box at the top. Choose **All Companies** to see everything, or one company to see only its records. When you add something new, the form asks **which company** it belongs to. Each company has exactly one location, so you never pick a location separately.

### Step 5 — Find things fast: the filter bar

Every list (sales, purchases, returns, orders, rentals, receipts, payments and more) has the same bar:

1. **Search box** — type a bill number, customer or supplier name.
2. **Date choice** — All dates, Today, Yesterday, Last 7 days, This month, Last month, or **Pick dates…** to type your own from–to dates.
3. **Status tabs** — All, Draft, Confirmed, Cancelled (names change by screen).
4. **Clear filters** — appears when a filter is on; click it to see everything again.
5. **Export** — downloads everything that matches your filters (all pages, not just the one on screen) as a CSV file that opens in Excel.

![Date filter on the Sales list](img/48-date-filter.jpg#1503x630)

**Page numbers.** Long lists show **25 rows at a time**. Under the list you see, for example, **26–50 of 59** and page buttons **1 2 3**. Click a number or the arrows to move. Searching or changing a filter goes back to page 1. Export still downloads every matching row, not only one page.

![Page numbers under a long list](img/57-pagination.jpg#1920x1200)

### Step 6 — Help and dark mode

**Help:** click **Help & User Guide** at the bottom of the menu, or the **?** button at the top right. This guide opens inside the app. Type a word in the search box (for example *transfer* or *GST*) to see only the chapters that mention it; the word is highlighted. Click any picture to see it bigger.

![Help & User Guide inside the app](img/58-help.jpg#1503x1091)

**Dark mode:** click the **moon** button at the top right (the **sun** switches back). The app remembers your choice on that computer. Tables, warning boxes, pop-ups and barcodes are all easy to read in dark mode; barcodes always keep a white background so they still scan.

![Dark mode](img/56-dark-mode.jpg#1920x1200)

### Step 7 — Your profile and password

Click your **initials** (top right) → **My profile**. Change your name, phone or email, and your password: type the **Current password**, then the **New password** twice, and **Save**. Use at least 8 letters and numbers. Never share your password — every bill shows who made it.

![My profile](img/59-profile.jpg#1503x1091)

### Step 8 — Ask for more access

If you open a page and see "You don't have access", or a button you need is missing, you can ask for it:

1. Click **Help → Request Access** in the menu (or the **Request access** link on the "no access" page — the permission you need is already ticked).
2. Tick what you need. Things you already have are greyed with a ✓.
3. Type **why** you need it (for example *I close the shop and need the day's sales report*).
4. Click **Send request**.
5. The admin answers. The **bell** at the top shows the answer. If approved, refresh the page — the new menu items appear.

![Request access](img/62-request-access.jpg#1503x1091)

Under **My requests** you see each request: *waiting*, *approved* (with what was given) or *rejected* (with the admin's note). You can **Withdraw** a request that is still waiting.

## 2. First-time setup

Do these once, in this order: company → users → categories and units → settings → products → customers → suppliers. Most of it is done by the owner or head office.

### 2.1 Companies (each shop)

**Setup → Companies** lists every shop. Each company keeps its own stock, bills and staff.

1. Click **Add company**.
2. Fill Name, Code (a short name like CHK-AGRO), Legal name, GST number, State and State code, Phone and Address.
3. Click **Save**. The app makes the company's one location by itself — you do not add a location.

![Companies list](img/03-companies.jpg#1503x1091)

**Deleting a company:** if the company already has products, bills, customers or any other records, the app refuses and lists what it holds. Make it **Inactive** with the switch instead. Only an empty company can be deleted.

### 2.2 Users and roles

**Setup → Users** — everyone who can log in.

1. Click **Add user**.
2. Type Full name, Email, Phone / WhatsApp and a password.
3. Choose the **Company** and the **Role in this company** (for example Cashier or Salesman).
4. Click **Save**. Give the person their email and password.

![Users list](img/04-users.jpg#1503x1091)

**Setup → Roles & Permissions** decides what each role can see and do. The number beside each role is how many permissions it has.

![Roles](img/05-roles.jpg#1503x1091)

| Role | Usually allowed to |
| --- | --- |
| Administrator | Everything |
| Manager | Run the shop: sales, purchases, stock, reports, approvals |
| Cashier | Make bills, take money, see customers |
| Salesman | Make bills; sees **only his own** customers and bills |
| Supervisor | Production work |
| Godown Staff | Stock work: receive, count, transfer, damage |
| Finance | Payments, receipts, cash and bank books, reports |

Two permissions control privacy: **See every customer** and **See every staff member's sales**. A person without them sees only the customers they added and the bills they made.

**Answering access requests (admins).** The bell shows how many requests are waiting. Open **Setup → Access Requests**:

1. Read who asked, for which company, and why.
2. Untick anything you do not want to give.
3. Type a note (optional) and click **Approve** — or **Reject**.

You can only give permissions you have yourself (head office can give anything). What you give is for that person in that company only; their role does not change.

![Access requests](img/63-access-requests.jpg#1503x1091)

The user's page (**Setup → Users → click the name**) lists these **Extra permissions** with who gave them and when. Click **Remove** to take one back.

![User page with role and extra permissions](img/70-user-detail.jpg#1503x1091)

### 2.3 Categories and units

**Setup → Categories & Units** has three tabs: **Categories** (Live Plants, Pots & Planters…), **Subcategories** and **Units** (Nos, kg, bag…). Click **New category** (or New unit) and type a name and a short code. Every product needs a category.

![Categories and units](img/06-masters.jpg#1503x1091)

### 2.4 Settings

**Setup → Settings** holds the rules the app uses in its sums and messages. Each company has its own settings, so **choose the company first** (box at the top). Every setting has a short line under it saying what it does. Change a value, then press **Save** in that box. Only that box is saved.

![Settings](img/07-settings.jpg#1503x1091)

**Appearance**

| Setting | What it does |
| --- | --- |
| Dark mode | Light or dark screens. Only for you, on this browser. The moon button at the top does the same. |

**Loyalty points**

| Setting | Standard | What it does |
| --- | --- | --- |
| Earn: ₹ spent per point | 100 | Spend this much to earn points. |
| Earn: points per unit | 1 | Points for each step above. |
| Redeem: ₹ value per point | 1 | What 1 point is worth when used on a bill. |
| Redeem cap (% of bill) | 50 | Most of a bill that points can pay. |

> **Example.** Bill ₹1,250. Points earned = 1,250 ÷ 100 = 12 (rounded down) × 1 = **12 points**. Next bill ₹600 and the customer has 400 points: points can pay at most 50% = ₹300, so **300 points** are used (₹300 off), 100 are left.
>
> These are the standard rate. A rule made on the **Loyalty Points** page (offer, customer tier) takes over for the bills it covers.

**Sales & discounts**

| Setting | Standard | What it does |
| --- | --- | --- |
| Discount ceiling (%) | 20 | Biggest discount on one bill line, as % of qty × rate. More is refused. 0 = no limit. Free (complimentary) bills are not checked. |
| Require HO approval to cancel confirmed sales | On | **On:** a confirmed bill is not cancelled straight away — staff click **Request cancellation**, head office approves. **Off:** anyone allowed to cancel does it directly. |

> **Example.** 5 pots × ₹200 = ₹1,000. Ceiling 20% → the discount on that line can be up to **₹200**. Typing ₹250 shows "Discount exceeds the 20% ceiling".

**Accounting**

| Setting | Standard | What it does |
| --- | --- | --- |
| Cash opening balance (₹) | 0 | Cash in hand on the day you started using the app. The **Cash Book** starts from this. |
| Bank opening balance (₹) | 0 | Bank balance on that day. The **Bank Book** starts from this. |
| Freight / loading on purchases is paid to the supplier | On | **On:** freight and loading typed on a purchase bill are added to what you owe the supplier. **Off:** you pay a transporter, so they are **not** added to the supplier balance. Either way they go into the product's cost. |

> **Example.** Purchase bill ₹17,376 including ₹1,200 transport. **On** → you owe the supplier ₹17,376. **Off** → you owe the supplier 17,376 − 1,200 = **₹16,176**; pay the ₹1,200 to the transporter from the Cash Book. The purchase page shows it as **Bill total → Freight paid to transporter → Owed to supplier**. The setting is fixed on a bill when it is **confirmed**; changing the setting later does not change bills already confirmed.
>
> Set the opening balances **once**, at the start. Changing them later changes every balance shown in the Cash / Bank Book.

**Inventory & company**

| Setting | Standard | What it does |
| --- | --- | --- |
| Default reorder alert qty | 10 | A new product with the **Reorder level** left blank gets this number. When stock falls to the reorder level, the product shows as **low stock** and appears in the Reorder report. |
| Daily expense (₹, for approx. profit) | 0 | Rough running cost per day (rent, salary, power). The **Approx. Profit** report takes off this × number of days. |
| WhatsApp invoice sharing | On | **On:** bills, order updates and commission messages can be sent on WhatsApp. **Off:** nothing goes out on WhatsApp for this company. |
| Allow company website / app API | Off | **On:** the company's own website or mobile app can show products and take orders, using a key from **Company website API** (further down the page). **Off:** every key is blocked. |

> **Example.** Daily expense ₹2,000. In a 30-day month with sales profit ₹90,000, Approx. Profit = 90,000 − (2,000 × 30) = **₹30,000**.

**Reports & EOD (end-of-day summary)**

| Setting | Standard | What it does |
| --- | --- | --- |
| Financial year start month (1–12) | 4 | Month the accounting year starts. 4 = April. The **This FY** button in Reports and the leaderboard's "Financial year" start from this month. |
| Dead stock threshold (days) | 180 | Stock with nothing sold or sent out for this many days is marked **dead** in Stock Levels → Fast / slow / dead. |
| Send HO management EOD summary | Off | **On:** every day a summary is sent — sales, cash and bank collected, stock value, low stock, top staff. |
| EOD WhatsApp phones | — | Numbers that get the summary, separated by commas: `9876543210, 9123456789` |
| EOD email recipients | — | Emails that get the summary, separated by commas. |
| EOD send time (HH:MM, 24h) | 20:30 | When to send. 20:30 = 8:30 pm. |

**Rental automation**

| Setting | Standard | What it does |
| --- | --- | --- |
| Auto-generate invoices on billing cycle | On | **On:** every morning a rent bill is made by itself for each rental whose cycle has ended (if the rental has "Generate invoices automatically" ticked). **Off:** make each rent bill by hand. |
| WhatsApp when auto-billing | On | The customer gets the auto-made rent bill on WhatsApp. |
| SMS rental reminders & overdue alerts | Off | Reminders and overdue alerts also go by SMS (needs an SMS provider set up on the server). |
| Payment due days after period end | 7 | Days the customer gets to pay a rent bill. |
| Overdue alert grace (days) | 0 | Extra days to wait after the due / return date before an overdue alert. 0 = alert on the due date. |
| Reminder lead time (days before due) | 3 | A reminder goes this many days before the payment due or return date. |

> **Example.** Monthly rental, period 1–31 October. Due days 7 → the bill is due **7 November**. Reminder lead 3 → reminders from **4 November** (once a day until it is due). Grace 2 → if still unpaid, overdue alert from **9 November**.

**Company website API** (bottom of the page) — make a key for the company's website or app: type a name, **Generate key**, copy it at once (it is shown only once). **Disable** stops a key; **Regenerate** gives a new one and stops the old one. Works only when "Allow company website / app API" is **On**.

### 2.5 Products

**Setup → Products** shows every item with its price and stock. Use the search box, **All categories**, **All status** or tick **Reorder level** to see only items running low.

![Products list](img/08-products.jpg#1503x1091)

To add one:

1. Click **New product**.
2. Choose the **Company**.
3. Type **Name**, choose **Category** (and Subcategory, Unit) and **GST %**. The SKU (item code) is made automatically.
4. Under **Pricing**, type **Cost price**, **MRP** and the three selling prices: **Retail**, **Wholesale** and **Dealer**.
5. **Reorder level** (warn me when stock falls to this) is filled with the company default from Settings (for example 10); change it if you like, or leave it. Add photos if you want.
6. Click **Save**.

![New product form](img/09-product-new.jpg#1503x1091)

There is no HSN code box any more. Codes saved earlier are kept and still print on bills.

**Product page:** click a product to open it. It shows **Category** (the main one, e.g. Live Plants) and **Subcategory** (e.g. Palms) separately, and the heading reads "PLT-ROSE · Live Plants › Palms". Choosing a main category in the products list also shows products saved under its subcategories.

![Product page with category and subcategory](img/54-product-detail.jpg#1503x1091)

**Price helper — "Suggest from cost + margin":** type a margin % and click **Apply**. Retail price = cost × (1 + margin ÷ 100).

> Example: cost ₹200, margin 40% → 200 × 1.40 = **₹280** retail. If MRP is empty, it is also set to ₹280.

**Barcodes:** you do not type a barcode on the product. Each purchase lot (batch) gets its own barcode when the purchase is confirmed (see section 3). Print them from **Batches & Barcodes** or **Products → Labels**.

### 2.6 Customers

**Selling → Customers.** Click **New customer**, choose the company and fill:

- **Name** and **Type**: Retail, Wholesale or Dealer. The type decides which price the bill uses.
- Phone, WhatsApp, Email, GST number, address.
- **Credit days** — days the customer gets to pay (0 = cash only).
- **Credit limit** — the most they may owe you (0 = no limit).
- **Outstanding** — what the customer already owed you **before you started using this app** (old credit bills written in a book or another software). Leave it 0 for a new customer.

**Why this field is needed.** Bills made in this app are counted automatically. Old unpaid bills are not in the app, so without this number the app would think the customer owes nothing: the **Owes you** total, the credit-limit check, the Customer Account report and dues reminders would all be wrong. Type the old amount once, and from then on it is part of what the customer owes.

The **Outstanding** box always shows what is **still unpaid** of that old amount. When the customer pays it (section 4.3, **On account / outstanding from before**), it goes down by itself; the edit form shows the working, for example *Entered ₹5,000 − received ₹2,000 = ₹3,000 still to receive*. On the customer page, **Owes you now (all bills)** is the full amount (old outstanding + unpaid bills in the app), and **Outstanding (from before)** is just the old part.

> Example: Ravi owed ₹5,000 on old bills. Type 5,000 in Outstanding. He buys ₹2,000 on credit in the app → **Owes you ₹7,000**. He pays ₹2,000 "On account / outstanding from before" → Outstanding (from before) **₹3,000**, Owes you **₹5,000** (₹3,000 old + ₹2,000 bill).

![New customer](img/11-customer-new.jpg#1920x1200)

> Example: credit limit ₹10,000, already owes ₹8,500. A new credit bill of ₹2,000 would make it ₹10,500, so the app **refuses** the bill and says: collect payment or raise the limit.

![Customers list](img/12-customers.jpg#1503x1091)

### 2.7 Suppliers

**Buying → Suppliers.** Click **New supplier** and fill Name, **Address** (required), Phone, GST and PAN, **Credit days** (days you get to pay them), Credit limit, **Outstanding** (what you already owe them from before you started using the app — bills not entered here) and bank details.

The **Outstanding** box always shows what is **still unpaid** of that old amount. When you pay it (section 3.7), it goes down by itself; the edit form then shows the working, for example *Entered ₹10,000 − paid ₹3,000 = ₹7,000 still to pay*. If the number is wrong, type the correct amount still to pay and save — what was already paid stays recorded.

![Outstanding goes down as it is paid](img/67-supplier-outstanding.jpg#1366x900)

![New supplier](img/13-supplier-new.jpg#1920x1200)

![Suppliers list](img/14-suppliers.jpg#1503x1091)

**Supplier page.** Click a supplier's name to open it. Three boxes at the top answer the main questions:

| Box | Means | Example |
| --- | --- | --- |
| You owe this supplier | Outstanding from before + unpaid bills | ₹8,392.00 |
| Advance with this supplier | Money paid ahead, to use on future bills | ₹300.00 |
| Paid so far | All money you paid them | ₹3,000.00 (2 payments) |

![Supplier page — the three boxes](img/53-supplier-account.jpg#1503x1091)

Below them are two separate lists:

- **Payments made** — each payment with date, mode and reference, and what it was for: *Paid bill PO-000001 · ₹2,500*, *Paid as advance · ₹500*, *Outstanding from before · ₹500*, or *Extra kept as advance*.
- **Advance** — where advance came from and which bills it was used on, with **Left ₹…** after each line. The last "Left" always equals the Advance box.

> Example: you pay ₹500 as advance (Left ₹500), then use ₹200 of it on bill PO-000001 → Left ₹500 − ₹200 = **₹300**, the same as the Advance box.

![Payments made and Advance lists](img/53b-supplier-payments.jpg#1503x1091)

Use **Pay supplier** or **Use advance on a bill** to go to Pay Suppliers.

**Every supplier list in a form shows only the suppliers of the company chosen in that form.** If a supplier is missing, check the **Company** box at the top of the form.

### 2.8 Add many at once — Import from Excel

Products, Customers and Suppliers each have an **Import** button (only for people allowed to add them).

1. Click **Import**, choose the company and click **Download template**.
2. Open the template in Excel. Fill **one row per item**. Required columns: Products — Name, Category, Cost price; Customers — Name; Suppliers — Name, Address.
3. In Excel: **File → Save As → CSV UTF-8 (Comma delimited)**.
4. Click **Browse** and choose the file.
5. Click **Check N rows**. Nothing is saved yet. The app tests every row with the same rules as the Add form and lists problems by row number.
6. Click **Import N good rows**. Rows with problems are skipped; fix them and import them later.

![Import window](img/15-import.jpg#1920x1200)

Good to know:

- A category or unit typed in the file that does not exist yet is created for you. The Check step lists them first.
- **Opening stock** in the product file creates the stock batch and barcode, the same as adding by hand.
- A name already in the app, or repeated in the same file, is refused.
- Up to 2,000 rows per file.
- **Export** gives a file with the same columns, so you can edit it and import it into another company.

### 2.9 Backups

**Setup → Backups** keeps saved copies of all your data. Click **Backup Now** before big changes. Each copy has buttons to **download**, **restore** and **delete** (bin). Deleting a copy only removes that saved file; your current data is not touched. The last copy cannot be deleted, so there is always one to restore from.

![Backups](img/55-backups.jpg#1503x1091)

### 2.10 Activity monitoring and log

**Setup → Activity Log** shows, for the company, today's sales, today's production, transfers on the way, approvals waiting for head office (for example stock counts) and recent staff logins. Use it each morning to see what is waiting.

![Activity monitoring](img/61-activity.jpg#1503x1091)

## 3. Buying stock

The buying path is: (optional) Purchase order → Purchase bill → Confirm (stock comes in, barcodes are made) → Pay the supplier. If something is wrong, make a Purchase return.

### 3.1 What should I buy? — Reorder report

**Buying → Purchase Orders → Reorder report.** Choose the company. The list shows items at or below their reorder level, with a suggested quantity and the supplier to buy from.

![Reorder report](img/18-reorder.jpg#1503x1091)

**Supplier on each line:** a product with no supplier saved uses the supplier it was **last bought from** (shown as "From the last purchase of this product"). Only products never bought stay "Supplier not assigned". **Export PDF** and **Supplier-wise PDF** print exactly what is on screen — the supplier you chose, the order qty and the Include tick. When nothing needs reordering, these buttons are hidden.

**Suggested quantity = (2 × reorder level) − stock in hand − quantity already on order.**

> Example: reorder level 20, stock 8, nothing on order → (2 × 20) − 8 − 0 = **order 32**. If 10 are already on an open order → 40 − 8 − 10 = order 22.

### 3.2 Purchase order (optional)

A purchase order is the list you send to the supplier before goods arrive. It does not change stock or money.

1. **Buying → Purchase Orders → New PO.**
2. Choose the company and supplier, add items, quantities and rates.
3. Save. Status is **Draft**. Send the PDF to the supplier, then click **Mark sent**.
4. When the goods arrive, open the order and click **Convert to purchase**. The purchase bill is filled in for you. A **draft** order cannot be converted — it must be **sent** first.
5. If the supplier cannot deliver, click **Cancel**.

![A sent purchase order, ready to convert](img/67-po-sent.jpg#1503x1091)

![Purchase orders list](img/16-po-list.jpg#1503x1091)

### 3.3 Purchase bill — step by step

1. **Buying → Purchases (Bills) → New purchase.**
2. Choose **Purchasing for company**, the **Supplier** and the **Purchase date**. Type the supplier's **Invoice no.** and invoice date.
3. Tick **Inter-state supply** only if the supplier is in another state (then IGST is used instead of CGST + SGST).
4. For each item: Product, **Qty**, **Rate**, **Disc. ₹** (discount in rupees for the whole line) and **GST %**. Click **Add line** for more.
5. Type transport, loading or unloading in **Landed cost**. The line under it says whether this is added to what you owe the supplier or paid to a transporter (Settings).
6. Click **Save**. Check the totals against the supplier's paper bill.
7. When the goods are counted and correct, click **Confirm**.

**Which supplier?** A purchase made from a purchase order (**Convert to purchase**) keeps that order's supplier — it shows as fixed, "From purchase order PO-…". A direct purchase lets you pick only from suppliers already saved (type to search). Add a new supplier first under **Buying → Suppliers**.

![New purchase](img/20-purchase-new.jpg#1503x1091)

**What happens when you click Confirm**

- Stock goes up.
- Each line becomes a **batch** with its own barcode (for example PO-000001-01).
- The new cost (including its share of transport) is stored on **that batch only**. The product's own cost is not changed (it is filled in only if the product had no cost yet), and the selling prices are never changed by a purchase.
- The amount you owe the supplier goes up.

A confirmed purchase cannot be cancelled. If goods go back, make a **Purchase return** (3.6).

### 3.4 Purchase sums, worked out

Example bill: 50 pots at ₹120 with ₹300 discount (GST 18%) and 20 bags of potting mix at ₹450 (GST 5%). Transport ₹1,200. Same state.

| Step | Pots | Potting mix |
| --- | --- | --- |
| Qty × rate | 50 × 120 = 6,000 | 20 × 450 = 9,000 |
| Minus discount = taxable value | 6,000 − 300 = 5,700 | 9,000 − 0 = 9,000 |
| GST | 5,700 × 18% = 1,026 | 9,000 × 5% = 450 |
| Split into CGST + SGST (half each) | 513 + 513 | 225 + 225 |
| Share of ₹1,200 transport (by value) | 1,200 × 5,700 ÷ 14,700 = 465.31 | 1,200 − 465.31 = 734.69 |
| New cost of one piece (GST not included) | (5,700 + 465.31) ÷ 50 = **₹123.31** | (9,000 + 734.69) ÷ 20 = **₹486.73** |

**Bill total = 5,700 + 9,000 (items) + 1,476 (GST) + 1,200 (transport) = ₹17,376.**

The formulas in words:

- Taxable value = qty × rate − discount.
- GST = taxable value × GST %. Same state: half CGST + half SGST. Other state: all IGST.
- Transport share of a line = transport × line taxable value ÷ total taxable value of the bill.
- Cost of one piece = (taxable value + transport share) ÷ qty.
- GST is not in the piece cost, because you claim it back as input credit.
- **Owed to supplier** = bill total (₹17,376). If Settings says transport is paid to someone else, the transport is left out: 17,376 − 1,200 = ₹16,176.

![Purchase detail with batch barcodes](img/21-purchase-detail.jpg#1503x1091)

### 3.5 Batches and barcode labels

**Stock → Batches & Barcodes** shows every lot with how many are left. Click **Print all**, or use **Setup → Products → Labels** to choose how many stickers to print for each batch (**Match stock** prints one per piece).

![Batches and barcodes](img/23-batches.jpg#1503x1091)

![Barcode labels](img/24-labels.jpg#1503x1091)

**How "Left in batch" is worked out.** Every sale, transfer out or damage takes pieces from the **oldest batch first**. A batch that has been used shows "of N received · M gone out" under its number.

| Batch | Received | Gone out | Left in batch |
| --- | --- | --- | --- |
| OPEN-PLT-ARECA1 (opening stock, older) | 30 | 11 | **19** |
| GRN-000006-01 (purchase, newer) | 200 | 0 | **200** |
| Total | 230 | 11 | **219** |

The total left (219) always equals the product's **On hand** in Stock Levels.

**Source column:** the purchase number (GRN-… / PO-…), **Opening** (stock typed when the product was added), **Production**, **Split BS-…**, **Transfer TRF-…** for stock received from another company, or **ADJ-…** for stock that came back without a batch.

**Opening stock edits:** changing a product's opening stock (for example 150 → 0 → 150) corrects the opening batch; it does not add to "received". The batch shows **150 of 150**, not 150 of 300.

**Same product, different prices in different batches.** Each batch keeps what one piece **cost** when it came in (purchase rate + its share of freight). The **selling price** belongs to the product — MRP, Retail, Wholesale and Dealer prices on the product page — so a customer pays the same for an Areca Palm whichever batch it comes from. When you sell:

| On the bill | Which batch the pieces leave | Cost used for profit |
| --- | --- | --- |
| Barcode of a batch scanned, or the batch chosen in **Batch** | That batch | That batch's cost |
| Product picked, **Batch** left as "oldest first (automatic)" | Oldest batch first | Each batch's own cost, mixed by quantity |

> **Example.** Areca Palm: old batch 3 left at ₹100, new batch 5 at ₹150. Sell 4 by name → 3 from the old batch + 1 from the new. Cost = (3 × 100 + 1 × 150) ÷ 4 = **₹112.50 each**, ₹450 in all. Price on the bill is still the product's ₹300 each. The Approx. Profit and Margin reports use ₹450, not 4 × ₹150.
>
> **Cost / pc** on Batches & Barcodes shows each batch's cost (only to people allowed to see cost). To sell old stock at a different price, change the price on the bill line, or change the product's price.

### 3.6 Sending goods back — Purchase return

1. **Buying → Purchase Returns → New.**
2. Choose the company and the **Original purchase** (only confirmed ones are listed).
3. For each item, type the quantity going back and pick the batch (or the damage entry, for broken goods). Type a reason.
4. **Save**, then **Confirm**.

![New purchase return](img/25-purchase-return-new.jpg#1503x1091)

Rate, discount and GST come from the original bill — you only type the quantity. You cannot return more than you bought. The discount is shared by quantity. A confirmed return cannot be cancelled.

> Example: 5 of the 50 pots go back. 5 × 120 = 600. Discount share = 300 × 5 ÷ 50 = 30. Taxable = 570. GST 18% = 102.60. **You now owe ₹672.60 less.**

On Confirm, stock goes down (broken goods already left stock when the damage was entered). If you owed the supplier nothing, the amount is kept as an **advance** with that supplier.

### 3.7 Paying a supplier

**Buying → Pay Suppliers.** The **Payables** tab lists every unpaid bill with what was paid, the balance, the **Due date** and status (Unpaid, Partial, Paid).

**Due date = purchase date + supplier's credit days.**

> Example: credit days 30, bill dated 1 October → due 31 October. On 15 November it is 15 days late.

![Supplier payments](img/26-payments.jpg#1503x1091)

Three ways to pay:

1. **Pay** (on a bill row) — pays that bill. The balance goes down.
2. **Record payment** — pay against one or more bills, or as an **advance** (money paid before a bill). Choose mode: cash, bank, UPI or cheque.
3. **Apply advance** — use money already paid in advance to settle a bill. No new money goes out.

You cannot pay more against a bill than is still due on it; any extra becomes advance. Each payment is written into the Cash Book or Bank Book by itself.

> Example with the ₹17,376 bill:
>
> | What happened | Owed | Advance |
> | --- | --- | --- |
> | Bill confirmed | ₹17,376.00 | ₹0 |
> | Paid ₹10,000: ₹8,000 to the bill, ₹2,000 extra | ₹9,376.00 | ₹2,000 |
> | Sent 5 pots back (₹672.60) | ₹8,703.40 | ₹2,000 |
> | Applied the ₹2,000 advance | ₹6,703.40 | ₹0 |

Payment history is read-only: there is no Void or delete button, so a payment once recorded stays on record.

**How "You owe" is worked out.** When you pick a supplier in **Record payment**, a box shows the working:

| Line | Example |
| --- | --- |
| + Outstanding from before (supplier page) | ₹500.00 |
| + Purchase bills (1) | ₹9,850.00 |
| − Paid to the supplier | ₹2,000.00 |
| **= You owe** | **₹8,350.00** |

Advance used on bills and goods returned appear as extra − lines only when there are any. Pick a bill and its own working shows under it: *Bill ₹9,850 − paid ₹1,500 = ₹8,350 left*.

![Record payment with the working](img/65-payment-owed.jpg#1366x900)

**Paying "On account / outstanding from before".** Money paid without picking a bill clears the supplier's **outstanding from before first**, then the **oldest bills**. So the bills never show more unpaid than you really owe, and a payment can never be more than you owe (pay extra only as an advance).

> **Example.** Outstanding from before ₹500, bill GRN-000001 ₹9,850, two payments on account of ₹1,000. You owe 500 + 9,850 − 2,000 = **₹8,350**. The first ₹500 clears the outstanding from before; the other ₹1,500 goes on the bill → bill shows paid ₹1,500, balance **₹8,350**. The supplier page shows: PAY-000001 = outstanding from before ₹500 + bill ₹500; PAY-000002 = bill ₹1,000.

> **Example — the Outstanding box goes down.** Outstanding from before ₹10,000, one bill ₹5,000. Pay ₹3,000 with "On account / outstanding from before". You owe 10,000 + 5,000 − 3,000 = **₹12,000**. The payment box and the supplier's **Outstanding** now show 10,000 − 3,000 = **₹7,000 still to pay**; the bill still shows ₹5,000. Pay ₹8,000 more on account → ₹7,000 clears the old outstanding (now **₹0**) and ₹1,000 goes on the bill (₹4,000 left).

## 4. Selling

The selling path is: New sale → Save → Confirm (stock goes out, money and points are recorded) → if it was on credit, collect the money later. If goods come back, make a Sales return.

### 4.1 Make a bill — step by step

1. **Selling → Sales / Billing → New sale.**
2. Choose **Billing for company**.
3. Choose the **Customer**, or leave it as **Walk-in** for a counter customer.
4. Choose the **Bill type**: **Tax invoice** (normal bill), **Proforma** (a quotation — nothing moves) or **Complimentary** (free gift).
5. Choose **Payment mode**: Cash, Card, UPI or Credit (pay later).
6. Add items: scan the batch barcode in **Scan barcode**, or pick the product. Under the product, the **Batch** box lists its batches with how many are left (and the cost of one, if you may see cost). Leave it on **oldest first (automatic)**, or choose the batch you are selling from. Type the **Qty**. The **Rate** fills in from the price tier. Type a **Disc. %** if you give a discount.

![Choosing the batch on a sale line](img/68-sale-batch.jpg#1500x900)

> Example: Money Plant has batch GRN-000001-01 (50 left, ₹45 each) and GRN-000004-01 (40 left, ₹52 each). Choose GRN-000004-01 and sell 10 → that batch goes to 30, the bill uses the Money Plant retail price, and profit uses ₹52 each. If you type more than the batch has, the line says *Only 40 in batch GRN-000004-01*.

**Request shortage** (on a saved draft bill) appears only when an item needs more than is in stock; the line shows *Short by …*. It makes a waiting order (backorder) for the missing pieces. When all items are in stock, the button is hidden.
7. If the customer wants to use loyalty points, type the points.
8. Type **Amount paid** (leave blank if they pay the full bill now).
9. Click **Save**, check the totals, then click **Confirm**.

![New sale](img/28-sale-new.jpg#1503x1091)

**Which price is used? (price tier)**

| Customer type | Rate used |
| --- | --- |
| Retail or Walk-in | Retail price |
| Wholesale | Wholesale price |
| Dealer | Dealer price |

For a Retail customer the tier box is locked to Retail, so a cheaper price cannot be chosen by mistake.

**Discount limit:** the discount on one line cannot be more than 20% of qty × rate (changeable in Settings). Free (Complimentary) bills have no limit.

### 4.2 Bill sums, worked out

| Item | Qty × rate | Disc. % | Discount ₹ | Taxable | GST | Line total |
| --- | --- | --- | --- | --- | --- | --- |
| Ceramic pot | 4 × 250 = 1,000 | 10% | 100 | 900 | 18% = 162.00 | 1,062.00 |
| Areca palm, medium | 2 × 180 = 360 | 0 | 0 | 360 | 0% = 0 | 360.00 |
| Potting mix | 1 × 650 = 650 | 0 | 0 | 650 | 5% = 32.50 | 682.50 |
| **Total** |  |  |  | **1,910** | **194.50** | **2,104.50** |

1. Discount ₹ = qty × rate × Disc. % ÷ 100 → 1,000 × 10% = ₹100.
2. Taxable = qty × rate − discount.
3. GST = taxable × GST %. Same state: half CGST, half SGST (162 = 81 + 81). Other state (tick Inter-state): all IGST.
4. **Round off:** the bill is rounded to the nearest rupee. 2,104.50 → **₹2,105** (round off +₹0.50).
5. **Points:** the customer uses 300 points = ₹300 off → to pay **₹1,805**.
6. **Credit:** they pay ₹1,000 now → they still owe **₹805**.
7. **New points earned** on what they actually pay: 1,805 ÷ 100 = 18.05 → **18 points** (part points are dropped).

**Most points allowed on one bill** = 50% of the bill. Bill ₹2,105 → at most 1,052 points. Points cannot be used on a Walk-in bill.

![Sale detail](img/29-sale-detail.jpg#1503x1091)

**What happens when you click Confirm**

- Stock goes down (from the batch that was scanned or picked).
- Cash goes into the **Cash Book**; Card and UPI go into the **Bank Book**.
- On a credit bill, the unpaid part is added to what the customer owes. **Due date = bill date + the customer's credit days.**
- If the customer has a credit limit and this bill would take them over it, the app refuses to confirm.
- Points used are taken off and new points are added.
- The salesperson's commission is added (section 8).

From the bill you can **Print**, save a **PDF** or send it on **WhatsApp**.

**Cancelling a confirmed bill:** click **Request cancellation**. Head office approves it (Settings: "Cancel needs approval"). Cancelling puts back stock, money, points and commission.

![Sales list](img/27-sales.jpg#1503x1091)

### 4.3 Collecting money from customers

**Selling → Money from Customers.** The **Receivables** tab lists every unpaid bill with what was received, the balance and the **Due** date.

![Customer receipts](img/30-receipts.jpg#1503x1091)

1. Click **Collect** on a bill (or **Record receipt** for several bills, or **Record receipt** on the bill itself).
2. Type the amount and mode (cash, card, UPI, bank, cheque).
3. Save. What they owe goes down. Extra money becomes their **advance**.
4. **Apply advance** settles a bill from money they paid earlier.
5. Money for old dues (the customer's **Outstanding** from before): leave **Against invoice** as **On account / outstanding from before**. It reduces the old outstanding first. The form shows *Owes you* and *Outstanding from before still unpaid* when you pick the customer.

> Example: the customer owes ₹805. They pay ₹805 against that bill → they owe ₹0.

The **Receipt history** tab shows every receipt. Voiding a receipt undoes it, including the cash or bank entry.

### 4.4 Goods come back — Sales return

1. **Selling → Sales Returns → New.**
2. Choose the company and the **Original sale** (only confirmed bills).
3. Type how many of each item came back and a reason.
4. **Save draft**, then **Confirm**.

![New sales return](img/31-sales-return-new.jpg#1503x1091)

Rate, discount and GST come from the original bill. You cannot return more than was sold. The discount is shared by quantity.

> Example: 2 of the 4 pots from the bill above come back.
>
> | Step | Sum | Amount |
> | --- | --- | --- |
> | Value | 2 × 250 − (100 × 2 ÷ 4) discount | ₹450 |
> | GST 18% | 450 × 18% | ₹81 |
> | **Credit note** | 450 + 81 | **₹531** |
> | Customer now owes | 805 − 531 | ₹274 |
> | Points taken back | 531 ÷ 100 = 5.31 | 5 points |
> | Used points given back | 300 × 531 ÷ 2,105 = 75.68 | 75 points |

On Confirm the goods go back into stock and the salesperson's commission is reduced in the same proportion.

## 5. Bookings, waiting orders and plant rental

### 5.1 Advance order (a booking with advance money)

Use this when a customer books items for a later date and pays something now.

1. **Selling → Advance Orders → New.**
2. Choose company, **Customer**, **Order date** and **Expected date**.
3. Add the products, quantities and rates. The **Estimated total** is shown.
4. Type **Advance paid** and **Advance mode** (cash, UPI…). Click **Book order**.
5. When the goods are ready, open the order and click **Fulfil**. A bill is made, with the advance already counted as paid.

![New advance order](img/32-advance-new.jpg#1503x1091)

> Example: booking total ₹5,000, advance ₹2,000. At Fulfil the bill is ₹5,000 with ₹2,000 paid, so the customer owes **₹3,000**. If the booking is cancelled, the advance receipt is removed.

### 5.2 Backorders (Waiting)

A backorder is an item a customer wanted that was out of stock.

1. **Selling → Backorders (Waiting) → New backorder** — customer, items, quantity, expected date.
2. When stock arrives, open it and **Fulfil** — all at once or part by part.
3. Status moves Open → Partial → Fulfilled.

![Backorders](img/33-backorders.jpg#1503x1091)

> Example: customer wants 30, you give 20 today → status **Partial**, 10 still waiting. Give 10 next week → **Fulfilled**.

### 5.3 Plant rental — step by step

1. **Selling → Plant Rental → New rental.**
2. Choose company, **Customer**, **Billing cycle** (Daily, Weekly or Monthly = 30 days) and **Start date**.
3. Add the plants, **Qty** and **Rate / cycle** (rent for one plant for one cycle). Only products with **This product can be rented** ticked (on the product form, with a daily rate) appear in the list.
4. **Security deposit:** type it, or leave it empty — then deposit = full retail price of the plants.
5. Keep **Generate invoices automatically** ticked if a rent bill should be made each cycle by itself.
6. **Save draft**, then **Activate** when the plants leave. Stock goes down.
7. Each cycle a rent bill is made (or click **Generate invoice**). It is due 7 days after the cycle ends. Click **Mark paid** when paid. A rent bill cannot be deleted.
8. Plants can come back a few at a time with **Return**.
9. At the end click **Return & settle**. Mark each plant **good**, **damaged** or **missing**.

![New rental](img/34-rental-new.jpg#1503x1091)

![Rental detail](img/35-rental-detail.jpg#1503x1091)

**Rent at a glance.** The rentals list shows **Rent / cycle** and **Billed so far** for each rental. The rental page has a **Rent** box: rent per month (or day/week), **Total till expected end**, billed so far and the deposit. While making a rental, each plant line shows its total, and the bottom shows the rent per cycle and the total for the expected period.

> Example: 2 plants × ₹150 per month = **₹300 a month**. Start 1 October, expected end 29 November = 60 days = 2 months → **₹600 till expected end**.

![Rentals list with rent totals](img/66-rentals-list.jpg#1503x1091)

**Rental sums**

| Charge | How it is worked out |
| --- | --- |
| Cycles | Days rented ÷ days in a cycle, **rounded up** (any part of a cycle counts as a full cycle) |
| Rent | plants × rate per cycle × cycles |
| Rent still due at settle | total rent − rent bills already made |
| Damaged plant | half its retail price (or an amount you type) |
| Missing plant | full retail price |
| Refund | deposit − charges (if the deposit is bigger) |
| Balance to pay | charges − deposit (if the charges are bigger) |

> Example: 10 medium palms, monthly, rent ₹150 per plant per month, retail price ₹250.
>
> 1. Deposit (left empty) = 10 × 250 = **₹2,500**.
> 2. First month's rent bill = 10 × 150 = **₹1,500**.
> 3. Returned after 45 days: 8 good, 1 damaged, 1 missing. Cycles = 45 ÷ 30 = 1.5 → **2**.
> 4. Total rent = 10 × 150 × 2 = 3,000. Already billed 1,500 → rent still due **₹1,500**.
> 5. Damaged = 250 ÷ 2 = **₹125**. Missing = **₹250**.
> 6. Charges = 1,500 + 125 + 250 = **₹1,875**.
> 7. Deposit 2,500 − 1,875 = **refund ₹625** to the customer. The 8 good palms go back into stock.

## 6. Looking after stock

### 6.1 Stock levels

**Stock → Stock Levels** has four tabs:

| Tab | Shows |
| --- | --- |
| Stock levels | For each item: on hand, promised on open transfer requests, available, reorder level, cost of one, status (Low / OK) |
| Stock ledger | Every movement in and out, with date and reason |
| Valuation | What your stock is worth |
| Fast / slow / dead | Which items sell quickly, slowly, or not at all |

![Stock levels](img/22-stock.jpg#1503x1091)

The sums:

- **Available = on hand − quantity promised on open transfer requests** (already promised to another shop).
- **Low** = on hand at or below the reorder level.
- **Stock value = pieces in stock × cost of one.** Example: 23 pots × ₹123.31 = **₹2,836.13**.
- **Dead stock** = an item with no sale for 180 days (Settings). **Fast / slow** = selling more / less than the average item.
- **On hand = the total of its batches.** Stock Levels, Batches & Barcodes and the sale screen always show the same number. When stock comes back without a batch (a rental cancelled, a split undone, a stock count finding more), it goes back on the batch it came from, or into a new **ADJ-…** batch with its own barcode.

> Example: Mango Sapling — batch PO-000001 had 60, 10 went out (8 sold, 2 rented). The 2 rented plants come back → the batch goes from 50 back to **52**, the same as on hand.

### 6.2 Getting stock from another shop (Stock Transfers)

When you run short, you **ask** another company for stock. Four steps, each done by a different person:

| Step | Who | Button | What happens to stock |
| --- | --- | --- | --- |
| 1. Request | The shop that needs stock | **Send request** | Nothing moves yet. The pieces are kept aside at the other shop. |
| 2. Approve | Admin (head office) | **Approve** | Nothing moves yet. Admin can choose which company sends it. |
| 3. Dispatch | The shop that sends | **Dispatch** | Stock goes down at the sending shop. Status: On the way. |
| 4. Receive | The shop that asked | **Receive** | Stock goes up at your shop and a **new barcode batch** is made. |

**Step 1 — Request.** Go to **Stock → Stock Transfers → Request stock**. The list shows every **other** company's products and how many each can give (**Free stock**). Search a product, type how many you need in **I need**, click **Add**. Add as many as you like, then click **Send request**. Asking two companies makes two requests, one for each.

![Request stock — pick company, product and quantity](img/49-transfer-request.jpg#1920x1200)

> Free stock = what the shop has − what is already promised on other open requests. Example: 35 Money Plants, 5 already requested by another shop → you can ask for up to **30**.

**Step 2 — Approve.** The admin opens the request and clicks **Approve**. The window lists every company that has the product and whether it **Has enough**. Pick the company that should send it, check the quantity, click **Confirm approval**. Your own request cannot be approved by you; you can **Cancel** it until it is approved.

![Approve — choose which company sends the stock](img/50-transfer-approve.jpg#1920x1200)

**Step 3 — Dispatch.** The sending shop sees the request marked **We send** with status **To dispatch**. Pack the items and click **Dispatch**.

**Step 4 — Receive.** When the goods arrive, your shop opens the transfer (marked **Coming to us**, status **On the way**), clicks **Receive** and types how many actually arrived. The stock is added to the **same product by name** in your shop (a new product is made if you don't have it yet), and the new barcode shows under **New barcode**.

![A received transfer with its new barcode](img/51-transfer-received.jpg#1503x1091)

> Example: you asked for 10 pots. 9 arrive fine, 1 is broken → type 9. Your shop gets **+9** with a new barcode; **1** goes back to the sender.

![Stock transfers list — statuses and "We send" / "Coming to us"](img/52-transfers-list.jpg#1503x1091)

The status tabs read: **Waiting approval → To dispatch → On the way → Received** (or Rejected / Cancelled). The admin can still move stock directly with **Send stock directly** at the bottom of the Request stock page.

### 6.3 Splitting a big lot into sizes (Bulk Split)

Use this when you buy one big lot (for example 100 palms) and sell it as Small, Medium and Large.

1. **Stock → Bulk Split → New.**
2. Choose company, **Bulk product** and its **Batch**, and the quantity to split.
3. Choose the **Split method**: quantity per split, or number of splits. Click **Generate splits**, or type the rows yourself.
4. For each size type how many and a **weight** (how much costlier that size is; 1 = normal). **Markup** is 40% unless you change it.
5. **Save**, then **Confirm**. The lot's stock goes down; each size becomes its own product with its own barcode.

![New bulk split](img/37-bulk-split-new.jpg#1503x1091)

> Example: 100 palms bought at ₹80 = ₹8,000 in total.
>
> | Size | How many | Weight | Points (how many × weight) | Share of ₹8,000 | Cost of one | Suggested price (+40%) |
> | --- | --- | --- | --- | --- | --- | --- |
> | Small | 50 | 1 | 50 | ₹2,758.62 | ₹55.17 | ₹77.24 |
> | Medium | 30 | 1.5 | 45 | ₹2,482.76 | ₹82.76 | ₹115.86 |
> | Large | 20 | 2.5 | 50 | ₹2,758.62 | ₹137.93 | ₹193.10 |
> | Total | 100 |  | 145 | ₹8,000 |  |  |
>
> Share = 8,000 × that size's points ÷ 145. Cost of one = share ÷ how many. Suggested price = cost of one × 1.40. With weight 1 for all, every palm costs the same ₹80.

### 6.4 Stock count

Count the shelves now and then so the app matches reality.

1. **Stock → Stock Count → New.**
2. Choose company, **Count date** and the area counted.
3. The sheet has **one row per batch** (batch number, barcode label and date), oldest first; a product with no batches has one row. Count the pieces of each batch on the shelf (match the barcode label) and type the **Counted** number. The **Variance** (counted − system) shows at once. Type a batch number or barcode in the filter box to find its row.
4. Save, then **Submit**. A manager or head office approves it.
5. On approval, each batch is set to its own count, and the product's stock changes by the same total.

![Counting batch by batch](img/69-count-batches.jpg#1400x900)

![New stock count](img/38-stock-count-new.jpg#1503x1091)

> Example: the system says 33 pots, you count 32 → variance −1 → **1 pot is removed** on approval.
>
> Batch example: Money Plant batch GRN-000001-01 shows 30, batch GRN-000004-01 shows 20 (stock 50). You count 28 and 21 → variances −2 and +1. On approval GRN-000001-01 becomes **28**, GRN-000004-01 becomes **21**, and Money Plant stock becomes **49**.

### 6.5 Broken or dead plants (Damage Entry)

1. **Stock → Damage Entry → Record damage.**
2. Pick the product, batch, quantity and reason (broken, wilted…).
3. Save. Stock goes down at once. Damaged goods can later be sent back to the supplier with a purchase return.

![Damage entries](img/39-damage.jpg#1503x1091)

### 6.6 Making combos (Production)

Use this when you put items together to make a new product, like a palm planted in a pot.

1. Write the recipe once: what goes into **one** finished piece, and any waste % (for example 5% of soil is spilled).
2. **Stock → Production → Add new production**: choose the recipe, how many to make and the supervisor.
3. Check the material needed, what is short, and the cost.
4. Click **Complete**. Ingredients go out of stock; finished pieces come in with their own barcode. If anything is short, the app will not let you complete.

![Production](img/40-production.jpg#1503x1091)

> Example: make 10 "Potted Areca combos". Recipe for one: 1 pot, 1 medium palm, 0.2 bag of potting mix with 5% waste.
>
> | Ingredient | Needed for 10 | Cost of one | Cost |
> | --- | --- | --- | --- |
> | Pot | 10 | ₹123.31 | ₹1,233.10 |
> | Medium palm | 10 | ₹82.76 | ₹827.60 |
> | Potting mix | 0.2 × 10 = 2, plus 5% = 2.1 bags | ₹486.73 | ₹1,022.13 |
> | **Total** |  |  | **₹3,082.83** |
>
> Cost of one combo = 3,082.83 ÷ 10 = **₹308.28**.

## 7. Money books and dues

Bills, receipts and payments write themselves into these books. You only add entries for other money, such as rent, electricity or a bank deposit.

### 7.1 Cash Book

**Accounts → Cash Book.** Choose **From** and **To** dates. Four boxes show **Opening**, **Receipts** (money in), **Payments** (money out) and **Closing**.

**Closing cash = opening + money in − money out.**

> Example: opening ₹25,000 + cash sales and receipts ₹1,000 − supplier payment ₹10,000 = closing **₹16,000**. Count the drawer at day end; it should match.

To add other cash spending or income, click **Add entry**, choose the account (for example Electricity), type the amount and a note, and save.

![Cash Book](img/41-cash-book.jpg#1503x1091)

### 7.2 Bank Book

**Accounts → Bank Book** works the same way, for money through the bank: **UPI, card, cheque and transfers**. Walk-in sales paid by UPI or card come here automatically.

![Bank Book](img/42-bank-book.jpg#1503x1091)

Where each payment mode goes:

| Mode | Book |
| --- | --- |
| Cash | Cash Book |
| UPI, Card, Cheque, Bank transfer | Bank Book |
| Credit | No book yet — it becomes customer dues until collected |

The opening cash and bank balances are set in Settings.

### 7.3 Journal

**Accounts → Journal** lists **every money entry** in one place. You do not type most of them — they are made by themselves:

| When you… | The journal gets (both sides are always equal) |
| --- | --- |
| Take money from a customer (receipt) | Dr Cash / Bank — Cr Customer |
| Pay a supplier | Dr Supplier (Accounts payable) — Cr Cash / Bank |
| Make a cash (walk-in) sale | Dr Cash — Cr Sales |
| Pay commission | Dr Commission — Cr Cash / Bank |
| Add a Cash Book or Bank Book voucher | as you typed it |

Each row shows the date, voucher number, type, what it was for, and under it the two sides, for example *Dr Accounts Payable ₹1,000 · Cr Cash in Hand ₹1,000*. **auto** means it was made by itself. The **Debits** and **Credits** boxes at the top are always the same total.

![Journal](img/43-journal.jpg#1503x1091)

**Show → Only corrections I made** lists just the entries typed by hand.

**Add entry** is for a correction that does not involve cash or bank, for example moving an amount from one account to another. Total debits must equal total credits.

> Example: an expense of ₹500 was booked to Repairs but should be Transport. Add entry: Transport debit ₹500, Repairs credit ₹500. Both sides are ₹500.

Automatic entries cannot be edited here — fix the receipt or payment itself. If you are not sure, ask your accountant before adding a correction.

### 7.4 Who owes whom

Find these under **Reports**:

| Report | Answers |
| --- | --- |
| Customer Account (who owes you) | Every bill, receipt and return for a customer, with the running balance |
| Supplier Account (what you owe) | Same for a supplier |
| Customer dues by age | Unpaid customer bills, grouped by how late they are |
| Supplier dues by age | Unpaid supplier bills, grouped by how late they are |

**Days late = today − due date** (due date = bill date + credit days). The groups are:

| Days late | Group |
| --- | --- |
| Not yet due | Current |
| 1 to 30 | 1–30 days |
| 31 to 60 | 31–60 days |
| 61 to 90 | 61–90 days |
| 91 to 180 | 91–180 days |
| More than 180 | 180+ days |

> Example: three bills from one supplier with 30 credit days, checked on 15 November.
>
> | Bill date | Due date | Still to pay | Days late | Group |
> | --- | --- | --- | --- | --- |
> | 1 Nov | 1 Dec | ₹5,000 | 0 | Current |
> | 1 Oct | 31 Oct | ₹6,703.40 | 15 | 1–30 days |
> | 1 Aug | 31 Aug | ₹2,000 | 76 | 61–90 days |
>
> Pay the August bill first.

Credit days only set the due date. They do not block a bill, add interest or change any amount. The credit limit (section 2.6) is what stops new credit bills.

## 8. Staff commission

Commission is extra money staff earn on top of salary. You set the rate once; the app adds it up from every bill by itself; you pay it at month end. **Selling → Commission** shows these three steps across the top.

![Commission screen](img/44-commission.jpg#1503x1091)

### Step 1 — Set rates (once per person)

1. Click **Set staff rate**.
2. **Who?** Choose the company and the staff member. Choose what they do: **Sells to customers** or **Grows / makes items**. (People who already have a rate are not listed — use **Change** on their row.)
3. **How much?** Type how much they get from every ₹100 sold. Example: 2 means ₹2 for every ₹100. The window shows what they would get on an example month.
4. **Good-day bonus (optional):** "If they sell in one day ₹***, give extra ₹***".
5. Click **Save**.

![Set staff rate](img/45-commission-rate.jpg#1920x1200)

Under **More options** you can also set the **Highest commission in a month**, **Pause** the person, or (if the % is 0) a fixed bonus when the month's sales reach a target.

On each person's row: **Higher % slabs** (a bigger % after a sales level) and **Good-day bonus** (more day levels).

### Step 2 — See earnings (nothing to do)

Every confirmed bill adds the salesperson's commission by itself. A returned or cancelled bill takes it back by itself. Open this tab to check.

### Step 3 — Pay staff (month end)

1. Click **Pay commission**.
2. Choose the person and the month. The app shows the amount to pay.
3. Pay them, then click **Mark as paid** (leave the amount blank for the full amount). It is written into the Cash Book or Bank Book.

A person with no rate set cannot be paid commission. A month can be paid only once per person — to fix it, click **Change** on that payout.

**You cannot pay more than was earned.** If the month's commission is ₹1,820, the amount box accepts up to ₹1,820; typing ₹2,000 shows "More than they earned for this month" and **Mark as paid** stays off. Pay less if you like (for example ₹1,000 now) — the rest shows as still to be paid.

The staff lists in **Set staff rate** and **Pay commission** show only the people of the company chosen in that window.

**When someone's role changes** (for example Salesman → Supervisor in Setup → Users), their existing staff rate changes with it — it is not duplicated. Their % and targets stay; open **Change** on their row to adjust them.

### My commission (for staff)

A salesperson with **View own commission** sees **My Commission** in the menu (and a link on the Dashboard). It shows their rate in plain words, what they sold and earned in the month, how it was worked out, each bill that earned commission, and the payments they received.

![My commission](img/64-my-commission.jpg#1503x1091)

### How the money is worked out

**What counts as "sales":** the bill amount **before GST**, minus any points discount, minus returns. Proforma and free (Complimentary) bills count for nothing.

> Example bill from section 4.2: before GST ₹1,910, points discount ₹300 → commission counts **₹1,610**.

| Kind | Who gets it | How it is counted |
| --- | --- | --- |
| Sales commission | Salesperson | % of their month's sales |
| Higher % slabs | Salesperson | A higher % only on the part of the month's sales above a level |
| Good-day bonus | Salesperson | Fixed ₹ (plus % if set) once on any day their sales reach the level; each level reached pays |
| Monthly target bonus | Salesperson with 0% | Fixed ₹ when the month's sales reach the target |
| Product offer | Salesperson | Extra ₹ per piece, or % of price, for chosen products, for chosen dates |
| Manager share | Shop manager | % of the whole shop's sales minus returns |
| Production bonus | Person who grew / made items | ₹ per piece made, paid when the production is completed; plus ₹ per piece or % of price when that piece is sold or sent to another shop |
| Highest in a month | — | The sales % commission stops at this amount |

**Worked example — one salesperson, one month**

Rates: 2% on sales up to ₹25,000, 3% above ₹25,000; ₹250 extra on any day they sell ₹5,000 or more; product offer ₹10 for each Areca Palm sold.

The month: sales ₹40,000 (before GST, after returns); 3 days were ₹5,000 or more; 12 Areca Palms sold.

| Part | Sum | Amount |
| --- | --- | --- |
| 2% on the first ₹25,000 | 25,000 × 2% | ₹500 |
| 3% on the part above ₹25,000 | (40,000 − 25,000) × 3% | ₹450 |
| Good-day bonus | 3 days × ₹250 | ₹750 |
| Product offer | 12 × ₹10 | ₹120 |
| **Total to pay** |  | **₹1,820** |

If "Highest commission in a month" were ₹800, the first two lines (₹950) would stop at ₹800, so the total would be ₹1,670.

**Returns:** if a ₹2,000 bill (before GST) is returned, commission goes down by that bill's share — at 3% that is ₹60.

**Manager share example:** shop sales ₹6,40,000, returns ₹15,000, share 1% → (6,40,000 − 15,000) × 1% = **₹6,250**.

**Production bonus example:** ₹3 for each combo made and ₹5 for each combo sold. 50 combos made and 40 sold this month → 50 × 3 + 40 × 5 = 150 + 200 = **₹350**. If a completed production is cancelled, its making bonus is taken back.

### More tabs

- **Product offers** — give staff extra for selling a product faster. **Change** opens the offer to edit its dates, amounts or turn it **Off**.
- **Manager share** — only users with a Manager role can be chosen; use **Change** or **Remove** to edit.
- **Production bonus** — rates for people who grow or make items.
- **WhatsApp messages** — the end-of-day message staff receive with what they earned.
- **Care reminders** — plant care tips sent to customers after they buy. **Change** edits the message, days or turns it off.

![Changing a product offer](img/72-offer-change.jpg#1920x1200)

## 9. Loyalty points

Customers earn points on every confirmed bill and use them later as a discount. **Selling → Loyalty Points** shows the total points customers hold, the earn rules and each customer's balance.

![Loyalty points](img/46-loyalty.jpg#1503x1091)

### The standard rule

|  | Rule | Example |
| --- | --- | --- |
| Earn | 1 point for every full ₹100 the customer pays | Pays ₹1,805 → 1,805 ÷ 100 = 18.05 → **18 points** |
| Use | 1 point = ₹1 off | 300 points = ₹300 off |
| Limit | At most 50% of the bill can be paid with points | Bill ₹2,105 → at most **1,052 points** |
| Walk-in | Points cannot be earned or used without a customer | Pick the customer on the bill |

These numbers are changed in **Loyalty settings** (or Setup → Settings). Points are earned on what the customer actually pays — the bill total minus the points used.

### Using points on a bill

1. On **New sale**, pick the customer (not Walk-in).
2. Type the points to use. The app stops you if it is more than allowed.
3. **Confirm.** Points used are taken off first; new points are added on what was paid.

If the bill is cancelled, earned points are taken back and used points are given back. On a return, see the example in section 4.4.

### Add rule — special offers

Click **Add rule** to give extra or different points. Choose **What gives points?**:

| Rule type | Use it for | Fields |
| --- | --- | --- |
| Spend-based | Points on the bill amount | For every ₹ \_\_\_ give \_\_\_ points |
| Customer tier | A special rate for one customer group (e.g. Dealers) | Same, plus **Only for customer group** |
| Product bonus | Extra points for one product | Product, bonus points per piece |
| Category bonus | Extra points for a whole category | Category, by money spent or a fixed number per piece |

Optional fields on every rule: **Multiply by** (e.g. 2 for double points), **Max points per bill**, **Starts on / Ends on** (for a festival offer) and **Priority**.

**Only one Spend-based or Customer-tier rule counts on a bill** — the highest priority one that fits the customer. Product and category bonuses are added on top.

> Example: a Dealer customer pays ₹3,000 for a bill that includes 4 Areca Palms.
>
> - Rules: Standard (1 point per ₹100), Dealer tier (2 points per ₹100, priority 2), Areca bonus (5 points per piece).
> - The Dealer tier wins over Standard (higher priority and it fits): 3,000 ÷ 100 × 2 = **60 points**.
> - Areca bonus: 4 × 5 = **20 points**.
> - Total earned: **80 points**.

### Adjust points by hand

Click **Adjust points**, choose the customer, type the points (+ to add, − to remove) and a reason. Every change is listed in the customer's points history.

**Points expiry:** off by default (0 days). If set, points earned more than that many days ago run out.

## 10. Reports

**Main → Reports.**

1. Choose the **company** at the top (head office can choose All companies; some reports need one company).
2. Choose the period: **7D**, **30D**, **90D**, **This FY** (from the start of your financial year, see Settings) or **Custom** dates.
3. Click a report's icon. The line under the menu shows its full name and what it shows (point at an icon to see this before clicking). Type a word (profit, GST, rent…) in **Search reports** to find one quickly.
4. Click **Export** for a file:
   - **Excel — this report as shown** (every report): all rows of the report, not only the first page, plus the summary figures at the top. Opens in Excel.
   - **Export PDF / Export Excel** (some reports): a printable file made by the server.
   - **Export for Tally (XML)** (Cash Book and others): a file your accountant can load into Tally.

If the export says *Nothing to export*, choose one company at the top (and a customer or supplier where the report asks for one) or other dates.

![Reports](img/47-reports.jpg#1760x900)

![Export menu](img/73-report-export.jpg#1920x1200)

### Which report answers what

| I want to know… | Open |
| --- | --- |
| How the business is doing at a glance | Overview |
| Sales by day, product or category | Sales details |
| Profit on each product | Profit by product |
| Profit of the shop for the period | Approx. profit |
| My own working of profit (owner only) | Owner estimate |
| Sales at retail, wholesale and dealer price | Sales by price type |
| GST to file and pay | GST |
| Cash and bank in and out | Cash book, Bank book |
| A customer's or supplier's full account | Customer account, Supplier account |
| Late payments | Customer dues by age, Supplier dues by age |
| Fast, slow and dead stock | Fast / slow / dead stock |
| What was made, by whom | Production total, by item, by supervisor, batches |
| Transfers, and goods on the way | Stock transfers, Transfers on the way |
| What to buy now | What to buy |
| Rentals: sent out, rent earned, by staff, out now, one customer | Plant rental group |
| Which staff sold the most | Best staff |
| Commission earned and paid | Commission |
| Points earned and used | Loyalty points |
| Messages sent | WhatsApp sent |
| End-of-day summary for head office | End-of-day summary |

### The report sums

**GST Reconciliation**

**GST to pay = GST on sales − GST on sales returns − (GST paid on purchases − GST on purchase returns).**

> Example: GST on sales ₹18,000; on sales returns ₹500; on purchases ₹11,000; on purchase returns ₹300. GST to pay = 18,000 − 500 − (11,000 − 300) = **₹6,800**. If the answer is below zero, you have credit to carry forward.

**Approx. Profit**

**Profit = sales (before GST, minus points discount) − cost of the goods sold − daily expense × days.**

> Example: sales ₹1,00,000, cost of those goods ₹70,000, daily expense ₹500 for 30 days = ₹15,000. Profit = 1,00,000 − 70,000 − 15,000 = **₹15,000**.

Cost of goods sold = pieces sold × the cost of one from the batch they left (scanned batch, or oldest first — see 3.5). The daily expense is set in Settings.

**Profit & Margin** (per product)

- Profit = selling price (before GST) − cost.
- Margin % = profit ÷ selling price × 100.

> Example: sold at ₹280, cost ₹200 → profit ₹80 → margin = 80 ÷ 280 × 100 = **28.6%**.

### Internal Estimate (owner only)

Where: **Reports → Internal** tile ("Internal Estimate (owner only)"). Only the owner — the super admin or a user with full access — can see this tile. Other users do not see it at all.

It is your own working figure, **not GST**. For the real GST to file, use GST Reconciliation.

1. Choose the period and company.
2. Type **Share of (sales + profit) %** (standard 50) and **Estimate rate %** (standard 18).
3. Read the five boxes and the day-by-day table. Click **Print** if needed.

**Base = share % × (sales + profit). Estimate = base × rate %.** Sales are before GST; profit = sales − cost of goods.

> Example: sales ₹1,00,000, cost of goods ₹70,000 → profit ₹30,000. Base = 50% × (1,00,000 + 30,000) = ₹65,000. Estimate = 65,000 × 18% = **₹11,700**.

### Exporting lists

Every list (Sales, Purchases, Returns, Orders, Rentals, Receipts, Payments, Products, Customers, Suppliers and more) has an **Export** button. It downloads **everything that matches your search and date filter**, not just the page on screen, as a CSV file that opens in Excel.

> Example: on Sales choose **Last month** and type "City Garden" → Export gives every City Garden bill of last month.

## 11. Every calculation on one page

| What | Formula | Example |
| --- | --- | --- |
| Line discount (sale) | qty × rate × Disc. % ÷ 100 | 4 × 250 × 10% = ₹100 |
| Taxable value | qty × rate − discount | 1,000 − 100 = ₹900 |
| GST | taxable × GST % (same state: half CGST + half SGST) | 900 × 18% = ₹162 = 81 + 81 |
| Bill total | taxable + GST, rounded to the nearest rupee | 2,104.50 → ₹2,105 |
| Amount to pay | bill total − points used | 2,105 − 300 = ₹1,805 |
| Customer still owes | amount to pay − amount paid | 1,805 − 1,000 = ₹805 |
| Discount limit | 20% of qty × rate | 1,000 → most ₹200 |
| Due date | bill date + credit days | 1 Oct + 30 = 31 Oct |
| Credit limit check | owed now + new credit ≤ limit | 8,500 + 2,000 > 10,000 → refused |
| Points earned | (bill total − points used) ÷ 100, whole points only | 1,805 → 18 |
| Most points on a bill | 50% of the bill | 2,105 → 1,052 |
| Purchase transport share | transport × line taxable ÷ bill taxable | 1,200 × 5,700 ÷ 14,700 = 465.31 |
| Cost of one piece | (taxable + transport share) ÷ qty | (5,700 + 465.31) ÷ 50 = ₹123.31 |
| Owed to supplier | bill total (minus transport if paid to someone else) | ₹17,376 |
| Return discount share | original discount × qty returned ÷ qty bought | 300 × 5 ÷ 50 = ₹30 |
| Retail price helper | cost × (1 + margin ÷ 100) | 200 × 1.40 = ₹280 |
| Reorder quantity | 2 × reorder level − stock − on order | 40 − 8 − 0 = 32 |
| Available stock | on hand − promised on open transfer requests | 20 − 5 = 15 |
| Stock value | stock × cost of one | 23 × 123.31 = ₹2,836.13 |
| Bulk split share | total cost × (qty × weight) ÷ total points | 8,000 × 45 ÷ 145 = ₹2,482.76 |
| Split suggested price | cost of one × 1.40 | 82.76 × 1.40 = ₹115.86 |
| Rental cycles | days ÷ cycle days, rounded up | 45 ÷ 30 → 2 |
| Rent | plants × rate × cycles | 10 × 150 × 2 = ₹3,000 |
| Rental damaged / missing | half / full retail price | ₹125 / ₹250 |
| Rental refund | deposit − (rent still due + damage + missing) | 2,500 − 1,875 = ₹625 |
| Commission sales | bill before GST − points − returns | 1,910 − 300 = ₹1,610 |
| Slab commission | each part of the month's sales × its % | 25,000 × 2% + 15,000 × 3% = ₹950 |
| Manager share | (shop sales − returns) × % | 6,25,000 × 1% = ₹6,250 |
| Closing cash | opening + in − out | 25,000 + 1,000 − 10,000 = ₹16,000 |
| GST to pay | sales GST − return GST − (purchase GST − purchase-return GST) | ₹6,800 |
| Approx. profit | sales − cost of goods − daily expense × days | ₹15,000 |
| Internal estimate | share % × (sales + profit) × rate % | 50% × 1,30,000 × 18% = ₹11,700 |

## 12. Daily and monthly checklist

**Every day**

- [ ] Confirm all of today's bills, purchases and returns (drafts change nothing).
- [ ] Record broken or dead plants in Damage Entry.
- [ ] Check the Cash Book closing cash against the cash in the drawer.
- [ ] Collect money on bills that are due (Money from Customers → Receivables).

**Every week or month**

- [ ] Open the Reorder report and make purchase orders.
- [ ] Do a Stock count and get it approved.
- [ ] Check Supplier dues by age and pay the oldest bills first.
- [ ] Check Customer dues by age and follow up late customers.
- [ ] Pay staff commission (Commission → Pay staff).
- [ ] Run GST Reconciliation and give it to your accountant.

## 13. Common questions

**I saved a bill but stock did not change.** It is still a Draft. Open it and click **Confirm**.

**I made a mistake in a confirmed bill.** Do not delete it. For a sale, make a Sales return (or request cancellation). For a purchase, make a Purchase return.

**The app says "Credit limit exceeded".** The customer would owe more than their limit. Collect some money first, or raise the limit in the customer's details.

**The app says the discount is too high.** One line's discount is over 20% of qty × rate. Lower it, or ask head office to change the limit in Settings.

**I cannot change the price tier on a bill.** The customer is Retail, so the bill always uses the retail price. Change the customer's type if they are really wholesale or a dealer.

**A salesman cannot see some customers or bills.** Salesmen see only their own. Give the role "See every customer" or "See every staff member's sales" if they need more.

**I cannot delete a company, customer, supplier, product or category.** Deleting is permanent, so anything used on bills, stock or other records cannot be deleted. The message says where it is used. Make it Inactive instead (the switch on its card).

**I cannot pay commission to someone.** Set their staff rate first (Commission → Set rates). If the month is already paid, use **Change** on that payout.

**I cannot find the owner's estimate.** Only the owner (super admin or full access) sees the **Internal** tile in Reports.

**A UPI sale is not in the Cash Book.** UPI and card go to the Bank Book. Only cash goes to the Cash Book.

**My Excel import shows errors.** Read the problem next to each row number, fix those rows in Excel, save as CSV UTF-8 again and import only those rows.

**I cannot approve my own stock request.** That is on purpose: the admin approves it. You can still **Cancel** it until it is approved.

**Stock transfers would not open for me.** Update the app: transfers now always open under your own company, and the **Receive** button shows for the shop that asked.

**A batch says "150 of 450 received" but I only bought 150.** That came from editing opening stock several times in an older version. Running the update fixes it to "150 of 150"; your stock on hand was always right.

**I can only see 25 rows.** Use the page numbers under the list, or search. Export downloads every row.

**Where is the Void button for a supplier payment?** It was removed on purpose: payment history is a permanent record. If a payment was typed wrongly, ask head office/your accountant to put it right.

**The app will not let me pay commission of ₹2,000.** The person earned less than that this month. The box shows the most you can pay.

**Why does the Journal show supplier payments and receipts?** The Journal now lists every money entry, the automatic ones too, with both sides. Choose **Show → Only corrections I made** to see just your own entries.

**A supplier is missing from the list in a form.** Each form lists only the suppliers of the company chosen in that form. Check the **Company** box at the top of the form.

**I need a page that is not in my menu.** Use **Request Access** (Help menu), tick what you need and say why. The bell tells you when the admin answers.

**An export file is empty.** Choose one company at the top of Reports (and a customer or supplier when the report asks), or other dates, then export again.
