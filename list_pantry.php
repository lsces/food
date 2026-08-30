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

use Bitweaver\BitBase;
use Bitweaver\Liberty\LibertyContent;

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

// Shop filter — same 'sup' param / SUP-xref join as list_components.php's own filter.
$supId = !empty( $_REQUEST['sup'] ) ? (int)$_REQUEST['sup'] : 0;
$supSql = '';
if( $supId ) {
	$supSql = " AND EXISTS (
		SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` sx
		WHERE sx.`content_id` = lc.`content_id` AND sx.`item` = 'SUP' AND sx.`xref` = ?
	)";
	$bindVars[] = $supId;
}

$listHash = $_REQUEST;
if( empty( $listHash['max_records'] ) ) {
	$listHash['max_records'] = 20;
}
BitBase::prepGetList( $listHash );
$maxRecords = $listHash['max_records'];
$offset     = $listHash['offset'];

// SGL/WT/VOL redesign (2026-08-22) — see Claude memory project_food_package_scoping's
// "SGL/WT/VOL pantry-display redesign" entry. SGL is no longer a competing quantity
// type, it's an independent display-mode flag: SGL-flagged components show REM as a
// derived count (REM ÷ their own WT/VOL value), everything else shows REM directly as
// g/ml. base_value pulled raw (not CAST in SQL) since a WT/VOL row can legitimately
// have a NULL xkey ("tracked by weight, real figure never entered") — validated
// numeric in PHP rather than risking a Firebird CAST error on that gap.
//
// SGL's own xkey_ext (2026-08-22, the author's own find) doubles as a free-text category
// note — e.g. "Ready Meal" — same spare-column reuse as REM's xkey_ext review tag
// (see admin/schema_inc.php), just a different item/purpose. Shown as its own column;
// blank for anything not SGL-flagged or not yet noted.
$X = BIT_DB_PREFIX;
$rows = $gBitDb->getAll(
	"SELECT lc.`content_id`, lc.`title`, CAST(rem.`xkey` AS DOUBLE PRECISION) AS quantity,
			base.`item` AS base_item, base.`xkey` AS base_value_raw,
			(SELECT FIRST 1 1 FROM `{$X}liberty_xref` s
			 WHERE s.`content_id` = lc.`content_id` AND s.`item` = 'SGL') AS has_sgl,
			(SELECT FIRST 1 s.`xkey_ext` FROM `{$X}liberty_xref` s
			 WHERE s.`content_id` = lc.`content_id` AND s.`item` = 'SGL') AS sgl_note,
			(SELECT FIRST 1 shop.`title` FROM `{$X}liberty_xref` sx
			 INNER JOIN `{$X}liberty_content` shop ON ( shop.`content_id` = sx.`xref` )
			 WHERE sx.`content_id` = lc.`content_id` AND sx.`item` = 'SUP') AS supplier_title
		FROM `{$X}liberty_content` lc
		JOIN `{$X}liberty_xref` rem ON ( rem.`content_id` = lc.`content_id` AND rem.`item` = 'REM' )
		LEFT JOIN `{$X}liberty_xref` base ON ( base.`content_id` = lc.`content_id` AND base.`item` IN ('WT','VOL') )
	 WHERE lc.`content_type_guid` = 'foodcomponent'
	   AND rem.`xkey` SIMILAR TO '[0-9]+([.][0-9]+)?' AND CAST(rem.`xkey` AS DOUBLE PRECISION) > 0
	   $findSql
	   $supSql
	 ORDER BY lc.`title`",
	$bindVars
);
$listHash['cant'] = count( $rows );
$rows = array_slice( $rows, $offset, $maxRecords );
foreach( $rows as &$row ) {
	$baseValue = is_numeric( $row['base_value_raw'] ?? null ) ? (float)$row['base_value_raw'] : null;
	if( !empty( $row['has_sgl'] ) && $baseValue > 0 ) {
		$count = $row['quantity'] / $baseValue;
		$countStr = number_format( $count, 1, '.', '' );
		if( str_ends_with( $countStr, '.0' ) ) {
			$countStr = substr( $countStr, 0, -2 );
		}
		$row['display_quantity'] = $countStr;
		$row['display_unit']     = '';
	} else {
		$row['display_quantity'] = $row['quantity'];
		$row['display_unit']     = match( $row['base_item'] ) { 'WT' => 'g', 'VOL' => 'ml', default => '' };
	}
	$row['note'] = $row['sgl_note'] ?? '';
	$urlHash = [ 'content_id' => $row['content_id'] ];
	$row['display_url'] = FoodComponent::getDisplayUrlFromHash( $urlHash );
}
unset( $row );

$listHash['page_records'] = count( $rows );
if( $find !== '' ) $listHash['listInfo']['parameters']['find'] = $find;
if( $supId )        $listHash['listInfo']['parameters']['sup']  = $supId;
BitBase::postGetList( $listHash );

$gBitSmarty->assign( 'pantryList', $rows );
$gBitSmarty->assign( 'listInfo',   $listHash['listInfo'] );
$gBitSmarty->assign( 'find',       $find );

// Shop filter dropdown — same shops query as list_components.php/edit_movement.php's own.
$shops = LibertyContent::listContentByXrefItem( 'B04', 'contactbusiness' );
$gBitSmarty->assign( 'shops',        $shops );
$gBitSmarty->assign( 'selectedShop', $supId );

$gBitSmarty->assign( 'gDefaultCenter', 'bitpackage:food/list_pantry.tpl' );
$gBitSystem->display( 'bitpackage:kernel/dynamic.tpl', 'Pantry', [ 'display_mode' => 'list' ] );
