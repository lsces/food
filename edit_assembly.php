<?php
/**
 * Edit a single FoodAssembly (meal instance) — change its meal type (only among
 * types not already taken that day — see FoodAssembly::getAvailableMealTypes()),
 * and manage its ingredient list. Individual ingredient rows are edited/removed via
 * liberty's generic edit_xref.php (history-preserving delete via stepXref, proper
 * last_update_date) — nothing bespoke needed for that part. Adding a new ingredient
 * needs add_assembly_item.php (a component picker, which the generic add_xref.php
 * doesn't provide).
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

if( !empty( $_REQUEST['save'] ) ) {
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
