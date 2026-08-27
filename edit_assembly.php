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

} elseif( !empty( $_REQUEST['fAddComponent'] ) ) {
	// Inline add, same shape as edit_movement.php's own fAddComponent branch —
	// stays on this page rather than bouncing out to add_assembly_item.php, so
	// adding several ingredients in a row doesn't cost a page round-trip each
	// time. add_assembly_item.php/.tpl are left in place as a still-working
	// fallback, not replaced.
	global $gBitDb;
	$title  = trim( $_REQUEST['component_title'] ?? '' );
	$compId = (int)( $_REQUEST['component_id'] ?? 0 );
	$qty    = trim( $_REQUEST['xkey'] ?? '' );

	if( $title === '' ) {
		$errors['add'] = KernelTools::tra( 'Food item title is required.' );
	} else {
		if( $compId ) {
			$valid = (bool)$gBitDb->getOne(
				"SELECT 1 FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ? AND `content_type_guid` = 'foodcomponent'",
				[ $compId ]
			);
			if( !$valid ) {
				$compId = 0;
			}
		}
		if( !$compId ) {
			$compId = (int)$gBitDb->getOne(
				"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."liberty_content` lc
				 WHERE lc.`content_type_guid` = 'foodcomponent' AND lc.`title` = ?",
				[ $title ]
			);
		}

		if( !$compId ) {
			header( 'Location: '.FOOD_PKG_URL.'edit_component.php?title='.urlencode( $title ) );
			die;
		}

		$baseRow = $gBitDb->getRow(
			"SELECT `item`, `xkey` FROM `".BIT_DB_PREFIX."liberty_xref`
			 WHERE `content_id` = ? AND `item` IN ('WT','VOL')",
			[ $compId ]
		);

		$mode = ( $_REQUEST['qty_mode'] ?? 'base' ) === 'sgl' ? 'sgl' : 'base';
		if( $qty === '' ) {
			$qty = $mode === 'sgl' ? '1' : (string)( $baseRow['xkey'] ?? '' );
		}

		if( !is_numeric( $qty ) || (float)$qty <= 0 ) {
			$errors['add'] = KernelTools::tra( 'Quantity must be a positive number — this component has no declared weight/volume to default from.' );
		} else {
			if( $mode === 'sgl' ) {
				$hasSgl = (bool)$gBitDb->getOne(
					"SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'SGL'",
					[ $compId ]
				);
				if( !$hasSgl ) {
					$errors['add'] = KernelTools::tra( 'This component is not flagged for count-based (SGL) tracking.' );
				} elseif( !$baseRow || !is_numeric( $baseRow['xkey'] ?? null ) ) {
					$errors['add'] = KernelTools::tra( 'This component has no declared weight/volume to convert a count through.' );
				} else {
					$grams = (float)$qty * (float)$baseRow['xkey'];
				}
			} else {
				$grams = (float)$qty;
			}
		}

		if( !$errors && isset( $grams ) ) {
			$nextXorder = (int)$gBitDb->getOne(
				"SELECT COALESCE( MAX(x.`xorder`) + 1, 1 ) FROM `".BIT_DB_PREFIX."liberty_xref` x
				 WHERE x.`content_id` = ? AND x.`item` = ?",
				[ $gContent->mContentId, $gContent->getMealType() ]
			) ?: 1;

			$roundedQty  = (float)round( $grams );
			$actualDelta = ( new FoodMovement() )->adjustComponentRem( $compId, -$roundedQty );

			$xrefObj = new \Bitweaver\Liberty\LibertyXref();
			$xrefObj->mContentTypeGuid = 'foodassembly';
			$pHash = [
				'content_id' => $gContent->mContentId,
				'item'       => $gContent->getMealType(),
				'xorder'     => $nextXorder,
				'xref'       => $compId,
				'xkey'       => (string)(int)$roundedQty,
				'xkey_ext'   => (string)( -$actualDelta ),
			];
			if( $xrefObj->store( $pHash ) ) {
				header( 'Location: '.FOOD_PKG_URL.'edit_assembly.php?content_id='.$gContent->mContentId );
				die;
			}
			$errors['add'] = KernelTools::tra( 'Failed to store food item.' );
		}
	}

} elseif( !empty( $_REQUEST['delete'] ) ) {
	// Deletes the whole meal — restocks every ingredient's REM first (see
	// FoodAssembly::expunge()). Confirmation happens client-side
	// (view_assembly.tpl's onclick="return confirm(...)" — same lightweight
	// pattern kernel's admin menu/module-config/layout delete links already
	// use) rather than a server-rendered confirmDialog() round-trip — no extra
	// choices to offer here (unlike stock's assembly delete, which asks about
	// a recurse option), so a second full page load would just be friction.
	$gBitSystem->verifyPermission( 'p_food_expunge' );
	// Captured before expunge() clears mContentId — view_day.php's own
	// day-boundary convention (gmdate('Y-m-d 00:00:00', ...), see
	// FoodAssembly::mealTypesTakenOnDay()) so this lands back on the day the
	// deleted meal used to belong to.
	$dayDateStr = gmdate( 'Y-m-d', (int)$gContent->getField( 'event_time' ) );
	$gContent->expunge();
	header( 'Location: '.FOOD_PKG_URL.'view_day.php?date='.$dayDateStr );
	die;

} elseif( !empty( $_REQUEST['second_take'] ) ) {
	// Guest for dinner — see FoodAssembly::takeSecondPortion()'s docblock. Gated by
	// the same verifyUpdatePermission() already checked above for this whole page,
	// same convention add_assembly_item.php's REM decrement uses (no separate
	// stock permission).
	$gContent->takeSecondPortion();
	header( 'Location: '.FOOD_PKG_URL.'edit_assembly.php?content_id='.$gContent->mContentId.'&second_take_done=1' );
	die;

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
		// Back to the meal once it actually has items - "Add Food Item" only lives
		// on this edit page (not view_assembly.php), so a brand-new/still-empty
		// meal needs to stay here after saving type/time, or the only way back to
		// it is a manual re-navigation. Once there's at least one item, there's
		// nothing left to do on this page (ingredient add/edit/remove are each
		// their own separate page with their own redirect back to here, not this
		// button), so view is the right landing spot again.
		$target = $gContent->getItems() ? 'view_assembly.php' : 'edit_assembly.php';
		header( 'Location: '.FOOD_PKG_URL.$target.'?content_id='.$gContent->mContentId );
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
$gBitSmarty->assign( 'secondTakeDone', !empty( $_REQUEST['second_take_done'] ) );

$gBitSystem->display( 'bitpackage:food/edit_assembly.tpl', KernelTools::tra( 'Edit' ).' '.FoodAssembly::mealTypeLabel( $mealType ?? '' ), [ 'display_mode' => 'edit' ] );
