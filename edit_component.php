<?php
/**
 * Edit a FoodComponent's title. Individual xref values (nutrition scalars, quantity
 * types, REM's status/note) are edited via liberty's generic edit_xref.php, linked
 * directly from each row on view_component.php — nothing bespoke needed for those.
 *
 * @package food
 */

namespace Bitweaver\Food;

use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'food' );

$gContent = new FoodComponent( !empty( $_REQUEST['content_id'] ) ? (int)$_REQUEST['content_id'] : null );
$gContent->load();

if( !empty( $_REQUEST['content_id'] ) && !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No component exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

if( $gContent->isValid() ) {
	$gContent->verifyUpdatePermission();
} else {
	$gBitSystem->verifyPermission( 'p_food_create' );
}

if( !empty( $_REQUEST['save'] ) ) {
	if( $gContent->store( $_REQUEST ) ) {
		header( 'Location: '.$gContent->getDisplayUrl() );
		die;
	}
}

$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'errors',   $gContent->mErrors );

$gBitSystem->display( 'bitpackage:food/edit_component.tpl', KernelTools::tra( 'Edit Component' ), [ 'display_mode' => 'edit' ] );
