<?php
/**
 * Food's day-summary calendar records - a whole day's food (kcal/fibre/5AD
 * totals) independently selectable on the calendar alongside FoodAssembly's
 * own per-meal records, instead of month/week views only ever being able to
 * show every meal separately.
 *
 * NOT a LibertyContent subclass and holds NO data of its own - there is no
 * liberty_content row for any individual day, no store/load/isValid/edit UI.
 * The *type itself* is registered the normal way (register(), self-installing
 * into liberty_content_types exactly like every LibertyContent subclass's own
 * constructor does - see LibertySystem::registerContentType()) purely so
 * calendar can discover it generically, the same way it discovers every other
 * type - no food-specific knowledge anywhere in Calendar.php or index.php.
 * Calendar::getEvents() checks method_exists($handler_class, 'getContentList')
 * on whatever the registry already has for a selected guid; when present (as
 * here), it calls that directly instead of its own normal SQL-against-
 * liberty_content path, which fundamentally requires real rows and isn't
 * per-type-overridable itself.
 *
 * @package food
 */
namespace Bitweaver\Food;

class FoodDay {

	/**
	 * Self-installs the 'foodday' type into liberty_content_types (metadata only
	 * - name/icon/handler_class - never any liberty_content row) so calendar can
	 * find it via the normal registry, the same way every LibertyContent
	 * subclass's own constructor does. Idempotent (registerContentType() checks
	 * for an existing row itself) - called defensively from getContentList() on
	 * every real use, so nothing needs a separate one-off setup step even if
	 * liberty_content_types ever gets reset.
	 */
	public static function register(): void {
		global $gLibertySystem;
		$gLibertySystem->registerContentType(
			'foodday', [
				'content_type_guid' => 'foodday',
				'content_name' => 'Food Day',
				'content_name_plural' => 'Food Days',
				'handler_class' => 'FoodDay',
				'handler_package' => 'food',
				'handler_file' => 'FoodDay.php',
				'maintainer_url' => 'https://www.bitweaver.org',
		], );
	}

	/**
	 * Calendar day-summary records for a date range - called directly by
	 * Calendar::getEvents() once it finds this class has its own getContentList()
	 * (see this class's own docblock), not through the generic SQL path every
	 * other type uses. Same return shape Calendar::getList() itself produces
	 * (day-start UTC timestamp => array of item rows), one row per calendar day
	 * that actually has food logged - no row (and so no calendar cell) for an
	 * empty day.
	 *
	 * @param  array $pListHash  Must contain time_limit_start/time_limit_stop -
	 *                           the same display-offset-shifted UTC bounds
	 *                           Calendar::getEvents() computes for every other
	 *                           type's own range query.
	 * @return array
	 */
	public static function getContentList( array $pListHash ): array {
		global $gBitDb, $gBitSystem;
		$gBitDate = $gBitSystem->mServerTimestamp;
		self::register();

		$startUtc = (int)( $pListHash['time_limit_start'] ?? 0 );
		$stopUtc  = (int)( $pListHash['time_limit_stop'] ?? 0 );
		if( !$startUtc || !$stopUtc ) {
			return [];
		}

		$ret = [];
		$cursor  = strtotime( gmdate( 'Y-m-d 00:00:00', $gBitDate->getDisplayDateFromUTC( $startUtc ) ) );
		$stopDay = strtotime( gmdate( 'Y-m-d 00:00:00', $gBitDate->getDisplayDateFromUTC( $stopUtc ) ) );

		while( $cursor <= $stopDay ) {
			$isoDate = gmdate( 'Y-m-d', $cursor );
			$item = self::buildDaySummary( $isoDate, $cursor );
			if( $item ) {
				[ $y, $m, $d ] = array_map( 'intval', explode( '-', $isoDate ) );
				// Same day-bucket key shape Calendar::buildMonth() itself builds for
				// each grid cell (gmmktime of the local calendar date) - matching
				// this exactly is what lets buildCalendar() attach the item to the
				// right cell.
				$ret[gmmktime( 0, 0, 0, $m, $d, $y )][] = $item;
			}
			$cursor += 86400;
		}

		return $ret;
	}

	/**
	 * One synthetic row for a single date, or null if that date has no food
	 * logged at all. content_id is a stable, never-real negative number (safe
	 * for calendar_box/parseDataHash's own defensive handling) since nothing
	 * looks it up as a real liberty_content row - display_url/cell_html are
	 * pre-baked here rather than relying on the generic getDisplayUrlFromHash/
	 * getDayCellHtml dispatch every real row goes through in
	 * LibertyContent::getContentList(), which this path never reaches.
	 */
	private static function buildDaySummary( string $pIsoDate, int $pLocalDayStart ): ?array {
		global $gBitDb, $gBitSystem;
		$gBitDate = $gBitSystem->mServerTimestamp;

		$utcDayStart = $gBitDate->getUTCFromDisplayDate( $pLocalDayStart );
		$utcDayEnd   = $gBitDate->getUTCFromDisplayDate( $pLocalDayStart + 86400 );

		$assemblyIds = $gBitDb->getCol(
			"SELECT `content_id` FROM `".BIT_DB_PREFIX."liberty_content`
				WHERE `content_type_guid` = 'foodassembly' AND `event_time` >= ? AND `event_time` < ?",
			[ $utcDayStart, $utcDayEnd ]
		);
		if( !$assemblyIds ) {
			return null;
		}

		$totalRaw = array_fill_keys( array_keys( FoodComponent::NUTRITION_SUMMARY_FIELDS ), 0.0 );
		foreach( $assemblyIds as $assemblyId ) {
			$assembly = new FoodAssembly( null, (int)$assemblyId );
			$data = $assembly->getItemsWithNutrition();
			$totalRaw = FoodComponent::sumNutrition( $totalRaw, $data['totalRaw'] );
		}
		$totals = FoodComponent::formatNutrition( $totalRaw );

		$displayUrl = CALENDAR_PKG_URL.'package_page.php?pkg=food&view_mode=day&todate='.$pIsoDate;
		$body = htmlspecialchars( 'Day: '.$totals['CAL'].' · '.$totals['FIBR'].' fibre · '.$totals['5AD'].' 5AD' );

		// event_time/timestamp deliberately use $pLocalDayStart (the naive, unshifted
		// gmmktime(0,0,0,...) value - same as the array bucket key getContentList()
		// uses), NOT $utcDayStart (the proper BST-aware conversion used just above
		// for the actual SQL query). Calendar's own hour-slot matching in
		// buildCalendar() (day view) compares a real row's `timestamp` against
		// Calendar::buildDay()'s own naively-computed slot times - real rows get
		// there via getList()'s `raw event_time + a fixed display_offset`, not a
		// per-date BST-aware shift. Using the BST-aware value here landed this item
		// a full hour before every slot even existed, silently matching nothing.
		return [
			'content_id'        => -$pLocalDayStart,
			'content_type_guid' => 'foodday',
			'title'             => $pIsoDate,
			'event_time'        => $pLocalDayStart,
			'timestamp'         => $pLocalDayStart,
			// The real UTC instant of this local day's midnight - already computed above for
			// the SQL query. Calendar::buildCalendar()'s day-view row matching now buckets on
			// this (timestamp_utc), not the naive 'timestamp' above, so this tile needs it too
			// or it would silently fail to match any row at all. Found 2026-08-31, see
			// kernel/DATETIME.md.
			'timestamp_utc'     => $utcDayStart,
			'created'           => $pLocalDayStart,
			'last_modified'     => $pLocalDayStart,
			'no_cache'          => true,
			'display_url'       => $displayUrl,
			'cell_html'         => '<div class="calfoodday"><a href="'.htmlspecialchars( $displayUrl ).'">'.$body.'</a></div>',
		];
	}
}
