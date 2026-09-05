=== Universal WooCommerce Product Scraper & Importer ===
Contributors: gds5545
Tags: woocommerce, scraper, importer, product import, playwright
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 0.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Analyze any product/category page and extract structured data through a
layered fallback pipeline (JSON-LD → DOM → embedded JSON → AI as a last
resort), normalize attributes, and import into WooCommerce with category
mapping, variable-product support, and sync. Works out of the box with no
separate service to deploy; a real browser worker is an optional upgrade
for JavaScript-heavy sites.

== Description ==

**No separate service required to start using this plugin.** By default,
Analyze/Import fetch pages with plain HTTP (`wp_remote_get()`) — no
account, no API key, no permission from the site you're importing from;
it's the same as any browser opening a public page, just without
JavaScript execution. This works for most stores, including
server-rendered ones (1C-Bitrix, OpenCart, classic WooCommerce themes),
since search-engine-facing product data — JSON-LD included — is normally
already in the HTML a plain fetch receives.

For a site whose product data only appears after JavaScript runs
(React/Vue/Next/Nuxt storefronts), or that needs a "Load more" button
clicked, deploy the optional Node.js/Playwright worker (`worker/`) and set
its URL under Browser Settings — every request then goes through a real
Chromium browser instead, with no other configuration change, via
`Scraper\ScraperEngineInterface`. See `ARCHITECTURE.md` for the full
design and the 16-stage build roadmap; `INSTALL.md` covers both paths.

= Current status =

Stages 1–8 and 11 of 16 are working end to end: paste a product URL,
Analyze fetches it (plain HTTP by default — see above — or the Playwright
worker if one is configured), runs it through the extraction pipeline
(JSON-LD → meta tags → specification tables → images → breadcrumbs → DOM
heuristics), and shows an editable, confidence-highlighted preview. Import
creates a real WooCommerce simple product (categories, global attributes,
downloaded images, duplicate detection by URL/SKU/GTIN/MPN) via
WooCommerce's own CRUD. Bulk/category URLs go through the same pipeline
via a cron-driven queue with retries.

Stage 9 (variable products) detects `<select>`/radio option groups and
creates a real WooCommerce variable product with those attributes marked
for variation — it does not invent per-variation price/SKU/stock (that
data lives behind AJAX on real stores), so the result tells you to use
WooCommerce's own "Generate variations" button next. Stage 10 (category
and attribute mappings) is done: WooCommerce → Universal Scraper →
Mappings lets you rename or "skip" any source label, applied to all
future imports without touching products already created.

Stage 12 (AI fallback) calls Anthropic or OpenAI only when name/SKU/brand/
price/description are still missing after the conventional extractors run
— never for a page they already resolved, and never overwriting an
already-confident field. Stage 13 (sync) tracks what the plugin last
wrote for title/description/price/stock so a re-scrape can tell "still
matches what we imported" apart from "a human edited this in wp-admin
since" (Settings → Protect manual edits), with per-field toggles for
what's allowed to update on re-import at all. Stage 16: run `./build.sh`
for an installable ZIP; `worker/Dockerfile` + `docker-compose.yml`
package the worker.

Not yet built: a side-by-side "here's what changed" review screen before
a sync applies (updates apply directly, governed by the toggles above),
and an in-wp-admin installation wizard (install remains ZIP-upload or
copy-the-folder). See `ARCHITECTURE.md` for the full stage-by-stage status
table.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP
   built by `./build.sh` from the Plugins screen.
2. Activate WooCommerce first, then this plugin.
3. That's it — go to WooCommerce → Universal Scraper → Import Product and
   try a URL. Only deploy a scraper worker (Browser Settings) if a
   specific site needs JavaScript rendering (see `INSTALL.md`).

== Changelog ==

= 0.8.0 =
* Site Templates (spec §47): WooCommerce → Universal Scraper → Site
  Templates lets you save per-domain XPath overrides for name/SKU/brand/
  price/description/specifications/images/categories — get one from any
  browser's DevTools ("Copy XPath"). `ManualSelectorExtractor` applies
  these with the highest priority of any extractor (confidence 1.0,
  always wins over JSON-LD/automatic guesses); for images and
  specifications it also excludes the generic extractor entirely for
  that domain so a manually-pointed product photo isn't diluted by
  unrelated same-domain images elsewhere on the page. Added after
  real-site testing (a 1C-Bitrix "e-shop" store) showed no amount of
  general heuristics reliably finds the right content on every CMS.

= 0.7.0 =
* Russian translation (`languages/universal-woo-scraper-ru_RU.mo`) — all
  180 user-facing strings across the admin UI and API error messages.
  Loads automatically on a Russian-locale WordPress install via the
  plugin's existing `load_plugin_textdomain()` call; no settings change
  needed. `languages/universal-woo-scraper.pot` is included for anyone
  translating to another language.
* `ImageExtractor` now looks for a recognizable product-gallery container
  first (WooCommerce's own markup, 1C-Bitrix's `detail_picture`, generic
  `product-image`/`product-gallery` theme conventions) and, if one exists
  and contains at least one surviving image, uses only images inside it —
  fixes real-site cases where an unrelated same-domain photo block
  elsewhere on the page (that no filename/size heuristic could
  distinguish from a real product photo) was being picked up instead.
  Falls back to a whole-page scan when no such container is found.
* Import Product preview now has an "Add image URL" field so a photo the
  automatic extraction misses can be added by hand before importing,
  without waiting on extractor accuracy.

= 0.6.1 =
* Fixed a real-site regression found during testing: `ImageExtractor` was
  pulling in a WhatsApp click-to-chat badge, language-switcher flag icons,
  partner/payment logos, and third-party tracking pixels (Mail.ru/Yandex
  counters) as if they were product photos — and could even pick one of
  them as the *main* image when it happened to appear before the real
  photo in the page's HTML. Images are now required to be on the same
  registrable domain as the page (a CDN subdomain is fine; an unrelated
  domain like a messenger/analytics widget is not), filtered against an
  expanded site-chrome keyword list, and checked against declared
  icon-sized `width`/`height` attributes.
* Categories preview field's placeholder text no longer looks like real
  scraped category names (was a literal "Equipment, Pumps, Water Pumps"
  example, confusing when the field is actually empty).

= 0.6.0 =
* Added `Scraper\HttpEngine`: a plain-`wp_remote_get()` fallback engine
  that needs no separate service, used automatically whenever no worker
  URL is configured. Covers most server-rendered stores (JSON-LD is
  normally already in the initial HTML); JS-only content, "Load more"
  pagination, and popups still require the optional Playwright worker.
  This is now the plugin's default rather than the worker being required.

= 0.5.0 =
* Stage 12: AI fallback extraction (Anthropic/OpenAI) — only called for
  fields still missing after the conventional pipeline runs, on a
  size-capped and script/style-stripped copy of the page; never
  overwrites an already-confident field.
* Stage 13: `ImportSnapshot` tracks title/description/price/stock so
  re-imports can protect fields a merchant edited manually in WooCommerce;
  per-field update-policy toggles (title/description/price/stock/
  categories/attributes/images) added to Settings.
* Stage 16: `build.sh` packages an installable ZIP; `worker/Dockerfile` +
  `docker-compose.yml` package the worker.
* Fixed a settings-form bug where saving AI Settings or Browser Settings
  could silently reset "Protect manual edits"/debug mode back to
  unchecked (checkboxes not present on the submitted page's form were
  being coerced to false regardless of their stored value).

= 0.4.0 =
* Stage 9: variable-product detection (`<select>`/radio option groups) and
  real `WC_Product_Variable` creation with variation attributes marked;
  per-variation data is intentionally left to WooCommerce's own "Generate
  variations" rather than fabricated.
* Stage 10: category/attribute mapping review UI backed by a real
  `MappingRepository` — rename or skip any source label, applied to all
  future imports.

= 0.3.0 =
* Stages 3-8 + 11: Playwright worker, extraction pipeline (JSON-LD/meta/
  specification tables/images/breadcrumbs/DOM), attribute normalization,
  WooCommerce simple-product importer, editable confidence-highlighted
  preview UI, and a working cron-driven queue with retries.

= 0.2.0 =
* Stage 2: WordPress plugin foundation — database schema, admin UI
  scaffold, REST API, SSRF protection, queue table + cron tick.

= 0.1.0 =
* Stage 1: architecture and project structure.
