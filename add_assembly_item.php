<?php
/**
 * Add a single ingredient line to a FoodAssembly (meal instance). Mirrors
 * stock/add_movement_component.php closely — same title-lookup-or-create-new flow,
 * same typeahead search — but simpler: no item-type choice (the meal's own type,
 * from getMealType(), is fixed) and no xkey_ext/note fields (not meaningful here).
 *
 * @package food
 */

namespace Bitweaver\Food;

use Bitweaver\KernelTools;
use Bitweaver\Liberty\LibertyXref;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gContent = new FoodAssembly( !empty( $_REQUEST['content_id'] ) ? (int)$_REQUEST['content_id'] : null );
$gContent->load();

if( !$gContent->isValid() ) {
	$gBitSystem->fatalError( 'No valid meal specified.' );
}
$gContent->verifyUpdatePermission();

$mealType = $gContent->getMealType();
$errors = [];

if( !empty( $_REQUEST['fCancel'] ) ) {
	header( 'Location: '.FOOD_PKG_URL.'edit_assembly.php?content_id='.$gContent->mContentId );
	die;
}

if( !empty( $_REQUEST['fAddComponent'] ) ) {
	$title  = trim( $_REQUEST['component_title'] ?? '' );
	$compId = (int)( $_REQUEST['component_id'] ?? 0 );
	$qty    = trim( $_REQUEST['xkey'] ?? '' );

	if( $title === '' ) {
		$errors[] = KernelTools::tra( 'Component title is required.' );
	} else {
		if( $compId ) {
			// Picked from the dropdown, which shows supplier alongside title so
			// same-titled components from different shops are distinguishable
			// (see project_food_package_scoping memory — supplier moved out of
			// the title into its own SUP xref, which made the plain title
			// exact-match below genuinely ambiguous for several real components).
			// Still verified here rather than trusted blindly — a tampered or
			// stale id falls straight through to the exact-title lookup instead.
			$valid = (bool)$gBitDb->getOne(
				"SELECT 1 FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ? AND `content_type_guid` = 'foodcomponent'",
				[ $compId ]
			);
			if( !$valid ) {
				$compId = 0;
			}
		}
		if( !$compId ) {
			// Fallback for a freshly-typed title (no suggestion picked) — only
			// ambiguous itself if two components happen to share the exact same
			// title with nothing selected, an edge case the dropdown above is
			// there specifically to avoid.
			$compId = (int)$gBitDb->getOne(
				"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."liberty_content` lc
				 WHERE lc.`content_type_guid` = 'foodcomponent' AND lc.`title` = ?",
				[ $title ]
			);
		}

		if( !$compId ) {
			header( 'Location: '.FOOD_PKG_URL.'edit_component.php?title='.urlencode( $title ) );
			die;
		}

		// Quantity left blank — fall back to the component's own declared WT/VOL
		// value (its quantity group's "1 pack = Ng" figure, e.g. a sandwich's real
		// pack weight) rather than forcing a retype of a number the component
		// already records. add_assembly_item.tpl's JS does this same lookup
		// client-side on selection, but the server-side fallback here covers a
		// submission that reaches this point with xkey still empty regardless.
		if( $qty === '' ) {
			// No FIRST/ORDER BY needed — WT/VOL/SGL are registered multiple=-2
			// (mutually exclusive) on foodcomponent's quantity group, so at most
			// one of WT/VOL can ever be set on a real component.
			$qty = (string)$gBitDb->getOne(
				"SELECT u.`xkey` FROM `".BIT_DB_PREFIX."liberty_xref` u
				 WHERE u.`content_id` = ? AND u.`item` IN ('WT','VOL') AND u.`xkey` IS NOT NULL AND u.`xkey` <> ''",
				[ $compId ]
			);
		}

		if( !is_numeric( $qty ) || (float)$qty <= 0 ) {
			$errors[] = KernelTools::tra( 'Quantity must be a positive number — this component has no declared weight/volume to default from.' );
		} else {
			$nextXorder = (int)$gBitDb->getOne(
				"SELECT COALESCE( MAX(x.`xorder`) + 1, 1 ) FROM `".BIT_DB_PREFIX."liberty_xref` x
				 WHERE x.`content_id` = ? AND x.`item` = ?",
				[ $gContent->mContentId, $mealType ]
			) ?: 1;

			$xrefObj = new LibertyXref();
			$xrefObj->mContentTypeGuid = 'foodassembly';
			$pHash = [
				'content_id' => $gContent->mContentId,
				'item'       => $mealType,
				'xorder'     => $nextXorder,
				'xref'       => $compId,
				'xkey'       => (string)(int)round( (float)$qty ),
			];
			if( $xrefObj->store( $pHash ) ) {
				header( 'Location: '.FOOD_PKG_URL.'edit_assembly.php?content_id='.$gContent->mContentId );
				die;
			}
			$errors[] = KernelTools::tra( 'Failed to store ingredient.' );
		}
	}
}

$gBitSmarty->assign( 'gContent',  $gContent );
$gBitSmarty->assign( 'mealLabel', FoodAssembly::mealTypeLabel( $mealType ?? '' ) );
$gBitSmarty->assign( 'errors',    $errors );
$gBitSmarty->assign( 'lookupUrl', FOOD_PKG_URL.'includes/lookup_component.php' );

$gBitSystem->display( 'bitpackage:food/add_assembly_item.tpl', KernelTools::tra( 'Add Ingredient' ).': '.$gContent->getTitle(), [ 'display_mode' => 'edit' ] );
