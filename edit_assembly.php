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
	// copy_assembly.php is for). Plain UTC arithmetic, matching every other
	// event_time computation in FoodAssembly.php — no display-timezone
	// conversion, to stay consistent with the day-boundary math elsewhere.
	$timeStr = trim( $_REQUEST['event_time'] ?? '' );
	if( !$errors && preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $timeStr, $m ) ) {
		$currentEventTime = (int)$gContent->getField( 'event_time' );
		$dayStart = strtotime( gmdate( 'Y-m-d 00:00:00', $currentEventTime ) );
		$newEventTime = $dayStart + ( (int)$m[1] * 3600 ) + ( (int)$m[2] * 60 );
		if( $newEventTime !== $currentEventTime ) {
			if( !$gContent->changeEventTime( $newEventTime ) ) {
				$errors = $gContent->mErrors;
			}
		}
	}
	if( !$errors ) {
		header( 'Location: '.FOOD_PKG_URL.'edit_assembly.php?content_id='.$gContent->mContentId );
		die;
	}
}

$mealType = $gContent->getMealType();

$eventTime    = (int)$gContent->getField( 'event_time' );
$dateFixed    = gmdate( 'Y-m-d', $eventTime );
$timeDisplay  = gmdate( 'H:i', $eventTime );

$gBitSmarty->assign( 'gContent',      $gContent );
$gBitSmarty->assign( 'mealType',      $mealType );
$gBitSmarty->assign( 'mealLabel',     FoodAssembly::mealTypeLabel( $mealType ?? '' ) );
$gBitSmarty->assign( 'mealTypes',     $gContent->getAvailableMealTypes() );
$gBitSmarty->assign( 'items',         $gContent->getItems() );
$gBitSmarty->assign( 'dateFixed',     $dateFixed );
$gBitSmarty->assign( 'timeDisplay',   $timeDisplay );
$gBitSmarty->assign( 'errors',        $errors );

$gBitSystem->display( 'bitpackage:food/edit_assembly.tpl', KernelTools::tra( 'Edit' ).' '.FoodAssembly::mealTypeLabel( $mealType ?? '' ), [ 'display_mode' => 'edit' ] );
