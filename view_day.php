<?php
/**
 * Day view — one calendar day, all 5 meal-type slots stacked down the page (not
 * tabs, per Lester's own preference). Each slot either shows its existing meal's
 * ingredients (a real FoodAssembly, looked up via lookupByDayAndType()) or, if
 * nothing's logged for that slot yet, a "log a new meal" action
 * (FoodAssembly::createForDay()) that redirects straight into edit_assembly.php to
 * add ingredients.
 *
 * @package food
 */

namespace Bitweaver\Food;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitUser;

$gBitSystem->verifyPackage( 'food' );
$gBitSystem->verifyPermission( 'p_food_view' );

$dateStr  = trim( $_REQUEST['date'] ?? '' );
$dayStart = strtotime( gmdate( 'Y-m-d 00:00:00', ( $dateStr && strtotime( $dateStr ) ) ? strtotime( $dateStr ) : time() ) );

$errors = [];

if( !empty( $_REQUEST['create_type'] ) ) {
	$gBitSystem->verifyPermission( 'p_food_create' );
	$assembly = new FoodAssembly();
	$newId = $assembly->createForDay( $dayStart, $_REQUEST['create_type'] );
	if( $newId ) {
		header( 'Location: '.FOOD_PKG_URL.'edit_assembly.php?content_id='.$newId );
		die;
	}
	$errors = $assembly->mErrors;
}

$slots = [];
foreach( FoodAssembly::MEAL_TYPE_LABELS as $code => $label ) {
	$contentId = FoodAssembly::lookupByDayAndType( $dayStart, $code );
	$items = [];
	if( $contentId ) {
		$a = new FoodAssembly( $contentId );
		$a->load();
		$items = $a->getItems();
	}
	$slots[] = [ 'code' => $code, 'label' => $label, 'content_id' => $contentId, 'items' => $items ];
}

// Nutrition totals — one batch lookup across every distinct component referenced
// anywhere on the day (avoids an N+1 query per ingredient), then scaled per item and
// summed up into meal totals and a day total. See FoodComponent::NUTRITION_SUMMARY_FIELDS
// for the field list/order — the same list is used at every level (item/meal/day).
$componentIds = [];
foreach( $slots as $slot ) {
	foreach( $slot['items'] as $item ) {
		$componentIds[] = (int)$item['component_content_id'];
	}
}
$nutritionByComponent = FoodComponent::getNutritionBatch( array_unique( $componentIds ) );

$dayRaw = array_fill_keys( array_keys( FoodComponent::NUTRITION_SUMMARY_FIELDS ), 0.0 );
foreach( $slots as &$slot ) {
	$mealRaw = array_fill_keys( array_keys( FoodComponent::NUTRITION_SUMMARY_FIELDS ), 0.0 );
	foreach( $slot['items'] as &$item ) {
		$raw = FoodComponent::scaleNutrition(
			$nutritionByComponent[(int)$item['component_content_id']] ?? [],
			(float)$item['quantity']
		);
		$item['nutrition'] = FoodComponent::formatNutrition( $raw );
		$mealRaw = FoodComponent::sumNutrition( $mealRaw, $raw );
	}
	unset( $item );
	$slot['nutrition_total'] = FoodComponent::formatNutrition( $mealRaw );
	$dayRaw = FoodComponent::sumNutrition( $dayRaw, $mealRaw );
}
unset( $slot );

$gBitSmarty->assign( 'dateStr',          gmdate( 'Y-m-d', $dayStart ) );
$gBitSmarty->assign( 'slots',            $slots );
$gBitSmarty->assign( 'nutritionFields',  FoodComponent::NUTRITION_SUMMARY_FIELDS );
$gBitSmarty->assign( 'dayTotal',         FoodComponent::formatNutrition( $dayRaw ) );
$gBitSmarty->assign( 'canCreate',        $gBitUser->hasPermission( 'p_food_create' ) );
$gBitSmarty->assign( 'errors',           $errors );

$gBitSystem->display( 'bitpackage:food/view_day.tpl', KernelTools::tra( 'Day' ).': '.gmdate( 'Y-m-d', $dayStart ) );
