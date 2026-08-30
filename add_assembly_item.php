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
use Bitweaver\Liberty\LibertyContent;
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
		$errors[] = KernelTools::tra( 'Food item title is required.' );
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

		// WT/VOL are registered multiple=-2 (mutually exclusive) on
		// foodcomponent's quantity group, so at most one can ever be set on a
		// real component - lookupXrefByItem()'s FIRST 1 is safe either way.
		$baseRow = LibertyContent::lookupXrefByItem( $compId, [ 'WT', 'VOL' ], 'foodcomponent' );

		$mode = ( $_REQUEST['qty_mode'] ?? 'base' ) === 'sgl' ? 'sgl' : 'base';

		// Quantity left blank — fall back to a sensible default rather than forcing
		// a retype of a number that's either already on record (base mode: the
		// component's own "1 pack = Ng" WT/VOL figure) or obvious (sgl mode: one
		// unit). add_assembly_item.tpl's JS does this same default client-side on
		// selection, but the server-side fallback here covers a submission that
		// reaches this point with xkey still empty regardless.
		if( $qty === '' ) {
			$qty = $mode === 'sgl' ? '1' : (string)( $baseRow['xkey'] ?? '' );
		}

		if( !is_numeric( $qty ) || (float)$qty <= 0 ) {
			$errors[] = KernelTools::tra( 'Quantity must be a positive number — this component has no declared weight/volume to default from.' );
		} else {
			// The list itself only ever stores/edits a plain gram figure — the
			// user's *actual* weight eaten, adjustable afterward regardless of how
			// the line was originally added (see this file's own docblock/session
			// notes: meals need the real weight, unlike receipts' nominal-pack-size
			// guestimates, so an sgl-mode add is a one-off conversion at entry
			// time, not something the stored line remembers or needs to track).
			// Same conversion math as FoodMovement::addComponentLine()'s own sgl
			// branch: a count times the component's own declared per-unit weight.
			if( $mode === 'sgl' ) {
				$hasSgl = LibertyContent::lookupXrefByItem( $compId, 'SGL', 'foodcomponent' ) !== null;
				if( !$hasSgl ) {
					$errors[] = KernelTools::tra( 'This component is not flagged for count-based (SGL) tracking.' );
				} elseif( !$baseRow || !is_numeric( $baseRow['xkey'] ?? null ) ) {
					$errors[] = KernelTools::tra( 'This component has no declared weight/volume to convert a count through.' );
				} else {
					$grams = (float)$qty * (float)$baseRow['xkey'];
				}
			} else {
				$grams = (float)$qty;
			}
		}

		if( !$errors && isset( $grams ) ) {
			$nextXorder = (int)$gBitDb->getOne(
				"SELECT COALESCE( MAX(x.`xorder`) + 1, 1 ) FROM `".BIT_DB_PREFIX."liberty_xref` x
				 WHERE x.`content_id` = ? AND x.`item` = ?",
				[ $gContent->mContentId, $mealType ]
			) ?: 1;

			// Applied before the line itself is stored — a meal eating a component
			// takes it out of the pantry balance the same way a receipt puts it in,
			// clamped by adjustComponentRem() itself (empty pantry, or its dust
			// threshold near an empty pack — see that method's docblock). The
			// *actual* delta applied (not the nominal $grams) is stashed on the line
			// via xkey_ext, so removeItem()/expunge() can restock exactly this much
			// later rather than over-crediting stock that was never really there.
			$roundedQty = (float)round( $grams );
			$actualDelta = ( new FoodMovement() )->adjustComponentRem( $compId, -$roundedQty );

			$xrefObj = new LibertyXref();
			$xrefObj->mContentTypeGuid = 'foodassembly';
			$pHash = [
				'content_id' => $gContent->mContentId,
				'item'       => $mealType,
				'xorder'     => $nextXorder,
				'xref'       => $compId,
				'xkey'       => (string)(int)$roundedQty,
				'xkey_ext'   => (string)( -$actualDelta ),
			];
			if( $xrefObj->store( $pHash ) ) {
				header( 'Location: '.FOOD_PKG_URL.'edit_assembly.php?content_id='.$gContent->mContentId );
				die;
			}
			$errors[] = KernelTools::tra( 'Failed to store food item.' );
		}
	}
}

$gBitSmarty->assign( 'gContent',  $gContent );
$gBitSmarty->assign( 'mealLabel', FoodAssembly::mealTypeLabel( $mealType ?? '' ) );
$gBitSmarty->assign( 'errors',    $errors );
$gBitSmarty->assign( 'lookupUrl', FOOD_PKG_URL.'includes/lookup_component.php' );

$gBitSystem->display( 'bitpackage:food/add_assembly_item.tpl', KernelTools::tra( 'Add Food Item' ).': '.$gContent->getTitle(), [ 'display_mode' => 'edit' ] );
