# Installation

## 1. WordPress plugin

1. Copy this directory into `wp-content/plugins/universal-woo-scraper`
   (or upload it as a ZIP from Plugins → Add New → Upload Plugin).
2. Run `composer install --no-dev` inside the plugin directory if you want
   PSR-4 class loading via `vendor/autoload.php`; if you skip this step,
   `includes/autoload.php` provides an equivalent minimal autoloader
   automatically, so plain-ZIP installs still work.
3. Activate **WooCommerce**, then activate **Universal WooCommerce
   Product Scraper & Importer**. Activation creates the plugin's five
   custom tables via `dbDelta` (see `ARCHITECTURE.md` §4).
4. Go to **WooCommerce → Universal Scraper → Dashboard** to confirm the
   menu loaded.

## 2. Scraper worker (Playwright) — Stage 3, deployed separately

The worker is a standalone Node.js service; it does not run inside
WordPress and does not need PHP. It is being built in Stage 3 of the
roadmap in `ARCHITECTURE.md`. Once available, deployment will be:

```bash
cd worker
npm install
npx playwright install chromium
npm start          # or: node index.js
```

Then, in WordPress, go to **WooCommerce → Universal Scraper → Browser
Settings** and set:

- **Worker URL** — e.g. `https://scraper-worker.example.com`
- **Worker API key** — shared secret the worker checks on every request

Use **Browser Settings → Delay between requests** and **Max parallel
jobs** to stay within a reasonable rate against any single target site
(spec §32).

## 3. Verifying the connection

`WooCommerce → Universal Scraper → Import Product`, paste a product URL,
click **Analyze Product**. Until a worker URL is configured (or before
Stage 3 ships), this correctly returns:

> No scraper worker is configured yet. Set the worker URL under Universal
> Scraper → Browser Settings once the Playwright worker (Stage 3) is
> deployed.

That response — not a crash, not a fake product preview — is the expected
behavior at this stage.

## 4. Running the PHP test suite

```bash
composer install
vendor/bin/phpunit
```

Stage 15 will extend this with WooCommerce-integration tests
(`WP_UnitTestCase` against a real `wp-env`/`wp-cli` scaffold with mock
HTML fixtures, spec §78) once there is importer/extractor behavior to
exercise; today's suite covers the SSRF-safe `UrlValidator`.
