<?php
/**
 * Every FoodMovement (pantry receipt) — mirrors stock/list_movements.php, trimmed
 * to Food's simpler single-reference-type shape (no assembly/component BOM-
 * quantity lookup — Food's REM is a stored value, not derived from movement
 * history, see food/CLAUDE.md).
 *
 * @package food
 */
namespace Bitweaver\Food;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'food' );
$gBitSystem->verifyPermission( 'p_food_view' );

$movement     = new FoodMovement();
$movementList = $movement->getList( $_REQUEST );

$_REQUEST['listInfo']['parameters'] = array_filter( [
	'find' => $_REQUEST['find'] ?? '',
] );
$gBitSmarty->assign( 'listInfo',     $_REQUEST['listInfo'] );
$gBitSmarty->assign( 'movementList', $movementList );

$gBitSmarty->assign( 'gDefaultCenter', 'bitpackage:food/list_movements.tpl' );
$gBitSystem->display( 'bitpackage:kernel/dynamic.tpl', 'Movements', [ 'display_mode' => 'list' ] );
