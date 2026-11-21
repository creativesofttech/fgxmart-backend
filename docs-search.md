# Storefront search

The public `GET /api/v1/search/suggestions?q=...` endpoint accepts 3–100 characters and returns at most six products with current image, price, brand and category metadata. It is rate limited to 120 requests per minute per client. It requires no account or external search service.

`ProductSearch` normalizes case, accents and punctuation. Every query word must match product-name, SKU, active-brand or active-category/main-category words. Exact and prefix matches rank above close spelling matches. Short words allow one edit; words of eight or more characters allow two, and an adjacent letter transposition counts as one. Numeric tokens are not fuzzily expanded. SKUs support their separated or compact form. This is bounded spelling tolerance, not semantic search.

Public `/products?search=...` uses the same matches and ranks by relevance unless an explicit sort is supplied. Category, brand, price and collection filters still apply. Admin catalogue search retains its existing direct name/SKU matching. Draft products cannot appear in public suggestions or full results.

The service caches lightweight searchable catalogue metadata for 60 seconds using Laravel's configured cache store; prices and images are fetched from the database when returning suggestions. Model saves/deletes for products, brands and categories invalidate the cache, including after transaction commit. Direct SQL/bulk changes which bypass model events become visible when the cache expires, or after `php artisan cache:clear`.

No migration, Composer dependency, index import, queue worker or additional hosting service is needed. This implementation scans the cached metadata, so cost grows with catalogue size; profile it as the catalogue grows and replace the service with a dedicated search engine when needed. Do not assume Elasticsearch-scale throughput.

Tests cover input bounds, prefix/typo/transposition matching, ranking, price sorting, six-row suggestions, full pagination, inactive/draft exclusion, and metadata invalidation. Frontend browser tests cover the three-character threshold, keyboard and mouse navigation, mobile sizing, empty/error states and superseded-request cancellation.
