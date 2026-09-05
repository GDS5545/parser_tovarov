# Installation

## 1. WordPress plugin

**Option A — packaged ZIP (recommended):** run `./build.sh` from the repo
root. It builds a clean copy in a temp directory, runs
`composer install --no-dev --optimize-autoloader` there (your own working
tree's `vendor/` is never touched), and writes
`universal-woo-scraper-<version>.zip` next to it — upload that via
Plugins → Add New → Upload Plugin. The ZIP excludes `worker/`, `tests/`,
and dev tooling; it's exactly what a WordPress install needs.

**Option B — copy the folder directly:**

1. Copy this directory into `wp-content/plugins/universal-woo-scraper`.
2. Run `composer install --no-dev` inside it if you want PSR-4 class
   loading via `vendor/autoload.php`; if you skip this step,
   `includes/autoload.php` provides an equivalent minimal autoloader
   automatically, so a plain copy still works.

Either way, then:

3. Activate **WooCommerce**, then activate **Universal WooCommerce
   Product Scraper & Importer**. Activation creates the plugin's five
   custom tables via `dbDelta` (see `ARCHITECTURE.md` §4).
4. Go to **WooCommerce → Universal Scraper → Import Product** and try a
   URL. **That's it — nothing else to deploy.** By default the plugin
   fetches pages with plain HTTP (`Scraper\HttpEngine`), which works for
   most server-rendered stores. Only continue to §2 below if a specific
   site needs JavaScript rendering.

## 2. Scraper worker (Playwright) — optional, for JS-rendered sites

Skip this section unless a site you're importing from renders its product
data with JavaScript (React/Vue/Next/Nuxt), needs a "Load more" button
clicked to page through a category, or you want debug screenshots. The
worker is a standalone Node.js service; it does not run inside WordPress
and does not need PHP. Two ways to run it:

**Docker (recommended for production):**

```bash
cd worker
echo "WORKER_API_KEY=$(openssl rand -hex 32)" > .env
docker compose up -d --build
```

The image is `mcr.microsoft.com/playwright:v1.63.0-jammy` (pinned to match
the exact `playwright` npm version in `package.json` — Chromium ships
inside that base image, so the two must stay in lock-step; if you bump
one, bump the other).

**Plain Node.js:**

```bash
cd worker
npm install
npx playwright install chromium --with-deps
cp .env.example .env   # set WORKER_API_KEY to a long random secret
npm start
```

See `worker/README.md` for the full API and its security notes. Either
way, then in WordPress go to **WooCommerce → Universal Scraper → Browser
Settings** and set:

- **Worker URL** — e.g. `https://scraper-worker.example.com`
- **Worker API key** — shared secret the worker checks on every request

Use **Browser Settings → Delay between requests** and **Max parallel
jobs** to stay within a reasonable rate against any single target site
(spec §32).

## 3. Verifying it works

`WooCommerce → Universal Scraper → Import Product`, paste a product URL,
click **Analyze Product**. This fetches the page (plain HTTP by default,
or through the worker if one is configured), runs it through the
extraction pipeline, and shows an editable preview (name, SKU, price,
categories, attributes, images) with low-confidence fields outlined in
red/amber so you know what to double check before importing, plus a note
saying which engine handled the fetch. Click **Import** to create the
WooCommerce product; a source URL/SKU/GTIN/MPN match against an
already-imported product returns a prompt to update, duplicate, or skip
rather than silently overwriting anything.

If the preview comes back with little or no data and the note says it was
fetched via plain HTTP, that site likely renders its product data with
JavaScript — deploy the worker (§2) and set its URL under Browser
Settings, then try Analyze again.

Optionally, set an API key under **AI Settings** (Anthropic or OpenAI) to
enable the Stage 12 fallback: it only calls the API when name/SKU/brand/
price/description are still missing after the conventional extractors run,
on a size-capped, script/style-stripped copy of the page — never for a
page the conventional pipeline already resolved confidently.

## 4. Running the test suites

```bash
composer install
vendor/bin/phpunit

cd worker && npm install && npm test
```

`phpunit` covers `UrlValidator` (SSRF), `PriceParser`, `AttributeNormalizer`,
`HttpEngine` (JSON-LD extraction, listing-link filtering, SSRF rejection),
`ImportSnapshot`, `ContentCleaner`/`AiExtractor`, and an extractor-merge
integration test (JSON-LD price outranking a DOM guess, specification
tables becoming attributes, breadcrumbs becoming categories). `npm test`
covers the worker's own SSRF guard. Stage 15 adds a full WP-integration
harness (`wp-env`/`wp-cli`, mock HTML fixtures per spec §78) against a
real WooCommerce install; today's suites are unit-level and don't require
WordPress or a browser to run.
