# Food Package — Reference Manual

How the package actually works today. For the history of *why* — decisions, bugs found, wrong
turns — see `CLAUDE.md`'s dated session log instead; this file only tracks current behaviour.

## Architecture overview

Modeled closely on `stock` (see `stock/CLAUDE.md`) and reuses `liberty`'s xref machinery
throughout (see `liberty/MANUAL.md` for how xref groups/items/templates work in general) — but a
separate package, not an extension of `stock`, since food and stock may end up on different
domains.

Three content types, all pure `liberty_content` records — **no schema tables**, content_id only
(see `[[feedback_content_id_only]]` memory: a table that only aliases content_id to a second
sequential id is pointless).

- **`FoodComponent`** — a single food item (an ingredient, a ready meal, a branded product —
  anything with its own nutrition/quantity data). User-facing pages call these "Food Items"
  (page titles, headings, search boxes) — the class/file names still say `Component` throughout,
  deliberately not renamed to match.
- **`FoodAssembly`** — a meal instance (breakfast/lunch/dinner/snack) *or* a reusable recipe/
  favourite — same shape, distinguished by which item code populates its `type` group.
- **`FoodMovement`** — a pantry receipt (`movement_in` only currently — see "Not yet built" below).

## FoodComponent

### Nutrition (`nutrition` xref group)

Populated from Samsung's `food_info.csv` only (`nutrition.csv` was evaluated and dropped as an
import source — no reliable join key to `food_intake`, and per-meal nutrition is more accurately
*computed* from per-100g component values than imported as a snapshot).

**Basis: per-100g, curated.** Samsung's own basis varies per row; import normalizes any row with a
real gram/ml basis via `value/metric_serving_amount*100`, flags anything without one (blank/zero
`metric_serving_amount`, or a Samsung serving-count unit code instead of `g`/`ml`) for manual
curation rather than guessing.

**Two tiers, by how the data is used**:
- **Scalar xref_items** (own row, integer milligrams, `CAL` plain integer kcal): `CAL`, `PROT`,
  `CARB`, `FIBR`, `SUGR`, `SOD` (already mg-scale in `food_info`, not grams — no ×1000 needed).
  `SOD` has its own `template='sod'` item template (`edit_sod_item.tpl`) accepting Salt (g) *or*
  Sodium (mg) — salt takes priority when both are filled, `sodium_mg = salt_g / 2.5 × 1000`
  (UK labels show salt, not sodium).

  `edit_nutrition.php`'s group-edit form (see below) shows/accepts `CARB`/`SUGR`/`FIBR`/`PROT`
  and `FAT`'s `total`/`saturated` sub-fields **in grams**, converting ×1000 on save — storage
  stays integer mg regardless of entry page. `CAL` (kcal) and `5AD` (a decimal factor, not a
  mass) are never converted.

  On the Nutrition tab's row list (a different display from the edit form above), `CARB`/`SUGR`/
  `FIBR`/`PROT` use `template='mgg'` (`view_mgg_item.tpl`) and `SOD` uses its own `template='sod'`
  view — both apply the same `>=1000mg → "X.Xg"` threshold as `formatMg()`'s totals-bar behaviour,
  rather than showing the raw stored integer mg.
- **Compound JSON xref_items** (`liberty_xref.data`, a CLOB, one row each): `FAT` →
  `{total/saturated/mono/poly/trans/cholesterol}_mg`, `VIT` → `{vitamin_a_mcg, vitamin_c_mg,
  vitamin_d_mcg}` (genuinely mixed units per field — `_mcg`/`_mg` suffixes baked into the key
  names rather than one blob-wide unit), `MIN` → `{potassium/calcium/iron}_mg`. Edited via a
  food-package-local `template='json-list'` (own per-package template dispatch — no generic
  liberty JSON-xref mechanism exists yet, built here first, promote to liberty only if a second
  package wants it) for all three — the edit form is unaffected by the view-side split below.

  **View-side split, `FAT` only**: `FAT`'s row-list display uses `template='json-list-mgg'`
  (`view_json-list-mgg_item.tpl`) instead of plain `json-list` — same `>=1000mg → "X.Xg"`
  threshold as `formatMg()`, applied per sub-field, with the `_mg`/`_mcg` suffix stripped from the
  key before building the row label (so "Total"/"Saturated" stay accurate once the displayed unit
  can flip). `MIN` and `VIT` stay on plain `json-list` display deliberately: `MIN`'s values are
  decimal-scale mg (potassium/calcium/iron never sensibly shown in g) and `VIT` already has
  genuinely mixed mcg/mg units baked into its own keys — a different problem this doesn't attempt
  to solve.
- **`5AD`** (five-a-day) — a fixed portion-size *adjustment factor*, `xkey = true_portion_g / 80`
  (`1` for a standard 80g portion, `0.375` for dried fruit's real 30g portion). Not a per-100g
  additive nutrient like the other eight — `FoodComponent::scaleNutrition()` special-cases it:
  `portions = grams / (80 × factor)`. Opt-in (no row = doesn't count toward five-a-day), no NHS
  source data exists for this so it's entirely self-curated. **Known unmodelled gap**: NHS also
  caps juice/smoothies and beans/pulses at 1 portion/day regardless of quantity — a per-category
  daily cap, not expressible as a per-gram factor.

**`FoodComponent::NUTRITION_SUMMARY_FIELDS`** is the canonical field list (Energy/Fat/Saturates/
Carbohydrate/Sugars/Fibre/Protein/Sodium, UK front-of-pack order, `5AD` appended last) driving
every nutrition summary in the package (day/meal/component views). `getNutritionBatch()` loads
one query per page regardless of ingredient count; `scaleNutrition()`/`sumNutrition()`/
`formatNutrition()` compose item→meal→day. `formatMg()` renders `>=1000mg` as `"X.Xg"`; `5AD`
values need 2 decimal places (a whole-number round destroys the point of a fractional factor).

### Quantity / pantry tracking (`quantity` xref group)

Entirely separate axis from nutrition. Registered items:

- **`WT`** (weight, grams) / **`VOL`** (volume, ml) — `multiple=-2`, genuinely mutually exclusive
  (a component is weight-tracked *or* volume-tracked, never both — storing one evicts the other).
  Set by the importer from `food_info.csv`'s own `metric_serving_amount`/`unit` where that basis
  is real (not the assumed-100g curation fallback, which doesn't tell you weight vs volume).
- **`SGL`** — **`multiple=0`, an independent display-mode flag, not a competing type** (redesigned
  2026-08-22 — originally `multiple=-2` alongside WT/VOL, which meant a component could only ever
  be "counted" *or* "weighed", never both; real components need both, e.g. a multi-pack you
  sometimes buy as "8 items" and sometimes need the real weight of). A component flagged `SGL`
  shows its pantry stock as a derived count (`REM ÷ its own WT-or-VOL value`) instead of raw
  grams; everything else shows `REM` directly as g/ml. `SGL`'s own `xkey_ext` doubles as a
  free-text category note (e.g. "Ready Meal", "Pack of 8") — shown as `list_pantry.php`'s Note
  column and as the label on `edit_movement.php`'s count-mode picker.
- **`PCK`** — a stored pack-size multiplier. Redundant under the current design (`WT`/`VOL`
  itself always holds the real divisor now) — left in the schema, nothing reads it.
- **`REM`** — live remaining pantry balance, in whichever unit the component's own `WT`/`VOL`
  declares. **Stored/mutable, not derived by summing movement history** (unlike Stock's
  always-aggregate `list_stock.php` model) — deliberate, since Food's ledger is known-incomplete
  (no historical `movement_out` for anything before a stocktake baseline). Only
  `FoodMovement::adjustComponentRem()` writes it, always inside the same transaction as the
  triggering line add/remove/edit.
  - **Dust threshold** (`adjustComponentRem()`'s `DUST_THRESHOLD_RATIO`, 0.25, 2026-08-24): a
    consumption delta (negative) landing below 25% of the component's own declared `WT`/`VOL`
    portion size zeroes `REM` instead of leaving a small remainder — a shrinking pack rarely gets
    weighed out to the exact last gram, so a lingering "12g" isn't a real trackable amount. Applies
    to any negative delta through this method (receipt reversals included), not just meal
    consumption. A component with no declared `WT`/`VOL` falls back to a plain floor-at-zero.
  - **Actual-delta tracking** (same date): `adjustComponentRem()` returns the delta it actually
    applied (`new − old`), which can differ from the requested delta once floor-at-zero or the
    dust threshold clamp it. `FoodAssembly`'s consuming call sites (`add_assembly_item.php`,
    `copy_assembly.php`) stash this actual value on the ingredient line itself, via that line's own
    `xkey_ext` (see `addItem()`'s `$pRemRestockAmount` param) — so a later reversal
    (`FoodAssembly::removeItem()`, `expunge()`) restocks exactly what was really taken, not the
    nominal logged quantity. This is what makes "delete a meal that was logged against an already-
    empty pantry" correctly restock nothing, instead of crediting stock that was never there.
- **`REM`'s `xkey_ext` also carries a review-status tag** (`'REVIEW'` = still needs curation,
  cleared by hand once fixed) — a second, unrelated use of the same spare column, not to be
  confused with the pantry balance itself. `data` (on `liberty_content`, not the `REM` row) is a
  free-text curation note, decoupled from that status.

### Supplier (`supplier` xref group)

`SUP` — `multiple=1` (a component can have several real suppliers, e.g. bought from both Lidl
and Waitrose over time), `xref` = the supplier's Contact `content_id`, `xkey`=Product Code,
`xkey_ext`=Price, `data`=free note. Own group template (`template='sup'`), not the generic
`list_xref.tpl` — a supplier row needs Supplier/Price/Note columns, not the generic Type/Value/
Notes shape.

`foodMatchSupplier()` (in the `food_info` importer) resolves Samsung's bracketed supplier suffix
in the title (`"Ice cream sandwich (Gelatelli)"`) to a real `SUP` xref via an explicit-alias table
(`Chef Select`/`Chef select`→Lidl, `By Sainsbury's`→Sainsbury's, `M&S Food`→Marks & Spencer, etc.)
— restaurant/takeaway chains and manufacturer brands are deliberately not resolved this way, left
for manual curation.

### Review/curation flag

`REM`'s `xkey_ext = 'REVIEW'` (see above) is the outstanding-work marker, set by the importer on
import, cleared by hand via `edit_component.tpl`'s tick floaticon (`clear_review=1` →
`FoodComponent::clearReviewFlag()`) once actually fixed. `list_review.php` is the live progress
report — joins components against their `REM` xref, ranks by how many diary `FoodAssembly` rows
reference each one (highest-impact fixes surface first), not a static export.

## FoodAssembly

One content type, no separate classification xref — the meal-type item code itself
(`BREAKFAST`/`LUNCH`/`DINNER`/`MSNK`/`ESNK`, `type` group, `sort_order=0` — the liberty
type-marker convention, excluded from the generic tabbed xref display) *is* the multi=1
`liberty_xref` item holding the ingredient list. Each ingredient row: `xref` = the
`FoodComponent`'s content_id, `xkey` = quantity in grams/ml, `xorder` = position.

**"Day" is a report over these, not its own record** — `view_day.php` groups `FoodAssembly`
instances by calendar day via `event_time` range queries (`FoodAssembly::mealTypesTakenOnDay()`/
`lookupByDayAndType()`), matching how `list_stock.php` already aggregates Stock movements by
date without a day object. Day-uniqueness (one of each meal type per real day) is enforced by
hand in `changeMealType()`/`createForDay()` — no generic liberty hook exists for this kind of
cross-record uniqueness check.

**`RECIPE`/`FAVOURITE`** (reusable dish / reusable named combo) share the same shape and the same
`type` group as the five diary meal-types — not registered/built yet, only the diary types exist
today.

### Time storage — UTC, via `BitDate` (fixed 2026-08-22)

`event_time` (and `created`/`last_modified`) store true UTC, per the whole-stack convention.
`edit_assembly.php`'s Time field reads/writes local (display-timezone) wall-clock time through
`$gBitSystem->mServerTimestamp` (a `BitDate` instance): `getDisplayDateFromUTC()` to prefill the
form, `getUTCFromDisplayDate()` to convert the typed value back to true UTC on save. Day-start
computation uses `gmmktime()`, never `strtotime(gmdate(...))` — `BitDate`'s own conversion calls
used to mutate PHP's global default timezone with no restore (fixed at the kernel level,
`kernel` commit `3a71379` — see `reference_firebird_clock_and_bitweaver_tz` memory), so a bare
`strtotime()` anywhere later in the same request risked silently re-interpreting a naive date
string against the wrong timezone. `gmmktime()` sidesteps the whole class of bug regardless.

**Known residual gap, not fixed**: day-boundary grouping (`mealTypesTakenOnDay()` etc.) still
computes midnight in *UTC* terms, not the user's local calendar day. A meal genuinely logged very
late/early (near the UTC midnight boundary) could theoretically group into the "wrong" local day.
Low real-world impact (meal times aren't near midnight) — flagged, not addressed.

### `copy_assembly.php`

Date-only form — copies a source meal's full ingredient list (component/quantity/order) onto a
chosen date, same meal type, time-of-day carried over from the source `event_time` numerically
(`% 86400`, no timezone math — it's just shifting which day, not changing what the time means).
Same day-uniqueness check as `changeMealType()`. Built specifically to speed up backfilling a
repeating pattern (e.g. the same breakfast most days) — full-page retyping was "taking an age".

### Nutrition display

`FoodAssembly::getItemsWithNutrition()` factors the per-item-scale + meal-total-sum logic shared
by `view_day.php` and `view_assembly.php` — returns `items` (each with a `nutrition` key),
`total` (formatted), `totalRaw` (unformatted, so `view_day.php` can sum several meals' totals
into a day total — formatted strings like `"1.5g"` can't themselves be summed).

## FoodMovement (pantry receipts)

`movement_in` only — receipts. `movement_out` (diary meal logged → `REM` decremented, or a
recipe "used") is **not built** — the historical diary import deliberately does *not* generate
movement rows at all; `FoodMovement` only starts existing from a manual stocktake baseline
forward, otherwise 500+ days of consumption history would need reconciling against zero purchase
history.

**Schema**: `reference` group (`RECEIPT` item, `xref`→shop Contact, `xkey`=free-text reference,
`start_date`=purchase date via a native `<input type="date">`, `data`=note) + `quantity` group
(`SGL`/`WT`/`VOL` items, `multiple=1`, reusing `foodcomponent`'s own type codes).

**`FoodMovement.php`** mirrors `StockMovement.php`'s shape, trimmed hard (no CSV import, no BOM
kit-count rescaling — Food has no BOM-shaped movements). The real addition over a generic xref
add: every line-touching method keeps the referenced `FoodComponent`'s `REM` in sync, in the same
transaction —

- **`addComponentLine(componentId, quantity, mode)`** — `mode` is `'base'` (quantity is a direct
  weight/volume, stored under the component's own `WT`/`VOL` item, added straight to `REM`, no
  conversion) or `'sgl'` (quantity is a count, stored under `SGL`, converted through the
  component's *current* `WT`/`VOL` value before touching `REM` — only valid if the component is
  actually `SGL`-flagged and has a real `WT`/`VOL` value to convert through).
- **`removeComponentLine(xrefId)`** / **`updateComponentLine(xrefId, newQuantity)`** — both
  reverse/recompute through the same `resolveRemDelta()` helper (SGL-mode lines convert via the
  component's *current* `WT`/`VOL` value, not a frozen snapshot at add-time — accepted tradeoff).
  `removeComponentLine()` hard-deletes via `stepXref()`/`expunge=3`, gated behind
  `verifyExpungePermission()` (`p_food_expunge`) — a mis-entered line should actually go away, not
  sit in the item's History tab; `getLines()`'s own query also filters `end_date IS NULL` as a
  second, independent safeguard. The line quantity-edit form on `edit_movement.php` is a genuine
  in-place correction, not a remove+re-add.
- **`expunge()`** walks every line and reverses each one's `REM` contribution individually before
  deleting the content record — there's no bulk shortcut, since each line might resolve to a
  different delta (`base` vs `sgl` mode).

**Component search** (`includes/lookup_component.php`, shared by `edit_movement.tpl` and
`add_assembly_item.tpl`): returns `content_id`, `supplier` (disambiguates same-titled components
from different shops — a real, common case since supplier lives in its own `SUP` xref, not the
title), `default_qty` (the component's own `WT`/`VOL` value, prefills Quantity), `quantity_item`/
`has_sgl`/`sgl_note` (drive the entry-mode picker), and an optional `?shop=<content_id>` filter
(scopes results to components already tagged with that supplier — opt-in, no shop selected means
unfiltered).

## UI conventions

- **"Food Item" in every user-facing string** (page titles, headings, search placeholders, table
  columns, tooltips, form labels, validation errors) — file/class names still say `Component`
  throughout (`list_components.php`, `FoodComponent`), deliberately not renamed to match.
- **"Ingredient"** was briefly kept as a separate term for a food item *within a meal* (`add_
  assembly_item.php`'s button/heading), deliberately not touched by the 2026-08-22 rename sweep —
  reconsidered 2026-08-23: that page's button/heading/link now say "Add Food Item" too, for
  consistency with every other add/edit context. General descriptive text ("ingredient list", "No
  ingredients recorded") is untouched — this only ever covered the "Add X" naming.
- Native `<input type="date">`/`<input type="time">` for date/time entry (`copy_assembly.tpl`,
  `view_day.tpl`, `edit_movement.tpl`'s purchase date, `edit_assembly.tpl`'s Time field) — no
  vendored JS date-picker library is actually live anywhere in this codebase despite one sitting
  unused in `config/themes/bootstrap/`; native inputs need zero extra wiring and give unambiguous
  ISO values on submit.
- Icon-only inline actions use `class="btn btn-link"` (no button box/border), matching
  `comments.tpl`/Stock's print buttons — not `btn-default`, which reads as a heavier action.

## Not yet built

- **Shopping list generator** — the actual "buy this" report. Needs a `MIN` quantity-group item
  (shortage threshold, opt-in like `5AD` — no row means never appears) that doesn't exist yet;
  design otherwise settled: `REM <= MIN` intersected with `SUP` includes shop X (no schema change
  needed for shop-filtering — `SUP` is already `multiple=1`, a component can genuinely have
  several real suppliers). `list_components.php`'s own shop-filter dropdown (built 2026-08-22) is
  a step toward this, not the generator itself.
- **Receipt-copy** — mirroring `copy_assembly.php`'s pattern for `FoodMovement`. Parked, not
  scoped in detail.
- **Recipe publish step** (`FoodAssembly` RECIPE content → myhomecloud, the public domain) — no
  cross-domain publish mechanism exists anywhere in the stack yet. Reclassified as a liberty-level
  project, not Food's own — Food would just be the first consumer.
- **Modal quick-add UX** (`[[project_modal_quick_add_ux]]`) — replacing full-page add/edit flows
  with popups. Cross-package (Stock/Food/Contact), also reclassified as liberty-level.
- **~~`explodeFromAssembly()`~~ — now built, piecemeal, not as one method.** The outbound half of
  pantry tracking: `add_assembly_item.php` (manual add) and `copy_assembly.php` decrement `REM` via
  `FoodMovement::adjustComponentRem()` per item added; `FoodAssembly::removeItem()` (single-line
  remove, wired to `edit_assembly.tpl`'s per-row Remove link since 2026-08-24 — previously bypassed
  `REM` via generic `liberty/edit_xref.php`) and `FoodAssembly::expunge()` (whole-meal delete) both
  restock on the way out. CSV import (`ImportFoodIntake.php`, via `FoodAssembly::addItem()`)
  deliberately does NOT touch `REM` — those meals predate any pantry tracking baseline. See the
  REM xref group's own entry above for the dust-threshold/actual-delta-tracking mechanics that
  make the restock side accurate. **Known remaining gap**: `edit_assembly.tpl`'s per-row "Edit"
  link (in-place quantity correction, generic `liberty/edit_xref.php`) still bypasses `REM` —
  changing a logged quantity after the fact doesn't adjust the pantry balance to match. Same bug
  class as the fixes above, not yet reported/actioned (mirrors `FoodMovement::updateComponentLine()`,
  which already handles this correctly for receipt lines — that's the pattern to follow if picked
  up).

## Deployment topology

Private home is **rdmcloud** — live on desktop, srv9, and srv10, each with its own independent DB
(no cross-machine data sync except deliberate one-off pushes). srv9 is the primary day-to-day
editing site (tablet, browser); desktop is a working/dev copy, normally kept in sync via a
nightly srv9→desktop restore cron — **never assume that restore covers same-day edits**; a full
database reverse-sync (desktop→srv9) to push a schema/dev-state change can silently destroy
anything entered on srv9 since the last nightly restore (real incident, 2026-08-22 — see
`[[feedback_reverse_sync_data_loss]]` memory). Schema changes are still hand-pushed via isql
directly into each machine's live DB (no upgrade-file/install-cycle path yet — the package
predates that convention being needed). srv10 has the package installed but is generally the last
machine to receive any given day's changes, often left pending.
