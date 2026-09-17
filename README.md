# RT Source Tracker

A WordPress plugin that tracks visitor traffic sources (organic, paid, social,
LLM/AI) on every page and ties them to form submissions. Supports Contact Form 7,
Gravity Forms, WPForms, and generic HTML forms.

Live under **Tools → Source Tracker** once installed and activated.

## Install

Copy the `rt-source-tracker/` folder into `wp-content/plugins/`, then activate
it from the WordPress admin Plugins screen.

## How it works

- [`assets/tracker.js`](rt-source-tracker/assets/tracker.js) runs on every
  front-end pageview, classifies the traffic source client-side (referrer +
  UTM params), and reports it to a REST endpoint via `sendBeacon`/`fetch`. It
  also injects hidden fields into every form so server-side hooks can
  attribute submissions even without JS.
- [`includes/class-tracker.php`](rt-source-tracker/includes/class-tracker.php)
  registers the REST routes and the CF7/Gravity Forms/WPForms submission
  hooks, and writes events to the database.
- [`includes/class-classifier.php`](rt-source-tracker/includes/class-classifier.php)
  contains the channel-classification rules (mirrored in `tracker.js` for the
  client-side first pass).
- [`includes/class-database.php`](rt-source-tracker/includes/class-database.php)
  owns the custom `{prefix}rt_source_tracker` table and all report queries.
- [`includes/class-admin.php`](rt-source-tracker/includes/class-admin.php) +
  [`templates/admin-page.php`](rt-source-tracker/templates/admin-page.php)
  render the Tools → Source Tracker dashboard (events, sessions, CSV export,
  retention settings).

## Configuration hooks

No settings UI for these — wire them from a small mu-plugin or your theme's
`functions.php` if needed:

- `add_filter( 'rtst_trust_proxy_headers', '__return_true' );` — trust
  `CF-Connecting-IP`/`X-Forwarded-For` for rate limiting and IP hashing.
  **Only enable this if the origin server is actually locked down to accept
  traffic solely through that trusted proxy** — otherwise these headers are
  attacker-suppliable and defeat rate limiting entirely.
- `add_filter( 'rtst_record_bot_events', '__return_true' );` — store
  bot-flagged events instead of skipping them (restores the pre-1.1.0
  behavior, at the cost of extra table growth from crawler traffic).

## Notes for production use

- Every pageview writes to the database, including on fully cached pages —
  this plugin is not compatible with a "zero PHP execution on cache hit"
  caching strategy.
- The weekly retention purge runs on WP-Cron, which only fires on incoming
  requests. If the site disables WP-Cron in favor of a real system cron, make
  sure that system cron actually hits `wp-cron.php`, or the purge will never
  run.
- The `/wp-json/rt-tracker/v1/*` endpoints are intentionally public
  (unauthenticated) so tracking works for logged-out visitors and cached
  pages. Consider adding edge-level rate limiting (e.g. a Cloudflare rule) in
  front of that path for defense in depth.

## License

GPL-2.0-or-later
