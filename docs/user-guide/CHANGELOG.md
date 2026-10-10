# User Guide — Change Log

Tracks what the PDF covers and which app changes still need to go into it, so an update
never needs to re-read the whole PDF. Newest first.

## Pending (in the app, not yet in the guide)

<!-- Add one line per user-facing change: `- YYYY-MM-DD · <Module> · <what changed> · shots: <screenshot names>` -->

- 2026-10-10 · Segmentation Tracker · New Summary tab (after Weekly Segmentation): filters CRA / Type / Product, Month or one day on the right; tiles Leads, Catered, Pending, Converted, Feedback recorded; Customer's feedback donut (BLOCKED under Other) with counts and %; Insights (pending leads, top feedback with advice, STILL HAVE STOCKS without callback, feedback coverage, busiest and best-converting hour, best/worst product, most/least catered CRA, conversions); Statuses and Customer tags bars; Time of contact chart (contacted vs converted per hour); Per CRA and Per product tables with top feedback. A CRA sees only their own leads · shots: new (segmentation-summary)
- 2026-10-10 · Dashboard · Segmentation Tracker section moved above Goal & conversion per CRA (both full width); bigger Customer tags donut · shots: 02-dashboard
- 2026-10-10 · Customer Database · Faster filters: tile counts and list pages are kept for 3 minutes (new deliveries can take up to 3 minutes to show, as can the Dashboard's churn rate (was 10 minutes); saving Pancake Accounts or a finished older-orders check refreshes them) · shots: —
- 2026-10-10 · Segmentation Tracker · New Product filter (All products + the Product Consumption products) narrows the lists, tiles and Per CRA pop-up; the All / Pending / Catered toggle is removed (both lists always show) · shots: tracker shots
- 2026-10-10 · Dashboard · Results row: new Retained (indigo) and Repeat customers (magenta) tiles for the month to date or the dates picked, counted as in the Customer Database; click opens the Customer Database filtered the same way. Shown to users with Customer Database access. Row is 4 tiles wide (2 rows) on laptops, one row on wide screens · shots: 02-dashboard
- 2026-10-10 · Settings · New Pancake Accounts tab (permission pancake_accounts.manage; Super Admin by default): CRD team accounts list (add / remove names; used by the Customer Database for CRD Leads, Retained, Repeat, "By a CRA", the pre-Jan 1 order check and CRD/FSD labels of Jan 1–Apr 4 Pancake deliveries) and the CRA accounts from User Access (read-only; used by Dashboard, Confirmed Orders, Conversion Breakdown, Segmentation Productivity, order issues and the Customer Database), each with where it's used. Saving relabels the Jan 1–Apr 4 deliveries and re-checks older orders only for customers delivered in the month before · shots: new (settings-pancake-accounts)
- 2026-10-10 · All modules · New section look: each section has a coloured header band with an icon (title, date badge, actions) over a tinted body, so the white tiles inside stand out · shots: all module shots
- 2026-10-10 · Dashboard · Results is one section; Total confirmed orders (teal), Conversion rate (sky), AOV (amber), Churn rate (coral) are coloured tiles. Goal card renamed "CRD monthly goal (Gross Sales)": gross sales on the left, "Target ₱…" pill at the far right, "₱… to go" in red on a white pill. Logistics tiles are white on a light-blue section · shots: 02-dashboard
- 2026-10-10 · Segmentation Tracker · Reset (circular arrow) icon and the greyed-out Backlogs button removed; filters sit on one row under the tabs (wraps on narrow screens): search and filters on the left, Month, Date and Columns on the right; Pending (coral) and Catered (teal) have coloured headers. Weekly: Month / Week on the right; Catered per CRA and Carry-over customers have coloured headers; each CRA's carry-over list is collapsible (closed by default) and scrolls after about 10 rows · shots: tracker shots, weekly
- 2026-10-10 · Segmentation Productivity · Opens on today compared with today (was: the day before); report section has a coloured header; View by / Day / Compare with on the right; CRA cards show the CRA's colour as a dot by the name (no coloured top edge) · shots: productivity
- 2026-10-10 · Conversion Breakdown · Coloured scorecard tiles (teal = BC, purple = SC, sky = total conv %, amber = gross) with white change pills; View by / Day on the right; per-CRA table has a coloured header and an "i" button explaining ▲ green / ▼ red, the funnel bars and "N reached → N orders" · shots: conversion-breakdown
- 2026-10-10 · Customer Database · Coloured tiles (hints moved to hover), shorter header and history note; filters: Search box (joined to its button), Show and Sort by on top; Delivered below Search on the left, date range on the right; The tile in use sits raised with a "Showing" pill. Pop-up titled "Customer Life Time Value (CLTV)": customer card, Customer CLTV and Product CLTV highlight tiles, then Orders by status, Product CLTV per product and Orders · shots: customer-database, customer-profile
- 2026-10-10 · Settings · Sales Goals: new optional "Net income goal" (monthly); "CRD monthly goal" field now labelled "(Gross Sales)" · shots: settings-sales-goals
- 2026-10-10 · Segmentation Tracker · Copying names and mobile numbers: the right-click menu on a row now also has Copy name / Copy mobile number; right-clicking highlighted text (or a field) shows the browser's own Copy/Paste menu; the mobile number can be highlighted by dragging · shots: — (right-click menu not pictured)
- 2026-10-09 · Customer Database · New module (own sidebar item, permission customers.view, CRA Supervisor): every FSD- and CRD-delivered customer from the logistics API delivered since Jun 1, 2026, one row per contact number — Customer name, Contact number, QTY (delivered orders), Total spent (CLTV overall, Pancake POS totals); tiles Customers / CRD Leads / Retained / Repeat Customers (click to filter); Delivered All / Today / Week / Month; Show filter; search by name or number; sort. CRD Lead = a delivery handled by a CRA (CRD-delivered, or sold by a CRA's Pancake account); in a period, Retained = their first CRA-handled order, Repeat = an earlier one too. Clicking a row opens the customer: orders by POS status with ₱ value, Product CLTV (units × SRP vs SRP × 30, green "Reached CLTV"), and the order list · shots: new (customer-database, customer-profile)
- 2026-10-10 · Dashboard / Customer Database · New "How the numbers are worked out" section at the bottom of each page: dates, monthly goal (and prorating), gross sales, confirmed orders, conversion rate, AOV, churn (with example), goal and conv % per CRA, logistics and tracker rates; Customer Database: who is listed, QTY, total spent, handled by a CRA, CRD Leads, Retained, Repeat, product CLTV, date filters · shots: 02-dashboard, customer-database
- 2026-10-10 · Dashboard · Dates: a Month picker (Jan–Dec, month to date by default) plus a From–To range replace Today / Week / Month for every section; "This month" resets. Results: CRD monthly goal (gross; prorated for a custom range), Total confirmed orders (click → new Confirmed Orders page: date, order #, CRA, customer, Broadcast/Segmentation, status, amount; CRA filter), Conversion rate, AOV, Churn rate (CRD customers lost ÷ due: due = their 30 days to reorder after running out ended in the range; lost = no order in that time). Goal per CRA and Conversion per CRA combined into one "Goal & conversion per CRA" table below · shots: 02-dashboard, new (confirmed-orders)
- 2026-10-10 · Dashboard · Renamed: Logistics tile "Ordered again" → "Actual Order" (no sub-label; Repeat rate note "Actual ÷ CRD delivered"); Top CRAs column "Done" → "Catered" · shots: 02-dashboard
- 2026-10-10 · Customer Database · Covers Jan 1, 2026 on (logistics from Apr 5, Pancake deliveries before); QTY column; From–To date range beside Today/Week/Month; Retained vs Repeat also counts CRA-handled orders from before Jan 1 (each CRD customer's history looked up in Pancake; a note shows progress, the pop-up shows "Before Jan 1, 2026: N CRA-handled orders"); CRD accounts = the CRD team's Pancake accounts list (past CRAs too); missing amounts load when a customer is opened · shots: customer-database, customer-profile
- 2026-10-09 · Settings · Product Consumption: new SRP column (₱ per unit) and the CLTV it sets (SRP × 30). CRA Supervisors can now open this tab and set SRPs only (new permission product_consumption.srp; name, keywords and days stay read-only for them) · shots: settings-product-consumption

- 2026-10-09 · Dashboard · Live: a teal "Live · Pancake 10:42 AM" pill next to the title; the dashboard checks every minute and reloads by itself when new data comes in (not while a pop-up is open or someone is typing). Pancake now syncs every 10 minutes (was hourly), also on Conversion Breakdown / Productivity · shots: 02-dashboard
- 2026-10-09 · All pages (header) · Order issues clear within ~10 minutes of fixing the tag in Pancake: each sync looks up every flagged order again. A Pancake page whose engagements fail no longer stops orders and tags from syncing (that day keeps its last good engagements) · shots: —

- 2026-10-09 · All pages (header) · Order issues: a red pill in the top bar ("33 issues · 33 no crd tag") lists problems in the CRAs' orders this month — No CRD tag (own order without CRD - BROADCAST/SEGMENTATION), Both CRD tags, No Pancake account; the zoom button opens all issues grouped by CRA (date, order #, customer, amount) with how to fix each; green "No order issues" when clean. Supervisors see every CRA, a CRA only their own · shots: new (header-issues), all module shots (header)
- 2026-10-09 · Productivity / Conversion / Dashboard · Each sync also picks up tag and status changes made that day on older orders, so every day uses the orders' latest CRD tags · shots: —

- 2026-10-09 · Segmentation Tracker / Weekly / Dashboard · Wording: Unprocessed → **Pending**, Processed / Handled → **Catered** everywhere (tracker lists, Show filter, right-click "Mark as catered" / "Unmark catered", CRA greeting "N pending", carried labels "Pending since…" / "Catered · carried", Weekly tiles, table, legend and note, dashboard trend legend). Supersedes the "Mark as processed" wording in the lines below · shots: segmentation-tracker, weekly-segmentation, 02-dashboard

- 2026-10-09 · Segmentation Tracker · Search box (first in the filter bar): customer name, contact # (any format) or order #, across every lead day; CRAs search only their own leads; tiles follow the search; blue "Search results for …" bar with Clear search · shots: segmentation-tracker
- 2026-10-09 · Segmentation Tracker · Choosing status PJR/Inactive/CBR/Drop call sets Customer's Feedback to NO VERBAL CONV automatically (the row's feedback updates at once; Customer Tagging is unchanged) · shots: —

- 2026-10-09 · Segmentation Tracker · Right-click a customer row → "Mark as processed": it moves from Unprocessed to Processed at once (no reload) without setting a status or contact date; right-click any Processed row → "Unmark processed" to bring it back (for a lead with a status or date of contact, it confirms, then clears them). Status Updated tile, dashboard and Weekly "Catered" still count statuses only · shots: new (tracker-mark-processed), segmentation-tracker

- 2026-10-09 · Dashboard · Goal per CRA shows each CRA's remaining sales to the goal in red ("₱52.3k left"), or "Goal hit" in teal · shots: 02-dashboard

- 2026-10-08 · Segmentation Productivity · Total confirmed orders = the CRA's own orders tagged CRD - BROADCAST or CRD - SEGMENTATION (per order, same orders as Conversion Breakdown); assigned lead conversion = those whose customer is on the CRA's leads, Pancake conversion = the rest (Repeat Purchase no longer matters) · shots: productivity

- 2026-10-08 · Weekly Segmentation / Dashboard · Renamed: Weekly tile "Handled" → "Catered"; dashboard Segmentation Tracker KPI and trend legend "Processed" → "Catered" · shots: weekly-segmentation, 02-dashboard

- 2026-10-08 · Conversion Breakdown / Segmentation Productivity / Dashboard · Gross sales now use each order's sales from Shecom, which leaves out the child (TSD) row; Pancake's total is used only until Shecom has the order. Untagged orders still don't count until the CRA adds CRD - BROADCAST or CRD - SEGMENTATION. Settings → Connections has a new "Shecom sales API" row · shots: settings-connections (if covered)

- 2026-10-08 · Dashboard · One date picker and one Today / Week / Month switch at the top drive every section (replacing each section's own tabs; Logistics loses All time); Goal per CRA becomes day / week-to-date / month-to-date (daily goal × days); each frame shows its range; Back to today button · shots: 02-dashboard

- 2026-10-08 · Dashboard · Grouped into Results (real date: Sales Goals, Conversion), Logistics (company-wide) and Leads (Segmentation Tracker, working date); each module in its own frame with its own period tabs inside · shots: 02-dashboard

- 2026-10-08 · Dashboard · With a working date, Conversion (Today/Week/Month) and Daily goal per CRA show the real date plus a yellow "leads from Sep 8" tag for the lead days worked · shots: 02-dashboard

- 2026-10-08 · Segmentation Tracker · CRAs get a "Hey <name>!" pop-up on their first visit each day: their lead count for the lead day and how many are unprocessed, with "Show unprocessed" / "Let's go" · shots: new (tracker-greeting)

- 2026-10-08 · All modules · Working date now applies to the Segmentation Tracker only (and the dashboard's Segmentation panel, labelled "<Month> lead data · lead day …"); Productivity, Conversion Breakdown and the rest of the dashboard use the real date; conversion "Leads" / productivity "Assigned" use the paired lead day (real day minus the gap); working-date banner shows on tracker pages only · shots: 02-dashboard, segmentation-tracker

- 2026-10-07 · Segmentation Tracker · Redesign: the selected day's leads split into Unprocessed (top, no status and no contact date) and Processed (below); Show filter All / Unprocessed / Processed (Backlogs disabled); 5 customers per page in each list, with an expand (slanted arrows) button that opens the full list (50 per page) in a pop-up; pagination in the app's light style; filters moved beside the Daily / Weekly tabs, icons only except Type and Status; carry-over list turned off for now · shots: segmentation-tracker

- 2026-10-07 · Settings · New "Working Date" tab (CRA Supervisor): pick the start (e.g. Sept 8) and from tomorrow the app runs that fixed gap behind the real date, one day forward per real day; the whole app (dashboard, tracker + backlog, weekly, productivity, conversion, hourly syncs) treats it as today; yellow "Working date" banner on every page; "Use real date" clears it · shots: new (settings-working-date), banner on all module screenshots
- 2026-10-07 · Segmentation Tracker · Daily leads now include FSD-delivered customers (out of stock = delivered + qty × Product Consumption days, qty from Pancake); type comes from Shecom (CRD vs FSD delivered), "2+ orders = CRD" only when Shecom is down · shots: —
- 2026-10-07 · Dashboard · Managers/supervisors see a yellow alert listing FSD leads with no quantity in Pancake (qty 1 assumed) · shots: 02-dashboard
- 2026-10-07 · Segmentation Tracker · Lead type "New Customer" renamed "FSD Lead" (type filter, summary tile "FSD Leads", per-CRA split "x CRD · y FSD", dashboard "FSD" conversion %); Sept 1–7 backlog imported from the Google Sheet · shots: segmentation-tracker, 02-dashboard

- 2026-10-07 · Dashboard · "Welcome, user" replaced by a "CRD Board" button: opens a fun poster (violet tape board matching the mascot, handwritten) with today + the 2 days before (Gross Sales, Net Income) and a Top Seller panel, all typed by hand and not saved; Clear and Full screen buttons; waving CRD mascot with an editable message · shots: new (crd-board)
- 2026-10-07 · All pages · Browser tab icon (favicon) is now the CRD logo from the sidebar · shots: —

- 2026-10-07 · Dashboard · Heading renamed "Performance Deck"; accent colours: purple monthly-goal card, coloured KPI and retention-rate tiles, soft blue/green logistics tiles, icon badges on the Daily goal and Conversion cards, icons on tiles · shots: 02-dashboard
- 2026-10-07 · All modules · Darker grey text and deeper tile colours for readability; chart hover tooltips now show instantly on every chart · shots: all module screenshots

- 2026-10-07 · Dashboard · New "Live from Logistics" row (6 tiles from the Shecom retention report, by delivered date): Total FB delivered, Retained by CRD, Retention rate, Total CRD delivered, Ordered again via CRD, Repeat rate; Week / Month / All time; "Updated" time · shots: 02-dashboard

- 2026-10-07 · Dashboard · Went cold tile now shows the count beside the % (cold leads / all leads, e.g. 45/320); cold = Customer Tagging Cold or CanPro Cold · shots: 02-dashboard

- 2026-10-07 · All modules · Compact layout to match the dashboard: one-line page titles with the description inline (no icon tile), smaller section headings, tighter cards and spacing; Segmentation Tracker summary tiles smaller; Segmentation Productivity CRA cards in one row on wide screens · shots: all module screenshots

- 2026-10-07 · Conversion Breakdown · New module (own sidebar item): team scorecard + per-CRA funnel board with Orders BC/SC (POS tags CRD - BROADCAST / CRD - SEGMENTATION), Engagements, Leads, BC/SC/Total conv %, Gross BC/SC/total, BC vs SC mix, TOTAL row; Day/Week/Month vs the period before; sortable columns; Sync Pancake button; new permissions conversion.view (CRA), conversion.view_all (CRA Supervisor) · shots: new (conversion-breakdown-day, conversion-breakdown-month)
- 2026-10-07 · Settings · New "Sales Goals" tab: CRA daily goal (default ₱77,000), CRD monthly goal (default ₱1,000,000), optional own daily goal per CRA; new permission sales_goals.manage (CRA Supervisor) · shots: new (settings-sales-goals)
- 2026-10-07 · Dashboard · Redesigned to fit one screen: Sales Goals (CRD monthly goal % with pace marker, daily goal % per CRA) and Conversion (Total conv % per CRA, Today/Week/Month) on top, compact Segmentation Tracker below; User Access tile removed; a CRA sees only their own rows · shots: 02-dashboard
- 2026-10-06 · Segmentation Productivity · Gross sales (and AOV) now = Gross BC + Gross SC from tagged POS orders; "How the numbers are worked out" wording · shots: productivity-day, productivity-week

- 2026-10-06 · Segmentation Productivity · New module: CRA cards (confirmed orders, funnel, conversion, pick-up, trend), weekly/daily charts, report table with TOTAL; filters for CRA, day/week, compare period, trend metric; Sync Pancake button; AOV and Gross sales on hold · shots: new (productivity-day, productivity-week)
- 2026-10-06 · Segmentation Tracker · Backup mode: when the retention API is down, new leads come from saved delivered orders (retention API copy + Pancake POS deliveries) with Est. out of stock = delivered + qty × consumption days − 1; existing leads untouched; yellow "Backup mode" notice · shots: 03-daily-overview
- 2026-10-06 · Settings · Product Consumption: new "Consumption days (per unit)" field; seeded values (Scar Cream 10, CanPro 10, others 15) · shots: 15-products
- 2026-10-06 · Settings · New "Connections" tab (Super Admin): Run check tests database, Shecom, Pancake POS (API key / access token), Pancake pages and shows the server's outgoing IP; new permission connections.check · shots: new (settings-connections)
- 2026-10-06 · Settings · New "Pancake Pages" tab: add/edit/turn off/remove/test Facebook pages and their Pancake access tokens (tokens masked, stored encrypted); replaces PANCAKE_PAGE_* in .env; new permission pancake_pages.manage · shots: new (settings-pancake-pages)
- 2026-10-06 · Segmentation Tracker · CRD Lead now means 3+ delivered orders (1st = FSD, 2nd = Retention, 3rd+ = CRD); glossary "CRD Lead" wording · shots: —
- 2026-10-06 · Segmentation Productivity · "Confirmed orders per day" chart replaced by "Sales per CRA" (stacked by CRA, Day/Week/Month switch, top-seller dot per bar, top seller line); Gross sales and AOV now shown from Pancake order totals · shots: productivity-day, productivity-week
- 2026-10-06 · User Access · New "Pancake account" field under the display name for CRA users · shots: 13-user-access
- 2026-10-06 · Roles & Access · New permissions: productivity.view (CRA), productivity.view_all (CRA Supervisor) · shots: —

## Current version

**v1.0 — 2026-10-06** · 19 pages · `CRD-Performance-Deck-User-Guide.pdf`

| # | Section (guide.html id) | Covers | Screenshots |
|---|---|---|---|
| 1 | Signing In (`signin`) | Google sign-in, remember me | 01-login |
| 2 | Roles & Access (`roles`) | What each role can do | — (table) |
| 3 | Dashboard (`dashboard`) | Today/Week/Month, KPIs vs previous, trend, tags, Top CRAs | 02-dashboard |
| 4 | Segmentation Tracker — Daily (`daily`) | Sync, filters, live tiles, leads table, tracking fields, Per CRA pop-up, show/hide columns, notes | 03-daily-overview, 04-daily-table, 04b-daily-fields, 05-per-cra-popup, 06-column-picker, 07-notes |
| 5 | Carry-over & Transfers (`carry`) | Carry-over rule (no status, PJR, Repeat Purchase, Inactive), labels, transfer with workload + confirm | 08-carry-over, 09-transfer-choose, 10-transfer-confirm |
| 6 | Weekly Segmentation (`weekly`) | Weeks from the 1st, handled/assigned grid, carry-over customers | 11-weekly, 12-weekly-carry-over |
| 7 | User Access (`user-access`) | Grant access, display names, roles, disable/remove, custom roles | 13-user-access, 14-roles |
| 8 | Settings — Products (`settings`) | Product name grouping (keyword + also matches) | 15-products |
| 9 | Glossary (`glossary`) | Lead, CRD Lead, Processed, Carry-over, Converted… | — |

## History

- **2026-10-06 · v1.0** — First version: all modules above.
