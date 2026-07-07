# Deploying to InfinityFree

InfinityFree's free plan gives FTP access to a single `htdocs/` folder —
there's no directory above it to keep `vendor/`, `app/`, `.env`, etc. outside
the web root the way Laravel normally expects. The app has been made to
tolerate this (see `public/index.php`), but the **deploy step must lay files
out correctly** for it to work.

## What goes where in htdocs/

Everything in the repo goes into `htdocs/`, flattened — i.e. `public/`'s
contents move up to sit alongside `vendor/`, `app/`, etc., not nested under
a `public/` subfolder:

```
htdocs/
  app/
  bootstrap/
  config/
  database/           (without database.sqlite — see note below)
  resources/
  routes/
  storage/            (must stay writable by PHP)
  vendor/              <- from `composer install --no-dev --optimize-autoloader`
  build/                <- from public/build/ (npm run build output)
  index.php             <- from public/index.php (unmodified — it auto-detects this layout)
  favicon.ico           <- from public/favicon.ico
  robots.txt             <- from public/robots.txt
  .htaccess              <- deploy/htdocs.htaccess, renamed
  build/.htaccess        <- deploy/build.htaccess, renamed, placed inside build/
  artisan, composer.json, .env, ...
```

`public/index.php` already detects whether `vendor/` is a sibling or one
directory up, so it doesn't need editing for this layout — just copy it as-is.

## Locking down everything else

`deploy/htdocs.htaccess` denies direct HTTP access to everything except
`index.php`, `favicon.ico`, `robots.txt`, and itself, then re-applies
Laravel's normal front-controller rewrite rules. `deploy/build.htaccess`
re-opens just the `build/` folder so compiled CSS/JS/fonts still load. Rename
and place both as shown above — without them, hitting
`https://yourdomain.com/.env` would otherwise serve your secrets in plain
text, since `.env` is physically inside the web-servable folder in this
layout (it isn't in normal Laravel hosting).

## Database: MySQL, not SQLite

This app runs on SQLite locally (zero setup, fast tests), but **production
must use MySQL**. InfinityFree's shared filesystem is commonly unreliable for
SQLite's persistent file-based writes, and since login (`users`,
`allowed_emails`, `sessions` tables) depends on the database working, that's
not a risk worth taking.

This has been verified end-to-end against a real local MySQL 9.7 instance —
migrations (including the `->change()` one on `users.password`, which doesn't
need `doctrine/dbal` on Laravel 13's native schema builder), the seeder, and
the full auth + admin CRUD flow all ran cleanly with `DB_CONNECTION=mysql`.
No code changes needed; `config/database.php`'s `mysql` connection already
reads everything from `DB_*` env vars.

**To wire up production:**

1. In the InfinityFree control panel, create a MySQL database. You'll get a
   host (something like `sqXXX.infinityfree.com`), a database name and
   username (both usually prefixed `if0_XXXXXXXX_`), and a password.
2. Add those four values as GitHub Secrets (e.g. `DB_HOST`, `DB_DATABASE`,
   `DB_USERNAME`, `DB_PASSWORD`).
3. Have the deploy workflow write them into the `.env` that ships to
   `htdocs/`, alongside `DB_CONNECTION=mysql` and `DB_PORT=3306`.
4. Run migrations once against that database. There's no SSH on the free
   tier, so `php artisan migrate` can't run the normal way — the common
   workaround is dropping a one-off PHP script in `htdocs/` that bootstraps
   Laravel and calls the migrator, then **deleting it immediately** after
   running it once (treat it as security-sensitive while it exists; anyone
   who finds the URL before you delete it can re-run it).
