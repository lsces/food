<?php
/**
 * One-off tidy: receipt (FoodMovement) titles of the form "Shop-YYYYMMDD" (or "Shop - YYYYMMDD")
 * become "Shop-YYYY-MM-DD", matching the default title edit_movement.php now generates.
 *
 * Dry run by default — shows old → new for every title that would change. Add ?apply=1 to
 * write. Writes go through FoodMovement::store() so each rename keeps its history row.
 * Safe to re-run: titles already in the dashed form are left alone.
 *
 * @package food
 */
namespace Bitweaver\Food;

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'food' );
$gBitSystem->verifyPermission( 'p_food_admin' );

$apply = !empty( $_REQUEST['apply'] );

$rows = $gBitDb->getAll(
	"SELECT `content_id`, `title` FROM `".BIT_DB_PREFIX."liberty_content`
	 WHERE `content_type_guid` = 'foodmovement' ORDER BY `content_id`"
);

$changes = [];
$unchanged = 0;
foreach( $rows as $row ) {
	if( preg_match( '/^(.*?)\s*-\s*(\d{4})(\d{2})(\d{2})$/', trim( $row['title'] ), $m ) ) {
		$changes[] = [
			'content_id' => (int)$row['content_id'],
			'old'        => $row['title'],
			'new'        => $m[1].'-'.$m[2].'-'.$m[3].'-'.$m[4],
			'status'     => $apply ? '' : 'would rename',
		];
	} else {
		$unchanged++;
	}
}

if( $apply ) {
	foreach( $changes as &$change ) {
		$movement = new FoodMovement( $change['content_id'] );
		$movement->load();
		$pHash = [ 'title' => $change['new'] ];
		$change['status'] = $movement->isValid() && $movement->store( $pHash ) ? 'renamed' : 'FAILED';
	}
	unset( $change );
}

$gBitSmarty->assign( 'apply',     $apply );
$gBitSmarty->assign( 'changes',   $changes );
$gBitSmarty->assign( 'unchanged', $unchanged );

$gBitSystem->display( 'bitpackage:food/tidy_receipt_titles.tpl', 'Tidy Receipt Titles' );
