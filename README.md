# Food

A [Bitweaver](https://github.com/lsces/bitweaver) package for tracking ingredients, recipes, and
what you actually eat — built to import a full **Samsung Health** export and turn it into your own
queryable nutrition database, instead of leaving that data locked inside Samsung's app.

**Status: early, active development, personal use.** This is being built primarily for the
author's own day-to-day tracking. The schema and importers are still settling — expect breaking
changes, hand-run migrations, and gaps. It's shared here in case it's useful to other Samsung
Health users who'd like to own a copy of their own food data, not as a finished product yet.

> **Scope note:** this package only covers food/nutrition — the food-related slice of a Samsung
> Health export. The rest of that export (weight, blood pressure, sleep, exercise, heart rate, and
> so on) is out of scope here by design and is planned as a separate companion `health` package
> (see "What's planned" below), not something Food will grow into over time.

## Why this exists

Samsung Health has no API — the only way to get your data out is a manual full CSV export from
the app. Once it's out, Samsung's own tools don't do much with it: no per-ingredient nutrition
database you can browse or correct, no recipe/meal modelling beyond a flat diary, no way to fix a
food's nutrition data without creating a confusing duplicate entry. This package imports that
export into Bitweaver, where it becomes normal editable content — components, recipes, and a
diary you can query, correct, and build reports against.

## What it imports (and what it doesn't, yet)

From a Samsung Health export's `food_info.csv` and `food_intake.csv`:

- **Ingredients** (`FoodComponent`) — one per distinct food actually referenced in your diary
  (unreferenced/orphaned entries are skipped), each carrying nutrition facts normalized to a
  **per-100g basis**: calories, protein, carbs, fibre, sugar, sodium, fat (total/saturated/mono/
  poly/trans + cholesterol), and a set of vitamins/minerals. Provenance (Samsung's internal ID,
  and whether it was one of Samsung's own database entries vs a manually-added correction) is kept
  on each record.
- **Meals** (`FoodAssembly`) — your diary, imported as one record per real meal (all items eaten
  together, grouped the way Samsung itself groups them), tagged Breakfast/Lunch/Dinner/Morning
  snack/Evening snack, each a list of ingredients with quantities. The same content type will also
  cover reusable recipes and favourites later — a meal, a recipe, and a favourite are the same
  underlying shape.

Deliberately **not** imported (for now, or possibly ever):
- `nutrition.csv` (Samsung's own per-meal rollup) — dropped in favour of computing meal nutrition
  from ingredient data directly, which is more accurate once individual foods get corrected.
- `food_favorite.csv`/`food_frequent.csv` — Samsung's own shortcut lists; not needed once the real
  ingredient/recipe data exists here.
- Purchase history — Samsung has none. A pantry/shopping-list side (see below) will need a manual
  starting stocktake rather than being backfilled from the diary.

Data quality varies a lot by food item — Samsung's own figures are missing or unreliable for a
meaningful chunk of entries (no usable serving weight, missing fibre, etc.). Rather than silently
importing bad data, anything importer notices as suspect gets flagged for a human to review later,
with an on-screen note of what's wrong. Reviewing the flagged list down over time is expected, not
a one-off cleanup pass.

## What's built so far

- Ingredient (`FoodComponent`) and meal (`FoodAssembly`) content types, viewable/editable in the
  browser
- Importers for `food_info.csv` and `food_intake.csv`, safe to re-run against a newer export
  (updates existing records rather than duplicating them)
- A curation queue — a live report of which ingredients still need a human to check/fix their data,
  ranked by how often that ingredient actually shows up in your diary (fix the ones you eat most
  often first)

## What's planned

Roughly in order:
- Proper structured editing for the compound nutrition fields (fat/vitamin/mineral breakdowns),
  rather than raw text
- A friendlier salt-to-sodium entry field (UK food labels show salt, not sodium)
- A day view — see a whole day's meals together, not one at a time
- A real pantry ledger (`FoodMovement`) — record what you buy, draw it down as you log meals, get
  a shopping list generator (reusing the same shortages-by-supplier mechanism the companion
  `stock` package already has, reframed as shortages-by-shop)
- Recipes and favourites as first-class, reusable entries (not just diary history)
- A "five-a-day" (fruit/veg portion) tracker — a UK metric Samsung's US-centric export has no data
  for at all, so this needs sourcing from scratch
- Eventually, a way to publish selected recipes to a public site while keeping the day-to-day diary
  and health data private

A companion `health` package (weight, blood pressure, sleep, exercise, heart rate — the rest of
the Samsung Health export) is planned but not yet started.

## Requirements

- [Bitweaver](https://github.com/lsces/bitweaver) 5.x
- [`liberty`](https://github.com/lsces/liberty) package (≥ 5.0.1) — this package is built entirely
  on Liberty's generic content/xref framework, the same foundation the
  [`stock`](https://github.com/lsces/stock) package uses (Food's design deliberately mirrors
  Stock's throughout — same content-type/xref-group approach, same reasoning for what does and
  doesn't need its own database table)
- A Samsung Health data export (Settings → your account → Download personal data, inside the
  Samsung Health app)

## Getting your data in

1. Export your data from Samsung Health (full export, no partial/date-range option exists).
2. Locate `com.samsung.health.food_info.<date>.csv` and `com.samsung.health.food_intake.<date>.csv`
   in the export.
3. Run the `food_info` importer, then the `food_intake` importer (must be run in that order —
   meals reference ingredients).
4. Re-running either importer against a newer export later is safe — it updates existing records
   by Samsung's own record ID rather than creating duplicates.

Since this package isn't through a stable install/upgrade cycle yet, see `CLAUDE.md` in this repo
for the current schema-deployment approach if you're installing it fresh.
