---
paths:
  - 'app/Support/Storefront/**'
---

# Storefront

## Storefront catalogue reads go through Catalogue and CatalogueProps
Live Blocks, Product and Category pages read published Products via the scoped `Catalogue` (Search queries through `ProductSearch`, eager-loading `Catalogue::CARD_RELATIONS`) (queried once per request, eager-loads category/brand/tags/media) and turn them into props only through `CatalogueProps` (card/detail/category; every key mapped, blank characteristics sent as null/[]). Prices go to the client as each Product's own Tiers in minor units; the shared `minorUnitsPerTaka` prop + `useTaka()` formats them — never divide by 100 in a component. Search matches with `whereLike` (ILIKE on PostgreSQL, LIKE on SQLite) and strips `%`/`_`/`\` instead of escaping, since SQLite has no default LIKE escape. robots.txt is a route (`SitemapController`), so never re-add public/robots.txt.
