# Food Package — Developer Notes

## Status (2026-08-15): skeleton only

Package exists so bitweaver recognizes it (`includes/bit_setup_inc.php` registered, empty
`admin/schema_inc.php`) — no schema, no content classes, no pages built yet. Not yet installed on
any site.

## Architecture plan

Modeled closely on the `stock` package (see `stock/CLAUDE.md` and `liberty/CLAUDE.md` for the
patterns being reused), but a **separate package**, not an extension of `stock` — food and stock
may end up on different domains, and groceries shouldn't mix with electronics parts in one list.

- **FoodComponent** (≈ StockComponent) — an ingredient, with a `nutrition` xref_type group.
- **FoodAssembly** (≈ StockAssembly) — a recipe/meal: a BOM of ingredient components with
  quantities.
- **FoodMovement** (≈ StockMovement) — consumption/purchase ledger (eaten/bought), reusing
  `explodeFromAssembly()`-style logic to decrement a whole recipe's ingredients when a meal is
  logged as eaten. Also carries the same `nutrition` xref group as a per-meal snapshot.
- **Shopping list** — modeled on `stock`'s `list_stock.php` shortages-by-supplier report,
  reframed as shortages-by-shop.

### Nutrition xref design
One `nutrition` xref_group, shared across FoodComponent and FoodMovement via `LibertyXref`'s
dual-guid scoping (`mContentTypeGuid` + `mPackageGuid`). Scalar xref_items for the values worth
individually browsing/tidying: `CAL` calorie, `PROT` protein, `CARB` carbohydrate, `FIBR` fibre,
`SUGR` sugar, `SOD` sodium (needs a salt-value conversion helper: `sodium = salt / 2.5` by
weight). Compound JSON xref_items (using the existing-but-currently-unused `liberty_xref.data`
CLOB column) for the long tail: `FAT` → {total/saturated/mono/poly/trans}, `VIT` → {vitamin
panel}, `MIN` → {mineral panel}. No generic JSON-xref mechanism exists in liberty yet — build it
food-package-local first (own templates, same per-package override dispatch stock's BOM/supplier
templates already use), only promote to liberty once a second package wants it.

Five-a-day (fruit/veg portions, no Samsung source data) is a per-FoodComponent portion tag, needs
sourcing separately (e.g. NHS "what counts as one portion" guidance).

**Known data-quality gap**: Samsung's own `food_info` is incomplete for a real chunk of items
(fibre confirmed missing on some existing entries) — the importer needs to flag FoodComponents
with missing core scalar values for review, not just silently import nulls.

## Data source: Samsung Health export

No live API — periodic manual full-CSV re-export. `~/Personal/Health/Samsung Health/` holds one
dated folder per export; `split_health.sh` there splits each into `food_lester_<date>` (7 files:
food_info, food_intake, food_favorite, food_frequent, food_goal, nutrition, water_intake) and
`health_lester_<date>` (everything else) — **the food importer should read from
`food_lester_<date>`, not the raw combined export**. Every record carries a `datauuid` +
`update_time` — import strategy is upsert keyed on `datauuid`, skip unchanged `update_time`, safe
to re-run against every new export. CSVs must be read by header name, not positional column
index — older exports have fewer columns (strictly additive over time, confirmed 2025-03 vs
2026-08 headers), never renamed/removed.

The March 2025 export (`food_lester_20250321123361`) is a safe, much smaller dev/test dataset —
same headers as the current export for every food-relevant file.

## Companion package: health

A `health` package (weight/blood-pressure/heart-rate/sleep/exercise, from the same Samsung Health
export's `health_lester_<date>` split) is planned but not yet scoped or scaffolded.
