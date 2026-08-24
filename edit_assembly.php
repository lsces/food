<?php
/**
 * Edit a single FoodAssembly (meal instance) — change its meal type (only among
 * types not already taken that day — see FoodAssembly::getAvailableMealTypes()),
 * manage its ingredient list (quantity correction, removal), and delete the whole
 * meal. Ingredient add/remove/quantity-edit all go through FoodAssembly's own
 * methods (addItem() via add_assembly_item.php, removeItem(), updateItem()), never
 * liberty's generic edit_xref.php — that path knows nothing about the REM
 * pantry-balance side-effect (see those methods' docblocks).
 *
 * @package food
 */

namespace Bitweaver\Food;

use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

// Time field below reads/writes local (display-timezone) wall-clock time via
// BitDate, converting to/from true UTC for storage — bitweaver's own
// established convention (see kernel/includes/classes/BitDate.php's docblock:
// "Dates will always stored in UTC... Display dates will be computed based on
// the preferred display offset"), already used elsewhere (articles' publish/
// expire date pickers). Food never plugged into it until 2026-08-22 — found
// via a real BST-period timestamp discrepancy, see Claude memory
// project_food_bst_timestamp_fix for the full incident.
$gBitDate = $gBitSystem->mServerTimestamp;

$gBitSystem->verifyPackage( 'food' );

$gContent = new FoodAssembly( !empty( $_REQUEST['content_id'] ) ? (int)$_REQUEST['content_id'] : null );
$gContent->load();

if( !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No meal exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

$gContent->verifyUpdatePermission();

$errors = [];

if( !empty( $_REQUEST['remove_xref_id'] ) ) {
	// Hard-deletes the line and restocks its REM contribution (see
	// FoodAssembly::removeItem()) — gate on expunge permission, not just the
	// update permission the page load already checked, matching
	// edit_movement.php's identical remove_xref_id branch/convention.
	$gContent->verifyExpungePermission();
	$gContent->removeItem( (int)$_REQUEST['remove_xref_id'] );
	header( 'Location: '.FOOD_PKG_URL.'edit_assembly.php?content_id='.$gContent->mContentId );
	die;

} elseif( !empty( $_REQUEST['update_xref_id'] ) ) {
	// Corrects an existing line's quantity in place — reverses the line's old
	// actual REM contribution and applies the new one (see
	// FoodAssembly::updateItem()), same "actual delta, not nominal" accuracy
	// removeItem()/expunge() rely on. Mirrors edit_movement.php's identical
	// update_xref_id branch/convention.
	$newQty = trim( $_REQUEST['new_quantity'] ?? '' );
	if( !is_numeric( $newQty ) || (float)$newQty <= 0 ) {
		$errors['quantity'] = KernelTools::tra( 'Quantity must be a positive number.' );
	} elseif( $gContent->updateItem( (int)$_REQUEST['update_xref_id'], (float)$newQty ) ) {
		header( 'Location: '.FOOD_PKG_URL.'edit_assembly.php?content_id='.$gContent->mContentId );
		die;
	} else {
		$errors['quantity'] = KernelTools::tra( 'Failed to update line.' );
	}

} elseif( !empty( $_REQUEST['delete'] ) ) {
	// Deletes the whole meal — restocks every ingredient's REM first (see
	// FoodAssembly::expunge()). Mirrors edit_movement.php's/stock's
	// edit_assembly.php's identical delete/confirm/cancel convention.
	$gBitSystem->verifyPermission( 'p_food_expunge' );
	if( !empty( $_REQUEST['cancel'] ) ) {
		header( 'Location: '.FOOD_PKG_URL.'view_assembly.php?content_id='.$gContent->mContentId );
		die;
	} elseif( empty( $_REQUEST['confirm'] ) ) {
		$gBitSystem->confirmDialog(
			[ 'delete' => true, 'content_id' => $gContent->mContentId ],
			[
				'warning' => KernelTools::tra( 'Are you sure you want to delete this meal? This restocks the pantry for its ingredients.' ).' ('.$gContent->getTitle().')',
				'error'   => KernelTools::tra( 'This cannot be undone!' ),
			]
		);
	} else {
		// Captured before expunge() clears mContentId — view_day.php's own
		// day-boundary convention (gmdate('Y-m-d 00:00:00', ...), see
		// FoodAssembly::mealTypesTakenOnDay()) so this lands back on the day
		// the deleted meal used to belong to.
		$dayDateStr = gmdate( 'Y-m-d', (int)$gContent->getField( 'event_time' ) );
		$gContent->expunge();
		header( 'Location: '.FOOD_PKG_URL.'view_day.php?date='.$dayDateStr );
		die;
	}

} elseif( !empty( $_REQUEST['save'] ) ) {
	$newType = $_REQUEST['meal_type'] ?? null;
	if( $newType && $newType !== $gContent->getMealType() ) {
		if( !$gContent->changeMealType( $newType ) ) {
			$errors = $gContent->mErrors;
		}
	}
	// Time only — the date is fixed, not editable here (that's what
	// copy_assembly.php is for). The typed HH:MM is the user's local wall-clock
	// time — combined with the *displayed* (local) date, then converted through
	// BitDate to true UTC for storage.
	$timeStr = trim( $_REQUEST['event_time'] ?? '' );
	if( !$errors && preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $timeStr, $m ) ) {
		$currentEventTime = (int)$gContent->getField( 'event_time' );
		$displayCurrent = $gBitDate->getDisplayDateFromUTC( $currentEventTime );
		// gmmktime(), not strtotime(gmdate(...)) — BitDate's own conversion calls
		// mutate PHP's ambient default timezone as a side effect (never restored),
		// so a bare strtotime() here would silently re-interpret the naive string
		// against that just-changed timezone instead of UTC, double-shifting the
		// result. Same defensive pattern BitArticle::store() uses for exactly this
		// reason (gmmktime()/getUTCFromDisplayDate(), never strtotime() alone).
		$dayStart = gmmktime( 0, 0, 0, (int)gmdate( 'n', $displayCurrent ), (int)gmdate( 'j', $displayCurrent ), (int)gmdate( 'Y', $displayCurrent ) );
		$newEventTime = $gBitDate->getUTCFromDisplayDate( $dayStart + ( (int)$m[1] * 3600 ) + ( (int)$m[2] * 60 ) );
		if( $newEventTime !== $currentEventTime ) {
			if( !$gContent->changeEventTime( $newEventTime ) ) {
				$errors = $gContent->mErrors;
			}
		}
	}
	if( !$errors ) {
		// Back to the meal, not back to this same edit page — there's nothing left
		// to do here once meal type/time are saved (ingredient add/edit/remove are
		// each their own separate page with their own redirect back to here, not
		// this button).
		header( 'Location: '.FOOD_PKG_URL.'view_assembly.php?content_id='.$gContent->mContentId );
		die;
	}
}

$mealType = $gContent->getMealType();

$eventTime    = (int)$gContent->getField( 'event_time' );
$displayEventTime = $gBitDate->getDisplayDateFromUTC( $eventTime );
$dateFixed    = gmdate( 'Y-m-d', $displayEventTime );
$timeDisplay  = gmdate( 'H:i', $displayEventTime );

$gBitSmarty->assign( 'gContent',      $gContent );
$gBitSmarty->assign( 'mealType',      $mealType );
$gBitSmarty->assign( 'mealLabel',     FoodAssembly::mealTypeLabel( $mealType ?? '' ) );
$gBitSmarty->assign( 'mealTypes',     $gContent->getAvailableMealTypes() );
$gBitSmarty->assign( 'items',         $gContent->getItems() );
$gBitSmarty->assign( 'dateFixed',     $dateFixed );
$gBitSmarty->assign( 'timeDisplay',   $timeDisplay );
$gBitSmarty->assign( 'errors',        $errors );

$gBitSystem->display( 'bitpackage:food/edit_assembly.tpl', KernelTools::tra( 'Edit' ).' '.FoodAssembly::mealTypeLabel( $mealType ?? '' ), [ 'display_mode' => 'edit' ] );
