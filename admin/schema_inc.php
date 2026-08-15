<?php

$tables = [

// no tables yet — FoodComponent/FoodAssembly/FoodMovement schema not designed yet,
// see Claude memory project_food_package_scoping for the plan.

];

global $gBitInstaller;

foreach( array_keys( $tables ) AS $tableName ) {
	$gBitInstaller->registerSchemaTable( FOOD_PKG_NAME, $tableName, $tables[$tableName] );
}

$gBitInstaller->registerPackageInfo( FOOD_PKG_NAME, [
	'description' => 'Food tracks ingredients, recipes, and consumption — imported from Samsung Health, modeled on the Stock package.',
	'license'     => '<a href="http://www.gnu.org/licenses/licenses.html#LGPL">LGPL</a>',
] );

$gBitInstaller->registerPreferences( FOOD_PKG_NAME, [
	[ FOOD_PKG_NAME, 'food_menu_text', 'Food' ],
] );
