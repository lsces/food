<?php
/**
 * food_info.csv → FoodComponent importer.
 *
 * Reads a paired Samsung Health export (com.samsung.health.food_info.<date>.csv +
 * ...food_intake.<date>.csv, both from the same date suffix) out of FOOD_IMPORT_PATH.
 * Only food_info rows actually referenced by at least one food_intake.food_info_id are
 * imported — unreferenced rows are abandoned duplicates from Samsung's crude edit UI
 * (see project_food_package_scoping memory) and are skipped.
 *
 * Upsert keyed on datauuid (FoodComponent::lookupByDatauuid) via the DUID xref item;
 * rows whose update_time matches the existing content's last_modified are left alone.
 *
 * Nutrition is normalized to per-100g at import time (food_info's own basis varies per
 * row — 91g for plain Broccoli, already 100g for most branded items, 0/blank for many
 * ready-meals). Rows without a usable gram basis (metric_serving_amount 0/blank, or
 * metric_serving_unit not 'g') get a FoodComponent with title/DUID/PFID only — no
 * nutrition xrefs are guessed — and are flagged in curation_needed.csv instead.
 *
 * @package food
 */

use Bitweaver\Food\FoodComponent;
use Bitweaver\Liberty\LibertyXref;

/**
 * Parse one Samsung Health CSV: skip the 1-line preamble, read the real header row,
 * return data rows as associative arrays keyed by header name (never positional —
 * older exports have fewer columns, always additive, see project_food_package_scoping).
 */
function foodParseSamsungCsv( string $pPath ): array {
	$rows = [];
	$handle = fopen( $pPath, 'r' );
	if( $handle === false ) {
		return $rows;
	}
	fgets( $handle ); // preamble: type,version,field-count — not needed
	$header = fgetcsv( $handle, 0, ',', '"', '' );
	if( $header === false ) {
		fclose( $handle );
		return $rows;
	}
	$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] ); // strip BOM
	while( ( $data = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
		if( count( $data ) === 1 && $data[0] === null ) {
			continue; // trailing blank line
		}
		$row = [];
		foreach( $header as $i => $col ) {
			$row[$col] = $data[$i] ?? null;
		}
		$rows[] = $row;
	}
	fclose( $handle );
	return $rows;
}

/**
 * Locate the most recent paired food_info/food_intake CSVs (same date suffix) in
 * $pImportPath. Returns [infoFile, intakeFile] or null if no complete pair exists.
 */
function foodFindLatestExportPair( string $pImportPath ): ?array {
	$infoFiles = glob( $pImportPath . 'com.samsung.health.food_info.*.csv' );
	if( !$infoFiles ) {
		return null;
	}
	sort( $infoFiles ); // Samsung's YYYYMMDDHHMMSS suffix sorts chronologically as text
	$infoFile = end( $infoFiles );
	if( !preg_match( '/food_info\.(\d+)\.csv$/', $infoFile, $m ) ) {
		return null;
	}
	$intakeFile = $pImportPath . 'com.samsung.health.food_intake.' . $m[1] . '.csv';
	return file_exists( $intakeFile ) ? [ $infoFile, $intakeFile ] : null;
}

/**
 * Samsung timestamps are 'Y-m-d H:i:s.u' (fractional seconds) — DateTime handles that
 * format directly; strtotime() is the fallback for anything unexpected.
 */
function foodParseSamsungTime( ?string $pStr ): ?int {
	$str = trim( (string)$pStr );
	if( $str === '' ) {
		return null;
	}
	$dt = \DateTime::createFromFormat( 'Y-m-d H:i:s.u', $str, new \DateTimeZone( 'UTC' ) );
	if( $dt !== false ) {
		return (int)$dt->format( 'U' );
	}
	$ts = strtotime( $str );
	return $ts !== false ? $ts : null;
}

/**
 * Scale a raw food_info value (expressed per that row's own metric_serving_amount) to
 * a per-100g basis. Returns null if either value is missing/non-numeric — callers must
 * not guess a basis, per the curation-flagging policy.
 */
function foodNormalizePer100g( $pRawValue, $pServingAmount ): ?float {
	if( $pRawValue === null || $pRawValue === '' || !is_numeric( $pRawValue ) ) {
		return null;
	}
	if( !is_numeric( $pServingAmount ) || (float)$pServingAmount <= 0 ) {
		return null;
	}
	return (float)$pRawValue / (float)$pServingAmount * 100.0;
}

/**
 * Insert-or-update one liberty_xref row via LibertyXref::store() (the real API behind
 * the historical 'storeXref' memory note — always a named variable, it takes
 * &$pParamHash by reference). $pXkey/$pData null means "don't touch that column".
 */
function foodStoreXref( int $pContentId, string $pItem, $pXkey = null, $pData = null ): void {
	global $gBitDb;

	$existingId = $gBitDb->getOne(
		"SELECT `xref_id` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ? AND `xorder` = 0",
		[ $pContentId, $pItem ]
	);

	$pHash = [
		'content_id' => $pContentId,
		'item'       => $pItem,
	];
	if( $pXkey !== null ) {
		$pHash['xkey'] = (string)$pXkey;
	}
	if( $pData !== null ) {
		$pHash['edit'] = $pData; // verify() maps 'edit' -> xref_store['data']
	}
	if( $existingId ) {
		$pHash['xref_id'] = (int)$existingId;
	}

	$xref = new LibertyXref();
	$xref->store( $pHash );
}

/**
 * Import (or update) one food_info row as a FoodComponent.
 *
 * @param array $pRow     Associative row from foodParseSamsungCsv().
 * @param int   $pRowNum  1-based source line number, for error messages.
 * @param array &$pResult Accumulator: created/updated/unchanged/skipped counts,
 *                        flagged[] (curation queue), errors[].
 */
function foodImportFoodInfoRow( array $pRow, int $pRowNum, array &$pResult ): void {
	$datauuid = trim( (string)( $pRow['datauuid'] ?? '' ) );
	$title    = trim( (string)( $pRow['name'] ?? '' ) );
	if( $datauuid === '' || $title === '' ) {
		$pResult['skipped']++;
		$pResult['errors'][] = "Row $pRowNum: missing datauuid or name, skipped.";
		return;
	}

	$updateTime = foodParseSamsungTime( $pRow['update_time'] ?? null );
	$createTime = foodParseSamsungTime( $pRow['create_time'] ?? null );

	$existingContentId = FoodComponent::lookupByDatauuid( $datauuid );
	$component = new FoodComponent( $existingContentId );
	if( $existingContentId ) {
		$component->load();
		if( $updateTime !== null && (int)$component->getField( 'last_modified' ) === $updateTime ) {
			$pResult['unchanged']++;
			return;
		}
	}

	$pHash = [ 'title' => $title ];
	if( $createTime !== null ) {
		$pHash['created'] = $createTime;
	}
	if( $updateTime !== null ) {
		$pHash['last_modified'] = $updateTime;
	}
	if( !$component->store( $pHash ) ) {
		$pResult['skipped']++;
		$pResult['errors'][] = "Row $pRowNum: '$title' — failed to store FoodComponent.";
		return;
	}
	$contentId = $component->mContentId;
	$existingContentId ? $pResult['updated']++ : $pResult['created']++;

	foodStoreXref( $contentId, 'DUID', $datauuid );
	$pfid = trim( (string)( $pRow['provider_food_id'] ?? '' ) );
	if( $pfid !== '' ) {
		foodStoreXref( $contentId, 'PFID', $pfid );
	}

	$servingAmount = $pRow['metric_serving_amount'] ?? '';
	$servingUnit   = strtolower( trim( (string)( $pRow['metric_serving_unit'] ?? '' ) ) );
	$hasGramBasis  = is_numeric( $servingAmount ) && (float)$servingAmount > 0 && $servingUnit === 'g';

	if( !$hasGramBasis ) {
		$pResult['flagged'][] = [
			'title'    => $title,
			'datauuid' => $datauuid,
			'reason'   => "no gram basis (metric_serving_amount='$servingAmount', unit='$servingUnit') — nutrition not imported",
		];
		return;
	}

	// CAL — kcal, not a mass, no *1000
	$cal = foodNormalizePer100g( $pRow['calorie'] ?? null, $servingAmount );
	if( $cal !== null ) {
		foodStoreXref( $contentId, 'CAL', (int)round( $cal ) );
	}

	// Scalar mg items — grams in food_info -> integer mg (lossless, confirmed against
	// Samsung's own <=3-decimal-place precision)
	$scalarGramFields = [
		'protein'       => 'PROT',
		'carbohydrate'  => 'CARB',
		'dietary_fiber' => 'FIBR',
		'sugar'         => 'SUGR',
	];
	foreach( $scalarGramFields as $csvField => $item ) {
		$g = foodNormalizePer100g( $pRow[$csvField] ?? null, $servingAmount );
		if( $g !== null ) {
			foodStoreXref( $contentId, $item, (int)round( $g * 1000 ) );
		}
	}

	// SOD — sodium is already mg-scale in food_info, not grams (confirmed against real
	// values, e.g. banana sodium=1mg matches USDA), so no *1000 here.
	$sod = foodNormalizePer100g( $pRow['sodium'] ?? null, $servingAmount );
	if( $sod !== null ) {
		foodStoreXref( $contentId, 'SOD', (int)round( $sod ) );
	}

	// FAT blob — sub-fields are grams (-> mg); cholesterol is already mg-scale
	$fat = [];
	$fatGramFields = [
		'total_fat'         => 'total_mg',
		'saturated_fat'     => 'saturated_mg',
		'monosaturated_fat' => 'mono_mg',
		'polysaturated_fat' => 'poly_mg',
		'trans_fat'         => 'trans_mg',
	];
	foreach( $fatGramFields as $csvField => $key ) {
		$g = foodNormalizePer100g( $pRow[$csvField] ?? null, $servingAmount );
		if( $g !== null ) {
			$fat[$key] = (int)round( $g * 1000 );
		}
	}
	$chol = foodNormalizePer100g( $pRow['cholesterol'] ?? null, $servingAmount );
	if( $chol !== null ) {
		$fat['cholesterol_mg'] = (int)round( $chol );
	}
	if( $fat ) {
		foodStoreXref( $contentId, 'FAT', null, json_encode( $fat ) );
	}

	// MIN blob — food_info only supplies potassium/calcium/iron, all mg-scale (no
	// trace-mcg minerals appear in food_info at all — those only existed in the
	// now-dropped nutrition.csv)
	$min = [];
	$minMgFields = [
		'potassium' => 'potassium_mg',
		'calcium'   => 'calcium_mg',
		'iron'      => 'iron_mg',
	];
	foreach( $minMgFields as $csvField => $key ) {
		$v = foodNormalizePer100g( $pRow[$csvField] ?? null, $servingAmount );
		if( $v !== null ) {
			$min[$key] = (int)round( $v );
		}
	}
	if( $min ) {
		foodStoreXref( $contentId, 'MIN', null, json_encode( $min ) );
	}

	// VIT blob — mixed native units per sub-field, confirmed against real data:
	// vitamin_d is mcg (15mg would be ~600x RDA), vitamin_c is mg-scale, vitamin_a
	// assumed mcg by the same toxicity-bound reasoning as vitamin_d (not independently
	// confirmed — spot-check after first import). Unit-suffixed keys, not one
	// blob-wide unit.
	$vit = [];
	$va = foodNormalizePer100g( $pRow['vitamin_a'] ?? null, $servingAmount );
	if( $va !== null ) {
		$vit['vitamin_a_mcg'] = (int)round( $va );
	}
	$vc = foodNormalizePer100g( $pRow['vitamin_c'] ?? null, $servingAmount );
	if( $vc !== null ) {
		$vit['vitamin_c_mg'] = (int)round( $vc );
	}
	$vd = foodNormalizePer100g( $pRow['vitamin_d'] ?? null, $servingAmount );
	if( $vd !== null ) {
		$vit['vitamin_d_mcg'] = (int)round( $vd );
	}
	if( $vit ) {
		foodStoreXref( $contentId, 'VIT', null, json_encode( $vit ) );
	}

	if( ( $pRow['dietary_fiber'] ?? '' ) === '' ) {
		$pResult['flagged'][] = [
			'title'    => $title,
			'datauuid' => $datauuid,
			'reason'   => 'FIBR missing from source',
		];
	}
}
