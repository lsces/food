<?php
/**
 * Edit every scalar nutrition xref item (CAL/PROT/CARB/FIBR/SUGR/SOD/5AD) on one
 * FoodComponent in a single form/submit. The plain scalar items never had the
 * "edit several related values in one form" convenience FAT/VIT/MIN already get
 * from their own json-list template (see admin/schema_inc.php's nutrition group
 * comments) — tidying several fields on a real receipt meant a full
 * edit_xref.php round trip per field. Linked from the new "Edit all" icon on
 * view_nutrition_group.tpl, next to the normal "Add record" link.
 *
 * Built 2026-08-20 as a scoped, non-modal fix — flagged as "the best example of
 * the problem" that the eventual generic edit-popup redesign (see
 * project_modal_quick_add_ux memory) should solve properly across every
 * package's xref groups. This page is shaped so a future popup could load it
 * (plain form, no dependency on being a top-level navigation), but the actual
 * popup/AJAX mechanism is separate, larger, still-deferred work.
 *
 * Also fixes the "always lands back on the first tab" annoyance for this one
 * entry point: redirects to edit_component.php with &jstab=<nutrition's actual
 * position>, computed at runtime from $gContent->mXrefInfo->mGroups rather than
 * a hardcoded number, so it stays correct if the group order changes again (it
 * already has once — see admin/schema_inc.php's sort_order history).
 *
 * @package food
 */
namespace Bitweaver\Food;

use Bitweaver\KernelTools;
use Bitweaver\Liberty\LibertyXref;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty, $gBitDb;

$gBitSystem->verifyPackage( 'food' );

$gContent = new FoodComponent( !empty( $_REQUEST['content_id'] ) ? (int)$_REQUEST['content_id'] : null );
$gContent->load();

if( !$gContent->isValid() ) {
	$gBitSystem->fatalError( 'No valid component specified.' );
}
$gContent->verifyUpdatePermission();

// The scalar items this page edits directly. FAT/VIT/MIN are deliberately left
// out — they already have a working combined edit via their own json-list
// template, this page isn't trying to replace that.
$scalarFields = [
	'CAL'  => [ 'label' => 'Calories',     'suffix' => 'kcal' ],
	'PROT' => [ 'label' => 'Protein',      'suffix' => 'mg' ],
	'CARB' => [ 'label' => 'Carbohydrate', 'suffix' => 'mg' ],
	'FIBR' => [ 'label' => 'Fibre',        'suffix' => 'mg' ],
	'SUGR' => [ 'label' => 'Sugar',        'suffix' => 'mg' ],
	'5AD'  => [ 'label' => 'Five-a-day (adjustment factor, true_portion_g/80)', 'suffix' => '' ],
];

$existing = [];
foreach( $gBitDb->getAll(
	"SELECT `item`, `xref_id`, `xkey` FROM `".BIT_DB_PREFIX."liberty_xref`
	 WHERE `content_id` = ? AND `item` IN ('CAL','PROT','CARB','FIBR','SUGR','SOD','5AD')",
	[ $gContent->mContentId ]
) as $row ) {
	$existing[$row['item']] = $row;
}

if( !empty( $_REQUEST['fCancel'] ) ) {
	header( 'Location: '.$gContent->getEditUrl() );
	die;
}

if( !empty( $_REQUEST['fSaveNutrition'] ) ) {
	foreach( array_keys( $scalarFields ) as $item ) {
		$val = trim( (string)( $_REQUEST['val_'.$item] ?? '' ) );
		if( $val === '' || !is_numeric( $val ) ) {
			// Blank stays blank — this page never creates a 0-value row for a field
			// left empty (e.g. FIBR genuinely missing), and never touches a field it
			// wasn't given a new value for.
			continue;
		}
		$pHash = [ 'content_id' => $gContent->mContentId, 'item' => $item, 'xkey' => $val ];
		if( isset( $existing[$item] ) ) {
			$pHash['xref_id'] = $existing[$item]['xref_id'];
		} else {
			$pHash['fAddXref'] = 1;
		}
		$xref = new LibertyXref();
		$xref->store( $pHash );
	}

	// SOD — same salt-priority conversion as liberty/edit_xref.php's own
	// sod_salt/sod_sodium handling (a Food-specific hook there); replicated here
	// since this page bypasses that controller entirely.
	$salt   = trim( (string)( $_REQUEST['sod_salt'] ?? '' ) );
	$sodium = trim( (string)( $_REQUEST['sod_sodium'] ?? '' ) );
	$sodVal = null;
	if( $salt !== '' && is_numeric( $salt ) ) {
		$sodVal = (string)(int)round( (float)$salt / 2.5 * 1000 );
	} elseif( $sodium !== '' && is_numeric( $sodium ) ) {
		$sodVal = $sodium;
	}
	if( $sodVal !== null ) {
		$pHash = [ 'content_id' => $gContent->mContentId, 'item' => 'SOD', 'xkey' => $sodVal ];
		if( isset( $existing['SOD'] ) ) {
			$pHash['xref_id'] = $existing['SOD']['xref_id'];
		} else {
			$pHash['fAddXref'] = 1;
		}
		$xref = new LibertyXref();
		$xref->store( $pHash );
	}

	$gContent->loadXrefInfo();
	$jstab = array_search( 'nutrition', array_keys( $gContent->mXrefInfo->mGroups ?? [] ), true );
	$redirect = $gContent->getEditUrl();
	if( $jstab !== false ) {
		$redirect .= '&jstab='.$jstab;
	}
	header( 'Location: '.$redirect );
	die;
}

$gBitSmarty->assign( 'gContent',      $gContent );
$gBitSmarty->assign( 'scalarFields',  $scalarFields );
$gBitSmarty->assign( 'existing',      $existing );

$gBitSystem->display( 'bitpackage:food/edit_nutrition.tpl', KernelTools::tra( 'Edit Nutrition' ).': '.$gContent->getTitle(), [ 'display_mode' => 'edit' ] );
