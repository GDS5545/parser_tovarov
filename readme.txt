=== Universal WooCommerce Product Scraper & Importer ===
Contributors: gds5545
Tags: woocommerce, scraper, importer, product import, playwright
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Analyze any product/category page with a real browser, extract structured
product data through a layered fallback pipeline (JSON-LD → DOM → embedded
JSON → AI as a last resort), normalize attributes, and import into
WooCommerce with category mapping, variable-product support, and sync.

== Description ==

This plugin is the WordPress/WooCommerce half of a two-component system.
The browser automation (Playwright/Chromium) runs in a separate Node.js
worker service, reached over HTTP through `Scraper\ScraperEngineInterface`
— WordPress never has to open a JS-rendered page itself. See
`ARCHITECTURE.md` in this plugin's folder for the full design and the
16-stage build roadmap; `INSTALL.md` covers deploying the worker.

= Current status =

Stages 1–8 and 11 of 16 are working end to end: paste a product URL,
Analyze fetches it through the Playwright worker (`worker/`), runs it
through the extraction pipeline (JSON-LD → meta tags → specification
tables → images → breadcrumbs → DOM heuristics), and shows an editable,
confidence-highlighted preview. Import creates a real WooCommerce simple
product (categories, global attributes, downloaded images, duplicate
detection by URL/SKU/GTIN/MPN) via WooCommerce's own CRUD. Bulk/category
URLs go through the same pipeline via a cron-driven queue with retries.

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
   from the Plugins screen.
2. Activate WooCommerce first, then this plugin.
3. Go to WooCommerce → Universal Scraper → Browser Settings and enter the
   Scraper Worker URL once it is deployed (see `INSTALL.md`).

== Changelog ==

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
