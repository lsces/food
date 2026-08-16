<?php
/**
 * Add a single ingredient line to a FoodAssembly (meal instance). Mirrors
 * stock/add_movement_component.php closely — same title-lookup-or-create-new flow,
 * same typeahead search — but simpler: no item-type choice (the meal's own type,
 * from getMealType(), is fixed) and no xkey_ext/note fields (not meaningful here).
 *
 * @package food
 */

namespace Bitweaver\Food;

use Bitweaver\KernelTools;
use Bitweaver\Liberty\LibertyXref;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gContent = new FoodAssembly( !empty( $_REQUEST['content_id'] ) ? (int)$_REQUEST['content_id'] : null );
$gContent->load();

if( !$gContent->isValid() ) {
	$gBitSystem->fatalError( 'No valid meal specified.' );
}
$gContent->verifyUpdatePermission();

$mealType = $gContent->getMealType();
$errors = [];

if( !empty( $_REQUEST['fCancel'] ) ) {
	header( 'Location: '.FOOD_PKG_URL.'edit_assembly.php?content_id='.$gContent->mContentId );
	die;
}

if( !empty( $_REQUEST['fAddComponent'] ) ) {
	$title = trim( $_REQUEST['component_title'] ?? '' );
	$qty   = trim( $_REQUEST['xkey'] ?? '' );

	if( $title === '' ) {
		$errors[] = KernelTools::tra( 'Component title is required.' );
	} elseif( !is_numeric( $qty ) || (float)$qty <= 0 ) {
		$errors[] = KernelTools::tra( 'Quantity must be a positive number.' );
	} else {
		$compId = (int)$gBitDb->getOne(
			"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."liberty_content` lc
			 WHERE lc.`content_type_guid` = 'foodcomponent' AND lc.`title` = ?",
			[ $title ]
		);

		if( !$compId ) {
			header( 'Location: '.FOOD_PKG_URL.'edit_component.php?title='.urlencode( $title ) );
			die;
		}

		$nextXorder = (int)$gBitDb->getOne(
			"SELECT COALESCE( MAX(x.`xorder`) + 1, 1 ) FROM `".BIT_DB_PREFIX."liberty_xref` x
			 WHERE x.`content_id` = ? AND x.`item` = ?",
			[ $gContent->mContentId, $mealType ]
		) ?: 1;

		$xrefObj = new LibertyXref();
		$xrefObj->mContentTypeGuid = 'foodassembly';
		$pHash = [
			'content_id' => $gContent->mContentId,
			'item'       => $mealType,
			'xorder'     => $nextXorder,
			'xref'       => $compId,
			'xkey'       => (string)(int)round( (float)$qty ),
		];
		if( $xrefObj->store( $pHash ) ) {
			header( 'Location: '.FOOD_PKG_URL.'edit_assembly.php?content_id='.$gContent->mContentId );
			die;
		}
		$errors[] = KernelTools::tra( 'Failed to store ingredient.' );
	}
}

$gBitSmarty->assign( 'gContent',  $gContent );
$gBitSmarty->assign( 'mealLabel', FoodAssembly::mealTypeLabel( $mealType ?? '' ) );
$gBitSmarty->assign( 'errors',    $errors );
$gBitSmarty->assign( 'lookupUrl', FOOD_PKG_URL.'includes/lookup_component.php' );

$gBitSystem->display( 'bitpackage:food/add_assembly_item.tpl', KernelTools::tra( 'Add Ingredient' ).': '.$gContent->getTitle(), [ 'display_mode' => 'edit' ] );
