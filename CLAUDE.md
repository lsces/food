# Food Package — Developer Notes

## Status (2026-08-20): FoodComponent + FoodAssembly + FoodMovement (receipts only) built

`admin/schema_inc.php` (permissions, `registerContentObjects`, `external`/`nutrition`/`quantity`/
`type`/`supplier` xref groups on `foodcomponent`/`foodassembly`, plus `reference`/`quantity` on
`foodmovement`), `includes/classes/FoodComponent.php`/`FoodAssembly.php`/`FoodMovement.php`, and
`import/ImportFoodInfo.php`/`ImportFoodIntake.php` + `load_food_info.php`/`load_food_intake.php` +
`templates/import_results.tpl` written. Installed and running on rdmcloud — real CSV imports run
2026-08-19 (1163 components, 1951 diary entries), live on desktop+srv9+srv10. `FoodMovement`
(pantry receipts, see its own section below) built and verified 2026-08-20 but **desktop-only so
far** — its schema is still pre-production and hasn't been pushed to srv9/srv10 yet. Schema is
still hand-pushed via isql into the live DB (see "Dev-stage schema workflow" below), not yet
through a proper install/upgrade cycle.

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
export's `health_lester_<date>` split) has its skeleton scaffolded as of 2026-08-18 — own repo at
`github.com/lsces/health`, symlinked into rdmcloud, no schema/content classes yet, same state
`food` itself was in right after its own 2026-08-15 scaffold. Architecture sketch in
`health/CLAUDE.md` + `health/MANUAL.md`. Its `jsons/` detail tier turned out to need a genuinely
different JSON xref shape than this package's planned `FAT`/`VIT`/`MIN` flat-object template
(array-of-objects/time-series, not object-of-scalars) — see `health/MANUAL.md`'s shape taxonomy
before assuming Food's `template='json'` design covers it.

## Remaining smaller items (checked/corrected 2026-08-18, next to pick up)

Two threads I'd initially mis-listed as open in a todo summary — corrected here so it doesn't
happen again:

- **Meal-type-per-day uniqueness is NOT a gap.** `view_day.php` (built 2026-08-17) shows one slot
  per meal type; an occupied slot only ever offers viewing/editing that existing `FoodAssembly`,
  never a second "log a new meal" action for the same slot — `createForDay()` is the only route
  that creates a new diary entry, and it's keyed to a specific empty slot. No separate
  `verifyAssemblyData()` uniqueness check is needed; the UI structurally can't produce a duplicate.
  (Earlier notes calling this an open "data integrity gap" predate `view_day.php` — stale once it
  landed.)
- **Supplier alias handling is already done.** `foodMatchSupplier()` (built 2026-08-17) already
  matches `Chef Select`/`Chef select`→Lidl and `By Sainsbury's`→Sainsbury's (plus `M&S Food`→Marks
  & Spencer) as its explicit-alias stage, writing the correct `SUP` xref — this was flagged "not
  yet built" in an earlier same-day note and never corrected once it was. Nothing outstanding here
  except the already-deliberate, still-parked deferral of restaurant/takeaway chains and
  manufacturer brands (never promised, not a gap).

**`SOD` salt/sodium edit template — built 2026-08-18.** `SOD` re-registered `template='sod'`
(was `text`); `food/templates/xref/foodcomponent/edit_sod_item.tpl` offers Salt (g)/Sodium (mg)
inputs, salt taking priority when filled in (`sodium_mg = salt_g / 2.5 × 1000`, computed in
`liberty/edit_xref.php`'s save path — a small Food-specific hook alongside the existing
`json_field` one, gated purely on `sod_salt`/`sod_sodium` presence so it can't affect any other
item's save). **No new view template needed** — `getXrefRecordTemplate()`'s hardcoded final
fallback is `view_text_item.tpl`, so SOD's display is untouched, still the plain generic scalar
row. Live-verified on desktop rdmcloud against a real component (content_id 8567): salt-input
path (0.5g → 200mg) and direct-sodium path both confirmed correct, value reverted afterward.

**Five-a-day: `5AD` adjustment factor — registered 2026-08-18, revised same day.** Sourcing turned
out not to be needed at all — Lester's own working model sidesteps per-food NHS portion data
entirely. First cut was a pure yes/no presence marker (row exists = counts); revised same day once
Lester wanted dried fruit's real 30g portion (vs. standard 80g) actually representable, not just
flagged as a known gap: `5AD`'s stored `xkey` is now a **decimal adjustment factor**,
`true_portion_g / 80` — `1` for a standard 80g item, `0.375` for dried fruit (`0.375*80=30g`).
Always stored explicitly when adding (never left blank/defaulted). Reuses the generic `text`
template throughout both revisions — no custom UI needed either time, an arbitrary decimal is just
as easy to store/edit as `'Y'` was.

Day total (not built yet, just the tag) = `SUM(grams eaten that day / (80 × that component's 5AD
value))` across flagged diary items — equivalent to `grams / true_portion_g` per item. **Known,
deliberately unmodelled gap**: NHS also caps juice/smoothies and beans/pulses at 1 portion/day
regardless of quantity — a per-category *daily cap*, not a per-gram adjustment, so the factor
alone can't express it; not addressed.

Hand-pushed to desktop rdmcloud both times (item title, then the live row's value). Live-verified
via the real Add flow against **Strawberries (content_id 7469)** — left flagged for real, not a
test artifact (matches Lester's own worked example: 100g strawberries = 1.25 portions), value
corrected `'Y'`→`'1'` when the scheme changed. **Day-total aggregation/report itself is a separate
next step, not built.**

**BST timestamp correction — folds into the planned clean reinstall, not a separate task.** The
importer itself (`foodParseSamsungTime()`) was already fixed 2026-08-16 to respect
`food_intake.csv`'s own `time_offset` column; only *already-imported* historical rows are still
off by an hour for BST-period entries. Rather than a risky in-place bulk correction, this resolves
itself for free once the planned from-scratch reinstall + reimport cycle happens (see
[[project_food_package_scoping]]'s "Open thread, not decided" section on that reinstall) — every
row gets freshly computed through the now-correct importer, no migration code needed.

## Day-total nutrition report built (2026-08-18)

`view_day.php`/`view_day.tpl` now show real nutrition, not just ingredient lists — per-item,
per-meal (in the panel heading), and a day-total panel at the top, all driven by one canonical
field list: `FoodComponent::NUTRITION_SUMMARY_FIELDS` (Energy/Fat/Saturates/Carbohydrate/Sugars/
Fibre/Protein/Sodium — UK front-of-pack order). `FAT_TOTAL`/`FAT_SAT` are pulled out of `FAT`'s
json-list blob specifically so they sit alongside the plain scalar items in the same summary,
rather than being excluded because they live in a compound field — `VIT`/`MIN` deliberately stay
out (detail-level, not headline).

**New `FoodComponent` methods**: `getNutritionBatch(contentIds)` — one query per page load
regardless of ingredient count (no N+1), decodes `FAT`'s JSON inline so callers never see the
compound-field distinction. `scaleNutrition()`/`sumNutrition()`/`formatNutrition()` compose
item → meal → day from that batch. `formatMg()` — `>=1000mg` renders as `"X.Xg"` rather than raw
mg (e.g. `1500` → `"1.5g"`), applied to every mass field via `NUTRITION_SUMMARY_FIELDS`'s `mass`
flag; `CAL` (kcal, not a mass) never goes through it.

**Incidental fix while rebuilding the template**: `view_day.tpl`'s item rows never showed the
g/ml unit suffix despite `FoodAssembly::getItems()` already computing `quantity_unit` — the
2026-08-17 g/ml display work only reached `view_assembly.tpl`/`edit_assembly.tpl`, this page's own
item table was missed since it renders independently.

Live-verified against 2026-08-14's real Breakfast (Skimmed Milk/Strawberries/Tea with Milk/Malted
Wheaties) on desktop rdmcloud — checked by hand, not just "it rendered": every item's values sum
correctly to the meal total, and the meal total matches the day total (only meal logged that day).

## Nutrition display extended to view_assembly.php and view_component.php (2026-08-18, same day)

Two more of "the few other places" — the third (retrofitting the *raw xref-item* mg values inside
`view_component.php`'s generic Nutrition tab, e.g. per-row `PROT`/`CARB`/etc.) turned out not to
be what was wanted; the actual ask was a summary block *above* that tab, reusing the same
formatted-total shape already built for the day report, not touching the generic xref item
templates at all — simpler, and consistent with how the day/meal totals already look.

**`FoodAssembly::getItemsWithNutrition()`** — factors the per-item-scale + meal-total-sum logic
out of `view_day.php` into a shared method (now a second real consumer, not hypothetical) so
`view_assembly.php` and `view_day.php` don't duplicate it. Returns `items` (each gaining a
`nutrition` key), `total` (formatted, for direct display), and `totalRaw` (unformatted, so
`view_day.php` can still sum several meals' totals into a day total — formatted strings like
`"1.5g"` can't themselves be summed). `view_day.php` refactored onto this method; live-verified the
output is byte-identical to before the refactor, not just "no PHP errors."

**`view_assembly.php`** — same per-item nutrition columns as the day report, plus a `<tfoot>` Total
row (this page only ever shows one meal, so no further meal→day summing needed).

**`view_component.php`** — a per-100g nutrition summary table above the existing xref tabs, using
the component's own stored basis directly (no scaling — nothing's been "eaten" here, it's the
label value itself). Same `getNutritionBatch()`/`formatNutrition()` pair as everywhere else, just
called for one content_id instead of a batch.

Live-verified all three pages on desktop rdmcloud against the same real component (Strawberries,
2026-08-14 Breakfast) — numbers agree exactly across all three views and the day-report refactor.

## 5AD added as the last field in NUTRITION_SUMMARY_FIELDS (2026-08-18, later)

Genuinely different in kind from the other eight — `5AD`'s stored value is a fixed portion-size
*adjustment factor* (`true_portion_g/80`), not a per-100g additive nutrient, so it can't go through
the shared `value*grams/100` scaling. `FoodComponent::scaleNutrition()` special-cases `5AD`:
`portions = grams/(80*factor)` (0 if unflagged). Once computed, portions sum normally like
everything else — no change needed to `sumNutrition()`.

`NUTRITION_SUMMARY_FIELDS`' metadata changed from a `mass: bool` flag to an explicit `format` key
(`mg`/`kcal`/`portions`) — a third distinct formatting rule now exists, and `portions` needs 2
decimal places (a whole-number round would destroy the entire point of a fractional factor like
dried fruit's `0.375`).

`view_component.php` now runs its per-100g values through `scaleNutrition($raw, 100)` before
formatting rather than passing them straight through — a no-op for the eight additive fields
(`value*100/100` = `value`) but gives `5AD` a meaningful "portions per 100g" figure instead of the
bare stored factor (e.g. Blueberries, factor `1` → `1.25` portions/100g, matching `100/(80*1)`).

Live-verified on rdmcloud against real curated data (Lester had already flagged Strawberries,
Blueberries, Apple, Orange, all factor `1`, while this was being built): Blueberries shows `1.25`
on `view_component.php`; Strawberries eaten at 100g on 2026-08-14 shows `1.25` on `view_day.php`'s
item row, correctly propagating up through the meal heading and day total (`1.25` day-wide, since
nothing else that day was flagged); unflagged components (Malted Wheaties, Tea with Milk) show
`0.00` throughout, not blank/error.

## First real live CSV imports — 2026-08-19
Both Samsung Health importers run clean against a real full export
(`~/Personal/Health/Samsung Health/food_lester_20260814090949/`) for the first time: `food_info`
(1163 components, 39 flagged to `storage/food/curation_needed.csv`), then `food_intake` (1951
diary entries, 0 skipped). Needed a `max_execution_time` override in both `load_food_info.php`/
`load_food_intake.php` (php-fpm's web pool caps it at 60s) - genuinely slower than an earlier
partial run since `foodMatchSupplier()` now does real work: it was a silent no-op before
`contact`'s supplier contacts existed (see below), writing a real `SUP` xref per matched row now.
nginx's own `fastcgi_read_timeout` (also 60s by default) needed bumping too - PHP's own limit
being raised doesn't help if the webserver cuts the connection first regardless. Detail in
`/etc/webstack/CLAUDE.md`.

**Supplier contacts rebuilt from scratch.** The 10 real UK-supermarket `contactbusiness` records
`foodMatchSupplier()` needs (Tesco/Sainsbury's/Asda/Morrisons/Aldi/Lidl/Waitrose/Co-Op/Iceland/
Marks & Spencer - the top 10 by frequency in `food_info.csv`'s bracketed supplier suffix) had been
wiped as a side effect of unrelated `contact` package reinstall testing earlier the same session
(not a backup issue - Firebird's backup/restore chain is DB-only, never touches `storage/`).
Rebuilt as a fresh `storage/contact/Contacts.csv` and re-imported via `load_contacts_csv.php`.

**`food` package cloned onto srv9 and srv10 for the first time** (existed on desktop only before
today, since it's new this session) - `git clone` from desktop's local copy (same
`ssh://root@desktop/...` convention every other package uses, not GitHub directly), symlinked
`rdmcloud/food -> ../_bw5/food` on each. `server-pull-all.sh` needed no changes - it discovers
packages dynamically by globbing `_bw5/*/`, not a hardcoded list, so it picked `food` up
automatically once present.

## Next up (not started): FoodMovement / add_movement — 2026-08-20

Real pantry ledger, scoped early on but never built (see the "FoodAssembly-as-meal-wrapper" and
"quantity group" sections above — `movement_in` from receipts, `movement_out` via
`explodeFromAssembly()` when a diary meal is logged or a recipe used; historical diary import
deliberately does NOT generate movement rows, only forward from a stocktake baseline). Lester's
framing going in: `add_movement` writes into `FoodComponent`'s existing `REM` xref (the live
remaining-balance value already registered on the `quantity` group, `xkey`/whichever numeric slot
holds the SGL/WT/VOL amount — **not** the same-named curation flag, which lives on `xkey_ext` of
that same row; check both semantics before touching it) *and* creates its own separate
receipt-specific xref set on the `FoodMovement` content object itself (supplier, price, date,
etc.) — his exact words: "working into the REM xref on foodcomponent but it's own set of receipt
xref's".

**Reference pattern to mirror, already checked**: `stock/includes/classes/StockMovement.php`
(764 lines) — pure `liberty_content` record (`content_type_guid='stockmovement'`), no dedicated
DB table (same `content_id`-only convention as `FoodComponent`/`FoodAssembly`, see
[[feedback_content_id_only]]). Direction inferred from a `reference` xref item (`REQN`=outbound,
`TRANS`/`ORDER`=inbound); status lives in `lc.event_time` (`0`=open, a real timestamp=received);
component lines live in `liberty_xref` (`x_group='quantity'`, items `SGL`/`PRT`/`SHT`/`VOL`); the
`reference` xref itself (`x_group='reference'`) carries from/ref/date/contact data. UI-side:
`stock/add_movement_component.php` (85 lines) is the "quick add a component line to an existing
movement" flow — not yet read in detail, check before assuming shape; there is no
`stock/add_movement.php` (movement-creation itself apparently happens some other way in
Stock — find and check that entry point too before designing Food's equivalent, don't assume one
needs building from scratch).

**Not yet designed, needs working through before writing code** (don't just copy Stock 1:1 -
Food's REM-balance-write requirement is genuinely different from Stock's pure-ledger model):
exact shape of the "receipt" xref group/items on `FoodMovement` (supplier — reuse the same
`SUP`-style Contact-xref pattern already built on `FoodComponent`? price? date already covered by
`lc.event_time`/`created`?); whether `REM` gets incremented/decremented directly by
`add_movement`'s own code or via some shared helper Stock already has; whether direction
(`movement_in`/`movement_out`) uses the same `reference`-xref-classifier trick as Stock's
`REQN`/`TRANS`/`ORDER`, and if so what Food's own item codes should be.

## FoodMovement built — receipts only, live on desktop rdmcloud (2026-08-20)

First real slice, scoped down deliberately per Lester: "view and edit movement are the first step
to be able to actually add TO the pantry" — receipts (`movement_in`) only. Outbound (diary meal →
`REM` down via a future `explodeFromAssembly()`-equivalent on `FoodAssembly`) is still not built.

**Schema**: two new `foodmovement` xref groups — `reference` (sort_order=0, one item `RECEIPT`,
`multiple=1` mirroring Stock's REQN/TRANS/ORDER registration convention even though only one row
is ever used per movement — `xref`→shop Contact content_id, `xkey`=free-text receipt/order
reference, `start_date`=purchase date, `data`=note) and `quantity` (sort_order=1, items `SGL`/`WT`/
`VOL`, `multiple=1`, reusing `foodcomponent`'s own type codes directly rather than Stock's
SGL/PRT/SHT/VOL set — no PRT/SHT concept in Food). Hand-pushed into desktop rdmcloud via isql
(pre-production workflow, unchanged) — **srv9/srv10 NOT touched**, schema still not stable enough
to roll out yet.

**`FoodMovement.php`** (`includes/classes/FoodMovement.php`) — mirrors `StockMovement.php`'s shape
(constructor/load/store/expunge/getList/getDisplayUrl/getEditUrl) but trimmed hard: no CSV import,
no assembly/BOM kit-count rescaling, no PBLD-to-requisition conversion — none of that applies,
Food has no BOM-shaped movements. The one thing genuinely new versus Stock: `addComponentLine()`
and `removeComponentLine()` both call `adjustComponentRem()` in the same transaction as the xref
write, keeping the referenced `FoodComponent`'s `REM` balance in sync — necessary because Food's
`REM` is a stored/mutable value, not derived by summing movement history the way Stock's
`list_stock.php` aggregates (see the `quantity` xref design section above). `removeComponentLine()`
archives via `stepXref()`/`expunge=1` (same history-preserving convention as every other xref
removal in this codebase), not a hard delete.

**Pages**: `edit_movement.php`/`view_movement.php` (create/view a receipt — shop via a plain
`<select>` of `contactbusiness`/B04 contacts, same dropdown convention as `add_supplier.php`;
reference, purchase date, note), `list_movements.php` (direct trim of `stock/list_movements.php`).
Also built **`list_pantry.php`** — "what's actually in stock right now", the other half of today's
scoping conversation: a plain `REM > 0` read (not an aggregate — Food's `REM` doesn't need summing
the way Stock's `list_stock.php` does), deliberately *not* modelled on `list_stock.php`'s own code
shape per Lester's explicit call earlier this session ("it's only how the list is built from the
database that actually matters"). `MIN`/shop-filtered shortage list (the actual "shopping list"
ask) is still not built — this was explicitly deferred, `list_pantry.php` only answers "what do I
have", not "what should I buy".

**Add-component flow folded into `edit_movement.php` itself (2026-08-20, same day, after real
use)** — originally a separate `add_movement_component.php` page (direct copy of
`add_assembly_item.php`'s typeahead-or-create-new flow), same as `FoodAssembly`'s own pattern.
Lester hit this immediately trying to tidy a real receipt with several items: a full page
navigation per line was "taking an age". Retired that page — the add form (same typeahead JS,
same `FoodComponent` title lookup, same "not found → redirect to `edit_component.php?title=`"
fallback) now lives inline on `edit_movement.php` itself, posts to itself, and redirects back to
itself (`#add-component` anchor, input auto-focused) on success — so adding several lines to one
receipt is now type → Enter → type → Enter, no page changes at all. Still delegates the actual
insert to `FoodMovement::addComponentLine()`, not a generic xref add, so `REM` stays in sync — that
part didn't change, only where the form lives. This is a scoped, receipt-specific fix, not the
cross-cutting modal/popup redesign — see [[project_modal_quick_add_ux]], still deferred as its own
larger piece of work across Stock/Food/Contact together.

## Nutrition group-edit page + a real cross-package `{jstabs}` bug fix (2026-08-20, same day)

Lester's next real friction point, hit live while actually tidying receipt items: editing the
plain scalar nutrition fields (`CAL`/`PROT`/`CARB`/`FIBR`/`SUGR`/`SOD`/`5AD`) meant one full
`edit_xref.php` round trip *per field* — `FAT`/`VIT`/`MIN` already get a combined one-form edit via
their `json-list` template, the scalars never did. Fixed with a scoped, non-modal page (not the
full generic popup redesign — see [[project_modal_quick_add_ux]], still deferred):

- **`edit_nutrition.php`** — one form, all seven scalar items, one submit. `SOD` reuses the same
  salt-priority conversion as `liberty/edit_xref.php`'s own `sod_salt`/`sod_sodium` hook (replicated
  inline since this page bypasses that controller). `FAT`/`VIT`/`MIN` deliberately left out — already
  solved.
- **`nutrition` xref group's `template` column changed from `''` to `'nutrition'`** (hand-pushed via
  isql, desktop-only) — resolves to a new custom `templates/xref/foodcomponent/
  view_nutrition_group.tpl`, a copy of liberty's generic `list_xref.tpl` plus one added "Edit all"
  icon next to the existing "Add record" link. Row rendering itself is untouched — `FAT`/`VIT`/`MIN`/
  `SOD`'s own item templates still resolve exactly as before.
- **Redirects back with `&jstab=N`**, N computed at runtime via `array_search('nutrition',
  array_keys($gContent->mXrefInfo->mGroups))` rather than hardcoded, so a future group-order change
  (it's happened once already) can't silently break it.

**Real bug found and fixed, not Food-specific**: `themes/smartyplugins/BlockJstabs.php`'s `?jstab=N`
handling computed `$tab` but never used it — the tab-activation JS was hardcoded to a literal
`a[href="#profile"]` selector that doesn't correspond to any real tab anywhere in this app, so
`?jstab=N` silently never worked for *any* package using `{jstabs}` (Stock/Contact/Food, all of
them), not just this new page. Fixed to `$('#$tabId a').eq($tab).tab('show')` — positional
selection by index, since each tab's own href is a title-derived slug (`BlockJstab.php`, singular),
not a numeric id, so there's no fixed target to select directly. Verified live across `jstab=0/1/2`
against the real Quantity/Supplier/Nutrition/External tab order — all three now switch to the
correct tab, confirmed via the generated `<script>` block's selector, not just "no error". This is
a `themes` package fix (its own git repo), committed there separately from `food`.

**Answers Lester's own framing directly**: this only solves "land back on the right tab after a
normal page reload" — it does NOT solve "update the current page without navigating away at all",
which is the real popup/AJAX problem (partial DOM update or live refetch on modal close) still
parked in [[project_modal_quick_add_ux]] as the bigger, deliberately-deferred piece.

**"Only REVIEW-flagged components have a `REM` xref row at all" — confirmed expected, not a bug**
(Lester noticed this while testing the above). `ImportFoodInfo.php`'s `REM` write
(`foodImportFoodInfoRow()`, `$storeXref('REM', null, 'REVIEW')`) only ever fires for components the
importer flagged for curation review — a clean, no-issue import never touches `REM` at all, since
`REM` had no real meaning before `FoodMovement` existed to give it one. So of the ~1163 imported
components, only the ~334 originally flagged (see `list_review.php`'s own numbers) start with any
`REM` row; the rest have none until their first receipt or diary deduction. `FoodMovement::
adjustComponentRem()` already handles this correctly (creates the row fresh at the first delta,
verified in this session's Bananas test, which had zero `REM` rows going in) — nothing to fix here,
just confirming the data shape matches the design.

**Verified end-to-end on desktop rdmcloud** via a faked session: created a receipt (shop/ref/date/
note all round-tripped correctly on `view_movement.php`), added a real component (Bananas) with no
prior `REM` row — `REM` created fresh at the added quantity, confirmed via isql; removed the line —
`REM` correctly dropped back down, the line's own `liberty_xref` row archived (`end_date` stamped,
not hard-deleted); `list_pantry.php` correctly excluded the component again once `REM` was back to
0. No bugs found in this pass. Test receipt and its side-effects (the fresh `REM` row, the
`users_cnxn` faked-session cookie) all cleaned up afterward — nothing left in Lester's real data.

**Real gotcha hit while testing, corrected in memory, not a code bug**: the "fake a session"
recipe recorded in the 2026-08-16 session-log entry above (`session_name` = `bit-user-rdmcloud`)
was stale — rdmcloud's actual live session cookie is `bit-user-rainbowdigitalmediahomecloud`
(site_title-derived, matches `reference_desktop_site_architecture` memory). Always read
`config/kernel/auth_config.php` directly rather than trusting a remembered cookie name — it's
exactly the kind of value that regenerates differently after a config reset (see the 2026-08-20
`rdmcloud` 755/`auth_config.php`-decay entry in the top-level CLAUDE.md).

## Shopping list design confirmed (2026-08-20, still ahead of FoodMovement build)

Worked through with Lester before any code — `MIN` (new `quantity`-group item, same unit as `REM`,
`template='value'` like `PCK`) is the shortage threshold: shopping list = components where `REM <=
MIN`. **`MIN` presence is opt-in** (no row = never appears on the list, not "defaults to 0") — this
is what gives "only regularly-used staples show up" for free, same convention as `5AD`. Confirmed
against Lester's own milk example: he doesn't wait for empty, the real trigger is starting the last
bottle — i.e. `MIN=1`, buy the moment `REM` drops to 1. Maps cleanly, no special-casing needed.

**Shop-filtering does NOT need a schema change.** Lester's milk isn't tied to one shop — he buys it
from Lidl or Waitrose depending on which has run out / voucher timing — which at first looked like
it might break a strict "one shop per item" grouping. It doesn't: `SUP` was already built
`multiple=1` for exactly this reason (a component can have several real suppliers, see the
`project_stock_schema` precedent this was copied from). "What do I need at shop X" is just `REM <=
MIN` intersected with "`SUP` includes shop X's contact content_id" — milk naturally appears under
both Lidl and Waitrose without any extra modelling.

**`list_stock.php` is not being adopted as a template to follow structurally** — Lester's own
framing: "it's only how the list is built from the database that actually matters," not which
existing file's shape gets reused. The reusable *idea* from Stock (group shortages by supplier) still
applies, but Food's version should be designed against its own query (REM/MIN/SUP as above), not
built by mechanically adapting `list_stock.php`'s code.
