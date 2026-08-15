<?php
global $gBitSystem;

$pRegisterHash = [
	'package_name' => 'food',
	'package_path' => dirname( dirname( __FILE__ ) ).'/',
	'homeable'     => true,
];
// fix to quieten down VS Code which can't see the dynamic creation of these ...
define( 'FOOD_PKG_NAME', $pRegisterHash['package_name'] );
define( 'FOOD_PKG_URL', BIT_ROOT_URL . basename( $pRegisterHash['package_path'] ) . '/' );
define( 'FOOD_PKG_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/' );
define( 'FOOD_PKG_INCLUDE_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/includes/');
define( 'FOOD_PKG_CLASS_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/includes/classes/');
define( 'FOOD_PKG_ADMIN_PATH', BIT_ROOT_PATH . basename( $pRegisterHash['package_path'] ) . '/admin/');
define( 'FOOD_IMPORT_PATH', STORAGE_PKG_PATH . 'food/' );

$gBitSystem->registerPackage( $pRegisterHash );

if( $gBitSystem->isPackageActive( 'food' ) ) {

	$menuHash = [
		'package_name'  => FOOD_PKG_NAME,
		'index_url'     => FOOD_PKG_URL.'index.php',
		'menu_template' => 'bitpackage:food/menu_food.tpl',
	];
	$gBitSystem->registerAppMenu( $menuHash );

	// content-type registration lives in admin/schema_inc.php (registerContentObjects),
	// not here — FoodComponent is registered there. Service/hook registration (see
	// stock/includes/bit_setup_inc.php for the pattern) would go here if/when needed.
}
