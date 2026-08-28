<?php
/**
 * Day view — one calendar day, all 5 meal-type slots stacked down the page (not
 * tabs, per the author's own preference). Each slot either shows its existing meal's
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

// Nutrition: FoodAssembly::getItemsWithNutrition() does the per-item scale + meal-total
// sum (shared with view_assembly.php, see its own docblock) — this loop just adds the
// one further level view_assembly.php doesn't need, summing meal totals into a day total.
$slots  = [];
$dayRaw = array_fill_keys( array_keys( FoodComponent::NUTRITION_SUMMARY_FIELDS ), 0.0 );
foreach( FoodAssembly::MEAL_TYPE_LABELS as $code => $label ) {
	$contentId = FoodAssembly::lookupByDayAndType( $dayStart, $code );
	$items = [];
	$mealTotal = FoodComponent::formatNutrition( [] );
	if( $contentId ) {
		$a = new FoodAssembly( $contentId );
		$a->load();
		$result    = $a->getItemsWithNutrition();
		$items     = $result['items'];
		$mealTotal = $result['total'];
		$dayRaw    = FoodComponent::sumNutrition( $dayRaw, $result['totalRaw'] );
	}
	$slots[] = [ 'code' => $code, 'label' => $label, 'content_id' => $contentId, 'items' => $items, 'nutrition_total' => $mealTotal ];
}

$gBitSmarty->assign( 'dateStr',          gmdate( 'Y-m-d', $dayStart ) );
$gBitSmarty->assign( 'prevDateStr',      gmdate( 'Y-m-d', strtotime( '-1 day', $dayStart ) ) );
$gBitSmarty->assign( 'nextDateStr',      gmdate( 'Y-m-d', strtotime( '+1 day', $dayStart ) ) );
$gBitSmarty->assign( 'slots',            $slots );
$gBitSmarty->assign( 'nutritionFields',  FoodComponent::NUTRITION_SUMMARY_FIELDS );
$gBitSmarty->assign( 'dayTotal',         FoodComponent::formatNutrition( $dayRaw ) );
$gBitSmarty->assign( 'canCreate',        $gBitUser->hasPermission( 'p_food_create' ) );
$gBitSmarty->assign( 'errors',           $errors );

$gBitSystem->display( 'bitpackage:food/view_day.tpl', KernelTools::tra( 'Day' ).': '.gmdate( 'Y-m-d', $dayStart ) );
