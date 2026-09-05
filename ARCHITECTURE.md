# Universal WooCommerce Product Scraper & Importer — Architecture

Status: all 16 stages have landed in some form; several (9, 10, 13) are
"done for the common case" rather than exhaustive — see the roadmap table
in §6 for exactly what's covered and what's explicitly still a gap. This
document is the reference design for the whole platform; it is updated as
the implementation evolves.

## 1. Problem & principle

Modern product pages are rendered by JS frameworks (React/Vue/Next/Nuxt),
load data via AJAX/GraphQL, lazy-load images, and vary wildly in markup —
but most e-commerce sites, including ones built on classic
PHP/CMS/1C-Bitrix stacks, still render their product data (JSON-LD
included) directly into the initial server response, specifically because
that's what search engines need to see. The plugin is built around that
distinction with **two interchangeable scraper engines** behind one
interface, `Scraper\ScraperEngineInterface`:

```
┌──────────────────────────────┐
│  WordPress / WooCommerce      │
│  Plugin (PHP)                  │
│                                 │
│  - Admin UI, REST API          │
│  - WooCommerce CRUD             │      apply_filters('uws_scraper_engine')
│  - Attribute normalization       │  ┌──────────────┴───────────────┐
│  - Category mapping               │  │                               │
│  - Queue orchestration (DB)        ▼  ▼                               ▼
│  - Sync / logs                  ┌─────────────┐            ┌───────────────────────┐
└──────────────────────────────┘  │  HttpEngine │  HTTP/JSON │     Scraper Worker      │
                                   │ (default,   │ ─────────▶ │  (Node.js + Playwright, │
                                   │  no deploy) │ ◀───────── │   deployed separately)  │
                                   │ wp_remote_  │            │  - real Chromium        │
                                   │ get(), no JS│            │  - popups/pagination/   │
                                   └─────────────┘            │    load-more/screenshot │
                                                               └───────────────────────┘
```

**`HttpEngine` is the default and needs nothing deployed**: it fetches a
page with `wp_remote_get()` and hands the raw HTML (plus any JSON-LD it
finds already in that HTML) to the same extraction pipeline described
below. It cannot execute JavaScript, click a "Load more" button, dismiss a
cookie popup, or take a debug screenshot — see its class docblock for the
full, honestly-stated list of what it can't do. For a site that needs
those things, deploying `worker/` (Node.js + Playwright) and setting its
URL under Browser Settings switches every request to `PlaywrightHttpEngine`
with no other configuration or code change, because both engines return
the exact same raw-page shape (`html`, `final_url`, `status_code`,
`json_ld_blocks`, `console_errors`, `network_errors`,
`screenshot_base64`) to the extractors.

Neither engine — nor the worker, when deployed — ever talks to WooCommerce
or the WP database directly. All product-model reasoning (merging
extractors, normalization, attribute mapping, WooCommerce creation)
happens in PHP, so the WordPress side stays authoritative and testable
without a browser.

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

Implemented so far: 1, 2, 4, 5, 6 (`VariationExtractor` — detects
`<select>`/radio-group option sets, not embedded-JSON variation data), 7, 8
(registered by `ExtractionPipeline`, priority order enforced by
`ProductDataMerger`, which lets each extractor's declared priority win on
scalar-field conflicts — and on which image is "main" — while
attributes/images accumulate from every extractor rather than overwrite).
`EmbeddedJsonExtractor` (3) and `AiExtractor` (9) are not built yet — a page
whose product/variation data only lives in a framework's inline JSON state
(`__NEXT_DATA__` etc.) will come back with lower-confidence/partial fields
rather than nothing, but won't be fully resolved until those land.

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
  Support/                     HtmlDocument (DOMXPath helper), UrlResolver, ImporterArgs
  Database/                    dbDelta schema + repositories for the 5 uws_* tables
  Security/                    Nonce/capability guards, SSRF-safe UrlValidator
  Rest/                        REST controllers under /wp-json/uws/v1/*
  Admin/                       Menu registration + page controllers
  Scraper/                     ScraperEngineInterface + HttpEngine (default, no deploy) +
                                PlaywrightHttpEngine (talks to worker/, Stage 3)
  Pipeline/                    ExtractionPipeline — fetch + run extractors + merge
  Extractors/                  ProductExtractorInterface + concrete extractors + ProductDataMerger
  Normalizer/                  PriceParser, AttributeNormalizer + synonym/unit dictionaries (Stage 7)
  (mapping persistence lives in Database\MappingRepository, consulted directly
   by CategoryResolver/AttributeResolver rather than a separate Mappers/ layer)
  Woocommerce/                 ProductImporter, CategoryResolver, AttributeResolver, ImageImporter (Stage 8),
                                ImportSnapshot (Stage 13, manual-edit protection)
  Ai/                          AiExtractor + AnthropicClient/OpenAiClient + ContentCleaner (Stage 12)
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
| 3 | Playwright worker (`worker/`, Express + Chromium, `/fetch` `/discover` `/health`) — optional, for JS-rendered/anti-bot-averse sites | ✅ done |
| 4 | URL analyzer (`ExtractionPipeline`, engine wired via `uws_scraper_engine`; `HttpEngine` is the zero-deploy default, `PlaywrightHttpEngine` used automatically once a worker URL is set) | ✅ done |
| 5 | Product extraction (JSON-LD, meta, specification tables, breadcrumbs, DOM heuristics, priority merge) | ✅ done |
| 6 | Image extraction (`img`/lazy attrs/srcset/og:image, download via Media Library, dedupe by source URL) — filters to same-registrable-domain images, a site-chrome keyword list (social/messenger/partner/payment badges), and icon-sized `width`/`height` attributes, after a real-site test surfaced a WhatsApp badge and third-party tracking pixels being picked up as "product images" | ✅ done |
| 7 | Attribute normalization (`AttributeNormalizer` + synonym/unit dictionaries, smart/strict modes) | ✅ done (dictionary is a starter set, not exhaustive) |
| 8 | WooCommerce importer (`ProductImporter`, real `WC_Product_Simple`/`WC_Product_Attribute` CRUD) | ✅ done — simple products only |
| 9 | Variable products | ◐ partial — `VariationExtractor` detects `<select>`/radio-group option sets and flags them for variation; `ProductImporter` creates a real `WC_Product_Variable` with those attributes marked for variation. Per-variation price/SKU/stock/image is **not** synthesized (that data lives behind AJAX on real stores, essentially never in the initial HTML) — the result tells the merchant to use WooCommerce's own "Generate variations" button instead of inventing numbers |
| 10 | Categories + mappings | ✅ done — `Database\MappingRepository` backs both `CategoryResolver` (scoped per source domain) and `AttributeResolver` (scoped globally); an identity mapping row is auto-created the first time a label is seen, and the Mappings admin page lets a merchant rename the WooCommerce-facing label or set a row to "skip" for all future imports without touching already-imported products |
| 11 | Queue (worker loop, retries, rate limiting) | ✅ done — cron-driven `Dispatcher`, exponential backoff, category→single fan-out |
| 12 | AI extraction | ✅ done — `Ai\AiExtractor` runs as a distinct second pass after the normal merge (not a member of the uniform extractor list, since it needs to see what's already resolved), calling Anthropic or OpenAI only when an important field (name/sku/brand/price/description) is still missing/low-confidence, on a script/style-stripped and size-capped (~12,000 char) copy of the page. Never overwrites an already-confident field |
| 13 | Synchronization | ✅ done for the fields that matter most — `ImportSnapshot` records what the plugin last wrote for title/description/short description/regular+sale price/stock, so a re-scrape can tell "matches what we imported, safe to refresh" apart from "a human changed this in wp-admin since" (protect_manual_edits). Per-field update-policy toggles (title/description/price/stock/categories/attributes/images) gate whether a group is touched at all. No diff/changed-fields *review* UI (a side-by-side "here's what would change" screen before committing) — updates apply directly, governed by the toggles above |
| 14 | Admin UI (full preview/editor) | ◐ partial — Import Product has an editable, confidence-highlighted preview; Bulk/Queue/Logs/Products are functional but plain |
| 15 | Tests | ◐ partial — PHPUnit covers `UrlValidator`, `PriceParser`, `AttributeNormalizer`, and an extractor-merge integration test; worker has `node --test` coverage for its SSRF guard. No WP-integration/WooCommerce test harness yet |
| 16 | Packaging + installation docs | ✅ done — `build.sh` produces a clean, installable `universal-woo-scraper-<version>.zip` (production Composer deps, `worker/`/`tests/`/dev tooling excluded, built in a temp dir so it never touches the dev `vendor/`); `worker/Dockerfile` + `docker-compose.yml` package the worker (base image pinned to match the exact `playwright` npm version). No installation *wizard* UI inside wp-admin — install remains ZIP-upload or copy-the-folder, per `INSTALL.md` |

`/import` still refuses to silently overwrite an existing product: a
match by source URL/SKU/GTIN/MPN returns `409 duplicate` with
`update`/`duplicate`/`skip` as the caller's explicit choices (spec §26).
