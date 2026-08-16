<?php
/**
 * Edit a FoodComponent — the title form, plus every xref group rendered editable
 * (allow_edit=true, per-row Edit/Delete icons visible) via liberty's generic
 * list_xref.tpl/edit_xref.php. view_component.php shows the same groups read-only
 * (allow_edit=false) — matches Stock's own view/edit split exactly.
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

$gContent->loadXrefInfo();

$gBitSmarty->assign( 'gContent',  $gContent );
$gBitSmarty->assign( 'gXrefInfo', $gContent->mXrefInfo );
$gBitSmarty->assign( 'errors',    $gContent->mErrors );

$gBitSystem->display( 'bitpackage:food/edit_component.tpl', KernelTools::tra( 'Edit Component' ), [ 'display_mode' => 'edit' ] );
