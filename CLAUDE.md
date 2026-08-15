# Food Package — Developer Notes

## Status (2026-08-15): FoodComponent built, FoodAssembly/FoodMovement not started

`admin/schema_inc.php` (permissions, `registerContentObjects`, `external`+`nutrition` xref
groups) and `includes/classes/FoodComponent.php` written, modeled on `StockComponent.php`. Not
yet installed on any site, no importer built yet.

## Architecture plan

Modeled closely on the `stock` package (see `stock/CLAUDE.md` and `liberty/CLAUDE.md` for the
patterns being reused), but a **separate package**, not an extension of `stock` — food and stock
may end up on different domains, and groceries shouldn't mix with electronics parts in one list.

- **FoodComponent** (≈ StockComponent) — an ingredient, with a `nutrition` xref_type group.
  **No `food_component` table** — content_id only (Stock's own `stock_component`/`stock_assembly`
  tables were retired 2026-06-01 for the same reason: a table that only aliases content_id to a
  second sequential ID is pointless). The Samsung `datauuid` needed for re-import dedupe lives as
  a `DUID` xref item in a package-level `external` group instead of a schema column — same
  pattern as Stock's `KLID` (Kitlocker ID Code).
- **FoodAssembly** (≈ StockAssembly) — **one content type, three roles**, distinguished by a
  single classifying xref (mirrors StockMovement's `reference` group: REQN/PBLD/TRANS/ORDER):
  - `RECIPE` — a reusable named dish (BOM of ingredients + quantities)
  - `FAVOURITE` — a reusable named combo (the clean version of what `food_favorite` was reaching
    for)
  - `BREAKFAST`/`LUNCH`/`DINNER`/`MSNK`/`ESNK` — a **diary meal instance**, one per
    `(start_time, meal_type)` group in `food_intake.csv` (all items eaten together share
    identical `start_time`+`create_time` to the millisecond — that's the grouping key). "Day" is
    a report/filter over these, not its own record — matches how `list_stock.php` already
    aggregates Stock movements by date without a day object.
  - `meal_type` is a flat 5-value Samsung enum, not an xorder-thousands scheme: `100001`
    Breakfast, `100002` Lunch, `100003` Dinner, `100004` Morning snack, `100006` Evening snack
    (`100005` unused). `xorder` is assigned independently at import to order items *within* one
    meal (1,2,3,4), no thousands convention needed.
  - `food_intake` ↔ `nutrition.csv` join: no shared `datauuid`/`client_data_id` (the latter is
    empty on every row) — join on `meal_type` + nearest `create_time` (~1s tolerance),
    cross-checked against calorie-sum. `nutrition.start_time` is a trap — it tracks
    `food_intake.create_time`, not `food_intake.start_time`, despite the shared field name.
- **FoodMovement** (≈ StockMovement) — the **pantry ledger**, kept separate from FoodAssembly
  (don't collapse this in — a diary meal instance says what was combined, FoodMovement says how
  much you actually have, different questions). `movement_in` = a receipt (Lidl/Waitrose — zero
  Samsung source data, entered by hand). `movement_out` = generated via `explodeFromAssembly()`
  when a *new* diary meal gets logged going forward. **Historical `food_intake` import does NOT
  generate FoodMovement records** — the imported diary stands alone as history; FoodMovement only
  starts existing from a manual stocktake baseline forward, otherwise stock levels go deeply
  negative trying to reconcile 500+ days of consumption against zero purchase history.
  **Open/unbuilt**: needs a `STOCK` xref_item for quantity-on-hand, which hits Stock's own
  unmet unit-conversion problem — milk purchased in litres, consumed 140ml at a time. Stock's
  `PCK`/`PRT` + `xkey_ext` (pack size) is the closest precedent but only handles fixed pack
  counts, not general unit conversion (L→ml, kg→g). Needs real design work.
- **Shopping list** — modeled on `stock`'s `list_stock.php` shortages-by-supplier report,
  reframed as shortages-by-shop.

### Nutrition xref design
`nutrition` xref_group on `foodcomponent` (reference facts, from `food_info.csv`) — the
per-meal snapshot from `nutrition.csv` was originally planned to reuse this same group on
FoodMovement, but with FoodMovement now decoupled from the diary/meal-instance role, that
snapshot's home is the diary FoodAssembly instance instead (not yet built — resolve when
FoodAssembly is implemented). Scalar xref_items for the values worth individually
browsing/tidying: `CAL` calorie, `PROT` protein, `CARB` carbohydrate, `FIBR` fibre, `SUGR` sugar,
`SOD` sodium (needs a salt-value conversion helper: `sodium = salt / 2.5` by weight). Compound
JSON xref_items (using the existing-but-currently-unused `liberty_xref.data` CLOB column) for the
long tail: `FAT` → {total/saturated/mono/poly/trans}, `VIT` → {vitamin panel}, `MIN` → {mineral
panel}. No generic JSON-xref mechanism exists in liberty yet — build it food-package-local first
(own templates, same per-package override dispatch stock's BOM/supplier templates already use),
only promote to liberty once a second package wants it.

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
