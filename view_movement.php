<?php
/**
 * View a single FoodMovement (pantry receipt) — shop/date/note header plus its
 * purchased-component lines, read-only. Editing (add/remove lines, change header)
 * lives on edit_movement.php, same view/edit split as
 * view_component.php/edit_component.php.
 *
 * @package food
 */
namespace Bitweaver\Food;

use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'food' );

$gContent = new FoodMovement( !empty( $_REQUEST['content_id'] ) ? (int)$_REQUEST['content_id'] : null );
$gContent->load();

if( !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No movement exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

$gContent->verifyViewPermission();
$gBitSystem->setCanonicalLink( $gContent->getDisplayUrl() );

$gBitSmarty->assign( 'gContent', $gContent );
$gBitSmarty->assign( 'lines',    $gContent->getLines() );

$gBitSystem->display( 'bitpackage:food/view_movement.tpl', $gContent->getTitle() );
