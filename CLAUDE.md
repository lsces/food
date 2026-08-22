# Food Package — Session Log

Dated history: decisions, bugs found, why things are the way they are, open follow-ups. For the
current architecture/reference — schema, mechanisms, what's built vs not — see `MANUAL.md`
instead; this file only tracks how it got there.

## 2026-08-15 — scaffolded

New package, modeled on `stock`'s pattern (see `MANUAL.md`'s Architecture overview for the
confirmed shape). Own repo, `github.com/lsces/food`, symlinked into rdmcloud. Skeleton only at
this point — no schema/content classes yet.

## 2026-08-16 — FoodComponent + nutrition + quantity groups built

First real schema and import work. Nutrition design (per-100g basis, scalar vs compound-JSON
split, units) and quantity design (`SGL`/`WT`/`VOL`/`PCK`/`REM`) both settled after working
through real Samsung export data — see `MANUAL.md` for the settled shape, not repeated here.

Two real bugs hit building `ImportFoodInfo.php`, neither previously exercised:
`FoodComponent::lookupByDatauuid()` referenced a nonexistent `liberty_xref.x_group` column (fixed
— join `liberty_xref_item` instead); the constructor was one-param, silently breaking under
`LibertyBase::getNewObject()`'s hardcoded `new $class(null, $contentId)` — see
`[[reference_liberty_content_constructor]]` memory, a contract every `LibertyContent` subclass
needs, not Food-specific.

`WT`/`VOL` writes were found never actually wired up despite being the documented design (only 1
component had a `WT` row in the live DB) — fixed same day.

`REM`'s `xkey_ext` review-flag polarity took two corrections to land on `'REVIEW'` = "needs
review" (was briefly `'CORRECT'`, read ambiguously as "this is correct").

## 2026-08-17 — FoodAssembly built, meal backfill begins, real bugs found

`FoodAssembly`'s meal-as-wrapper design settled (see `MANUAL.md`) after first drafting a
`food_assembly_map` table mirroring what looked like Stock's BOM mechanism — caught before
writing it ("Not sure we are even using the stock one?"); Stock's own `stock_assembly_map` turned
out to be leftover structural-hierarchy code, not the live pattern to copy.

`themes/smartyplugins/BlockJstabs.php`'s `?jstab=N` handling found dead — computed `$tab` but the
tab-activation JS was hardcoded to a nonexistent `#profile` selector, silently broken for every
package using `{jstabs}`, not just Food. Fixed to positional `.eq(N)` selection, own commit in
the `themes` repo.

`SOD`'s salt/sodium edit template built (`template='sod'`, see `MANUAL.md`). `5AD` registered,
revised same day from a pure presence flag to the current decimal adjustment-factor design once
dried fruit's real portion size needed representing.

Supplier alias matching (`foodMatchSupplier()`) built — this was later mis-flagged as "not yet
built" in a stale todo summary and corrected once caught; worth remembering that todo summaries
can drift stale within the same working session, not just across sessions.

## 2026-08-18 — nutrition display, day-total report, companion `health` package scaffolded

Nutrition summary extended to `view_day.php` (day/meal totals), `view_assembly.php`, and
`view_component.php` (per-100g summary above the xref tabs) — one shared method
(`getItemsWithNutrition()`) rather than three separate implementations. `5AD` folded into the
same summary pipeline as a special-cased "portions" format.

`health` package (weight/blood-pressure/sleep/exercise, same Samsung export) scaffolded —
own repo, no schema yet. Its `jsons/` tier needs a genuinely different JSON-xref shape
(array-of-objects/time-series) than Food's flat-object `FAT`/`VIT`/`MIN` template — checked
before assuming Food's design would just carry over.

Shopping list design worked through with Lester ahead of any code: `MIN` (opt-in shortage
threshold, same convention as `5AD`) + `REM <= MIN`, shop-filtering via `SUP` (already
`multiple=1`, no schema change needed — a component can genuinely have several real suppliers).
Not built yet — see `MANUAL.md`'s "Not yet built" section, still true as of 2026-08-22.

## 2026-08-19 — first real CSV imports, live on all three machines

`food_info` (1163 components) and `food_intake` (1951 diary entries) run clean against a real
full Samsung export for the first time. Needed `max_execution_time`/`fastcgi_read_timeout`
overrides (php-fpm and nginx both default to 60s, and the run was genuinely slower than an
earlier partial test since `foodMatchSupplier()` was now doing real work).

Supplier contacts (the 10 real UK supermarkets `foodMatchSupplier()` needs) had been wiped as a
side effect of unrelated `contact` package reinstall testing the same session — rebuilt from a
fresh CSV re-import, not a backup restore (Firebird's backup chain is DB-only, doesn't touch
`storage/`).

Package cloned onto srv9/srv10 for the first time — `server-pull-all.sh` needed no changes, it
discovers packages by globbing `_bw5/*/`, not a hardcoded list.

## 2026-08-20 — FoodMovement built, shopping-list scoping, merge-duplicate feature

`FoodMovement` (receipts only, `movement_in`) built and verified — see `MANUAL.md` for the
settled shape. Scoped deliberately narrow per Lester: "view and edit movement are the first step
to actually add TO the pantry." Add-component flow started as its own page, folded into
`edit_movement.php` itself the same day once real use showed a full page nav per line was "taking
an age."

Real bug: the nutrition group-edit page's redirect used a hardcoded `jstab=N`, fixed to compute
it from the group's actual position at runtime so a future group-order change can't silently
break it.

`FoodComponent::mergeInto()` built for retiring duplicates. First draft used a raw blanket
`UPDATE` for the xref repoint — caught before touching real data (skips `last_update_date`, see
`[[feedback_no_raw_sql_hacks]]`), fixed to loop through `LibertyXref::store()` per row. Lester
then ran it for real, entered the wrong target id — investigated thoroughly, confirmed harmless
(the mis-merged component had zero real references at merge time). That investigation led to a
useful systemic check ("total diary-xref rows should roughly match total food_intake.csv rows")
which found and closed a real 8-row gap (2 genuine missing ingredient lines, 6 an unresolvable
Samsung "quick-add" sentinel with no real component to point at, left alone on purpose). Found a
related **latent bug, not fixed**: 4 meals whose only source line was one of those sentinel rows
have zero ingredients, meaning a future full `food_intake` re-import would create duplicates for
them (`lookupByEventTime()` needs a real marker item to recognize an already-imported meal) —
flagged for whenever a full re-import is next needed, not touched since.

## 2026-08-21 — meal management UX, srv9 handoff, full backfill complete

Meal time-edit, day-link, and copy-to-date built (`copy_assembly.php`'s design settled here — see
`MANUAL.md`). Two real bugs: `changeEventTime()`'s partial `store()` call failed on title
validation until `verifyAssemblyData()` learned to fall back to the record's own stored title;
`copy_assembly.php`'s first draft passed a literal array into `store()`, which takes
`&$pParamHash` by reference and needs a named variable — see the note in the 2026-08-22 entry
below, this exact mistake recurred.

Component search picker fixed twice for real duplicate-row bugs: first the `SUP` join fanning out
one row per supplier (a component can have several), then a genuine client-side race (overlapping
AJAX responses landing out of order) fixed with a request-generation counter.

Full meal backfill (~500 days) completed via `copy_assembly.php`. srv9 became the primary editing
site partway through the day; desktop's accumulated data pushed up to match, then switched back
to its normal role of nightly-restoring *from* srv9.

## 2026-08-22 — huge day: pantry redesign, timezone bugs (food + kernel), terminology sweep

The single biggest day this package has had. Full detail lives in Claude's own memory system
(`project_food_package_scoping`, `project_food_bst_timestamp_fix`,
`project_liberty_simpletext_plugin_bug`, `reference_firebird_clock_and_bitweaver_tz` — all
cross-session, not duplicated here); this is the summary a human reading the repo needs.

**SGL/WT/VOL pantry redesign** (`food` `c997f2e` onward) — `SGL` changed from a competing
mutually-exclusive quantity type to an independent display-mode flag (see `MANUAL.md`). Built and
verified live, pushed to srv9 the same session after catching a real data-loss near-miss: a full
database reverse-sync (desktop→srv9) to push the schema change silently destroyed a same-morning
tablet entry that predated the sync — see `[[feedback_reverse_sync_data_loss]]` memory. Don't
reverse-sync a whole DB again without checking the destination for same-day activity first.

**`edit_movement.php` polish pass**, same session: supplier-disambiguating component search
(mirroring `add_assembly_item.tpl`'s existing fix, which this page had never received), SGL note
as the count-mode label instead of generic "x", inline per-line quantity correction (`btn-link`
icon, no button box), native `<input type="date">` for the purchase date (dropping a hand-rolled
dd/mm parser entirely), and a shop filter on the component search.

**Two real, separate timezone bugs found and fixed**, triggered by Lester noticing a freshly
re-entered meal displaying an hour late:
1. Food never plugged into bitweaver's own established UTC-storage/local-display convention
   (`BitDate`) at all — `edit_assembly.php`'s Time field did raw UTC arithmetic. Fixed (see
   `MANUAL.md`'s "Time storage" section).
2. Building that fix surfaced a real bug in `BitDate` itself: its display-conversion methods
   mutated PHP's *global* ambient default timezone with no restore, silently corrupting any later
   bare `strtotime()`/`date()`/`mktime()` call elsewhere in the same request — any package, not
   just Food. Root-caused at the kernel level (`kernel` `3a71379`) rather than defensively
   rewriting every vulnerable call site in Food.

Historical data checked directly against the original Samsung export CSVs still on disk — the
CSV-imported dataset (spanning back to Feb 2025) turned out to already be correct; Lester's
initial suspicion that it needed a blanket +1hr correction was his own memory of one atypical day
being wrong, not a real bug ("sods law that I hit a day that looks wrong"). The 15-21 Aug manual
backfill week *was* genuinely affected (entered via the not-yet-fixed Time field) — 22 records
corrected -1hr on both desktop and srv9, verified before/after.

Building the historical-data check hit a real infra gotcha: a bare CLI PHP bootstrap of
`kernel/includes/setup_inc.php` doesn't resolve the DB connection at all (multi-site config
depends on `$_SERVER['HTTP_HOST']`, absent outside a real request) — every query silently
returned empty with no error. Any future one-off correction script needs to run as a real HTTP
request (a temporary site-root file, deleted after use — not committed to any package repo), not
raw CLI.

Also found: `liberty`'s `simpletext` format plugin (used for `FoodComponent`'s plain-text Notes
field) was never actually active on any site — missing `auto_activate`, and the normal per-request
plugin loader only includes already-active plugin files, so it silently never ran. Every note
ever typed into a FoodComponent since the field was built had been discarded with no error. Fixed
in `liberty` + a one-row config unblock on the two already-live sites.

**Copy_assembly's by-reference `store()` mistake recurred**, this time in a one-off correction
script — worth remembering as a genuinely recurring footgun with this codebase's `store()`
convention (`&$pParamHash`, needs a named variable, not a literal array), not a one-off.

**Terminology**: menu labels (`Today`→`Food Diary`, `Components`→`Food Items`) tweaked for
clarity, then the same rename carried through every user-facing page string (titles, headings,
placeholders, tooltips, errors) — file/class names deliberately left alone, see `MANUAL.md`'s UI
conventions section.

**`list_components.php` gained a shop filter dropdown** — the query-level support (`sup=` param)
had existed since 2026-08-17 with no UI control. Framed by Lester as "a step toward" the shopping
list generator, not the generator itself (still blocked on `MIN` not existing).

**Todo list, reshuffled this session**: shopping-list generator and receipt-copy stay on Food's
own list (see `MANUAL.md`'s "Not yet built"); the recipe-publish step and the modal quick-add UX
idea were reclassified by Lester as liberty-level projects, not Food's own, since both are
cross-package in scope with Food just as the first consumer.
