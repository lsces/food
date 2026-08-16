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

$gBitSmarty->assign( 'dateStr',   gmdate( 'Y-m-d', $dayStart ) );
$gBitSmarty->assign( 'slots',     $slots );
$gBitSmarty->assign( 'canCreate', $gBitUser->hasPermission( 'p_food_create' ) );
$gBitSmarty->assign( 'errors',    $errors );

$gBitSystem->display( 'bitpackage:food/view_day.tpl', KernelTools::tra( 'Day' ).': '.gmdate( 'Y-m-d', $dayStart ) );
