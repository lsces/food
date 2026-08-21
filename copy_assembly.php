<?php
/**
 * Copy a FoodAssembly's ingredient list onto a new date — for a recurring pattern
 * (e.g. the same three-item Breakfast most days) where re-adding each ingredient
 * by hand every time is pure friction. Meal type stays the same as the source;
 * only the date is chosen. Time-of-day is preserved from the source meal, just
 * moved onto the new calendar date, so a 07:30 Breakfast copies to another 07:30
 * Breakfast rather than landing at midnight.
 *
 * Same day-uniqueness rule as changeMealType()/changeEventTime()/createForDay()
 * applies here too — copying onto a date that already has this meal type errors
 * rather than silently creating a second one.
 *
 * @package food
 */

namespace Bitweaver\Food;

use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'food' );

$source = new FoodAssembly( !empty( $_REQUEST['content_id'] ) ? (int)$_REQUEST['content_id'] : null );
$source->load();

if( !$source->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No meal exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

$source->verifyViewPermission();
$gBitSystem->verifyPermission( 'p_food_create' );

$mealType  = $source->getMealType();
$sourceItems = $source->getItems();
$errors = [];

if( !empty( $_REQUEST['save'] ) ) {
	$dateStr = trim( $_REQUEST['date'] ?? '' );
	$newDay  = $dateStr ? strtotime( $dateStr ) : false;
	if( !$mealType ) {
		$errors['meal_type'] = 'This meal has no items to copy.';
	} elseif( $newDay === false ) {
		$errors['date'] = 'Please choose a valid date.';
	} else {
		$newDayStart = strtotime( gmdate( 'Y-m-d 00:00:00', $newDay ) );
		$timeOfDay   = (int)$source->getField( 'event_time' ) % 86400;
		$newEventTime = $newDayStart + $timeOfDay;

		$taken = FoodAssembly::mealTypesTakenOnDay( $newEventTime );
		if( in_array( $mealType, $taken, true ) ) {
			$errors['date'] = FoodAssembly::mealTypeLabel( $mealType ).' already exists for this day.';
		} else {
			$new = new FoodAssembly();
			$title = FoodAssembly::mealTypeLabel( $mealType ).' — '.gmdate( 'Y-m-d', $newDayStart );
			// store() takes &$pParamHash by reference — must be a named variable,
			// not a literal array, or it fatals.
			$pHash = [ 'title' => $title, 'event_time' => $newEventTime ];
			if( $new->store( $pHash ) ) {
				foreach( $sourceItems as $item ) {
					$new->addItem( $mealType, (int)$item['component_content_id'], (int)$item['quantity'], (int)$item['xorder'] );
				}
				header( 'Location: '.FOOD_PKG_URL.'view_assembly.php?content_id='.$new->mContentId );
				die;
			}
			$errors = $new->mErrors;
		}
	}
}

$gBitSmarty->assign( 'gContent',    $source );
$gBitSmarty->assign( 'mealLabel',   FoodAssembly::mealTypeLabel( $mealType ?? '' ) );
$gBitSmarty->assign( 'items',       $sourceItems );
$gBitSmarty->assign( 'errors',      $errors );

$gBitSystem->display( 'bitpackage:food/copy_assembly.tpl', KernelTools::tra( 'Copy' ).' '.FoodAssembly::mealTypeLabel( $mealType ?? '' ), [ 'display_mode' => 'edit' ] );
