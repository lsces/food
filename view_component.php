<?php
/**
 * View a single FoodComponent — title plus its xref groups (nutrition, quantity,
 * external), rendered via liberty's generic list_xref.tpl per group. Each row's own
 * view template (dispatched by getXrefRecordTemplate()) carries its own Edit/Delete
 * links to liberty's generic edit_xref.php/add_xref.php — nothing bespoke needed here.
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

if( !$gContent->isValid() ) {
	if( !empty( $_REQUEST['content_id'] ) ) {
		$gBitSystem->fatalError( KernelTools::tra( 'No component exists with the given ID' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
	}
	KernelTools::bit_redirect( FOOD_PKG_URL.'list_review.php', HttpStatusCodes::HTTP_FOUND );
	die;
}

$gContent->verifyViewPermission();

$gContent->loadXrefInfo();
// Populates mInfo['parsed_data'] via the format plugin (simpletext — see
// FoodComponent::verifyComponentData()) so the template shows safely rendered
// notes (escaped + line breaks) rather than raw stored text or, worse, raw HTML
// from before the format was forced. Same convention as stock/view_component.tpl.
$gContent->getParsedData();

// Per-100g nutrition summary shown above the xref tabs — same formatted shape as
// view_day.php/view_assembly.php's totals. Still run through scaleNutrition() at
// 100g even though that's a no-op for the eight additive nutrients (value*100/100
// = value, the stored basis unchanged) — it's NOT a no-op for 5AD, which needs the
// scaling step to turn the raw stored adjustment factor into a meaningful "portions
// per 100g" figure (e.g. dried fruit's 0.375 factor -> 100/(80*0.375) = 3.33
// portions per 100g), consistent with the "per 100g" framing of this whole table.
$nutritionRaw    = FoodComponent::getNutritionBatch( [ $gContent->mContentId ] )[$gContent->mContentId] ?? [];
$nutritionPer100g = FoodComponent::scaleNutrition( $nutritionRaw, 100 );

$gBitSmarty->assign( 'gContent',         $gContent );
$gBitSmarty->assign( 'gXrefInfo',        $gContent->mXrefInfo );
$gBitSmarty->assign( 'nutritionSummary', FoodComponent::formatNutrition( $nutritionPer100g ) );
$gBitSmarty->assign( 'nutritionFields',  FoodComponent::NUTRITION_SUMMARY_FIELDS );

$gBitSystem->display( 'bitpackage:food/view_component.tpl', $gContent->getTitle() );
