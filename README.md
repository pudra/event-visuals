# Event Visuals

Two distinct ways to browse a large, seeded events dataset (an animated **card gallery**
and an interactive **clustered map**) with local images, human-readable locations,
timezone-aware times, filtering, attendee registration, and confirmation + reminder emails.

Built against the realistic **1.25M-row** seeded dataset as-is: display and filter fields are
denormalized into lean indexed columns so no list/map/filter query ever decodes the ~1.5KB
JSON payload. See **[SOLUTION.md](SOLUTION.md)** for the decisions and trade-offs.

## Stack

Laravel 13 · Inertia · Vue 3 + TypeScript · Tailwind 4 · SQLite · Pest

## Running it

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate

# Seed: full 1.25M by default; pass SEED_ROWS for a quick local set. One db:seed
# backfills + analyzes, so it yields a populated, working app:
SEED_ROWS=20000 php artisan db:seed

php artisan storage:link
npm run build                      # or: npm run dev
php artisan serve                  # http://127.0.0.1:8000/events-visual-1

# Emails log to storage/logs/laravel.log (MAIL_MAILER=log). To process the queue
# and fire reminders:
php artisan queue:work
php artisan schedule:work
```

Gallery: `/events-visual-1` · Map: `/events-visual-2`

## Tests

```bash
php artisan test
```

## Highlights

- **Built for scale**: a derived `is_public` boolean lets one `(is_public, created_time)`
  index serve both the filter and the `ORDER BY` (no temp b-tree); verified with
  `EXPLAIN QUERY PLAN` on the full dataset.
- **Two genuinely distinct layouts** over one shared filtered query.
- **Images end to end**: in-app upload, 2+ per event, served locally with a deterministic
  placeholder fallback.
- **Idempotent reminders**: 3-day and 24-hour waves, catch-up-safe and at-most-once.
- **Accessible**: native filter controls, an accessible register dialog, a keyboard path to
  the map's events, and `prefers-reduced-motion` support.
