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
	if( !$errors ) {
		header( 'Location: '.FOOD_PKG_URL.'edit_assembly.php?content_id='.$gContent->mContentId );
		die;
	}
}

$mealType = $gContent->getMealType();

$gBitSmarty->assign( 'gContent',   $gContent );
$gBitSmarty->assign( 'mealType',   $mealType );
$gBitSmarty->assign( 'mealLabel',  FoodAssembly::mealTypeLabel( $mealType ?? '' ) );
$gBitSmarty->assign( 'mealTypes',  $gContent->getAvailableMealTypes() );
$gBitSmarty->assign( 'items',      $gContent->getItems() );
$gBitSmarty->assign( 'errors',     $errors );

$gBitSystem->display( 'bitpackage:food/edit_assembly.tpl', KernelTools::tra( 'Edit' ).' '.FoodAssembly::mealTypeLabel( $mealType ?? '' ), [ 'display_mode' => 'edit' ] );
