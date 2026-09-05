=== Universal WooCommerce Product Scraper & Importer ===
Contributors: gds5545
Tags: woocommerce, scraper, importer, product import, playwright
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 0.2.0
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

Stages 1–2 of 16 are complete: project architecture, custom database
tables (`wp_uws_sources`, `wp_uws_jobs`, `wp_uws_logs`, `wp_uws_mappings`,
`wp_uws_product_links`), the admin menu (Dashboard, Import Product, Bulk
Import, Queue, Products, Mappings, Attributes, Settings, AI Settings,
Browser Settings, Logs), the `/wp-json/uws/v1/*` REST API, the SSRF-safe
URL validator, and the queue's cron tick. The `/analyze` and `/import`
endpoints correctly report "not implemented yet" (HTTP 501) until the
Playwright worker (Stage 3) and extraction/import pipeline (Stages 4–8)
are connected — nothing fakes success ahead of that.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP
   from the Plugins screen.
2. Activate WooCommerce first, then this plugin.
3. Go to WooCommerce → Universal Scraper → Browser Settings and enter the
   Scraper Worker URL once it is deployed (see `INSTALL.md`).

== Changelog ==

= 0.2.0 =
* Stage 2: WordPress plugin foundation — database schema, admin UI
  scaffold, REST API, SSRF protection, queue table + cron tick.

= 0.1.0 =
* Stage 1: architecture and project structure.
