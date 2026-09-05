# UWS Scraper Worker

Stage 3 of the Universal WooCommerce Product Scraper & Importer roadmap
(see `../ARCHITECTURE.md`). A small Express service that drives headless
Chromium via Playwright and returns a page's raw rendered HTML, JSON-LD
blocks, and console/network diagnostics. It has no knowledge of WordPress
or WooCommerce — all product-model reasoning happens in the PHP plugin,
which calls this service through `Uws\Scraper\PlaywrightHttpEngine`.

## Run it

```bash
cd worker
npm install
npx playwright install chromium --with-deps
cp .env.example .env   # set WORKER_API_KEY to a long random secret
npm start
```

The server listens on `PORT` (default 4790). `GET /health` needs no auth;
every other route requires header `X-UWS-Api-Key: <WORKER_API_KEY>`.

## API

### `POST /fetch`
```json
{ "url": "https://example.com/product/x", "options": { "wait_until": "networkidle", "screenshot": false } }
```
Returns `{ html, final_url, status_code, json_ld_blocks, console_errors, network_errors, screenshot_base64 }`.

### `POST /discover`
```json
{ "url": "https://example.com/category/pumps/", "options": { "max_pages": 3, "pagination_strategy": "query" } }
```
Returns `{ "urls": [...] }` — candidate product links found across the
requested pages (`pagination_strategy`: `query` → `?page=N`, `path` →
`/page/N/`, `load_more`/`infinite_scroll` → clicks/scrolls, `none` →
first page only).

## Security

- Every request (except `/health`) requires the shared `WORKER_API_KEY`.
  If it isn't set, the worker refuses all requests rather than running open.
- `src/ssrfGuard.js` re-validates every URL (scheme + a local/private-IP
  hostname blocklist) as defense-in-depth. WordPress's
  `Uws\Security\UrlValidator` (DNS-resolved, full private-range check) is
  the primary guard and must run before a URL ever reaches this worker.
- This worker does not attempt to bypass CAPTCHAs, logins, paywalls, or
  anti-bot protections (spec §36). If a page requires one, `/fetch` still
  returns whatever Chromium rendered (usually the challenge page itself);
  the PHP extraction pipeline is expected to report low confidence rather
  than fabricate data.

## Deploying separately from WordPress

This directory has no dependency on PHP/WordPress and can run on any host
with Node.js 18+ (a small VPS, a container, etc.) — see the Docker note in
`../ARCHITECTURE.md` §67 for the planned container packaging. Point
WooCommerce → Universal Scraper → Browser Settings → Worker URL at
wherever this ends up running, over HTTPS in production.
