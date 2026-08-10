# src/assets

Vendored data files. Anything here is **imported and bundled**, never fetched at
runtime — the hub runs behind a strict CSP and ships an offline service worker,
so a CDN URL is not an option.

## world-countries-110m.json

World country outlines for the Storefront Insights choropleth
(`src/pages/insights/WorldMapHero.tsx`).

| | |
|---|---|
| Source | [`world-atlas`](https://github.com/topojson/world-atlas) v2 `countries-110m.json` |
| Underlying data | Natural Earth 1:110m Admin 0 Countries — **public domain** |
| `world-atlas` licence | ISC |
| Format | TopoJSON, one object (`countries`), 177 geometries |
| Size | ~90 KB raw / ~28 KB gzipped |

Two changes were applied to the upstream file:

1. **`properties.iso` added** — the ISO-3166-1 **alpha-2** code, derived from the
   atlas's numeric `id`. Our analytics rows (`site_visits.country_code`,
   `orders.*_country_code`) are alpha-2, and the upstream file carries numeric
   ids only, so without this the choropleth cannot join to the data. Three
   geometries have no alpha-2 and are rendered from `properties.name`:
   N. Cyprus, Somaliland, Kosovo.
2. **Re-quantized to a 1e4 grid** and the unused `land` object dropped. That is
   a ~4 km cell, i.e. ~0.05 px on the 800 px-wide map — no visible change, and
   it takes the gzipped payload from 39 KB to 28 KB.

To regenerate (e.g. to move to the 1:50m outlines), take
`world-atlas@2/countries-110m.json`, map numeric ids to alpha-2 with
`i18n-iso-countries`, then `topojson-server.topology({ countries }, 1e4)`.
