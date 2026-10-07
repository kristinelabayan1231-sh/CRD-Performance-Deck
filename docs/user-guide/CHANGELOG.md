# User Guide — Change Log

Tracks what the PDF covers and which app changes still need to go into it, so an update
never needs to re-read the whole PDF. Newest first.

## Pending (in the app, not yet in the guide)

<!-- Add one line per user-facing change: `- YYYY-MM-DD · <Module> · <what changed> · shots: <screenshot names>` -->

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
