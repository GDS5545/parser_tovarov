=== Universal WooCommerce Product Scraper & Importer ===
Contributors: gds5545
Tags: woocommerce, scraper, importer, product import, playwright
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 0.3.0
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

Not yet built: variable products (Stage 9 — the importer reports this
case rather than dropping it silently), the persisted category/attribute
mapping review UI (Stage 10), AI fallback extraction (Stage 12), a
sync/diff review UI (Stage 13), and installer packaging (Stage 16). See
`ARCHITECTURE.md` for the full stage-by-stage status table.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP
   from the Plugins screen.
2. Activate WooCommerce first, then this plugin.
3. Go to WooCommerce → Universal Scraper → Browser Settings and enter the
   Scraper Worker URL once it is deployed (see `INSTALL.md`).

== Changelog ==

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
