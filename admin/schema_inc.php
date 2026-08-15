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

// ── foodcomponent-specific group (sort_order=1: nutrition) ─────────────────────────
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('nutrition','foodcomponent','Nutrition',1,3,'','')";

// Scalar nutrition items — worth individually browsing/sorting/tidying, own row each.
// FIBR deliberately promoted alongside PROT (Samsung's own app buries it; Lester rates
// it more useful day-to-day). SOD kept scalar rather than folded into a mineral blob —
// also feeds the Health package's blood-pressure tracking.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('CAL', 'foodcomponent','nutrition','Calories',      0,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('PROT','foodcomponent','nutrition','Protein',       0,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('CARB','foodcomponent','nutrition','Carbohydrate',  0,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('FIBR','foodcomponent','nutrition','Fibre',         0,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('SUGR','foodcomponent','nutrition','Sugar',         0,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('SOD', 'foodcomponent','nutrition','Sodium',        0,3,'','text',NULL)";

// Compound nutrition items — long-tail fields nobody browses individually, one row
// each, JSON payload in liberty_xref.data. No generic JSON-xref mechanism exists in
// liberty yet, so 'json' is a food-package-local template (still to build) rather
// than a liberty one — see project_food_package_scoping memory.
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('FAT','foodcomponent','nutrition','Fat (breakdown)',     0,3,'','json',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('VIT','foodcomponent','nutrition','Vitamins',            0,3,'','json',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('MIN','foodcomponent','nutrition','Minerals',            0,3,'','json',NULL)";

$gBitInstaller->registerSchemaDefault( FOOD_PKG_NAME, array_merge( $xrefTypes, $xrefItems ) );
