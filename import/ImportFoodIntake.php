<?php
/**
 * food_intake.csv → FoodAssembly (meal instance) importer.
 *
 * Groups food_intake rows by (start_time, meal_type) — all items sharing an exact
 * start_time+meal_type were logged together as one meal (confirmed against real data,
 * see project_food_package_scoping memory) — and writes one FoodAssembly per group,
 * with each item as an Xref row under the meal-type's own item code
 * (BREAKFAST/LUNCH/DINNER/MSNK/ESNK — no separate classification xref, see
 * FoodAssembly.php's own docblock).
 *
 * Must run after ImportFoodInfo.php — every food_intake row's food_info_id needs an
 * already-imported FoodComponent to link to.
 *
 * Rebuilds each meal's item list from scratch on every run (clearItems() then
 * re-add) rather than diffing — safe and simple, re-running with unchanged source
 * data just reproduces the same rows.
 *
 * @package food
 */

use Bitweaver\Food\FoodAssembly;
use Bitweaver\Food\FoodComponent;

/**
 * Samsung's meal_type is a flat enum, not an xorder-thousands scheme — confirmed
 * against nutrition.csv's own title field (100005 is an unused gap in Samsung's own
 * scheme, not a mapping error here).
 */
function foodMealTypeItem( ?string $pMealTypeCode ): ?string {
	return match( trim( (string)$pMealTypeCode ) ) {
		'100001' => 'BREAKFAST',
		'100002' => 'LUNCH',
		'100003' => 'DINNER',
		'100004' => 'MSNK',
		'100006' => 'ESNK',
		default  => null,
	};
}

/**
 * datauuid -> [amount, unit] from food_info.csv's own metric_serving_amount/unit —
 * needed to convert a food_intake row's serving-count amount (unit=120001) into
 * grams/ml. Re-parses food_info.csv rather than storing this on FoodComponent itself;
 * it's only needed transiently at import time, not as an ongoing schema feature (see
 * project_food_package_scoping's "massaging the raw data" scoping decision).
 */
function foodBuildServingLookup( array $pInfoRows ): array {
	$lookup = [];
	foreach( $pInfoRows as $row ) {
		$datauuid = trim( (string)( $row['datauuid'] ?? '' ) );
		if( $datauuid === '' ) {
			continue;
		}
		$lookup[$datauuid] = [
			'amount' => $row['metric_serving_amount'] ?? '',
			'unit'   => strtolower( trim( (string)( $row['metric_serving_unit'] ?? '' ) ) ),
		];
	}
	return $lookup;
}

/**
 * Convert one food_intake row's amount+unit into an integer grams/ml quantity
 * (whichever base unit the referenced FoodComponent actually uses — the map row
 * itself doesn't need to know which, see FoodAssembly.php's own docblock).
 *
 * unit=120002 (confirmed grams-direct) and unit=120001 (confirmed serving-count
 * multiplier, needs the component's own metric_serving_amount to convert) are the
 * two decoded codes. Anything else falls back to "amount is already the base-unit
 * quantity" and gets flagged — same assume-and-flag policy as ImportFoodInfo.php's
 * no-gram-basis handling, not a block.
 */
function foodIntakeAmountToGrams( $pAmount, ?string $pUnit, ?array $pServingInfo, array &$pNotes ): int {
	if( !is_numeric( $pAmount ) ) {
		$pNotes[] = "non-numeric amount '$pAmount', assumed 100";
		return 100;
	}
	$amount = (float)$pAmount;
	$unit   = trim( (string)$pUnit );

	if( $unit === '120002' ) {
		return (int)round( $amount );
	}

	if( $unit === '120001' ) {
		$servingAmount = $pServingInfo['amount'] ?? '';
		$servingUnit   = $pServingInfo['unit'] ?? '';
		if( is_numeric( $servingAmount ) && (float)$servingAmount > 0 && in_array( $servingUnit, [ 'g', 'ml' ], true ) ) {
			return (int)round( $amount * (float)$servingAmount );
		}
		$pNotes[] = "serving-count unit but component has no real serving weight, assumed {$amount}x100";
		return (int)round( $amount * 100 );
	}

	$pNotes[] = "unrecognized unit code '$unit', assumed amount is already the base-unit quantity";
	return (int)round( $amount );
}

/**
 * Import (or rebuild) one meal instance from a group of food_intake rows sharing the
 * same start_time+meal_type.
 *
 * @param array $pGroupRows      food_intake rows for one meal, in source order.
 * @param array $pServingLookup  datauuid -> [amount, unit], from foodBuildServingLookup().
 * @param array &$pResult        Accumulator: created/updated/skipped counts, flagged[], errors[].
 */
function foodImportIntakeGroup( array $pGroupRows, array $pServingLookup, array &$pResult ): void {
	$first    = $pGroupRows[0];
	$mealItem = foodMealTypeItem( $first['meal_type'] ?? null );
	if( $mealItem === null ) {
		$pResult['skipped'] += count( $pGroupRows );
		$pResult['errors'][] = "Unrecognized meal_type '".( $first['meal_type'] ?? '' )."' — group skipped (".count( $pGroupRows )." rows).";
		return;
	}

	$eventTime = foodParseSamsungTime( $first['start_time'] ?? null, $first['time_offset'] ?? null );
	if( $eventTime === null ) {
		$pResult['skipped'] += count( $pGroupRows );
		$pResult['errors'][] = "Group with unparseable start_time skipped (".count( $pGroupRows )." rows).";
		return;
	}

	$createTimes = array_filter( array_map( fn( $r ) => foodParseSamsungTime( $r['create_time'] ?? null, $r['time_offset'] ?? null ), $pGroupRows ) );
	$updateTimes = array_filter( array_map( fn( $r ) => foodParseSamsungTime( $r['update_time'] ?? null, $r['time_offset'] ?? null ), $pGroupRows ) );
	$createTime  = $createTimes ? min( $createTimes ) : null;
	$updateTime  = $updateTimes ? max( $updateTimes ) : null;

	$existingContentId = FoodAssembly::lookupByEventTime( $mealItem, $eventTime );
	$assembly = new FoodAssembly( $existingContentId );

	$title = ucfirst( strtolower( $mealItem ) ) . ' — ' . gmdate( 'Y-m-d', $eventTime );
	$pHash = [ 'title' => $title, 'event_time' => $eventTime ];
	if( $createTime !== null ) {
		$pHash['created'] = $createTime;
	}
	if( $updateTime !== null ) {
		$pHash['last_modified'] = $updateTime;
	}
	if( !$assembly->store( $pHash ) ) {
		$pResult['skipped'] += count( $pGroupRows );
		$pResult['errors'][] = "'$title' — failed to store FoodAssembly.";
		return;
	}
	$existingContentId ? $pResult['updated']++ : $pResult['created']++;

	$assembly->clearItems( $mealItem );

	$position = 0;
	foreach( $pGroupRows as $row ) {
		$position++;
		$datauuid = trim( (string)( $row['food_info_id'] ?? '' ) );
		$componentId = $datauuid !== '' ? FoodComponent::lookupByDatauuid( $datauuid ) : null;
		if( $componentId === null ) {
			$pResult['errors'][] = "'$title': item '".( $row['name'] ?? '?' )."' — no matching FoodComponent (datauuid '$datauuid'), item skipped.";
			continue;
		}

		$notes = [];
		$grams = foodIntakeAmountToGrams( $row['amount'] ?? null, $row['unit'] ?? null, $pServingLookup[$datauuid] ?? null, $notes );
		if( $notes ) {
			$pResult['flagged'][] = [
				'title'    => $title,
				'datauuid' => $datauuid,
				'reason'   => ( $row['name'] ?? '?' ).': '.implode( '; ', $notes ),
			];
		}

		$rowCreateTime = foodParseSamsungTime( $row['create_time'] ?? null, $row['time_offset'] ?? null );
		$rowUpdateTime = foodParseSamsungTime( $row['update_time'] ?? null, $row['time_offset'] ?? null );
		$assembly->addItem( $mealItem, $componentId, $grams, $position, $rowCreateTime, $rowUpdateTime );
	}
}
