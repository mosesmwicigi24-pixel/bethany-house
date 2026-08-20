# Legacy POS → hub product-name map

`legacy-product-name-map.json` maps lowercased, trimmed product names from the
legacy CI3 POS (`bethanyhouse.pos_sales` on the VPS MySQL) to hub product ids.
It exists so re-imports of the legacy seasonal history converge on the same
product links instead of depending on whoever runs them remembering the
decisions.

Used by:

    php artisan seasonal:import-legacy <export.json> --map=database/legacy/legacy-product-name-map.json

The import upserts on (sale_date, product_name), so re-running with an
extended export or an extended map is safe and idempotent.

Applied to production 2026-08-20: 9,494 rows (2020-11-02 → 2026-06-30,
KES 54,695,868.04 — reconciled to legacy takings net of delivery, pro-rata
across lines). 97.0% of rows and 98.1% of revenue are product-linked.

The ~32 names deliberately NOT mapped, and why guessing would be worse:

- **Services / non-products** (should never fuel product projections):
  White Rose Cleaning services, Lease, Embroidery, Love Packaging Bags,
  Aluminium cover, Suit Butler Garment Bags.
- **Books with no hub SKU** (map them only if the titles are ever added):
  Kingdom Mindset, Prayer Rain, Meditation for Spiritual Growth, Becoming a
  Leader, Fire Rain, Navigating Parenting as a Single Mother, My Favorite
  Bible Story, My First Promise Bible, Good News Bible, Holy Bible with
  Additional Notes, Holy Bible RSV, kikuyu bible, Study bible hard cover.
- **Too ambiguous to attribute** ("bottle" had 112 sale-days — to WHICH
  bottle?): bottle, Platter, Basic Communion Pack, Black Gown - Executive,
  Chimere and Rochet, Barretta, kinandu, holy water bottle 200ml,
  Golden/Silver Communion Refiller Jug, Tirosh Grape Juice, Portable Bread
  Holders.

Their history is preserved name-keyed in `legacy_sales_daily`; add a line to
the map and re-run the import if any of them gains a hub product.
