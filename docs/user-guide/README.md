# User Guide (PDF)

- `CRD-Performance-Deck-User-Guide.pdf` — the guide handed to users.
- `guide.html` — its source (text, layout, numbered callouts). Each module is a `<section id="…">`.
- `screenshots/` — annotated screenshots (red boxes, numbered badges, arrows) used by `guide.html`.
- `capture.mjs` — takes the screenshots; badge numbers follow the order of each shot's `marks`.
- `build.mjs` — prints `guide.html` to the PDF.
- `seed-demo.php` — fills the **demo** database with made-up CRAs and customers.
- `CHANGELOG.md` — what the guide covers and what's still pending. Start here.

Screenshots always come from the demo database `crd_demo`, never the real one.

## Regenerate

```sh
# 1. (Only if crd_demo is missing or should be refreshed) create, migrate, seed the demo DB
mysql -uroot -e "DROP DATABASE IF EXISTS crd_demo; CREATE DATABASE crd_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
DB_DATABASE=crd_demo php artisan migrate --force
DB_DATABASE=crd_demo php artisan tinker docs/user-guide/seed-demo.php   # Ctrl+C once it prints "demo seeded"

# 2. Temporarily add a dev login route to routes/web.php (remove it afterwards!):
#    if (app()->environment('local')) { Route::get('/_dev-login', function () {
#        \Illuminate\Support\Facades\Auth::login(\App\Models\User::where('email', request('email'))->firstOrFail());
#        return redirect('/'); }); }

# 3. Serve the demo DB (API pointed nowhere so no real leads are pulled in)
DB_DATABASE=crd_demo SHECOM_API_URL=http://127.0.0.1:9 SESSION_DRIVER=file php artisan serve --port=8123

# 4. Capture (all, or only matching names) and build
node docs/user-guide/capture.mjs            # or: node docs/user-guide/capture.mjs weekly
node docs/user-guide/build.mjs

# 5. Remove the dev login route, stop the server, update CHANGELOG.md
```

If a page shows "Automatic sync couldn't reach the retention API", the demo's sync mark expired. Mark it fresh:
`DB_DATABASE=crd_demo php artisan tinker --execute='Cache::put("segmentation.sync.".App\Models\Lead::today()->toDateString(), ["at" => now()->toIso8601String()], now()->addDay());'`
