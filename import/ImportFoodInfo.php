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
 * Samsung serving-count unit for many ready-meals). The same g/ml basis also sets the
 * component's own WT/VOL type marker (quantity group) — this is what lets
 * FoodAssembly::getItems() display the right unit after an ingredient's quantity.
 * Rows without a usable weight/volume basis still get nutrition imported under an
 * assumed 100g/ml basis (rough data beats none), but get no WT/VOL marker (an assumed
 * basis doesn't actually tell us weight vs volume) — flagged in curation_needed.csv,
 * with the reason written to the component's
 * own liberty_content.data (visible on view_component.php) and a bare REM xref row
 * (flag-only, no data payload) marking it. xkey_ext='REVIEW' is the outstanding-work
 * flag itself — "this needs review" — set by the importer at import time and cleared
 * by hand (edit_component.tpl's tick floaticon) once a component is actually fixed;
 * see list_review.php's own docblock for the full semantics. Missing FIBR gets the
 * same treatment.
 *
 * @package food
 */

use Bitweaver\Food\FoodComponent;
use Bitweaver\Liberty\LibertyContent;

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
/**
 * $pOffset is food_intake.csv's own time_offset column ('UTC+0000'/'UTC+0100' etc) —
 * confirmed both actually occur in the real export (GMT vs BST periods) and confirmed
 * the raw start_time/create_time/update_time strings are LOCAL wall-clock time, not
 * already UTC (standard mobile-health-export convention: record what the clock said
 * + the offset needed to compute true UTC, same as Apple Health/Google Fit/Fitbit).
 * Without $pOffset (food_info.csv has no such column at all, only food_intake.csv
 * does) falls back to naive UTC parsing — the best available for that file, an
 * honest gap rather than a fixable one, less consequential anyway (only affects
 * FoodComponent's own created/last_modified, not any day-grouping logic).
 */
function foodParseSamsungTime( ?string $pStr, ?string $pOffset = null ): ?int {
	$str = trim( (string)$pStr );
	if( $str === '' ) {
		return null;
	}
	$tz = new \DateTimeZone( 'UTC' );
	if( $pOffset && preg_match( '/^UTC([+-]\d{2})(\d{2})$/', trim( $pOffset ), $m ) ) {
		$tz = new \DateTimeZone( $m[1].':'.$m[2] );
	}
	$dt = \DateTime::createFromFormat( 'Y-m-d H:i:s.u', $str, $tz );
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
 * Insert-or-update one Xref row via LibertyContent::upsertXrefByContentId()
 * (existing-or-add, no loaded content object needed). $pXkey/$pXkeyExt/$pData
 * null means "don't touch that column"; params follow table column order
 * (xkey, xkey_ext, data). $pXkey is
 * `xkey C(32)` — short values only (mg-integers etc); anything that can run longer
 * (UUIDs, provider_food_id) must go in $pXkeyExt (`xkey_ext C(250)`) instead —
 * confirmed the hard way, xkey truncation is a Firebird fatal error (SQLSTATE 22001),
 * not a silent cut.
 *
 * $pEntryDate/$pLastUpdateDate (unix timestamps) let the xref mirror the
 * FoodComponent's own created/last_modified (the Samsung create_time/update_time)
 * rather than the moment the importer happened to run — see liberty's
 * LibertyXref::verify() override support, added alongside this.
 *
 * $pXref (a content_id, e.g. a supplier Contact) writes the xref column itself,
 * distinct from xkey/xkey_ext — used by SUP, see foodMatchSupplier().
 */
function foodStoreXref( int $pContentId, string $pItem, $pXkey = null, $pXkeyExt = null, $pData = null, ?int $pEntryDate = null, ?int $pLastUpdateDate = null, ?int $pXref = null ): void {
	$pHash = [];
	if( $pXkey !== null ) {
		$pHash['xkey'] = (string)$pXkey;
	}
	if( $pXkeyExt !== null ) {
		$pHash['xkey_ext'] = (string)$pXkeyExt;
	}
	if( $pXref !== null ) {
		$pHash['xref'] = $pXref;
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

	LibertyContent::upsertXrefByContentId( $pContentId, $pItem, $pHash );
}

/**
 * title -> content_id lookup for the known shop suppliers (contactbusiness records
 * carrying the B04 'Supplier' xref, see project_contact_bugs_found memory for the
 * business-type reference) — built once per import run, not once per row.
 *
 * @return array<string,int>  Contact title => content_id.
 */
function foodSupplierLookup(): array {
	static $lookup = null;
	if( $lookup === null ) {
		$lookup = [];
		foreach( LibertyContent::listContentByXrefItem( 'B04', 'contactbusiness' ) as $row ) {
			$lookup[$row['title']] = (int)$row['content_id'];
		}
	}
	return $lookup;
}

/**
 * Match a food_info title's trailing "(...)" text against a known shop supplier.
 *
 * Samsung bakes supplier/brand into the title as free text (e.g. "Ice cream sandwich
 * (Gelatelli)", "Cheese Slice(Morrisons)"). Whether that text stays in the title
 * depends on *how* it matched — see the `trim` flag on the return value.
 *
 * Three-stage match, in order:
 *   1. Plain exact match against a real shop title (e.g. "(Morrisons)" == "Morrisons")
 *      — pure duplication of what SUP now encodes structurally, safe to trim from the
 *      title (the author: "the (Morrisons) needs to stay in the .csv [source] it would be
 *      nice to trim from the title").
 *   2. Explicit aliases for own-brand sub-labels that don't literally contain their
 *      parent chain's name (e.g. Lidl's "Chef Select" range, "By Sainsbury's", "M&S
 *      Food" — the latter added as the same class of case as the two the author named, not
 *      explicitly confirmed, worth a spot-check).
 *   3. Substring containment against every known shop title, covering most retailer
 *      own-brand sub-lines for free ("Tesco Finest" contains "Tesco", "Waitrose
 *      Essential" contains "Waitrose") without needing an alias entry per sub-brand.
 * Stages 2 and 3 carry real information beyond "which shop" (the sub-brand/range name)
 * — the author's earlier call was explicit that this stays in the title ("the 'own brand'
 * bit just needs leaving in the title"), only a bare shop-name match is redundant
 * enough to trim.
 *
 * Most parenthetical text won't match anything at all — manufacturer brands
 * (Heinz, Cadbury...) and restaurant chains (McDonald's, KFC...) are real, common, and
 * deliberately not shops (the author: "worry about brands later") — that's the expected
 * majority outcome, not a gap to flag or curate.
 *
 * @return array{content_id:int,trim:bool}|null  Matched shop + whether the matched
 *         bracket text is safe to strip from the title, or null if nothing matched.
 */
function foodMatchSupplier( string $pTitle ): ?array {
	if( !preg_match( '/\(([^()]*)\)\s*$/', $pTitle, $m ) ) {
		return null;
	}
	$paren = trim( $m[1] );
	if( $paren === '' ) {
		return null;
	}
	$lower  = strtolower( $paren );
	$lookup = foodSupplierLookup();

	foreach( $lookup as $shopTitle => $contentId ) {
		if( strtolower( $shopTitle ) === $lower ) {
			return [ 'content_id' => $contentId, 'trim' => true ];
		}
	}

	static $aliases = [
		'chef select'    => 'Lidl',
		'chef select '   => 'Lidl', // trailing-space variant seen in real data
		"by sainsbury's" => "Sainsbury's",
		'm&s food'       => 'Marks & Spencer',
	];
	if( isset( $aliases[$lower] ) ) {
		$canonical = strtolower( $aliases[$lower] );
		foreach( $lookup as $shopTitle => $contentId ) {
			if( strtolower( $shopTitle ) === $canonical ) {
				return [ 'content_id' => $contentId, 'trim' => false ];
			}
		}
	}

	foreach( $lookup as $shopTitle => $contentId ) {
		if( str_contains( $lower, strtolower( $shopTitle ) ) ) {
			return [ 'content_id' => $contentId, 'trim' => false ];
		}
	}
	return null;
}

/**
 * Strip a trailing "(...)" bracket from a title, e.g. "Cheese Slice(Morrisons)" ->
 * "Cheese Slice" — only called for foodMatchSupplier()'s exact-match (trim=true) case.
 */
function foodTrimSupplierBracket( string $pTitle ): string {
	return trim( preg_replace( '/\s*\([^()]*\)\s*$/', '', $pTitle ) );
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

	// Matched against the raw title before any trimming — an exact shop-name match
	// (trim=true) strips the now-redundant bracket from the *stored* title below; a
	// sub-brand/alias match (trim=false) leaves the title exactly as Samsung wrote it.
	$supplierMatch = foodMatchSupplier( $title );
	if( $supplierMatch !== null && $supplierMatch['trim'] ) {
		$title = foodTrimSupplierBracket( $title );
	}

	// Curation checks computed up-front (both are pure functions of $pRow, no
	// dependency on anything below) so the note can go into the ONE store() call
	// below, rather than a second store() later — see the fatal-bug note further
	// down for why a second store() on the same object is unsafe, not just wasteful.
	$servingAmount = $pRow['metric_serving_amount'] ?? '';
	$servingUnit   = strtolower( trim( (string)( $pRow['metric_serving_unit'] ?? '' ) ) );
	// 'g' (weight) and 'ml' (volume) are both legitimate per-100-unit labeling bases —
	// matches the WT/VOL split already in foodcomponent's quantity group. Samsung's own
	// serving-count codes (e.g. metric_serving_unit='120001') and amount=0/blank are
	// the genuine no-basis case. Rather than leave nutrition empty, assume 100g/ml and
	// import anyway (rough data beats none) — flagged via a note in REM's data instead
	// of a dedicated xref item, since REM isn't used for anything yet.
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
	if( ( $pRow['dietary_fiber'] ?? '' ) === '' ) {
		$curationNotes[] = 'FIBR missing from source';
		$pResult['flagged'][] = [
			'title'    => $title,
			'datauuid' => $datauuid,
			'reason'   => 'FIBR missing from source',
		];
	}
	$effectiveServingAmount = $hasUsableBasis ? $servingAmount : 100;

	// Curation note: the human-readable reason goes straight onto the component's own
	// liberty_content.data (wrapped in <p>... actually plain text, format_guid is
	// forced to 'simpletext' in FoodComponent::verifyComponentData(), which renders
	// line breaks itself via nl2br at display time — hand-written HTML here would just
	// show up as literal escaped tags) via the SAME store() call as title/created/
	// last_modified below — not REM's own data field, which was only ever a stopgap
	// before this became the settled convention (see project_food_package_scoping
	// memory, "REM's xkey_ext/data split"). REM itself gets a flag row further down
	// (no data payload, that's on liberty_content.data) with xkey_ext='REVIEW' — the
	// outstanding-work flag itself, "this needs review", not "reviewed and accepted".
	$pHash = [ 'title' => $title ];
	if( $createTime !== null ) {
		$pHash['created'] = $createTime;
	}
	if( $updateTime !== null ) {
		$pHash['last_modified'] = $updateTime;
	}
	if( $curationNotes ) {
		$pHash['edit'] = implode( '; ', $curationNotes );
	}
	// Real bug found+fixed 2026-08-17: this used to be two separate store() calls on
	// the same $component object (title/dates here, then the curation note later) —
	// LibertyContent::store() never refreshes $this->mInfo['version'] in memory after
	// a write, so a second store() on the same object computes the identical "next
	// version" as the first and collides on liberty_content_history's unique
	// (content_id, version) key — Firebird SQLSTATE 23000. Silently never triggered
	// before today because the title never actually changed release-to-release (no
	// real field_changed on that first call), only surfaced once the SUP-trim logic
	// above started giving the first call real content to change. Folding the note
	// into this single call removes the second store() entirely, not just papers over
	// the collision.
	if( !$component->store( $pHash ) ) {
		$pResult['skipped']++;
		$pResult['errors'][] = "Row $pRowNum: '$title' — failed to store FoodComponent.";
		return;
	}
	$contentId = $component->mContentId;
	$existingContentId ? $pResult['updated']++ : $pResult['created']++;

	// Every xref written for this row shares the same entry_date/last_update_date —
	// the Samsung record's own create_time/update_time, not import-run time. Trimmed
	// to date-only (no time-of-day) — precise time within the day was never useful
	// here, only adds noise reading raw xref rows in isql/FlameRobin.
	$createDate = $createTime !== null ? strtotime( gmdate( 'Y-m-d 00:00:00', $createTime ) ) : null;
	$updateDate = $updateTime !== null ? strtotime( gmdate( 'Y-m-d 00:00:00', $updateTime ) ) : null;
	$storeXref = function( string $pItem, $pXkey = null, $pXkeyExt = null, $pData = null, ?int $pXref = null ) use ( $contentId, $createDate, $updateDate ) {
		foodStoreXref( $contentId, $pItem, $pXkey, $pXkeyExt, $pData, $createDate, $updateDate, $pXref );
	};

	// datauuid (36 chars) and provider_food_id (up to ~48 chars for quickinput-<uuid>)
	// both exceed xkey's 32-char limit — xkey_ext, not xkey.
	$storeXref( 'DUID', null, $datauuid );
	$pfid = trim( (string)( $pRow['provider_food_id'] ?? '' ) );
	if( $pfid !== '' ) {
		$storeXref( 'PFID', null, $pfid );
	}

	// SUP — real xref to a known shop's Contact content_id ($supplierMatch computed
	// earlier, against the raw title, before the possible trim above). Only a small
	// fixed set of shops exist as Contacts so far — no match is the normal/expected
	// outcome for most rows (manufacturer brands, restaurant chains, or shops not yet
	// added as Contacts), not flagged as a curation gap.
	if( $supplierMatch !== null ) {
		$storeXref( 'SUP', null, null, null, $supplierMatch['content_id'] );
	}

	// 'g' (weight) and 'ml' (volume) are both legitimate per-100-unit labeling bases —
	// matches the WT/VOL split already in foodcomponent's quantity group ($servingAmount/
	// $servingUnit/$hasUsableBasis/$effectiveServingAmount computed earlier, up front,
	// alongside the curation-note checks — see the note there for why).
	//
	// Declare the component's own WT/VOL type marker (foodcomponent's quantity group)
	// from the same serving-unit basis just used for nutrition normalization — this
	// is what lets FoodAssembly::getItems() show the right unit (g/ml) after an
	// ingredient's quantity. Only written when $hasUsableBasis is real (not the
	// assumed-100g/ml curation case) — an assumed basis doesn't actually tell us
	// weight vs volume, so leaving the marker unset (no unit shown) is more honest
	// than guessing, same spirit as the REM curation flag on these rows.
	if( $hasUsableBasis ) {
		$storeXref( $servingUnit === 'ml' ? 'VOL' : 'WT' );
	}

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

	// FIBR-missing and no-usable-basis curation checks + the note itself are computed
	// up front now (folded into the single store() call above) — REM just needs the
	// flag row itself here. xkey_ext='REVIEW' is the outstanding-work flag itself,
	// "this needs review", not "reviewed and accepted" (confirmed with the author
	// 2026-08-16 after a few rounds of me having the polarity backwards; renamed from
	// 'CORRECT' 2026-08-17, ambiguous as "this is correct"). list_review.php's query
	// finds outstanding work by this flag directly (xkey_ext='REVIEW'), so a fresh
	// import against a known export correctly re-flags every row still needing a
	// look — that's the real, expected to-do list, not something that starts empty.
	// Clearing the flag (edit_component.tpl's tick floaticon) is the only thing that
	// marks a component done, same as Gelatelli's WT/PCK fix.
	if( $curationNotes ) {
		$storeXref( 'REM', null, 'REVIEW' );
	}
}
