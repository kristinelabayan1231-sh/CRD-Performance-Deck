# Render: move the web service and its data to a new workspace

Created 2026-10-12. Backup scheduled for **2026-10-12, around 11:00 PM**.

**Goal:** move the `crd-performance-deck` web service and all of its database data to a new Render workspace, without losing any data.

## Summary

The backup tools are installed (`pg_dump` version 16).

**A bigger risk than the move:** the live database is on Render's free plan, and free Render databases expire after a set time and then get deleted. They also have no automatic backups, so right now there's no copy of the data anywhere. Making a backup protects the data whether or not the move goes smoothly.

Safe order, where nothing gets deleted until the end:

1. **Back up, read-only.** In the Render dashboard, open `crd-performance-deck-db` → **Connect**, copy the **External Database URL**, and run this in a terminal:

   ```
   pg_dump "<external-url>" -Fc -f ~/Documents/crd-backup-2026-10-12.dump
   ```

   If it fails with "server version mismatch", that's harmless: it means Render's database is newer than version 16. Run `brew install postgresql@17`, then use `/opt/homebrew/opt/postgresql@17/bin/pg_dump` instead.
2. **Check the backup.** Run `pg_restore --list ~/Documents/crd-backup-2026-10-12.dump | head` to confirm it holds the tables. Claude can also restore it into a local database and compare row counts with live, such as customers, orders and leads.
3. **Set up the new workspace from the blueprint** and restore the backup there. The old service keeps running the whole time.
   At switchover, suspend the old service and take one more backup so nothing written after tonight is missed (Step 3b).
4. **Test the new service:** log in, open the Customer Database and the tracker, and check the numbers match.
5. **Only then** delete the old service and database.

The detailed steps follow.

## Why

- The web service and its data need to be in a new workspace.
- Render can't move a web service to another workspace. The service has to be recreated there from `render.yaml`, and the database copied over by hand from a backup.
- The live database (`crd-performance-deck-db`) is on Render's **free plan**: no automatic backups, and free databases expire after a set time and are then deleted. There is no copy of the data anywhere yet.

## Ground rules

- `pg_dump` only **reads** the live database. Making a backup can't lose data.
- The old service and database stay running and untouched until the new one has been checked.
- The External Database URL contains the database password. Run commands with it yourself; don't paste it into chats, docs or commits.

## Step 1: Back up (11 PM tonight)

1. Render dashboard → `crd-performance-deck-db` → **Connect** → copy the **External Database URL**.
2. In a terminal:

   ```
   pg_dump "<external-url>" -Fc -f ~/Documents/crd-backup-2026-10-12.dump
   ```

3. If it fails with **"server version mismatch"** (harmless: Render's Postgres is newer than the local 16):

   ```
   brew install postgresql@17
   /opt/homebrew/opt/postgresql@17/bin/pg_dump "<external-url>" -Fc -f ~/Documents/crd-backup-2026-10-12.dump
   ```

   Use the version Render shows for the database if it's newer than 17.

## Step 2: Check the backup

- `pg_restore --list ~/Documents/crd-backup-2026-10-12.dump | head` should list the app's tables.
- Optional: restore it into a scratch local database and compare row counts with live (customers, orders, leads). Claude can do this part; it doesn't need the URL.
- Keep a second copy of the `.dump` file somewhere else (e.g. Google Drive).

## Step 3: Set up the new workspace

1. In the new workspace: **New → Blueprint** → pick this repo. This creates the web service and an **empty** database from `render.yaml`.
2. Re-enter the environment variables: the two `sync: false` values in `render.yaml`, plus anything set only in the old service's **Environment** tab (Pancake keys, Google login, etc.).
3. Restore the data into the new database (use its External Database URL):

   ```
   pg_restore --no-owner -d "<new-external-url>" ~/Documents/crd-backup-2026-10-12.dump
   ```

4. Google login: set `APP_URL` to the new address, and add `<new address>/auth/google/callback` in Google Cloud Console. A custom domain, if any, moves to the new service.

## Step 3b: Final copy at switchover (no gap in the data)

Tonight's backup is a snapshot. The live app keeps writing after it (Pancake sync every few minutes, lead assignment, CRA edits), so those changes would be missing from the new database. To avoid that:

1. Do Steps 1–3 first as a rehearsal: it proves the backup and restore work, while the old service keeps running.
2. Pick a quiet time to switch. **Suspend the old web service** in Render (Settings → Suspend). That stops new writes; the old database keeps its data.
3. Take a **fresh backup** of the old database (same command as Step 1, new file name, e.g. `crd-backup-final.dump`).
4. Empty the new database and restore the fresh backup into it:

   ```
   pg_restore --no-owner --clean --if-exists -d "<new-external-url>" ~/Documents/crd-backup-final.dump
   ```

5. Start using the new service. If anything is wrong, resume the old service: nothing on it was changed.

## Step 4: Check the new service

- Log in, open the Dashboard, Customer Database, High AOV CVR & VIP and Segmentation Tracker, and check the numbers match the old service.
- Check that scheduled jobs run (Pancake sync, lead assignment).

## Step 5: Clean up

- Only after Step 4 passes, and a few days of normal use: delete the old web service and the old database. Keep the `.dump` files.
- Note the new database's free-plan expiry date, and keep taking backups before it.

## Log

- 2026-10-12: Plan written; backup scheduled for 11 PM. Decision: the service and its data move to a new workspace.
