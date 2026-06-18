# Event Visuals: solution notes

Two distinct browsing layouts (a **card gallery** and an **interactive map**) over the
seeded events dataset, with images, human-readable locations, timezone-aware times,
filtering, and attendee registration + emails.

## The decision that shaped everything: build for 1.25M rows

The seeder defaults to **1,250,000 events (~2.5GB)** and each row carries a ~1.5KB JSON
`payload`. The single most expensive thing you can do at that scale is read and decode that
blob for every row in a list. So the core decision was to **project the few fields the
listings actually display and filter on into lean, indexed columns** and never touch the
payload in list/filter/sort/paginate queries; the blob is read **only** on the
single-event detail page.

- Migration `..._add_display_columns_to_events_table` adds `name`, `description`,
  `venue_name`, `city`, `min_price`, and a derived **`is_public`** boolean (true for
  `published`/`sold_out`), plus indexes on `created_time` and composites
  `(is_public, created_time)`, `(is_public, city, created_time)`, and
  `(is_public, type, created_time)`.
- **Why a boolean instead of `status`:** the public browse scope is `status IN
  ('published','sold_out')`. A 2-value `IN` can't be ordered straight from a `(status, …)`
  index; SQLite merges two ranges and falls back to a **temp b-tree for the `ORDER BY`** on
  every list and map request. Projecting the scope into a single derived boolean makes it an
  equality seek, so `(is_public, created_time)` serves *both* the filter and the
  `ORDER BY created_time` from the index. Verified with `EXPLAIN QUERY PLAN` on the full
  1.25M-row DB: `SEARCH … USING INDEX events_public_time_idx`, no temp b-tree; the gallery
  page query is single-digit milliseconds and the 1,500-point map fetch is low-double-digit
  milliseconds (approximate, machine-dependent; the point is the shape: bounded index range
  scans that don't grow with the table). `is_public` is kept in lockstep with `status` by a
  model `saving` hook, so it can't drift.
- `php artisan events:backfill` projects payload + geocoded city + `is_public` into those
  columns. It is **chunked, transactional per chunk, and idempotent** (only fills
  un-backfilled rows), so it resumes and scales.
- The `EventFactory` writes the same display columns inline, so factory-built rows are
  immediately listable without a backfill (keeps tests fast and realistic).

Result: every list/map row fetch reads a handful of indexed columns, never the payload, and
the sort is index-served, single-digit milliseconds at full scale. The one remaining O(n)
cost is the gallery's **exact total** (`paginate()` issues a `count()` over the filtered
set); I kept it because the "1,421 events" count is useful UX, and the map avoids a count
entirely via the `cap + 1` trick. The documented next step at true scale is keyset /
`simplePaginate` to drop the count and keep deep "load more" pages constant-time. The
`filters()` options (city frequencies + date bounds) are `Cache::remember`'d for 10 min
since they barely move at this scale and fire on first paint of both pages; the backfill
forgets the key.

## Reverse geocoding: offline, cached, accurate to the data

Events carry only lat/lng. The seeded coordinates are jittered ±0.5° around 75 known metro
anchors, so `App\Support\Geocoder` resolves each coordinate to its **nearest metro** ("City,
Country"). It is offline, deterministic, and accurate to how the data was generated, with no
external geocoding API, no rate limits, no runtime dependency across 1.25M rows. The result
is cached into the indexed `city` column at backfill time, so requests never geocode. The
column stores the **city** (not the full "City, Country") on purpose: it doubles as the
location-filter dimension, where a clean city value is what the dropdown groups on; the
country is a one-line change to project as well if richer labels are wanted later.

## Timezones: store UTC, render local

`created_time` is a global unix instant. The backend always treats it as UTC; the frontend
renders it in **the viewer's own timezone** via `Intl.DateTimeFormat` (the gallery header
shows which timezone is being used). Emails state times explicitly in UTC.

The date **filter** bounds on the **UTC calendar day** (`from 00:00:00 UTC` to `to 23:59:59
UTC`) on purpose: for a global dataset there is no single "local" day, so a deterministic,
index-served UTC-day bound is the predictable choice. At the edges this can differ from the
viewer's local day by the tz offset; passing the viewer's offset to shift the bounds is the
one-line extension if local-day filtering is ever wanted.

## Images: end to end, local files, no 2.5M rows

Image support runs the full path, **UI included**: the event detail page (`Show.vue`) has an
"Add images" control that posts the files as `FormData` to `POST /events/{event}/images`; the
endpoint validates them (`image|mimes:...|max:5120`, ≤10, per-event cap), stores each on the
local `public` disk (`$file->store(...)`, no hotlinked URLs), and records an owned
`event_images` row; `Event::displayImages()` then serves uploaded rows ahead of placeholders,
the detail gallery updates in place from the response, and `EventController::storeImage` is
covered by a feature test using `UploadedFile::fake()`. Because materialising 2+ rows for every one of
1.25M events is wasteful, `displayImages()` falls back to a **deterministic local placeholder
set** (category-themed SVGs in `public/images/events`) when an event has no uploads, so
every event renders 2+ locally-served images at any scale, while real uploads still win
(`EventImageSeeder` attaches a few so the upload path is visible in the seeded app). Images
are eager-loaded in lists to avoid N+1.

## The two layouts

- **Visual 1 (Gallery)** (`/events-visual-1`): responsive card grid, per-card image carousel
  (surfaces the multiple images), staggered entrance animations, "load more" pagination.
- **Visual 2 (Map)** (`/events-visual-2`): Leaflet map with category-coloured circle markers
  grouped with `leaflet.markercluster` (dense metros collapse into counts instead of
  overlapping blobs), a legend, and a selection card. The map directly fits an
  events/real-estate domain. Map results are capped at 1,500 points per request with a
  "narrow your filters" notice; it never streams 1.25M markers to a browser.

Both share `EventFilters` (filter by **date range** and **location**, plus category) and the
`RegisterDialog`, and both filter only on the indexed columns.

## Attendees & emails

- Registering creates an `event_attendees` row (unique per email per event) and **queues a
  confirmation email**.
- `events:send-reminders` sends two waves: **3 days** (events 48–72h out) and **24 hours**
  (0–24h out). The 3-day band is ~2–3 days (kept above 48h so a ~30h event is never labeled
  "3 days"); events 24–48h out get only the 24-hour reminder. Each window is a 24h-wide range,
  giving a missed hourly run plenty of catch-up margin, and per-attendee `*_sent_at` stamps
  make each wave fire **at most once** (idempotent, safe to re-run). Scheduled hourly.
- Register validation returns JSON 422 explicitly (the Inertia/web stack would otherwise turn
  a failed validation into a redirect the fetch-based dialog can't read). The form mirrors the
  rules client-side with inline per-field errors; the register modal is built on the repo's
  reka-ui `Dialog` (focus trap, Escape-to-close, focus restore, `role=dialog`/`aria-modal`).
- The list/map `from`/`to` filters are validated to `Y-m-d` (the same explicit-422 pattern), so
  a malformed value is rejected rather than failing open: a looser `date` rule would accept a
  full datetime, which then breaks the `strtotime()` parse and would silently drop the floor.

**Trust model:** one public scope (`published`/`sold_out`) is enforced everywhere; the
list/map/detail/register/image routes all use it (`EventController::PUBLIC_STATUSES`), and
the `status` query param is ignored, so draft/cancelled events and their payloads are never
reachable (by UUID or by `?status=`). The two public POST routes are rate-limited, the upload
validates real images and caps images per event; in production this write would sit behind
owner/admin auth, which is out of scope here (the starter has no ownership model).

**Accessibility (a deliberate split, not an oversight):** Visual 1 (the gallery) is the
**conformant primary browse path**: semantic cards, filters built entirely from native
controls (`<select>` for location/category, `<input type="date">` for the date range) so they
are keyboard- and screen-reader-complete with no custom widget to get wrong, distinct
per-image alt text, and an accessible reka-ui register dialog (focus trap, Escape, focus
restore). Visual 2 (the map) is a **supplementary spatial visualization** layered on the same
filtered data: Leaflet markers are inherently pointer-oriented and category is colour-coded.
To keep it operable without a pointer, the map carries `role="region"` + a label and a
visually-hidden (`sr-only`) focusable results list, one button per visible event (name +
category + city + date) that drives the same selection card a pin click does, so Register and
Details are reachable by keyboard and screen reader. Result counts use `aria-live`. Both
required browse layouts are therefore keyboard-operable, and the gallery remains the richer
accessible path.

## Running it

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate

# Seed: full 1.25M by default; pass SEED_ROWS for a quick local set. db:seed also
# runs the backfill, so one command yields a populated, working app:
SEED_ROWS=20000 php artisan db:seed

php artisan storage:link
npm run build                      # or: npm run dev
php artisan serve                  # http://127.0.0.1:8000/events-visual-1
# (browse defaults to UPCOMING events; use the date filter to see past ones)

# Emails (MAIL_MAILER=log → storage/logs/laravel.log) + reminders:
php artisan queue:work             # processes queued confirmation/reminder mail
php artisan schedule:work          # runs events:send-reminders hourly
```

## Tests

`php artisan test`: feature tests in `tests/Feature/EventBrowsingTest.php` cover the lean
listing, the date/city/category filters, registration + queued confirmation, duplicate
rejection, and both reminder waves including idempotency.
