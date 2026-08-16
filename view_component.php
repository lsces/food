<?php
/**
 * View a single FoodComponent — title plus its xref groups (nutrition, quantity,
 * external), rendered via liberty's generic list_xref.tpl per group. Each row's own
 * view template (dispatched by getXrefRecordTemplate()) carries its own Edit/Delete
 * links to liberty's generic edit_xref.php/add_xref.php — nothing bespoke needed here.
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

if( !$gContent->isValid() ) {
	if( !empty( $_REQUEST['content_id'] ) ) {
		$gBitSystem->fatalError( KernelTools::tra( 'No component exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
	}
	KernelTools::bit_redirect( FOOD_PKG_URL.'list_corrections.php', HttpStatusCodes::HTTP_FOUND );
	die;
}

$gContent->verifyViewPermission();

$gContent->loadXrefInfo();

$gBitSmarty->assign( 'gContent',  $gContent );
$gBitSmarty->assign( 'gXrefInfo', $gContent->mXrefInfo );

$gBitSystem->display( 'bitpackage:food/view_component.tpl', $gContent->getTitle() );
