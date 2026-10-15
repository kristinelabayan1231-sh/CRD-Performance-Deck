# Filling in missing data on the live app (Render)

While the app ran on Render's free plan it slept when nobody visited, and its Pancake syncs slept with
it, so some past days have missing orders (most visible in the **Order Database**). On the paid plan
the app stays awake, and the missing days were **filled in automatically** on the deploy of Oct 15, 2026.

## What happens on its own

`php artisan pancake:backfill-missing`:

- finds every past day since **Jun 1, 2025** (up to yesterday) with fewer than **50** Pancake orders
  saved (a normal day has about 300);
- fetches each one again from Pancake, **newest first**: the day's orders (Order Database, Cancelled /
  Waiting, Conversion Breakdown, Dashboard sales), chat engagements, delivered orders, Shecom sales and
  tag changes;
- remembers each finished day, so a genuinely quiet day (a holiday) isn't fetched again and again.

It ran **once, when that deploy started**; later deploys don't start it. If it was cut off (Step 3 still
lists days), start it by hand (see Good to know): it carries on with the days still missing. Only one
runs at a time. Nothing is deleted: fetching a day again only adds missing orders and updates saved ones.

Each day takes about 1–2 minutes. On the local copy 233 days were missing, which is roughly 4–8 hours.

---

## Step by step

### Step 1. (Done on Oct 15) Deploy

It started by itself on that deploy. To run it again, see Good to know.

### Step 2. Watch it in the Logs

Render dashboard → **crd-performance-deck** → **Logs**. Search for `Pancake backfill`. You'll see one
line per day, newest first:

```
Pancake backfill [1/233] 2026-10-14: 318 orders, 12 staff with engagements, 318 Shecom sales.
Pancake backfill [2/233] 2026-10-13: 305 orders, 11 staff with engagements, 305 Shecom sales.
...
Pancake backfill: ALL DONE.
```

A day that fails shows its error and isn't remembered, so starting it again by hand (see Good to know)
retries just the failed and remaining days.

### Step 3. Check what's left (any time)

Render dashboard → **crd-performance-deck** → **Shell**, then:

```bash
php artisan pancake:backfill-missing --dry-run
```

It prints how many days are still to fetch and which ones. `0 days to fetch.` means everything is in.

### Step 4. Check the Order Database

Open the live **Order Database → CRA's Performance → Month → May 2026**: orders should show for the whole
month, not just May 1–5.

### Step 5. Customer Database (optional, it also does this by itself)

The Customer Database refills itself every 15 minutes. To hurry it, in the Shell, one at a time:

```bash
php artisan customers:backfill --window
php artisan customers:check-history --limit=3000
php artisan customers:warm-cache
```

Done.

---

## Good to know

- **Start it by hand** (e.g. right after checking with `--dry-run`), in the Shell:
  ```bash
  nohup php artisan pancake:backfill-missing > storage/logs/backfill.log 2>&1 &
  tail -f storage/logs/backfill.log
  ```
  If one is already running it says `Pancake backfill: already running.` and stops; that's fine.
- **Check further back or a stricter limit:** `php artisan pancake:backfill-missing --from=2025-01-01 --min=100`
- **Fetch one particular day again** (even if it was done before): `php artisan pancake:sync --date=2026-05-14`
- **Don't run `leads:generate` for past days.** It hands out new Segmentation Tracker leads to CRAs.
- **Don't run `migrate:fresh`, `db:wipe` or any delete.** None of this needs it.
