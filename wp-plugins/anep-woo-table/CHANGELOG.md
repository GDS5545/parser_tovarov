# Changelog

## 3.7.1

Fix: "Fatal error: Allowed memory size ... exhausted" in `wp-includes/functions.php`
when opening a product category that has a large tree of subcategories with products.

Root cause: `get_category_attributes()` builds the filter/column attribute list by
calling `wc_get_product()` for every product (and every variation of every variable
product) in the whole category branch. "Large category fast mode" is supposed to skip
this by reading WooCommerce `pa_*` attribute taxonomies instead, but it only recognizes
global taxonomy attributes. When a catalog stores its characteristics as local/custom
product attributes (typical for catalogs built by import/parsing rather than manually
in WooCommerce), `get_large_category_attribute_data()` finds nothing and the code fell
through to the full, unbounded per-product/per-variation scan anyway — for every
product in the entire branch, on every page view, before any filter was even selected.
On a category with a large enough subcategory tree this alone was enough to exceed the
PHP memory limit.

The same unbounded per-product scan pattern also existed in `build_fast_filter_index()`
(runs once a filter/attribute sort is used) and a few smaller helpers
(`filter_product_ids_by_selected()`, `available_term_counts_for_filter()`,
`sort_product_ids_by_attribute()`), all of which call `wc_get_product()` in a loop
without ever releasing WordPress's runtime object cache, which accumulates every
scanned post/product for the rest of the request.

Changes:
- In fast mode, when no `pa_*` attributes are found, the local-attribute scan is now
  capped to the first `large_category_threshold` products instead of scanning the
  entire branch.
- Added `release_bulk_scan_memory()`, called every 200 iterations in all the
  per-product/per-variation scan loops above. It flushes WordPress's non-persistent
  runtime object cache (`wp_cache_flush_runtime()` when available, otherwise
  `wp_cache_flush()` only when no persistent cache backend is in use) so memory doesn't
  keep accumulating across a long scan.
