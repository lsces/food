<?php
/**
 * FoodComponents currently in the pantry — REM > 0. Unlike stock/list_stock.php,
 * this doesn't aggregate from movement history: Food's REM is a stored/mutable
 * value, kept in sync by FoodMovement::adjustComponentRem() (see
 * food/CLAUDE.md), so it's a plain read of the REM xref.
 *
 * @package food
 */
namespace Bitweaver\Food;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'food' );
$gBitSystem->verifyPermission( 'p_food_view' );

$find = trim( $_REQUEST['find'] ?? '' );
$bindVars = [];
$findSql = '';
if( $find !== '' ) {
	$findSql = " AND UPPER(lc.`title`) LIKE ?";
	$bindVars[] = '%'.strtoupper( $find ).'%';
}

$X = BIT_DB_PREFIX;
$rows = $gBitDb->getAll(
	"SELECT lc.`content_id`, lc.`title`, CAST(rem.`xkey` AS DOUBLE PRECISION) AS quantity,
			(SELECT FIRST 1 t.`item` FROM `{$X}liberty_xref` t
			 WHERE t.`content_id` = lc.`content_id` AND t.`item` IN ('SGL','WT','VOL')) AS quantity_item
		FROM `{$X}liberty_content` lc
		JOIN `{$X}liberty_xref` rem ON ( rem.`content_id` = lc.`content_id` AND rem.`item` = 'REM' )
	 WHERE lc.`content_type_guid` = 'foodcomponent'
	   AND rem.`xkey` SIMILAR TO '[0-9]+([.][0-9]+)?' AND CAST(rem.`xkey` AS DOUBLE PRECISION) > 0
	   $findSql
	 ORDER BY lc.`title`",
	$bindVars
);
foreach( $rows as &$row ) {
	$row['quantity_unit'] = match( $row['quantity_item'] ) { 'WT' => 'g', 'VOL' => 'ml', default => '' };
	$urlHash = [ 'content_id' => $row['content_id'] ];
	$row['display_url'] = FoodComponent::getDisplayUrlFromHash( $urlHash );
}
unset( $row );

$gBitSmarty->assign( 'pantryList', $rows );
$gBitSmarty->assign( 'find',       $find );

$gBitSmarty->assign( 'gDefaultCenter', 'bitpackage:food/list_pantry.tpl' );
$gBitSystem->display( 'bitpackage:kernel/dynamic.tpl', 'Pantry', [ 'display_mode' => 'list' ] );
