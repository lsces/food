<?php
/**
 * Import FoodAssembly meal instances from a Samsung Health food_intake.csv export.
 *
 * Run ImportFoodInfo.php first — every food_intake row needs an already-imported
 * FoodComponent to link to. Uses the same paired export in FOOD_IMPORT_PATH (see
 * ImportFoodInfo.php's own docblock for the expected filenames).
 *
 * Safe to re-run — each meal's item list is rebuilt from scratch every time
 * (FoodAssembly::clearItems() then re-add), not diffed.
 *
 * Flagged rows (unit code couldn't be cleanly converted to grams) are written to
 * storage/food/curation_needed_intake.csv alongside the on-screen report.
 *
 * @package food
 */

require_once '../../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'food' );
$gBitSystem->verifyPermission( 'p_food_admin' );

require_once __DIR__.'/ImportFoodInfo.php';   // foodParseSamsungCsv, foodParseSamsungTime, foodFindLatestExportPair
require_once __DIR__.'/ImportFoodIntake.php';

$pair = foodFindLatestExportPair( FOOD_IMPORT_PATH );

$result = [
	'created' => 0,
	'updated' => 0,
	'skipped' => 0,
	'flagged' => [],
	'errors'  => [],
];

if( !$pair ) {
	$result['errors'][] = 'No paired food_info/food_intake CSVs found in '.FOOD_IMPORT_PATH.
		' — copy a food_lester_<date> export split there first.';
} else {
	[ $infoFile, $intakeFile ] = $pair;

	$servingLookup = foodBuildServingLookup( foodParseSamsungCsv( $infoFile ) );

	$intakeRows = foodParseSamsungCsv( $intakeFile );
	$groups = [];
	foreach( $intakeRows as $row ) {
		$key = ( $row['start_time'] ?? '' ) . '|' . ( $row['meal_type'] ?? '' );
		$groups[$key][] = $row;
	}

	foreach( $groups as $groupRows ) {
		foodImportIntakeGroup( $groupRows, $servingLookup, $result );
	}

	if( $result['flagged'] ) {
		$curationFile = FOOD_IMPORT_PATH . 'curation_needed_intake.csv';
		$fh = fopen( $curationFile, 'w' );
		fputcsv( $fh, [ 'meal', 'datauuid', 'reason' ], ',', '"', '' );
		foreach( $result['flagged'] as $flag ) {
			fputcsv( $fh, [ $flag['title'], $flag['datauuid'], $flag['reason'] ], ',', '"', '' );
		}
		fclose( $fh );
	}
}

$gBitSmarty->assign( 'csvFile',      $pair[1] ?? '(no paired export found)' );
$gBitSmarty->assign( 'created',      $result['created'] );
$gBitSmarty->assign( 'updated',      $result['updated'] );
$gBitSmarty->assign( 'skipped',      $result['skipped'] );
$gBitSmarty->assign( 'flagged',      $result['flagged'] );
$gBitSmarty->assign( 'errors',       $result['errors'] );
$gBitSmarty->assign( 'curationFile', $curationFile ?? 'storage/food/curation_needed_intake.csv' );

$gBitSystem->display( 'bitpackage:food/import_results.tpl', 'Import Food Intake' );
