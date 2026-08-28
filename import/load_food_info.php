<?php
/**
 * Import FoodComponents from a Samsung Health food_info.csv export.
 *
 * Expects a paired export in FOOD_IMPORT_PATH (storage/food/) — copy the two files from
 * a food_name_<date> split (see ~/Personal/Health/Samsung Health/split_health.sh) in:
 *   com.samsung.health.food_info.<date>.csv
 *   com.samsung.health.food_intake.<date>.csv
 * Picks the most recent date suffix present. Only food_info rows referenced by at least
 * one food_intake row are imported (see ImportFoodInfo.php docblock). Safe to re-run
 * against a newer export — upserts on datauuid, skips rows whose update_time matches
 * what's already stored.
 *
 * Rows flagged for manual curation (no gram basis, or missing FIBR) are written to
 * storage/food/curation_needed.csv alongside the on-screen report.
 *
 * @package food
 */

require_once '../../kernel/includes/setup_inc.php';

// php-fpm's web pool caps max_execution_time at 60s (php.ini) - fine for normal requests, but
// this walks every food_info row doing several DB writes each (component upsert + xref upserts,
// now including a real SUP supplier match/write per row instead of the no-op it was while
// contact's supplier contacts didn't exist yet) and a full run can genuinely take longer than
// that. Same override install_packages.php already uses for its own long-running import passes.
ini_set( 'max_execution_time', '86400' );

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'food' );
$gBitSystem->verifyPermission( 'p_food_admin' );

require_once __DIR__.'/ImportFoodInfo.php';

$pair  = foodFindLatestExportPair( FOOD_IMPORT_PATH );
$force = ( ( $_REQUEST['force'] ?? '' ) === 'y' ); // reprocess even if update_time unchanged

$result = [
	'created'   => 0,
	'updated'   => 0,
	'unchanged' => 0,
	'skipped'   => 0,
	'flagged'   => [],
	'errors'    => [],
];

if( !$pair ) {
	$result['errors'][] = 'No paired food_info/food_intake CSVs found in '.FOOD_IMPORT_PATH.
		' — copy a food_name_<date> export split there first.';
} else {
	[ $infoFile, $intakeFile ] = $pair;

	$intakeRows = foodParseSamsungCsv( $intakeFile );
	$referenced = [];
	foreach( $intakeRows as $row ) {
		$fid = trim( (string)( $row['food_info_id'] ?? '' ) );
		if( $fid !== '' ) {
			$referenced[$fid] = true;
		}
	}

	$infoRows = foodParseSamsungCsv( $infoFile );
	foreach( $infoRows as $idx => $row ) {
		$rowNum   = $idx + 3; // +2 preamble lines in the source file, +1 for 1-based
		$datauuid = trim( (string)( $row['datauuid'] ?? '' ) );
		if( $datauuid === '' || !isset( $referenced[$datauuid] ) ) {
			$result['skipped']++;
			continue;
		}
		foodImportFoodInfoRow( $row, $rowNum, $result, $force );
	}

	if( $result['flagged'] ) {
		$curationFile = FOOD_IMPORT_PATH . 'curation_needed.csv';
		$fh = fopen( $curationFile, 'w' );
		fputcsv( $fh, [ 'title', 'datauuid', 'reason' ], ',', '"', '' );
		foreach( $result['flagged'] as $flag ) {
			fputcsv( $fh, [ $flag['title'], $flag['datauuid'], $flag['reason'] ], ',', '"', '' );
		}
		fclose( $fh );
	}
}

$gBitSmarty->assign( 'csvFile',   $pair[0] ?? '(no paired export found)' );
$gBitSmarty->assign( 'created',   $result['created'] );
$gBitSmarty->assign( 'updated',   $result['updated'] );
$gBitSmarty->assign( 'unchanged', $result['unchanged'] );
$gBitSmarty->assign( 'skipped',   $result['skipped'] );
$gBitSmarty->assign( 'flagged',   $result['flagged'] );
$gBitSmarty->assign( 'errors',    $result['errors'] );
$gBitSmarty->assign( 'curationFile', $curationFile ?? 'storage/food/curation_needed.csv' );

$gBitSystem->display( 'bitpackage:food/import_results.tpl', 'Import Food Info' );
