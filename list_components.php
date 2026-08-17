<?php
/**
 * Every FoodComponent, not just the ones flagged for review — see list_review.php
 * for that narrower queue view.
 *
 * @package food
 */

namespace Bitweaver\Food;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'food' );
$gBitSystem->verifyPermission( 'p_food_view' );

$component = new FoodComponent();

if( !empty( $_REQUEST['find'] ) ) {
	$_REQUEST['search'] = $_REQUEST['find'];
}

$componentList = $component->getList( $_REQUEST );
$_REQUEST['listInfo']['parameters'] = array_filter( [
	'sup' => !empty( $_REQUEST['sup'] ) ? (int)$_REQUEST['sup'] : '',
] );
$gBitSmarty->assign( 'listInfo', $_REQUEST['listInfo'] );
$gBitSmarty->assign( 'componentList', $componentList );

$gBitSmarty->assign( 'gDefaultCenter', 'bitpackage:food/list_components.tpl' );
$gBitSystem->display( 'bitpackage:kernel/dynamic.tpl', 'List Components', [ 'display_mode' => 'list' ] );
