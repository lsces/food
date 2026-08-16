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
 * Nutrition is normalized to per-100g/ml at import time (food_info's own basis varies
 * per row — 91g for plain Broccoli, already 100g for most branded items, 0/blank or a
 * Samsung serving-count unit for many ready-meals). Rows without a usable weight/volume
 * basis still get nutrition imported under an assumed 100g/ml basis (rough data beats
 * none) — flagged both in curation_needed.csv and as a note in the component's REM
 * xref data (no dedicated flag item; REM isn't used for anything else yet). Missing
 * FIBR gets the same treatment.
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
 * &$pParamHash by reference). $pXkey/$pXkeyExt/$pData null means "don't touch that
 * column"; params follow table column order (xkey, xkey_ext, data). $pXkey is
 * `xkey C(32)` — short values only (mg-integers etc); anything that can run longer
 * (UUIDs, provider_food_id) must go in $pXkeyExt (`xkey_ext C(250)`) instead —
 * confirmed the hard way, xkey truncation is a Firebird fatal error (SQLSTATE 22001),
 * not a silent cut.
 *
 * $pEntryDate/$pLastUpdateDate (unix timestamps) let the xref mirror the
 * FoodComponent's own created/last_modified (the Samsung create_time/update_time)
 * rather than the moment the importer happened to run — see liberty's
 * LibertyXref::verify() override support, added alongside this.
 */
function foodStoreXref( int $pContentId, string $pItem, $pXkey = null, $pXkeyExt = null, $pData = null, ?int $pEntryDate = null, ?int $pLastUpdateDate = null ): void {
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
	if( $pXkeyExt !== null ) {
		$pHash['xkey_ext'] = (string)$pXkeyExt;
	}
	if( $pData !== null ) {
		$pHash['edit'] = $pData; // verify() maps 'edit' -> xref_store['data']
	}
	if( $pEntryDate !== null ) {
		$pHash['entry_date'] = $pEntryDate;
	}
	if( $pLastUpdateDate !== null ) {
		$pHash['last_update_date'] = $pLastUpdateDate;
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
 * @param bool  $pForce   Reprocess even if update_time matches what's already stored
 *                        — needed after an importer rule change, since "unchanged"
 *                        is otherwise judged against the source data, not our own
 *                        normalization logic.
 */
function foodImportFoodInfoRow( array $pRow, int $pRowNum, array &$pResult, bool $pForce = false ): void {
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
		if( !$pForce && $updateTime !== null && (int)$component->getField( 'last_modified' ) === $updateTime ) {
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

	// Every xref written for this row shares the same entry_date/last_update_date —
	// the Samsung record's own create_time/update_time, not import-run time.
	$storeXref = function( string $pItem, $pXkey = null, $pXkeyExt = null, $pData = null ) use ( $contentId, $createTime, $updateTime ) {
		foodStoreXref( $contentId, $pItem, $pXkey, $pXkeyExt, $pData, $createTime, $updateTime );
	};

	// datauuid (36 chars) and provider_food_id (up to ~48 chars for quickinput-<uuid>)
	// both exceed xkey's 32-char limit — xkey_ext, not xkey.
	$storeXref( 'DUID', null, $datauuid );
	$pfid = trim( (string)( $pRow['provider_food_id'] ?? '' ) );
	if( $pfid !== '' ) {
		$storeXref( 'PFID', null, $pfid );
	}

	// 'g' (weight) and 'ml' (volume) are both legitimate per-100-unit labeling bases —
	// matches the WT/VOL split already in foodcomponent's quantity group. Samsung's own
	// serving-count codes (e.g. metric_serving_unit='120001') and amount=0/blank are
	// the genuine no-basis case. Rather than leave nutrition empty, assume 100g/ml and
	// import anyway (rough data beats none) — flagged via a note in REM's data instead
	// of a dedicated xref item, since REM isn't used for anything yet.
	$servingAmount = $pRow['metric_serving_amount'] ?? '';
	$servingUnit   = strtolower( trim( (string)( $pRow['metric_serving_unit'] ?? '' ) ) );
	$hasUsableBasis = is_numeric( $servingAmount ) && (float)$servingAmount > 0 && in_array( $servingUnit, [ 'g', 'ml' ], true );

	$curationNotes = [];
	if( !$hasUsableBasis ) {
		$curationNotes[] = "assumed 100g/ml basis (source amount='$servingAmount', unit='$servingUnit')";
		$pResult['flagged'][] = [
			'title'    => $title,
			'datauuid' => $datauuid,
			'reason'   => "nutrition assumed 100g/ml (source amount='$servingAmount', unit='$servingUnit') — needs checking",
		];
	}
	$effectiveServingAmount = $hasUsableBasis ? $servingAmount : 100;

	// CAL — kcal, not a mass, no *1000
	$cal = foodNormalizePer100g( $pRow['calorie'] ?? null, $effectiveServingAmount );
	if( $cal !== null ) {
		$storeXref( 'CAL', (int)round( $cal ) );
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
		$g = foodNormalizePer100g( $pRow[$csvField] ?? null, $effectiveServingAmount );
		if( $g !== null ) {
			$storeXref( $item, (int)round( $g * 1000 ) );
		}
	}

	// SOD — sodium is already mg-scale in food_info, not grams (confirmed against real
	// values, e.g. banana sodium=1mg matches USDA), so no *1000 here.
	$sod = foodNormalizePer100g( $pRow['sodium'] ?? null, $effectiveServingAmount );
	if( $sod !== null ) {
		$storeXref( 'SOD', (int)round( $sod ) );
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
		$g = foodNormalizePer100g( $pRow[$csvField] ?? null, $effectiveServingAmount );
		if( $g !== null ) {
			$fat[$key] = (int)round( $g * 1000 );
		}
	}
	$chol = foodNormalizePer100g( $pRow['cholesterol'] ?? null, $effectiveServingAmount );
	if( $chol !== null ) {
		$fat['cholesterol_mg'] = (int)round( $chol );
	}
	if( $fat ) {
		$storeXref( 'FAT', null, null, json_encode( $fat ) );
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
		$v = foodNormalizePer100g( $pRow[$csvField] ?? null, $effectiveServingAmount );
		if( $v !== null ) {
			$min[$key] = (int)round( $v );
		}
	}
	if( $min ) {
		$storeXref( 'MIN', null, null, json_encode( $min ) );
	}

	// VIT blob — mixed native units per sub-field, confirmed against real data:
	// vitamin_d is mcg (15mg would be ~600x RDA), vitamin_c is mg-scale, vitamin_a
	// assumed mcg by the same toxicity-bound reasoning as vitamin_d (not independently
	// confirmed — spot-check after first import). Unit-suffixed keys, not one
	// blob-wide unit.
	$vit = [];
	$va = foodNormalizePer100g( $pRow['vitamin_a'] ?? null, $effectiveServingAmount );
	if( $va !== null ) {
		$vit['vitamin_a_mcg'] = (int)round( $va );
	}
	$vc = foodNormalizePer100g( $pRow['vitamin_c'] ?? null, $effectiveServingAmount );
	if( $vc !== null ) {
		$vit['vitamin_c_mg'] = (int)round( $vc );
	}
	$vd = foodNormalizePer100g( $pRow['vitamin_d'] ?? null, $effectiveServingAmount );
	if( $vd !== null ) {
		$vit['vitamin_d_mcg'] = (int)round( $vd );
	}
	if( $vit ) {
		$storeXref( 'VIT', null, null, json_encode( $vit ) );
	}

	if( ( $pRow['dietary_fiber'] ?? '' ) === '' ) {
		$curationNotes[] = 'FIBR missing from source';
		$pResult['flagged'][] = [
			'title'    => $title,
			'datauuid' => $datauuid,
			'reason'   => 'FIBR missing from source',
		];
	}

	// Curation flags live as a free-text note in REM's data — no dedicated xref item,
	// REM isn't used for anything else yet (no FoodMovement/stocktake exists), and a
	// separate flag item would just be another thing to check when REM starts being
	// used for real. Whoever eventually reads REM for stock purposes should expect
	// this note may still be sitting there until someone curates it away.
	if( $curationNotes ) {
		$storeXref( 'REM', null, null, implode( '; ', $curationNotes ) );
	}
}
