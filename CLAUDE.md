# Food Package — Developer Notes

## Status (2026-08-16): FoodComponent + FoodAssembly built, food_info + food_intake importers built, FoodMovement not started

`admin/schema_inc.php` (permissions, `registerContentObjects`, `external`/`nutrition`/`quantity`/
`type` xref groups), `includes/classes/FoodComponent.php` + `FoodAssembly.php`, and
`import/ImportFoodInfo.php`/`ImportFoodIntake.php` + `load_food_info.php`/`load_food_intake.php` +
`templates/import_results.tpl` written. Installed and running on rdmcloud — `food_info` importer
run successfully (1163 created); schema pushed by hand into the live DB while pre-production (see
"Dev-stage schema workflow" below), not yet through a proper install/upgrade cycle.

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
- **FoodAssembly** (≈ StockAssembly, but no map table — see below) — **one content type, no
  separate classification xref**. The meal-type code itself (`BREAKFAST`/`LUNCH`/`DINNER`/`MSNK`/
  `ESNK`) IS the multi=1 `liberty_xref` item type that holds the ingredient list — a Breakfast
  assembly's rows are all `item='BREAKFAST'`, which code populated tells you the type. Each row:
  `xref`=the `FoodComponent`'s content_id, `xkey`=quantity (grams or ml, whatever the referenced
  component's own base unit is), `xorder`=position. `RECIPE`/`FAVOURITE`/`MEAL` (kitting-side,
  like Stock's `PBLD`) follow the same pattern later, not registered yet.
  - **No `food_assembly_map` table** — first drafted one mirroring what looked like Stock's own
    map-table BOM mechanism, corrected before writing it (see `stock/CLAUDE.md`'s own note on
    `stock_assembly_map` for why that turned out not to be a pattern worth copying). Plain
    multi=1 xref rows do the whole job here.
  - One `FoodAssembly` per `(start_time, meal_type)` group in `food_intake.csv` (all items eaten
    together share identical `start_time`+`create_time` to the millisecond — that's the grouping
    key). The meal's actual eaten time lives in `liberty_content.event_time` (a real column,
    distinct from `created`/`last_modified`) — same role Stock's own movement design already
    established ("received from lc.event_time"). "Day" is a report/filter over these, not its own
    record — matches how `list_stock.php` already aggregates Stock movements by date.
  - `meal_type` is a flat 5-value Samsung enum, not an xorder-thousands scheme: `100001`
    Breakfast, `100002` Lunch, `100003` Dinner, `100004` Morning snack, `100006` Evening snack
    (`100005` unused).
  - `food_intake` ↔ `nutrition.csv`: **`nutrition.csv` is dropped as an import source entirely**
    (was going to be joined via `meal_type` + nearest `create_time`, no shared key existed — see
    project_food_package_scoping memory for the full join analysis if it's ever needed again for
    a different reason). Once `FoodComponent` nutrition is per-100g and `FoodAssembly` quantities
    are grams/ml, per-meal totals are computed (`Σ grams/100 × per_100g_value`), not imported —
    more trustworthy than Samsung's own snapshot once portions get hand-corrected anyway, and it
    removes that fragile join altogether.
- **FoodMovement** (≈ StockMovement) — the **pantry ledger**, kept separate from FoodAssembly
  (a diary meal instance says what was combined, FoodMovement says how much you actually have —
  different questions, don't collapse them). `movement_in` = a receipt (Lidl/Waitrose — zero
  Samsung source data, entered by hand). `movement_out` = generated via `explodeFromAssembly()`
  when a *new* diary meal gets logged going forward. **Historical `food_intake` import does NOT
  generate FoodMovement records** — the imported diary stands alone as history; FoodMovement only
  starts existing from a manual stocktake baseline forward, otherwise stock levels go deeply
  negative trying to reconcile 500+ days of consumption against zero purchase history.
  Quantity-typing is built (`foodcomponent`'s `quantity` xref group, see below) — genuinely
  Stock's `SGL`/`PCK`/`SHT`/`VOL` problem again: apples bought "6" (count) but weighed (120g) when
  eaten; broccoli bought "0.5kg" but drawn in ~100g partial amounts; cereal bought "a box" but
  really tracked in grams once opened. Two different numbers for the same real event (movement:
  `-1` apple in `SGL`; diary: `120g` weighed) is intentional, not something to reconcile.
- **Shopping list** — modeled on `stock`'s `list_stock.php` shortages-by-supplier report,
  reframed as shortages-by-shop.

### Nutrition xref design (`foodcomponent`'s `nutrition` group)
From `food_info.csv` only (not `nutrition.csv`, see above). **Basis: per-100g, curated.**
Samsung's own basis varies per row — plain "Broccoli" is `metric_serving_amount=91g`, branded/
packaged items (Tesco/Waitrose/Lidl) are already `100g`, and **76/1202 rows (2026-08-14 export)
have `metric_serving_amount` 0 or blank** — mostly ready-meals/restaurant dishes with a bare
per-meal total and no weight to normalize against at all. Import rule: normalize rows with a
usable gram basis via `value/metric_serving_amount*100` (confirmed exact against Samsung's own
embedded "89 kcal, per 100g" text on some rows); flag the rest for manual portion-weight curation
rather than guessing — same queue as the missing-`FIBR` gap below.

**Units — revised 2026-08-16 once actually building the importer**: `food_info.csv` only supplies
a narrower nutrient set than originally assumed (no vitamin_e/k/b12/biotin/folate/magnesium/zinc/
etc. — those only existed in the now-dropped `nutrition.csv`). Given what `food_info` actually
has, a single blob-wide unit doesn't hold: `FAT` (fat subfields + cholesterol) and `MIN`
(potassium/calcium/iron) are **integer mg** — every field food_info supplies for those two is
mg/g-scale, no mcg-scale field exists in either once nutrition.csv is out of the picture. `VIT` is
genuinely mixed per sub-field: `vitamin_d_mcg` (confirmed — 15-20 raw value can only be mcg, 15mg
would be ~600× RDA), `vitamin_c_mg` (confirmed via magnitude check), `vitamin_a_mcg` (assumed by
the same toxicity-bound reasoning as vitamin_d, **not independently confirmed — spot-check after
first import**) — stored with unit-suffixed JSON keys rather than one blob-wide unit, since the
native units don't actually agree. Scalar items (`CAL`/`PROT`/`CARB`/`FIBR`/`SUGR`/`SOD`) stay
integer mg as before (confirmed lossless); `CAL` is plain integer kcal, not a mass; `SOD` is
already mg-scale in `food_info` (not grams), no ×1000 needed there unlike `PROT`/`CARB`/`FIBR`/
`SUGR`.

Scalar xref_items: `CAL` calorie, `PROT` protein, `CARB` carbohydrate, `FIBR` fibre, `SUGR` sugar,
`SOD` sodium (needs a salt-value conversion helper: `sodium = salt / 2.5` by weight, for curation
from UK labels which show salt not sodium). Compound JSON xref_items (`liberty_xref.data` CLOB):
`FAT` → {total/saturated/mono/poly/trans/cholesterol, all `_mg`}, `VIT` → {vitamin_a_mcg/
vitamin_c_mg/vitamin_d_mcg}, `MIN` → {potassium/calcium/iron, all `_mg`}. No generic JSON-xref
mechanism exists in liberty yet — build it food-package-local first (own templates, same
per-package override dispatch stock's BOM/supplier templates already use), only promote to
liberty once a second package wants it.

Five-a-day (fruit/veg portions, no Samsung source data) is a per-FoodComponent portion tag, needs
sourcing separately (e.g. NHS "what counts as one portion" guidance).

**Known data-quality gap**: Samsung's own `food_info` is incomplete for a real chunk of items
(fibre confirmed missing on some existing entries) — the importer needs to flag FoodComponents
with missing core scalar values for review, not just silently import nulls.

### Quantity xref design (`foodcomponent`'s `quantity` group — pantry/movement tracking)
Entirely separate axis from nutrition above — don't conflate them. Built in `schema_inc.php`:
- `SGL` — single unit/count, reused from Stock as-is
- `WT` — weight in grams — new, Stock's `SHT` (sheet-cutting, PCB-specific) doesn't fit food; no
  food use for `SHT` has come up yet either
- `VOL` — volume in ml, reused from Stock as-is
- **`WT`/`VOL` are set by `import/ImportFoodInfo.php`** from `food_info.csv`'s own
  `metric_serving_amount`/`unit` (the same basis nutrition gets normalized against) — added
  2026-08-16 after finding the write was never actually wired up despite being the documented
  design (only 1 component in the live DB had a `WT` row, 0 had `SGL`/`VOL`, found while adding a
  unit suffix to `FoodAssembly`'s ingredient display). Only written when that basis is real, not
  the assumed-100g/ml curation case — an assumed basis doesn't tell us weight vs volume. `SGL` is
  never set by the importer (Samsung has no per-unit/count concept) — hand-set only.
- `PCK` — not a competing type, a stored pack-size multiplier (`template='value'`, matching
  Stock's own distinction) feeding into whichever of `SGL`/`WT`/`VOL` the component uses
- `REM` — live remaining balance in the component's own declared type. **Stored/mutable, not
  derived by summing FoodMovement** — deliberate divergence from Stock's always-aggregate
  `list_stock.php` model, because Food's ledger is known-incomplete (no historical movement_out).
  **Also doing double duty as the curation-progress tag until FoodMovement needs it for real**:
  `xkey_ext` = `'REVIEW'` is the outstanding-work flag *itself* — "this needs review", not
  "reviewed and fixed" (took a few rounds to nail the polarity, see the 2026-08-16 session log
  below; renamed from `'CORRECT'` 2026-08-17 — read ambiguously as "this is correct" rather than
  "needs correcting", flagged as confusing especially for non-English speakers) — set by the
  importer at import time, cleared by hand via `edit_component.tpl`'s tick floaticon once a
  component is actually fixed. `data` (on `liberty_content`, not `REM` itself) = a free-text note,
  decoupled from that status — starts as the importer's own curation reason, but
  editable/replaceable at any time without affecting the report, and other components will gain
  notes there for unrelated reasons too.

### Curation progress: `list_review.php`
Live DB report (not a CSV dump) — joins `foodcomponent` against its `REM` xref, ranks by how many
`FoodAssembly` item-xref rows reference each one (`xref`=content_id, across
`BREAKFAST`/`LUNCH`/`DINNER`/`MSNK`/`ESNK`), so the highest-impact fixes surface first. Confirmed
useful in practice: 1109 flagged `food_intake` rows traced back to only 270 distinct components,
top 2 alone a third of all flags — nearly all rooted in the same cause (`food_info`'s own
`metric_serving_amount`/`unit` being a Samsung serving-code or blank, no real gram weight
anywhere in the chain). **The importer sets `xkey_ext='REVIEW'` on every flagged row at import
time** (re-running against the same known export re-flags the same ~334 rows — that's the real,
expected to-do list right after import, not something that starts empty) — only *clearing* it is
a manual step, done once a component is actually fixed. (File was `list_corrections.php` until
2026-08-17, renamed alongside the flag value — see `isFlaggedForReview()`/`clearReviewFlag()`/
`getReviewXrefId()` on `FoodComponent`, formerly `...Correction...`.)

## food_info importer notes (2026-08-16)

`create_time`/`update_time` map straight to `liberty_content`'s created/last_modified.

`provider_food_id` → `PFID` xref item (`external` group, alongside `DUID`), raw text. Its
`quickinput-`/`fatsecret-`/`default-fatsecret-` prefix is the provenance signal (`custom`/
`info_provider` are blank on every quickinput row, not usable). **Quickinput entries are
deliberate manual fixes for a fatsecret data gap** (Samsung's edit UI can't fix an existing entry
in place, so a new one gets added instead) — don't treat `quickinput-` as generically
lower-quality for curation-review purposes. The UUID inside `provider_food_id` has no relational
role anywhere (`food_intake.food_info_id` always matches `food_info.datauuid`, confirmed
directly, never the `provider_food_id` UUID).

**Only import `food_info` rows referenced by at least one `food_intake.food_info_id`** — build
that reference set first, skip unreferenced rows (abandoned duplicates from the edit-workaround
above do occur, confirmed one directly).

**Built**: `import/ImportFoodInfo.php` (row logic, CSV parsing, normalization, `LibertyXref::
store()`-based upsert via a `foodStoreXref()` helper) + `import/load_food_info.php` (entry point,
mirrors `stock/import/load_simple_components.php`'s bootstrap/permission-check/results-template
shape) + `templates/import_results.tpl`. Locates the newest paired export in `FOOD_IMPORT_PATH`
by globbing `com.samsung.health.food_info.*.csv`. Flagged rows also written to
`storage/food/curation_needed.csv`.

**Two real bugs fixed while building this** (both in the 2026-08-15 `FoodComponent.php`, neither
previously exercised): `lookupByDatauuid()` referenced a nonexistent `liberty_xref.x_group`
column (fixed — join `liberty_xref_item` instead); the constructor was one-param, which silently
breaks under `LibertyBase::getNewObject()`'s hardcoded `new $class(null, $contentId)` call — see
[[reference_liberty_content_constructor]] memory, applies to any future `LibertyContent`
subclass in this stack, not just Food.

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
export's `health_lester_<date>` split) has an architecture sketch as of 2026-08-18 —
`health/CLAUDE.md` + `health/MANUAL.md` — but is not yet scaffolded (no repo, no schema). Its
`jsons/` detail tier turned out to need a genuinely different JSON xref shape than this package's
planned `FAT`/`VIT`/`MIN` flat-object template (array-of-objects/time-series, not object-of-
scalars) — see `health/MANUAL.md`'s shape taxonomy before assuming Food's `template='json'` design
covers it.
