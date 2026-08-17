<?php

// No tables — FoodComponent and FoodAssembly are both pure liberty_content records,
// content_id only (see Claude memory feedback_content_id_only: Stock's own
// stock_component/stock_assembly tables were retired 2026-06-01 for the same reason).
// FoodAssembly's line items are plain multi=1 liberty_xref rows, not a map table —
// stock_assembly_map turned out not to be Stock's live BOM-quantity mechanism either
// (its own stockassembly quantity items are multi=0, structurally can't hold more than
// one row per type per assembly) once actually checked, not assumed. FoodMovement is
// still to design.

global $gBitInstaller;

$gBitInstaller->registerPackageInfo( FOOD_PKG_NAME, [
	'description' => 'Food tracks ingredients, recipes, and consumption — imported from Samsung Health, modeled on the Stock package.',
	'license'     => '<a href="http://www.gnu.org/licenses/licenses.html#LGPL">LGPL</a>',
] );

$gBitInstaller->registerPreferences( FOOD_PKG_NAME, [
	[ FOOD_PKG_NAME, 'food_menu_text', 'Food' ],
] );

// ### Default User Permissions
$gBitInstaller->registerUserPermissions( FOOD_PKG_NAME, [
	[ 'p_food_view',    'Can view food components and assemblies',   'registered', FOOD_PKG_NAME ],
	[ 'p_food_create',  'Can create food components and assemblies', 'editors',    FOOD_PKG_NAME ],
	[ 'p_food_update',  'Can update food components and assemblies', 'editors',    FOOD_PKG_NAME ],
	[ 'p_food_expunge', 'Can delete food records',                   'admin',      FOOD_PKG_NAME ],
	[ 'p_food_admin',   'Can administer food',                       'admin',      FOOD_PKG_NAME ],
] );

// ### Register content types
$gBitInstaller->registerContentObjects( FOOD_PKG_NAME, [
	'FoodComponent' => FOOD_PKG_CLASS_PATH.'FoodComponent.php',
	'FoodAssembly'  => FOOD_PKG_CLASS_PATH.'FoodAssembly.php',
] );

// ### Requirements
$gBitInstaller->registerRequirements( FOOD_PKG_NAME, [
	'liberty' => [ 'min' => '5.0.1' ],
	'contact' => [ 'min' => '5.0.2' ],
] );

// ### Xref seed data
// liberty_xref_group: x_group, content_type_guid, title, sort_order, role_id, type_href
// liberty_xref_item:  item, content_type_guid, x_group, cross_ref_title, multiple, role_id, cross_ref_href, template, data

$X = BIT_DB_PREFIX;

$xrefTypes = [];
$xrefItems = [];

// ── foodcomponent-specific group (sort_order=3: external) — external system
// reference. Same idea as Stock's KLID (Kitlocker ID Code): an external system's own
// id, kept as a plain xref item rather than a schema column, so re-import can
// dedupe/upsert on it without needing a table.
//
// Originally registered at the package level (content_type_guid='food', sort_order=0)
// on the theory it'd be "shared across every food content type" — corrected
// 2026-08-16: FoodAssembly has no datauuid at all (identified by event_time+meal-type
// item, not a Samsung UUID), so in practice this was never actually shared, just
// speculative scoping. Package-level sort_order=0 also collided with foodcomponent's
// own sort_order=1 'nutrition' group under LibertyXrefType::loadContent()'s dual-guid
// scoping (`content_type_guid IN (class_guid, package_guid)`, ORDER BY sort_order) —
// a real instance of this codebase's known package/class content_type_guid niggle.
// Scoped directly to foodcomponent now, sidesteps both problems; if a future Food
// content type needs its own external-reference tracking, it registers its own group
// rather than reusing a shared package-level one.
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('external','foodcomponent','External Reference',4,3,'','')";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('DUID','foodcomponent','external','Samsung Health datauuid',0,3,'','text',NULL)";
// PFID = food_info.provider_food_id verbatim ('fatsecret-<id>' or 'quickinput-<uuid>').
// Provenance ('is this a hand-entered fix') is a prefix check on this at query/curation
// time, not a separate stored flag — see project_food_package_scoping memory.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('PFID','foodcomponent','external','Samsung Health provider_food_id',0,3,'','text',NULL)";

// ── foodcomponent-specific group (sort_order=1: nutrition) ─────────────────────────
// All values are per-100g, curated at import time from food_info.csv's raw per-serving
// figures (value/metric_serving_amount*100) — Samsung's own basis varies per row (91g
// for plain Broccoli, 100g already for most branded/packaged items, 0/blank — no gram
// basis at all, mostly ready-meals/restaurant dishes — for ~76/1202 rows in the
// 2026-08-14 export). Rows without a usable gram basis are flagged for manual
// portion-weight curation rather than guessed. Scalar values are integer milligrams
// (exact vs. Samsung's own decimal-gram precision, confirmed lossless — see
// project_food_package_scoping memory). FAT/MIN compound JSON values are also integer
// mg — food_info.csv only supplies mg-scale fields for those two (fat subfields/
// cholesterol, potassium/calcium/iron; no trace-mcg minerals appear in food_info at
// all, those only existed in the now-dropped nutrition.csv). VIT is genuinely mixed
// native units per sub-field, confirmed against real data (Kipper vitamin_d=15 can
// only be mcg, not mg — 15mg would be ~600x RDA) — stored with unit-suffixed JSON
// keys (vitamin_d_mcg, vitamin_c_mg, etc.) rather than one blob-wide unit.
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('nutrition','foodcomponent','Nutrition',3,3,'','')";

// Scalar nutrition items — worth individually browsing/sorting/tidying, own row each.
// FIBR deliberately promoted alongside PROT (Samsung's own app buries it; Lester rates
// it more useful day-to-day). SOD kept scalar rather than folded into a mineral blob —
// also feeds the Health package's blood-pressure tracking.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('CAL', 'foodcomponent','nutrition','Calories (kcal, per 100g)',     0,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('PROT','foodcomponent','nutrition','Protein (mg, per 100g)',       0,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('CARB','foodcomponent','nutrition','Carbohydrate (mg, per 100g)',  0,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('FIBR','foodcomponent','nutrition','Fibre (mg, per 100g)',         0,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('SUGR','foodcomponent','nutrition','Sugar (mg, per 100g)',         0,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('SOD', 'foodcomponent','nutrition','Sodium (mg, per 100g)',        0,3,'','text',NULL)";

// Compound nutrition items — long-tail fields nobody browses individually, one row
// each, JSON payload in liberty_xref.data. 'json-list' is a generic liberty item
// template (xref/view_json-list_item.tpl, added 2026-08-17, moved under templates/xref/
// 2026-08-17) — each key renders as its
// own line in a nested table within the cell, not separate outer rows (list_xref.tpl
// owns the outer <tr> per xref row, an item template can't add more — see liberty's
// MANUAL.md).
//
// liberty_xref_item.data (normally an unused 'default/hint' column) holds a JSON array
// of every possible sub-field for this item — the edit template needs this because a
// component's actual stored blob only has whichever keys the importer had real values
// for (2026-08-17: found live on a component with only 2 of FAT's 6 possible keys) —
// without the full list, there's no way to add a currently-missing field via the edit
// form, only edit ones that already happen to be present.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('FAT','foodcomponent','nutrition','Fat breakdown (mg, per 100g)',    0,3,'','json-list','[\"total_mg\",\"saturated_mg\",\"mono_mg\",\"poly_mg\",\"trans_mg\",\"cholesterol_mg\"]')";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('VIT','foodcomponent','nutrition','Vitamins (mixed units, per 100g)',0,3,'','json-list','[\"vitamin_a_mcg\",\"vitamin_c_mg\",\"vitamin_d_mcg\"]')";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('MIN','foodcomponent','nutrition','Minerals (mg, per 100g)',        0,3,'','json-list','[\"potassium_mg\",\"calcium_mg\",\"iron_mg\"]')";

// ── foodcomponent-specific group (sort_order=1: quantity, moved ahead of nutrition
// 2026-08-17) — pantry/movement tracking,
// entirely separate from the nutrition group above. Modeled on Stock's SGL/PCK/SHT/VOL
// (stock_component quantity group), adapted for food: SHT (sheet-cutting, PCB-specific)
// doesn't apply here; WT (weight) is new, no food-related use has come up in Stock
// itself yet either. A component declares ONE of SGL/WT/VOL as its own tracking type —
// PCK is NOT a competing type (mirrors Stock's own template='value' distinction), it's
// a stored pack-size multiplier feeding into whichever type the component actually
// uses (e.g. eggs: PCK=6 feeding SGL; cereal: PCK≈500 feeding WT).
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('quantity','foodcomponent','Quantity',1,3,'','')";

$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('SGL','foodcomponent','quantity','Single unit (count)', 0,3,'','text', NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('WT', 'foodcomponent','quantity','Weight (g)',           0,3,'','text', NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('VOL','foodcomponent','quantity','Volume (ml)',          0,3,'','text', NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('PCK','foodcomponent','quantity','Pack size',            0,3,'','value',NULL)";
// REM is also doing double duty until FoodMovement/stocktake actually needs its
// numeric value: `xkey_ext` holds an outstanding-work tag — 'REVIEW' means "this
// needs review" (set by the importer, cleared by hand via edit_component.tpl's
// tick floaticon once fixed), not "reviewed and fixed" — otherwise-spare column, no
// reason to add a dedicated xref item just for a status word. (Was 'CORRECT' until
// 2026-08-17 — renamed, it read ambiguously as "this is correct" rather than "needs
// correcting".) `data` (on liberty_content, not REM itself) is a free-text note,
// completely decoupled from that status — starts as the food_info importer's own
// curation flag (no gram basis, missing FIBR) but can be edited/replaced with any
// manual note at any time without affecting anything, since list_review.php only
// ever reads `xkey_ext` to decide what's still outstanding, never the note's
// content.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('REM','foodcomponent','quantity','Remaining stock',      0,3,'','value',NULL)";

// ── supplier group, its own tab (sort_order=2, right after quantity) — corrects the
// 2026-08-17 decision to cram SUP into the quantity group ("cheating"), mirroring
// Stock's own dedicated 'supplier' group instead (see stock/admin/schema_inc.php +
// add_supplier.php + templates/xref/stockcomponent/view_sup_group.tpl, the proven real
// pattern this is copied from). Group-level template='sup' — a custom group template
// (xref/foodcomponent/view_sup_group.tpl), not the generic list_xref.tpl, same reason Stock's isn't
// generic either: a supplier row needs its own Supplier/Price/Note columns, not the
// generic Type/Value/Notes shape. multiple=1: a generic product can legitimately have
// several real suppliers (bought from different shops at different times).
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('supplier','foodcomponent','Supplier',2,3,'','sup')";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('SUP','foodcomponent','supplier','Supplier',              1,3,'../contact/?content_id=','sup',NULL)";

// ── foodassembly group (sort_order=0: 'type', not 'items' — 'items' reads as
// confusingly close to liberty_xref_item itself) — a meal instance's ingredient
// list. No separate classification xref — the item code itself IS the meal type
// (confirmed with Lester 2026-08-16: collapse classification+line-items into one
// mechanism, rather than a single classifying xref plus a separate generic 'ITEM'
// line-item type). A Breakfast assembly's rows are all item='BREAKFAST'; which code
// populated tells you the type, nothing else needed. Each row: xref=the
// FoodComponent's content_id, xkey=grams quantity, xorder=position within the meal.
// multi=1 lets many rows share the same item code on one assembly.
//
// meal_type mapping confirmed against real food_intake/nutrition data: 100001=
// Breakfast, 100002=Lunch, 100003=Dinner, 100004=Morning snack, 100006=Evening snack
// (100005 unused, a gap in Samsung's own scheme).
//
// RECIPE/FAVOURITE (and later MEAL, kitting-side like Stock's PBLD) follow the same
// pattern later, once actually needed — not registered yet, food_intake import only
// needs the five diary meal-types.
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('type','foodassembly','Type',0,3,'','')";

$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('BREAKFAST','foodassembly','type','Breakfast',      1,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('LUNCH',    'foodassembly','type','Lunch',          1,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('DINNER',   'foodassembly','type','Dinner',         1,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('MSNK',     'foodassembly','type','Morning snack',  1,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('ESNK',     'foodassembly','type','Evening snack',  1,3,'','text',NULL)";

$gBitInstaller->registerSchemaDefault( FOOD_PKG_NAME, array_merge( $xrefTypes, $xrefItems ) );
