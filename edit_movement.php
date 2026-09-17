<?php
/**
 * Create or edit a FoodMovement (pantry receipt) — shop/date/note header, plus an
 * inline "add a component" form and the current line list, all on one page.
 * Deliberately narrower than stock/edit_movement.php: no CSV import, no assembly/
 * BOM kit-count rescaling — Food has no BOM-shaped movements (see food/CLAUDE.md's
 * FoodMovement design notes).
 *
 * The add-component handling used to be its own page (add_movement_component.php,
 * mirroring add_assembly_item.php's separate-page pattern) — folded in here
 * 2026-08-20 because tidying a real receipt with several items meant a full page
 * navigation per line, which the author flagged as too slow. Landing back on this same
 * page (rather than a separate view) after each add is the actual fix; the
 * component-picker JS itself is unchanged. Still delegates the actual insert to
 * FoodMovement::addComponentLine() (not a generic add_xref.php form) so the
 * referenced FoodComponent's REM balance stays in sync — see that method's
 * docblock for why.
 *
 * @package food
 */
namespace Bitweaver\Food;

use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;
use Bitweaver\Liberty\LibertyContent;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb, $gBitThemes;

$gBitSystem->verifyPackage( 'food' );

$gContent = new FoodMovement( !empty( $_REQUEST['content_id'] ) ? (int)$_REQUEST['content_id'] : null );
$gContent->load();

if( !empty( $_REQUEST['content_id'] ) && !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No movement exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

if( $gContent->isValid() ) {
	$gContent->verifyUpdatePermission();
} else {
	$gBitSystem->verifyPermission( 'p_food_create' );
}

$addErrors = [];

if( !empty( $_REQUEST['save'] ) ) {
	$title = trim( $_REQUEST['title'] ?? '' );
	if( $title === '' ) {
		$title = KernelTools::tra( 'Receipt' ).' — '.date( 'Y-m-d' );
	}
	$pHash = [ 'title' => $title ];
	if( $gContent->store( $pHash ) ) {
		$shopId  = !empty( $_REQUEST['shop_content_id'] ) && is_numeric( $_REQUEST['shop_content_id'] ) ? (int)$_REQUEST['shop_content_id'] : null;
		$refKey  = trim( $_REQUEST['ref_key'] ?? '' );
		$note    = trim( $_REQUEST['note'] ?? '' );
		// type="date" submits unambiguous ISO Y-m-d - a calendar date, not an instant, so
		// UTC-midnight for that date (matching FoodDay's own gmmktime(0,0,0,...) convention),
		// not a display-timezone conversion.
		$dateParts = array_map( 'intval', explode( '-', trim( $_REQUEST['purchase_date'] ?? '' ) ) );
		$purDate = count( $dateParts ) === 3 ? gmmktime( 0, 0, 0, $dateParts[1], $dateParts[2], $dateParts[0] ) : null;
		$gContent->setReceiptReference( $shopId, $refKey, $purDate, $note );
		header( 'Location: '.FOOD_PKG_URL.'edit_movement.php?content_id='.$gContent->mContentId );
		die;
	}

} elseif( !empty( $_REQUEST['fAddComponent'] ) && $gContent->isValid() ) {
	$title = trim( $_REQUEST['component_title'] ?? '' );
	$qty   = trim( $_REQUEST['quantity'] ?? '' );
	$mode  = ( $_REQUEST['qty_mode'] ?? 'base' ) === 'sgl' ? 'sgl' : 'base';

	if( $title === '' ) {
		$addErrors[] = KernelTools::tra( 'Food item title is required.' );
	} elseif( !is_numeric( $qty ) || (float)$qty <= 0 ) {
		$addErrors[] = KernelTools::tra( 'Quantity must be a positive number.' );
	} else {
		// Picked from the dropdown, which shows supplier alongside title so
		// same-titled components from different shops are distinguishable — same
		// reasoning/pattern as add_assembly_item.php. Still verified here rather
		// than trusted blindly; a tampered or stale id falls through to the
		// exact-title lookup instead.
		$compId = LibertyContent::resolveContentIdByTitle( (int)( $_REQUEST['component_id'] ?? 0 ), $title, 'foodcomponent' );

		if( !$compId ) {
			// No path back to the movement after creating the component here — same
			// accepted limitation as add_assembly_item.php's identical redirect.
			header( 'Location: '.FOOD_PKG_URL.'edit_component.php?title='.urlencode( $title ) );
			die;
		}

		if( $gContent->addComponentLine( $compId, (float)$qty, $mode ) ) {
			header( 'Location: '.FOOD_PKG_URL.'edit_movement.php?content_id='.$gContent->mContentId.'#add-component' );
			die;
		}
		$addErrors = !empty( $gContent->mErrors ) ? array_values( $gContent->mErrors ) : [ KernelTools::tra( 'Failed to add component.' ) ];
	}

} elseif( !empty( $_REQUEST['remove_xref_id'] ) && $gContent->isValid() ) {
	// Hard-deletes the line (see FoodMovement::removeComponentLine()) — gate on
	// expunge permission, not just the update permission the page load already
	// checked, matching liberty's own convention for expunge=3.
	$gContent->verifyExpungePermission();
	$gContent->removeComponentLine( (int)$_REQUEST['remove_xref_id'] );
	header( 'Location: '.FOOD_PKG_URL.'edit_movement.php?content_id='.$gContent->mContentId.'#add-component' );
	die;

} elseif( !empty( $_REQUEST['update_xref_id'] ) && $gContent->isValid() ) {
	$newQty = trim( $_REQUEST['new_quantity'] ?? '' );
	if( !is_numeric( $newQty ) || (float)$newQty <= 0 ) {
		$addErrors[] = KernelTools::tra( 'Quantity must be a positive number.' );
	} elseif( $gContent->updateComponentLine( (int)$_REQUEST['update_xref_id'], (float)$newQty ) ) {
		header( 'Location: '.FOOD_PKG_URL.'edit_movement.php?content_id='.$gContent->mContentId.'#add-component' );
		die;
	} else {
		$addErrors = !empty( $gContent->mErrors ) ? array_values( $gContent->mErrors ) : [ KernelTools::tra( 'Failed to update line.' ) ];
	}

} elseif( !empty( $_REQUEST['delete'] ) ) {
	// Confirmation happens client-side (view_movement.tpl's onclick="return
	// confirm(...)" — same lightweight pattern kernel's admin menu/module-config/
	// layout delete links already use) rather than a server-rendered
	// confirmDialog() round-trip — there are no extra choices to offer (unlike
	// e.g. stock's assembly delete, which asks about a recurse option), so a
	// second full page load would just be unnecessary friction.
	$gBitSystem->verifyPermission( 'p_food_expunge' );
	$gContent->expunge();
	header( 'Location: '.FOOD_PKG_URL.'list_movements.php' );
	die;
}

$shops = LibertyContent::listContentByXrefItem( 'B04', 'contactbusiness' );

$gBitSmarty->assign( 'gContent',        $gContent );
$gBitSmarty->assign( 'shops',           $shops );
$gBitSmarty->assign( 'lines',           $gContent->getLines() );
$gBitSmarty->assign( 'purchaseDateVal', !empty( $gContent->mInfo['ref_start_date'] ) ? gmdate( 'Y-m-d', $gContent->mInfo['ref_start_date'] ) : '' );
$gBitSmarty->assign( 'errors',          $gContent->mErrors );
$gBitSmarty->assign( 'addErrors',       $addErrors );

if( $gContent->isValid() ) {
	$gBitThemes->loadJavascript( KERNEL_PKG_PATH.'scripts/BitComponentTypeahead.js', true );
}

$gBitSystem->display( 'bitpackage:food/edit_movement.tpl', KernelTools::tra( 'Edit Receipt' ), [ 'display_mode' => 'edit' ] );
