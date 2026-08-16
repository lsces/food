<?php

// No tables — FoodComponent is a pure liberty_content record, content_id only, no
// component-id-alias table (see Claude memory feedback_content_id_only: Stock's own
// stock_component/stock_assembly tables were retired 2026-06-01 for the same reason).
// FoodAssembly/FoodMovement are still to design.

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
] );

// ### Requirements
$gBitInstaller->registerRequirements( FOOD_PKG_NAME, [
	'liberty' => [ 'min' => '5.0.1' ],
] );

// ### Xref seed data
// liberty_xref_group: x_group, content_type_guid, title, sort_order, role_id, type_href
// liberty_xref_item:  item, content_type_guid, x_group, cross_ref_title, multiple, role_id, cross_ref_href, template, data

$X = BIT_DB_PREFIX;

$xrefTypes = [];
$xrefItems = [];

// ── 'food' package-level group — external system reference, shared across every food
// content type that gets imported from Samsung Health. Same idea as Stock's KLID
// (Kitlocker ID Code): an external system's own id, kept as a plain xref item rather
// than a schema column, so re-import can dedupe/upsert on it without needing a table.
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('external','food','External Reference',0,3,'','')";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('DUID','food','external','Samsung Health datauuid',0,3,'','text',NULL)";
// PFID = food_info.provider_food_id verbatim ('fatsecret-<id>' or 'quickinput-<uuid>').
// Provenance ('is this a hand-entered fix') is a prefix check on this at query/curation
// time, not a separate stored flag — see project_food_package_scoping memory.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('PFID','food','external','Samsung Health provider_food_id',0,3,'','text',NULL)";

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
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('nutrition','foodcomponent','Nutrition',1,3,'','')";

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
// each, JSON payload in liberty_xref.data. No generic JSON-xref mechanism exists in
// liberty yet, so 'json' is a food-package-local template (still to build) rather
// than a liberty one — see project_food_package_scoping memory.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('FAT','foodcomponent','nutrition','Fat breakdown (mg, per 100g)',    0,3,'','json',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('VIT','foodcomponent','nutrition','Vitamins (mixed units, per 100g)',0,3,'','json',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('MIN','foodcomponent','nutrition','Minerals (mg, per 100g)',        0,3,'','json',NULL)";

// ── foodcomponent-specific group (sort_order=2: quantity) — pantry/movement tracking,
// entirely separate from the nutrition group above. Modeled on Stock's SGL/PCK/SHT/VOL
// (stock_component quantity group), adapted for food: SHT (sheet-cutting, PCB-specific)
// doesn't apply here; WT (weight) is new, no food-related use has come up in Stock
// itself yet either. A component declares ONE of SGL/WT/VOL as its own tracking type —
// PCK is NOT a competing type (mirrors Stock's own template='value' distinction), it's
// a stored pack-size multiplier feeding into whichever type the component actually
// uses (e.g. eggs: PCK=6 feeding SGL; cereal: PCK≈500 feeding WT).
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('quantity','foodcomponent','Quantity',2,3,'','')";

$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('SGL','foodcomponent','quantity','Single unit (count)', 0,3,'','text', NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('WT', 'foodcomponent','quantity','Weight (g)',           0,3,'','text', NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('VOL','foodcomponent','quantity','Volume (ml)',          0,3,'','text', NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('PCK','foodcomponent','quantity','Pack size',            0,3,'','value',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('REM','foodcomponent','quantity','Remaining stock',      0,3,'','value',NULL)";

$gBitInstaller->registerSchemaDefault( FOOD_PKG_NAME, array_merge( $xrefTypes, $xrefItems ) );
