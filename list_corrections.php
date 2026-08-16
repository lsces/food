<?php
/**
 * Curation progress report — FoodComponents flagged by the food_info importer
 * (see import/ImportFoodInfo.php), ranked by how many meals actually reference
 * them (via FoodAssembly's BREAKFAST/LUNCH/DINNER/MSNK/ESNK item xrefs), so the
 * highest-impact corrections surface first.
 *
 * A component drops off this list once its REM xref's xkey_ext is set to
 * 'CORRECT' (a manual tag, set directly against the row — no edit UI yet). REM's
 * `data` note is shown for context only; it's never read to decide what's still
 * outstanding, so it's safe to add/replace with a manual note at any time without
 * affecting this report.
 *
 * The food_info importer itself is a one-time migration (no reason to ever
 * re-run it — see project_food_package_scoping memory), so nothing here expects
 * flags to clear themselves; clearing is always the 'CORRECT' tag, set by hand.
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
	"SELECT lc.`content_id`, lc.`title`, rem.`data` AS `note`,
		( SELECT COUNT(*) FROM `{$X}liberty_xref` u
			WHERE u.`xref` = lc.`content_id` AND u.`item` IN ('BREAKFAST','LUNCH','DINNER','MSNK','ESNK')
		) AS `usage_count`
	FROM `{$X}liberty_content` lc
	JOIN `{$X}liberty_xref` rem ON ( rem.`content_id` = lc.`content_id` AND rem.`item` = 'REM' )
	WHERE lc.`content_type_guid` = 'foodcomponent'
		AND ( rem.`xkey_ext` IS NULL OR rem.`xkey_ext` <> 'CORRECT' )
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
