<?php
/**
 * Curation report — FoodComponents the food_info importer flagged as needing a
 * correction (see import/ImportFoodInfo.php), ranked by how many meals actually
 * reference them (via FoodAssembly's BREAKFAST/LUNCH/DINNER/MSNK/ESNK item xrefs),
 * so the highest-impact ones surface first.
 *
 * `REM.xkey_ext = 'CORRECT'` is the outstanding-work tag itself — it means "this
 * needs correcting", not "this has been corrected" (confirmed 2026-08-16 after a
 * few rounds of me having the polarity backwards). The importer sets it on every
 * flagged component at import time, alongside a note on `liberty_content.data`
 * explaining what's wrong. Clearing the tag (via edit_component.php's tick
 * floaticon) is what marks a component done — `liberty_content.data` is NOT a
 * status signal, it's general-purpose notes (may get edited during a fix, and
 * other components will gain notes for entirely unrelated reasons), never read
 * here to decide what's outstanding.
 *
 * @package food
 */

namespace Bitweaver\Food;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'food' );
$gBitSystem->verifyPermission( 'p_food_view' );

$X = BIT_DB_PREFIX;

$rows = $gBitDb->getAll(
	"SELECT lc.`content_id`, lc.`title`, lc.`data` AS `note`,
		( SELECT COUNT(*) FROM `{$X}liberty_xref` u
			WHERE u.`xref` = lc.`content_id` AND u.`item` IN ('BREAKFAST','LUNCH','DINNER','MSNK','ESNK')
		) AS `usage_count`
	FROM `{$X}liberty_content` lc
	JOIN `{$X}liberty_xref` rem ON ( rem.`content_id` = lc.`content_id` AND rem.`item` = 'REM' )
	WHERE lc.`content_type_guid` = 'foodcomponent'
		AND rem.`xkey_ext` = 'CORRECT'
	ORDER BY `usage_count` DESC, lc.`title` ASC"
);

$totalFlagged = $gBitDb->getOne(
	"SELECT COUNT(*) FROM `{$X}liberty_xref` rem
		JOIN `{$X}liberty_content` lc ON ( lc.`content_id` = rem.`content_id` )
		WHERE rem.`item` = 'REM' AND lc.`content_type_guid` = 'foodcomponent'"
);

$totalFlagged = (int)$totalFlagged;
$outstanding  = count( $rows );
$percentDone  = $totalFlagged ? (int)round( ( $totalFlagged - $outstanding ) / $totalFlagged * 100 ) : 0;

$gBitSmarty->assign( 'corrections',  $rows );
$gBitSmarty->assign( 'outstanding',  $outstanding );
$gBitSmarty->assign( 'totalFlagged', $totalFlagged );
$gBitSmarty->assign( 'percentDone',  $percentDone );

$gBitSystem->display( 'bitpackage:food/list_corrections.tpl', 'Food Curation Progress' );
