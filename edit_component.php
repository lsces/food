<?php
/**
 * Edit a FoodComponent — the title form, plus every xref group rendered editable
 * (allow_edit=true, per-row Edit/Delete icons visible) via liberty's generic
 * list_xref.tpl/edit_xref.php. view_component.php shows the same groups read-only
 * (allow_edit=false) — matches Stock's own view/edit split exactly.
 *
 * @package food
 */

namespace Bitweaver\Food;

use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'food' );

$gContent = new FoodComponent( !empty( $_REQUEST['content_id'] ) ? (int)$_REQUEST['content_id'] : null );
$gContent->load();

if( !empty( $_REQUEST['content_id'] ) && !$gContent->isValid() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'No component exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

if( $gContent->isValid() ) {
	$gContent->verifyUpdatePermission();
} else {
	$gBitSystem->verifyPermission( 'p_food_create' );
}

if( !empty( $_REQUEST['save'] ) ) {
	// Always land back on this same edit page, not the read-only view — the
	// title/notes form here is isolated from the xref grid below (nutrition,
	// WT/VOL, supplier, etc., each edited via its own Edit link), so bouncing
	// to the view page on save cost every subsequent xref edit its own trip
	// back to edit_component.php, and tempted unsaved title/notes changes to
	// get discarded by clicking straight into an xref row's Edit link instead.
	if( $gContent->store( $_REQUEST ) ) {
		header( 'Location: '.$gContent->getEditUrl() );
		die;
	}
}

// New-component prefill from add_assembly_item.php's "not found, create it" redirect
// (?title=...) — matches stock/edit_component.php's own convention.
if( !$gContent->isValid() && !empty( $_REQUEST['title'] ) ) {
	$gContent->mInfo['title'] = trim( $_REQUEST['title'] );
}

$gContent->loadXrefInfo();

// Tick floaticon on edit_component.tpl — one-click clear of the outstanding-
// review flag (see list_review.php's docblock). Needs loadXrefInfo() already
// called above (getReviewXrefId() reads $gContent->mXrefInfo rather than
// re-querying). Redirects back to the read-only view on success, same as a
// normal save.
if( !empty( $_REQUEST['clear_review'] ) && $gContent->isValid() ) {
	$gContent->verifyUpdatePermission();
	if( $gContent->clearReviewFlag() ) {
		header( 'Location: '.$gContent->getDisplayUrl() );
		die;
	}
}

// Merge a duplicate into another component — see FoodComponent::mergeInto()'s
// docblock. Destructive (permanently deletes this component), so gated behind
// expunge permission and a confirm step, same pattern as edit_movement.php's
// own delete flow.
if( !empty( $_REQUEST['fMerge'] ) && $gContent->isValid() ) {
	$gContent->verifyExpungePermission();
	$mergeTargetId = isset( $_REQUEST['merge_target_id'] ) && is_numeric( $_REQUEST['merge_target_id'] )
		? (int)$_REQUEST['merge_target_id'] : 0;
	if( !empty( $_REQUEST['cancel'] ) ) {
		header( 'Location: '.$gContent->getEditUrl() );
		die;
	} elseif( empty( $_REQUEST['confirm'] ) ) {
		$gBitSystem->confirmDialog(
			[ 'fMerge' => true, 'content_id' => $gContent->mContentId, 'merge_target_id' => $mergeTargetId ],
			[
				'confirm_item' => $gContent->getTitle(),
				'warning'      => KernelTools::tra( 'Merge "'.$gContent->getTitle().'" into component #'.$mergeTargetId.'? Every reference to this component is repointed there, then this component is permanently deleted.' ),
				'error'        => KernelTools::tra( 'This cannot be undone!' ),
			]
		);
	} elseif( $gContent->mergeInto( $mergeTargetId ) ) {
		$targetHash = [ 'content_id' => $mergeTargetId ];
		header( 'Location: '.FoodComponent::getDisplayUrlFromHash( $targetHash ) );
		die;
	}
	// Falls through to the normal render below with $gContent->mErrors populated
	// if mergeInto() failed validation (bad/missing target id).
}

$gBitSmarty->assign( 'gContent',  $gContent );
$gBitSmarty->assign( 'gXrefInfo', $gContent->mXrefInfo );
$gBitSmarty->assign( 'errors',    $gContent->mErrors );

$gBitSystem->display( 'bitpackage:food/edit_component.tpl', KernelTools::tra( 'Edit Food Item' ), [ 'display_mode' => 'edit' ] );
