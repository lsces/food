<?php
/**
 * View a single FoodAssembly (meal instance) — read-only. Not the same shape as
 * view_component.php: the 'type' group is sort_order=0 (excluded from the generic
 * loadXrefInfo()/list_xref.tpl path by design — see FoodAssembly.php's own
 * docblock), so this uses FoodAssembly's own getMealType()/getItems() instead.
 *
 * Day view (multiple meals for one date, tabbed) is a separate, later page — this
 * one only ever targets a single content_id.
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

$gContent->verifyViewPermission();

$mealType  = $gContent->getMealType();
$nutrition = $gContent->getItemsWithNutrition();

$gBitSmarty->assign( 'gContent',        $gContent );
$gBitSmarty->assign( 'mealLabel',       FoodAssembly::mealTypeLabel( $mealType ?? '' ) );
$gBitSmarty->assign( 'items',           $nutrition['items'] );
$gBitSmarty->assign( 'nutritionTotal',  $nutrition['total'] );
$gBitSmarty->assign( 'nutritionFields', FoodComponent::NUTRITION_SUMMARY_FIELDS );

$gBitSystem->display( 'bitpackage:food/view_assembly.tpl', KernelTools::tra( 'View' ).' '.FoodAssembly::mealTypeLabel( $mealType ?? '' ) );
