# Universal WooCommerce Product Scraper & Importer — Architecture

Status: **Stage 1–2 of 16** (see roadmap below). This document is the
reference design for the whole platform; it is updated as later stages land.

## 1. Problem & principle

Modern product pages are rendered by JS frameworks (React/Vue/Next/Nuxt),
load data via AJAX/GraphQL, lazy-load images, and vary wildly in markup.
A plugin that only does `wp_remote_get()` + regex will work on a handful of
static sites and silently fail everywhere else. This project is therefore
split into two independently deployable components:

```
┌─────────────────────────────┐        HTTP/JSON        ┌───────────────────────────┐
│   WordPress / WooCommerce    │ ───────────────────────▶ │      Scraper Worker       │
│   Plugin (PHP)                │ ◀─────────────────────── │  (Node.js + Playwright)   │
│                               │                          │                           │
│  - Admin UI                  │                          │  - Browser automation     │
│  - WooCommerce CRUD           │                          │  - DOM / JSON-LD / meta   │
│  - Attribute normalization    │                          │    extraction              │
│  - Category mapping           │                          │  - Pagination / load-more  │
│  - Queue orchestration (DB)   │                          │  - Screenshot / debug      │
│  - REST API                   │                          │  - Returns raw structured  │
│  - Sync / logs                │                          │    page data (not a final  │
└─────────────────────────────┘                          │    WooCommerce product)    │
                                                            └───────────────────────────┘
```

The **worker never talks to WooCommerce or the WP database**. It receives a
URL + options, drives a real browser, and returns raw structured findings
(HTML fragments, JSON-LD blocks, image URLs, candidate spec tables, etc.).
All product-model reasoning (merging extractors, normalization, attribute
mapping, WooCommerce creation) happens in PHP, so the WordPress side stays
authoritative and testable without a browser.

The worker is reached through `Scraper\ScraperEngineInterface` — a
`PlaywrightHttpEngine` implementation calls the Node worker over HTTP; a
future `HttpEngine` (plain fetch, for static sites where no browser is
needed) or a different browser engine can be swapped in without touching
the extractor/import pipeline.

## 2. Extraction pipeline (priority order)

For a fetched page, extractors run in this order and feed a
`ProductDataMerger`, which fills a `ProductData` DTO field-by-field, higher
priority wins on conflict, and every field gets a confidence score:

1. `JsonLdExtractor` — `Product`/`Offer` schema.org blocks (highest trust)
2. `MetaExtractor` — OpenGraph / `<meta>` product tags
3. `EmbeddedJsonExtractor` — `__NEXT_DATA__`, `window.__INITIAL_STATE__`,
   Nuxt payloads, other inline `<script>` JSON blobs
4. `DomExtractor` — semantic heuristics (`h1`, price patterns, `[itemprop]`)
5. `SpecificationExtractor` — tables / `dl` / accordions / tabs → attributes
6. `VariationExtractor` — option groups → variable-product structure
7. `ImageExtractor` — gallery, lazy `data-src`/`srcset`, background-image
8. `BreadcrumbExtractor` — category path
9. `AiExtractor` — **only** runs for fields still missing/low-confidence
   after 1–8, with a size-capped, pre-cleaned text/HTML payload

Implemented so far: 1, 2, 4, 5, 7, 8 (registered by `ExtractionPipeline`,
priority order enforced by `ProductDataMerger`, which lets each extractor's
declared priority win on scalar-field conflicts while attributes/images
accumulate from every extractor rather than overwrite). `EmbeddedJsonExtractor`
(3), `VariationExtractor` (6), and `AiExtractor` (9) are not built yet — a
page whose data only lives in a framework's inline JSON state or in
variation option groups will come back with lower-confidence/partial
fields rather than nothing, but won't be fully resolved until those land.

This ordering directly implements spec requirement "AI only as fallback,
never as the primary path" — most conventional stores should resolve
entirely through 1–8, so the AI API is never called for the common case.

## 3. Directory layout (WordPress plugin, PSR-4 autoloaded)

```
universal-woo-scraper.php      Plugin bootstrap (header, activation/deactivation hooks)
uninstall.php                  Table/option cleanup on uninstall
composer.json                  PSR-4: Uws\ => includes/
includes/
  Plugin.php                   Wires everything together on plugins_loaded
  Support/                     HtmlDocument (DOMXPath helper), UrlResolver
  Database/                    dbDelta schema + repositories for the 5 uws_* tables
  Security/                    Nonce/capability guards, SSRF-safe UrlValidator
  Rest/                        REST controllers under /wp-json/uws/v1/*
  Admin/                       Menu registration + page controllers
  Scraper/                     ScraperEngineInterface + PlaywrightHttpEngine (Stage 3)
  Pipeline/                    ExtractionPipeline — fetch + run extractors + merge
  Extractors/                  ProductExtractorInterface + concrete extractors + ProductDataMerger
  Normalizer/                  PriceParser, AttributeNormalizer + synonym/unit dictionaries (Stage 7)
  Mappers/                     Category & attribute mapping persistence (Stage 10, not yet populated)
  Woocommerce/                 ProductImporter, CategoryResolver, AttributeResolver, ImageImporter (Stage 8)
  Ai/                          AiExtractor + provider clients (Stage 12, not yet built)
  Dto/                         ProductData / ProductAttribute value objects
worker/                        Node.js + Express + Playwright scraper service (Stage 3)
  src/server.js                 HTTP API: /health /fetch /discover
  src/browserPool.js             Lazy shared Chromium instance, per-request contexts
  src/pageFetcher.js              Navigation, popup dismissal, JSON-LD/console/network capture, pagination
  src/ssrfGuard.js                Defense-in-depth URL/host validation
admin/pages/                   PHP view templates rendered by Admin\Pages\*
assets/                        Admin CSS/JS (vanilla; no build step required for MVP)
templates/                     Reusable partials (preview cards, tables)
tests/                         PHPUnit tests + fixture HTML pages
worker/                        Node.js + Playwright scraper service (Stage 3, separate deployable)
```

Namespace root: `Uws\` (Universal Woo Scraper). No file exceeds a single
class/interface responsibility; no God classes.

## 4. Database schema (custom tables, not wp_options)

All under `$wpdb->prefix . 'uws_'`, created via `dbDelta` in
`Database\Installer`:

- **uws_sources** — one row per site domain: detected capabilities
  (has JSON-LD, has WooCommerce REST, product URL pattern, selector
  template), i.e. the "Site Profile" (spec §47–48).
- **uws_jobs** — the scrape/import queue: url, type (single/category/bulk),
  status (`pending|processing|completed|failed|retry|cancelled`), attempts,
  next_retry_at, payload (JSON), result (JSON), timestamps.
- **uws_logs** — one row per job attempt: status, duration, counts
  (images/attributes/variations), error message, debug snapshot ref.
- **uws_mappings** — source→WooCommerce mapping rows for both categories
  and attributes; `type` column discriminates them; `scope` = per-domain or
  global.
- **uws_product_links** — sync bridge: `source_url`, `source_id`,
  `product_id` (WC product), `content_hash`, `last_scraped_at`, used for
  duplicate detection (§26) and change-aware sync (§27).

## 5. Security posture (implemented from Stage 2 onward)

- Every admin action is nonce-protected and gated on `manage_woocommerce`.
- Every REST route declares a `permission_callback` (no `__return_true`).
- `Security\UrlValidator` enforces SSRF protection before any URL — user
  supplied or worker-resolved — is dispatched to the worker: only
  `http`/`https` schemes, DNS-resolved and re-checked against RFC1918 /
  loopback / link-local / multicast ranges, no credentials in the URL,
  bounded redirect count enforced worker-side.
- All `$wpdb` access uses `prepare()`; all output is escaped
  (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` where HTML must
  survive per §3).
- No `eval`, no unchecked `shell_exec`, no arbitrary file inclusion.

## 6. Roadmap (16 stages, per project brief)

| # | Stage | Status |
|---|-------|--------|
| 1 | Architecture + project structure | ✅ done |
| 2 | WordPress plugin foundation (DB, security, admin/REST scaffold) | ✅ done |
| 3 | Playwright worker (`worker/`, Express + Chromium, `/fetch` `/discover` `/health`) | ✅ done |
| 4 | URL analyzer (`ExtractionPipeline`, engine wired via `uws_scraper_engine`) | ✅ done |
| 5 | Product extraction (JSON-LD, meta, specification tables, breadcrumbs, DOM heuristics, priority merge) | ✅ done |
| 6 | Image extraction (`img`/lazy attrs/srcset/og:image, download via Media Library, dedupe by source URL) | ✅ done |
| 7 | Attribute normalization (`AttributeNormalizer` + synonym/unit dictionaries, smart/strict modes) | ✅ done (dictionary is a starter set, not exhaustive) |
| 8 | WooCommerce importer (`ProductImporter`, real `WC_Product_Simple`/`WC_Product_Attribute` CRUD) | ✅ done — simple products only |
| 9 | Variable products | ⏳ not started — importer detects and reports the case rather than silently dropping variations |
| 10 | Categories + mappings | ◐ partial — categories resolve/create live via `CategoryResolver`; the persisted `uws_mappings` review/override UI (spec §10, source-label ≠ target-label editing) isn't built |
| 11 | Queue (worker loop, retries, rate limiting) | ✅ done — cron-driven `Dispatcher`, exponential backoff, category→single fan-out |
| 12 | AI extraction | ⏳ not started |
| 13 | Synchronization | ◐ partial — re-scraping upserts `uws_product_links` and can update an existing product, but there's no diff/changed-fields review UI yet |
| 14 | Admin UI (full preview/editor) | ◐ partial — Import Product has an editable, confidence-highlighted preview; Bulk/Queue/Logs/Products are functional but plain |
| 15 | Tests | ◐ partial — PHPUnit covers `UrlValidator`, `PriceParser`, `AttributeNormalizer`, and an extractor-merge integration test; worker has `node --test` coverage for its SSRF guard. No WP-integration/WooCommerce test harness yet |
| 16 | Packaging + installation docs | ⏳ not started (`INSTALL.md` covers manual setup; no installer script or Docker image yet) |

`/import` still refuses to silently overwrite an existing product: a
match by source URL/SKU/GTIN/MPN returns `409 duplicate` with
`update`/`duplicate`/`skip` as the caller's explicit choices (spec §26).
