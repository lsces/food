<?php
/**
 * Add a supplier to a FoodComponent — a real xref to a Contact business (B04
 * 'Supplier' type), not a text string. Plain <select> rather than Stock's
 * add_supplier.php typeahead: Food's supplier list is deliberately small (the known
 * shops added as Contacts), a dropdown is the better fit while it stays that way — see
 * project_food_package_scoping memory.
 *
 * @package food
 */

namespace Bitweaver\Food;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitUser, $gBitDb;

$gBitSystem->verifyPackage( 'food' );

$gContent = new FoodComponent( !empty( $_REQUEST['content_id'] ) ? (int)$_REQUEST['content_id'] : null );
$gContent->load();

if( !$gContent->isValid() ) {
	$gBitSystem->fatalError( 'No valid component specified.' );
}

$gContent->verifyUpdatePermission();

if( !empty( $_REQUEST['fCancel'] ) ) {
	header( 'Location: '.$gContent->getEditUrl() );
	die;
}

if( !empty( $_REQUEST['fAddSupplier'] ) ) {
	if( empty( $_REQUEST['supplier_content_id'] ) || !is_numeric( $_REQUEST['supplier_content_id'] ) ) {
		$gContent->mErrors[] = KernelTools::tra( 'Please select a supplier.' );
	} else {
		$supHash = [
			'content_id' => $gContent->mContentId,
			'item'       => 'SUP',
			'xref'       => (int)$_REQUEST['supplier_content_id'],
			'xkey'       => trim( $_REQUEST['product_code'] ?? '' ),
			'xkey_ext'   => trim( $_REQUEST['price'] ?? '' ),
			'edit'       => trim( $_REQUEST['note'] ?? '' ),
			'fAddXref'   => 1,
		];
		$gContent->storeXref( $supHash );

		if( empty( $gContent->mErrors ) ) {
			header( 'Location: '.$gContent->getEditUrl() );
			die;
		}
	}
}

$suppliers = $gBitDb->getAll(
	"SELECT lc.`content_id`, lc.`title` FROM `".BIT_DB_PREFIX."liberty_content` lc
		JOIN `".BIT_DB_PREFIX."liberty_xref` lx ON ( lx.`content_id` = lc.`content_id` AND lx.`item` = 'B04' )
	 WHERE lc.`content_type_guid` = 'contactbusiness'
	 ORDER BY lc.`title`"
);

$gBitSmarty->assign( 'gContent',   $gContent );
$gBitSmarty->assign( 'suppliers',  $suppliers );
$gBitSmarty->assign( 'errors',     $gContent->mErrors );

$gBitSystem->display( 'bitpackage:food/add_supplier.tpl', KernelTools::tra( 'Add Supplier' ), [ 'display_mode' => 'edit' ] );
