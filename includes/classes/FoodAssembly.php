<?php
/**
 * A meal instance — imported from a (start_time, meal_type) group in Samsung Health's
 * food_intake.csv. Later also recipes/favourites (not yet built — see
 * project_food_package_scoping memory).
 *
 * Stored as a pure liberty_content record (content_type_guid='foodassembly'), no
 * companion schema table. Unlike FoodComponent there's no separate classification
 * xref either — the meal type itself (BREAKFAST/LUNCH/DINNER/MSNK/ESNK) IS the
 * multi=1 xref item code that holds the ingredient list: each row is one ingredient,
 * `xref` = the FoodComponent's content_id, `xkey` = grams, `xorder` = position. The
 * meal's actual eaten time lives in liberty_content.event_time (Samsung's
 * food_intake.start_time), separate from created/last_modified (Samsung's
 * create_time/update_time, i.e. when the diary entry was recorded).
 *
 * @package food
 */
namespace Bitweaver\Food;

use Bitweaver\Liberty\LibertyContent;
use Bitweaver\Liberty\LibertyXref;

defined( 'FOODASSEMBLY_CONTENT_TYPE_GUID' ) || define( 'FOODASSEMBLY_CONTENT_TYPE_GUID', 'foodassembly' );

#[\AllowDynamicProperties]
class FoodAssembly extends LibertyContent {

	/**
	 * @param int|null $pDummy      Unused — see FoodComponent's own constructor
	 *                              docblock for why this second-slot contract exists
	 *                              (LibertyBase::getNewObject() hardcodes
	 *                              `new $class(null, $contentId)`).
	 * @param int|null $pContentId  liberty_content.content_id to load.
	 */
	public function __construct( $pDummy = null, $pContentId = null ) {
		parent::__construct();
		$this->mContentTypeGuid = FOODASSEMBLY_CONTENT_TYPE_GUID;
		$this->mContentId = (int)( $pContentId ?? $pDummy );

		$this->registerContentType(
			FOODASSEMBLY_CONTENT_TYPE_GUID, [
				'content_type_guid' => FOODASSEMBLY_CONTENT_TYPE_GUID,
				'content_name' => 'Meal',
				'content_name_plural' => 'Meals',
				'handler_class' => 'FoodAssembly',
				'handler_package' => 'food',
				'handler_file' => 'FoodAssembly.php',
				'maintainer_url' => 'https://www.bitweaver.org',
		], );

		// Permission setup
		$this->mViewContentPerm   = 'p_food_view';
		$this->mCreateContentPerm = 'p_food_create';
		$this->mUpdateContentPerm = 'p_food_update';
		$this->mAdminContentPerm  = 'p_food_admin';
		$this->mExpungeContentPerm = 'p_food_expunge';
	}

	/**
	 * @param  array $pLookupHash  Must contain 'content_id'.
	 * @return static|null         Loaded object, or null if not found.
	 */
	public static function lookup( $pLookupHash ) {
		$ret = null;
		$lookupContentId = null;
		if( !empty($pLookupHash['content_id']) && is_numeric($pLookupHash['content_id']) ) {
			$lookupContentId = (int)$pLookupHash['content_id'];
		}
		if( static::verifyId( $lookupContentId ) ) {
			$ret = static::getLibertyObject( $lookupContentId, FOODASSEMBLY_CONTENT_TYPE_GUID );
		}
		return $ret;
	}

	/**
	 * Find an existing meal instance by its event_time (the food_intake group's shared
	 * start_time) and meal-type item code — used by the food_intake importer to decide
	 * upsert vs insert. Matches on event_time + having at least one xref row of that
	 * item code, since the item code itself is the type marker (no separate
	 * classification xref exists to match against).
	 *
	 * @param  string $pMealTypeItem  BREAKFAST/LUNCH/DINNER/MSNK/ESNK
	 * @param  int    $pEventTime     Unix timestamp — the meal's start_time.
	 * @return int|null  content_id if found, else null.
	 */
	public static function lookupByEventTime( string $pMealTypeItem, int $pEventTime ): ?int {
		global $gBitDb;
		$contentId = $gBitDb->getOne(
			"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."liberty_content` lc
				WHERE lc.`content_type_guid` = '".FOODASSEMBLY_CONTENT_TYPE_GUID."' AND lc.`event_time` = ?
				AND EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = lc.`content_id` AND x.`item` = ? )",
			[ $pEventTime, $pMealTypeItem ]
		);
		return $contentId ? (int)$contentId : null;
	}

	/**
	 * Find the assembly (if any) of a given meal type on a given calendar day —
	 * unlike lookupByEventTime(), doesn't need an exact event_time match, just
	 * somewhere within the day. Used by the day view.
	 *
	 * @param  int    $pDayStart      Unix timestamp — any moment on the day to check
	 *                                (day boundary computed from it, matches
	 *                                mealTypesTakenOnDay()'s own convention).
	 * @param  string $pMealTypeItem  BREAKFAST/LUNCH/DINNER/MSNK/ESNK.
	 * @return int|null  content_id if found, else null.
	 */
	public static function lookupByDayAndType( int $pDayStart, string $pMealTypeItem ): ?int {
		global $gBitDb;
		$dayStart = strtotime( gmdate( 'Y-m-d 00:00:00', $pDayStart ) );
		$dayEnd   = strtotime( '+1 day', $dayStart );
		$contentId = $gBitDb->getOne(
			"SELECT lc.`content_id` FROM `".BIT_DB_PREFIX."liberty_content` lc
				WHERE lc.`content_type_guid` = '".FOODASSEMBLY_CONTENT_TYPE_GUID."'
					AND lc.`event_time` >= ? AND lc.`event_time` < ?
					AND EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` x WHERE x.`content_id` = lc.`content_id` AND x.`item` = ? )",
			[ $dayStart, $dayEnd, $pMealTypeItem ]
		);
		return $contentId ? (int)$contentId : null;
	}

	/**
	 * Create a new, empty assembly for a given day + meal type — used by the day
	 * view's "log a new meal" action for a slot that doesn't exist yet. Caller adds
	 * ingredients afterward via addItem()/add_assembly_item.php.
	 *
	 * event_time is stored as plain midnight on the day, no fabricated time-of-day —
	 * the meal type (the item code) already conveys roughly when in the day this is,
	 * and nothing anywhere actually reads a precise time within the day (day-range
	 * queries are how mealTypesTakenOnDay()/lookupByDayAndType() work regardless).
	 * Historical imported meals keep their real Samsung start_time — this only
	 * affects newly-created ones, where a real time was never known anyway.
	 *
	 * @return int|null  The new content_id, or null on failure (see mErrors).
	 */
	public function createForDay( int $pDayStart, string $pMealTypeItem ) {
		if( !isset( self::MEAL_TYPE_LABELS[$pMealTypeItem] ) ) {
			$this->mErrors['meal_type'] = 'Not a recognized meal type.';
			return null;
		}
		$dayStart = strtotime( gmdate( 'Y-m-d 00:00:00', $pDayStart ) );
		if( in_array( $pMealTypeItem, self::mealTypesTakenOnDay( $dayStart ), true ) ) {
			$this->mErrors['meal_type'] = self::mealTypeLabel( $pMealTypeItem ).' already exists for this day.';
			return null;
		}
		$title = self::mealTypeLabel( $pMealTypeItem ).' — '.gmdate( 'Y-m-d', $dayStart );
		$pHash = [ 'title' => $title, 'event_time' => $dayStart ];
		if( !$this->store( $pHash ) ) {
			return null;
		}
		// Stamp the type immediately with a placeholder row so getMealType() resolves
		// even before any real ingredient is added. xorder=0 (getItems()/addItem()'s
		// next-position query both only ever look at xorder>=1 rows in practice
		// since add_assembly_item.php starts new content at 1) and xref left NULL, so
		// it's excluded from getItems()' INNER JOIN to liberty_content — invisible in
		// listings, left in place permanently rather than needing explicit cleanup.
		$xref = new LibertyXref();
		$pHash = [ 'content_id' => $this->mContentId, 'item' => $pMealTypeItem, 'xorder' => 0, 'xkey' => '0' ];
		$xref->store( $pHash );
		return $this->mContentId;
	}

	/**
	 * Load assembly data into $this->mInfo from liberty_content.
	 *
	 * @return int|null  Row count (> 0) on success, or null if mContentId is not set.
	 */
	public function load() {
		if( $this->verifyId( $this->mContentId ) ) {
			$selectSql = $joinSql = $whereSql = '';
			$bindVars = [];

			$whereSql = " WHERE lc.`content_id` = ? AND lc.`content_type_guid` = '".FOODASSEMBLY_CONTENT_TYPE_GUID."'";
			$bindVars[] = $this->mContentId;

			$this->getServicesSql( 'content_load_sql_function', $selectSql, $joinSql, $whereSql, $bindVars, $this );

			$sql = "SELECT lc.* $selectSql
						, uue.`login` AS `modifier_user`, uue.`real_name` AS `modifier_real_name`
						, uuc.`login` AS `creator_user`, uuc.`real_name` AS `creator_real_name`
					FROM `".BIT_DB_PREFIX."liberty_content` lc
						LEFT JOIN `".BIT_DB_PREFIX."users_users` uue ON (uue.`user_id` = lc.`modifier_user_id`)
						LEFT JOIN `".BIT_DB_PREFIX."users_users` uuc ON (uuc.`user_id` = lc.`user_id`) $joinSql
					$whereSql";
			if( $this->mInfo = $this->mDb->getRow( $sql, $bindVars ) ) {
				$this->mContentId       = $this->mInfo['content_id'];
				$this->mContentTypeGuid = $this->mInfo['content_type_guid'];
				$this->mInfo['creator'] = $this->mInfo['creator_real_name'] ?? $this->mInfo['creator_user'];
				$this->mInfo['editor']  = $this->mInfo['modifier_real_name'] ?? $this->mInfo['modifier_user'];
				LibertyContent::load();
			}
			return count( $this->mInfo );
		}
		return null;
	}

	/**
	 * @param  array $pParamHash  Must contain 'content_id'.
	 * @return string
	 */
	public static function getDisplayUrlFromHash( &$pParamHash ) {
		global $gBitSystem;
		$ret = '';
		if( static::verifyId( $pParamHash['content_id'] ?? 0 ) ) {
			$ret = FOOD_PKG_URL;
			$ret .= $gBitSystem->isFeatureActive( 'pretty_urls' )
				? 'assembly/'.$pParamHash['content_id']
				: 'view_assembly.php?content_id='.$pParamHash['content_id'];
		}
		return $ret;
	}

	/** @return string  Display URL for this assembly. */
	public function getDisplayUrl() {
		return static::getDisplayUrlFromHash( $this->mInfo );
	}

	/**
	 * Overrides LibertyContent's default (which points at a plain 'edit.php' every
	 * package is assumed to have) — Food has more than one content type
	 * (FoodComponent/FoodAssembly), same reason FoodComponent overrides this too.
	 * Real gap this closes: liberty/edit_xref.php falls back to
	 * $gContent->getEditUrl() on every redirect (Cancel, save success, archive/
	 * remove via stepXref) — without this override those all 404'd, straight into
	 * a nonexistent food/edit.php.
	 *
	 * @return string  URL to edit_assembly.php for this meal.
	 */
	public function getEditUrl( $pContentId = null, $pMixed = null ): string {
		if( $this->verifyId( $this->mContentId ) ) {
			return FOOD_PKG_URL.'edit_assembly.php?content_id='.$this->mContentId;
		}
		return FOOD_PKG_URL.'edit_assembly.php';
	}

	/**
	 * Validate $pParamHash before storing — requires a non-empty title.
	 *
	 * @param  array $pParamHash  Data to validate; modified in place.
	 * @return bool
	 */
	protected function verifyAssemblyData( array &$pParamHash ): bool {
		$pParamHash['content_type_guid'] = FOODASSEMBLY_CONTENT_TYPE_GUID;
		if( $this->isValid() ) {
			$pParamHash['content_id'] = $this->mContentId;
			// A partial update (e.g. changeEventTime() storing just event_time) has
			// no reason to also restate the title — fall back to what's already
			// stored rather than failing validation on an existing, valid record.
			if( empty( $pParamHash['title'] ) ) {
				$pParamHash['title'] = $this->getTitle();
			}
		}
		if( empty( $pParamHash['title'] ) ) {
			$this->mErrors['title'] = 'A title is required.';
		}
		return count( $this->mErrors ) == 0;
	}

	/**
	 * Persist assembly data inside a transaction via LibertyContent::store(). Accepts
	 * 'event_time' in $pParamHash same as 'created'/'last_modified' — LibertyContent
	 * handles it directly, nothing extra needed here.
	 *
	 * @param  array $pParamHash  Data to persist; modified in place.
	 * @return bool
	 */
	public function store( array &$pParamHash ): bool {
		if( $this->verifyAssemblyData( $pParamHash ) ) {
			$this->StartTrans();
			if( LibertyContent::store( $pParamHash ) ) {
				$this->mContentId          = $pParamHash['content_id'];
				$this->mInfo['content_id'] = $this->mContentId;
				$this->CompleteTrans();
			} else {
				$this->mDb->RollbackTrans();
			}
		}
		return count( $this->mErrors ) == 0;
	}

	/**
	 * @return bool TRUE when mContentId refers to a real liberty_content row of this
	 *              content type — not just an id that looks syntactically valid.
	 */
	public function isValid() {
		if( !@$this->verifyId( $this->mContentId ) ) {
			return false;
		}
		return (bool)$this->mDb->getOne(
			"SELECT 1 FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_id` = ? AND `content_type_guid` = ?",
			[ $this->mContentId, FOODASSEMBLY_CONTENT_TYPE_GUID ]
		);
	}

	/**
	 * Add one ingredient row. Always an insert, not an upsert — callers are expected
	 * to clearItems() first when rebuilding a meal's list, so there's no existing row
	 * to find/update the way FoodComponent's nutrition xrefs do.
	 *
	 * Deliberately does NOT touch the component's REM balance itself — historical
	 * import (ImportFoodIntake.php) is the other caller of this method, backfilling
	 * meals that predate any pantry tracking at all (see MANUAL.md's FoodMovement
	 * section — "no historical movement_out for anything before a stocktake
	 * baseline"), so a REM adjustment belongs at the call site, not in here. Live
	 * callers (add_assembly_item.php, copy_assembly.php) each call
	 * FoodMovement::adjustComponentRem() themselves alongside this — see those files.
	 *
	 * @param string   $pMealTypeItem       BREAKFAST/LUNCH/DINNER/MSNK/ESNK.
	 * @param int      $pComponentId        The FoodComponent's content_id (stored in xref).
	 * @param int      $pGrams              Quantity, in the component's own base unit (xkey).
	 * @param int      $pPosition           xorder — position within the meal.
	 * @param int|null $pEntryDate          Unix timestamp — mirrors the source row's own
	 *                                      create_time rather than import-run time.
	 * @param int|null $pLastUpdateDate     Unix timestamp — mirrors update_time.
	 * @param float|null $pRemRestockAmount The actual REM delta the caller's own
	 *                                      adjustComponentRem() call applied (its
	 *                                      return value, negated back to a positive
	 *                                      restock amount) — stashed in xkey_ext so
	 *                                      removeItem()/expunge() can restock this
	 *                                      exact line later instead of blindly
	 *                                      re-adding $pGrams, which would over-credit
	 *                                      stock whenever the original decrement was
	 *                                      clamped (empty pantry, dust threshold —
	 *                                      see adjustComponentRem()). Null (the
	 *                                      default) leaves xkey_ext unset, e.g. for
	 *                                      the CSV importer's REM-blind backfill.
	 */
	public function addItem( string $pMealTypeItem, int $pComponentId, int $pGrams, int $pPosition, ?int $pEntryDate = null, ?int $pLastUpdateDate = null, ?float $pRemRestockAmount = null ): void {
		$pHash = [
			'content_id' => $this->mContentId,
			'item'       => $pMealTypeItem,
			'xref'       => $pComponentId,
			'xkey'       => (string)$pGrams,
			'xorder'     => $pPosition,
		];
		if( $pEntryDate !== null ) {
			$pHash['entry_date'] = $pEntryDate;
		}
		if( $pLastUpdateDate !== null ) {
			$pHash['last_update_date'] = $pLastUpdateDate;
		}
		if( $pRemRestockAmount !== null ) {
			$pHash['xkey_ext'] = (string)$pRemRestockAmount;
		}
		$xref = new LibertyXref();
		$xref->store( $pHash );
	}

	/**
	 * Remove one ingredient line and restock its REM contribution — companion to
	 * addItem(), needed because liberty's generic edit_xref.php delete path knows
	 * nothing about the REM side-effect (same reasoning as why add_assembly_item.php
	 * exists instead of a generic add_xref.php form). Hard-deletes the row
	 * (expunge=3), matching the trash icon's existing implied action. Restocks
	 * using the line's own stored xkey_ext (the actual amount addItem()'s caller
	 * originally removed from REM — see addItem()'s $pRemRestockAmount docblock)
	 * rather than the nominal xkey grams, so a line that was consumed against an
	 * already-empty (or dust-clamped) pantry correctly restocks little or nothing
	 * instead of over-crediting stock that was never really there. Falls back to
	 * the nominal xkey for a legacy row that predates this tracking (no xkey_ext
	 * stored) — the pre-2026-08-24 best-effort behaviour, not a regression.
	 * Caller must gate this behind expunge permission — see edit_assembly.php's
	 * remove_xref_id branch, same convention as FoodMovement::removeComponentLine().
	 *
	 * @param  int $pXrefId
	 * @return bool  FALSE if no such line exists on this assembly.
	 */
	public function removeItem( int $pXrefId ): bool {
		$row = $this->mDb->getRow(
			"SELECT `xref`, `xkey`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `xref_id` = ? AND `content_id` = ?",
			[ $pXrefId, $this->mContentId ]
		);
		if( !$row ) {
			return false;
		}
		$this->StartTrans();
		$pHash = [ 'xref_id' => $pXrefId, 'expunge' => 3 ];
		$ok = $this->stepXref( $pHash );
		if( $ok ) {
			$restock = ( $row['xkey_ext'] !== null && $row['xkey_ext'] !== '' ) ? (float)$row['xkey_ext'] : (float)$row['xkey'];
			( new FoodMovement() )->adjustComponentRem( (int)$row['xref'], $restock );
			$this->CompleteTrans();
		} else {
			$this->mDb->RollbackTrans();
		}
		return $ok;
	}

	/**
	 * Correct an existing ingredient line's quantity in place — e.g. logging a
	 * more accurate weight after the fact. Reverses the line's old REM
	 * contribution (its own stored xkey_ext restock amount, or the nominal xkey
	 * for a legacy row — same fallback removeItem() uses) then applies the new
	 * quantity as a fresh consumption, storing *its* actual-applied delta back
	 * onto the line so a later edit/removal stays accurate too. Two separate
	 * adjustComponentRem() calls rather than one net delta, deliberately — the
	 * dust threshold and floor-at-zero clamping (see that method's docblock)
	 * each need to see the real old-then-new transition, not a collapsed sum,
	 * or a large correction could clamp differently than reversing-then-
	 * reapplying actually would.
	 *
	 * @param  int   $pXrefId
	 * @param  float $pNewQuantity  Must be positive, in the component's own base
	 *                              unit (grams/ml) — same convention addItem() uses.
	 * @return bool  FALSE if no such line exists, or the quantity isn't positive.
	 */
	public function updateItem( int $pXrefId, float $pNewQuantity ): bool {
		if( $pNewQuantity <= 0 ) {
			return false;
		}
		$row = $this->mDb->getRow(
			"SELECT `item`, `xref`, `xkey`, `xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `xref_id` = ? AND `content_id` = ?",
			[ $pXrefId, $this->mContentId ]
		);
		if( !$row ) {
			return false;
		}
		$this->StartTrans();
		$movement = new FoodMovement();
		$oldRestock = ( $row['xkey_ext'] !== null && $row['xkey_ext'] !== '' ) ? (float)$row['xkey_ext'] : (float)$row['xkey'];
		$movement->adjustComponentRem( (int)$row['xref'], $oldRestock );
		$newActualDelta = $movement->adjustComponentRem( (int)$row['xref'], -$pNewQuantity );

		$xref = new LibertyXref();
		$pHash = [
			'xref_id'    => $pXrefId,
			'content_id' => $this->mContentId,
			'item'       => $row['item'],
			'xref'       => (int)$row['xref'],
			'xkey'       => (string)$pNewQuantity,
			'xkey_ext'   => (string)( -$newActualDelta ),
		];
		$ok = $xref->store( $pHash );
		if( $ok ) {
			$this->CompleteTrans();
		} else {
			$this->mDb->RollbackTrans();
		}
		return $ok;
	}

	/**
	 * Take this meal's ingredient quantities out of REM a second time — for a guest
	 * at dinner, where the same recipe is being cooked twice but only one
	 * FoodAssembly (one set of nutrition-tracked ingredient lines) needs to exist.
	 * Unlike copy_assembly.php (a genuinely separate meal, on another date), this
	 * doesn't create a second assembly or add any ingredient rows — nothing on this
	 * meal's own item list changes, so there's no xkey_ext line to stash an actual-
	 * delta on for a later reversal the way addItem()'s callers do. If the extra
	 * portion needs restocking afterward (guest cancels, food not eaten), that's a
	 * manual correction via FoodMovement, same as any other REM adjustment outside
	 * the addItem()/removeItem()/updateItem() bookkeeping.
	 */
	public function takeSecondPortion(): void {
		$this->StartTrans();
		$movement = new FoodMovement();
		foreach( $this->getItems() as $item ) {
			$movement->adjustComponentRem( (int)$item['component_content_id'], -(float)$item['quantity'] );
		}
		$this->CompleteTrans();
	}

	/**
	 * Remove every ingredient row of one meal-type item code for this assembly —
	 * used by the importer to rebuild an existing meal's item list from scratch each
	 * run rather than trying to diff it (safe: re-running with unchanged source data
	 * just reproduces the same rows).
	 */
	public function clearItems( string $pMealTypeItem ): void {
		if( $this->isValid() ) {
			LibertyContent::deleteXrefByItem( $this->mContentId, $pMealTypeItem );
		}
	}

	/** Diary meal-type item codes → display label. Recipe/menu types (not yet built) are fixed,
	 *  never switchable the way these five are — see project_food_package_scoping memory. */
	public const MEAL_TYPE_LABELS = [
		'BREAKFAST' => 'Breakfast',
		'LUNCH'     => 'Lunch',
		'DINNER'    => 'Dinner',
		'MSNK'      => 'Morning snack',
		'ESNK'      => 'Evening snack',
	];

	public static function mealTypeLabel( string $pItem ): string {
		return self::MEAL_TYPE_LABELS[$pItem] ?? $pItem;
	}

	/** Referenced FoodComponent's own quantity-type marker (foodcomponent's quantity
	 *  group) → display unit suffix, for getItems()'s quantity_unit column. */
	public const QUANTITY_UNIT_LABELS = [
		'WT'  => 'g',
		'VOL' => 'ml',
	];

	/**
	 * Which single meal-type item code this assembly actually uses — the item code
	 * itself is the type marker (see class docblock), so this is just "which of the
	 * five item codes has rows here", not a stored field.
	 */
	public function getMealType(): ?string {
		if( !$this->isValid() ) {
			return null;
		}
		$item = $this->mDb->getOne(
			"SELECT `item` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` IN ('".implode( "','", array_keys( self::MEAL_TYPE_LABELS ) )."')",
			[ $this->mContentId ]
		);
		return $item ?: null;
	}

	/**
	 * This assembly's ingredient rows (whichever single meal-type item code is
	 * populated), ordered by position, with the referenced FoodComponent's title,
	 * display URL (a real backlink to the component), and declared quantity-type unit
	 * (its own quantity group's WT/VOL marker, if any) joined in.
	 *
	 * @return array  Each row: xref_id, item, component_content_id (xref), quantity
	 *                (xkey), rem_restock_amount (xkey_ext — see addItem()'s
	 *                $pRemRestockAmount docblock; null for a legacy pre-2026-08-24
	 *                row), xorder, component_title, component_display_url,
	 *                quantity_unit ('g'/'ml'/'').
	 */
	public function getItems(): array {
		if( !$this->isValid() ) {
			return [];
		}
		$rows = $this->mDb->getAll(
			"SELECT x.`xref_id`, x.`item`, x.`xref` AS component_content_id, x.`xkey` AS quantity,
					x.`xkey_ext` AS rem_restock_amount, x.`xorder`,
					lc.`title` AS component_title,
					( SELECT FIRST 1 u.`item` FROM `".BIT_DB_PREFIX."liberty_xref` u
						WHERE u.`content_id` = x.`xref` AND u.`item` IN ('WT','VOL')
						ORDER BY CASE u.`item` WHEN 'VOL' THEN 0 ELSE 1 END ) AS unit_item
				FROM `".BIT_DB_PREFIX."liberty_xref` x
				JOIN `".BIT_DB_PREFIX."liberty_content` lc ON ( lc.`content_id` = x.`xref` )
				WHERE x.`content_id` = ? AND x.`item` IN ('".implode( "','", array_keys( self::MEAL_TYPE_LABELS ) )."')
				  AND x.`end_date` IS NULL
				ORDER BY x.`xorder`",
			[ $this->mContentId ]
		);
		foreach( $rows as &$row ) {
			$row['quantity_unit'] = self::QUANTITY_UNIT_LABELS[$row['unit_item'] ?? ''] ?? '';
			$urlHash = [ 'content_id' => $row['component_content_id'] ];
			$row['component_display_url'] = FoodComponent::getDisplayUrlFromHash( $urlHash );
		}
		return $rows;
	}

	/**
	 * getItems() plus per-item and meal-total nutrition, in
	 * FoodComponent::NUTRITION_SUMMARY_FIELDS shape — shared by view_day.php (one
	 * call per meal slot) and view_assembly.php (one call for the whole page) so the
	 * scale/sum logic lives in exactly one place.
	 *
	 * @return array{items: array, total: array, totalRaw: array}  'items' = getItems()'s
	 *         rows, each gaining a 'nutrition' key (formatted, per
	 *         FoodComponent::formatNutrition()); 'total' = the same shape, summed across
	 *         every item; 'totalRaw' = the unformatted sum, for a caller that needs to
	 *         combine several assemblies' totals together (e.g. view_day.php summing
	 *         meals into a day total) before formatting — formatted strings like "1.5g"
	 *         can't themselves be summed.
	 */
	public function getItemsWithNutrition(): array {
		$items = $this->getItems();
		$componentIds = array_unique( array_map( fn( $i ) => (int)$i['component_content_id'], $items ) );
		$nutritionByComponent = FoodComponent::getNutritionBatch( $componentIds );

		$totalRaw = array_fill_keys( array_keys( FoodComponent::NUTRITION_SUMMARY_FIELDS ), 0.0 );
		foreach( $items as &$item ) {
			$raw = FoodComponent::scaleNutrition(
				$nutritionByComponent[(int)$item['component_content_id']] ?? [],
				(float)$item['quantity']
			);
			$item['nutrition'] = FoodComponent::formatNutrition( $raw );
			$totalRaw = FoodComponent::sumNutrition( $totalRaw, $raw );
		}
		unset( $item );

		return [ 'items' => $items, 'total' => FoodComponent::formatNutrition( $totalRaw ), 'totalRaw' => $totalRaw ];
	}

	/**
	 * Calendar day-grid cell content for one meal — see LibertyContent::getContentList()'s
	 * "Optional per-content-type override" comment for the hook this implements (same one
	 * HealthDay::getDayCellHtml() uses). Step 1 only, calendar/day view: one meal per cell,
	 * "MealType: item, item, item" then a totals line (kcal/fibre/5AD) — the consolidated
	 * one-tile-per-day version for month/week views (see project_food_package_scoping
	 * memory) is a separate, later step, deliberately not this method's job.
	 *
	 * @param  array $pHash  Row hash from getContentList() — content_id and (already
	 *                       computed by the caller) display_url are what's used here.
	 * @return string  Empty if this meal has no type or no items (shouldn't happen for a
	 *                 real assembly, but matches HealthDay's own empty-tile convention).
	 */
	public static function getDayCellHtml( array $pHash ): string {
		$assembly = new self( null, (int)( $pHash['content_id'] ?? 0 ) );
		$mealType = $assembly->getMealType();
		if( !$mealType ) {
			return '';
		}
		$data = $assembly->getItemsWithNutrition();
		if( !$data['items'] ) {
			return '';
		}
		$itemList = implode( ', ', array_map( fn( $i ) => $i['component_title'], $data['items'] ) );
		$totals = $data['total'];
		$lines = [
			self::mealTypeLabel( $mealType ).': '.$itemList,
			$totals['CAL'].' · '.$totals['FIBR'].' fibre · '.$totals['5AD'].' 5AD',
		];
		$body = implode( '<br/>', array_map( 'htmlspecialchars', $lines ) );
		$url  = htmlspecialchars( $pHash['display_url'] ?? '#' );
		return "<div class=\"calfoodassembly\"><a href=\"$url\">$body</a></div>";
	}

	/**
	 * Meal-type item codes already used by some *other* FoodAssembly on the same
	 * calendar day as $pEventTime — the day-uniqueness check ("a day should only see
	 * one of each intake type", flagged 2026-08-16, no generic bitweaver hook for
	 * this so it's enforced here by hand).
	 *
	 * @param  int      $pEventTime          Unix timestamp — any moment on the day to check.
	 * @param  int|null $pExcludeContentId   This assembly's own content_id, so it doesn't
	 *                                       count against itself.
	 * @return string[]  Item codes already taken that day.
	 */
	public static function mealTypesTakenOnDay( int $pEventTime, ?int $pExcludeContentId = null ): array {
		global $gBitDb;
		$dayStart = strtotime( gmdate( 'Y-m-d 00:00:00', $pEventTime ) );
		$dayEnd   = strtotime( '+1 day', $dayStart );
		$sql = "SELECT DISTINCT x.`item` FROM `".BIT_DB_PREFIX."liberty_xref` x
					JOIN `".BIT_DB_PREFIX."liberty_content` lc ON ( lc.`content_id` = x.`content_id` )
					WHERE lc.`content_type_guid` = '".FOODASSEMBLY_CONTENT_TYPE_GUID."'
						AND lc.`event_time` >= ? AND lc.`event_time` < ?
						AND x.`item` IN ('".implode( "','", array_keys( self::MEAL_TYPE_LABELS ) )."')";
		$bindVars = [ $dayStart, $dayEnd ];
		if( $pExcludeContentId ) {
			$sql .= " AND x.`content_id` != ?";
			$bindVars[] = $pExcludeContentId;
		}
		return $gBitDb->getCol( $sql, $bindVars );
	}

	/**
	 * Meal-type item codes available to switch this assembly to — every registered
	 * type except ones already taken that day by a different assembly. Always
	 * includes the current type itself (picking "no change" is always valid).
	 *
	 * @return array  item code => label, same shape as MEAL_TYPE_LABELS.
	 */
	public function getAvailableMealTypes(): array {
		$current = $this->getMealType();
		$taken   = $this->isValid() ? self::mealTypesTakenOnDay( $this->getField( 'event_time' ), $this->mContentId ) : [];
		$ret = [];
		foreach( self::MEAL_TYPE_LABELS as $item => $label ) {
			if( $item === $current || !in_array( $item, $taken, true ) ) {
				$ret[$item] = $label;
			}
		}
		return $ret;
	}

	/**
	 * Reclassify this assembly to a different meal type — bulk-renames every
	 * ingredient row's item from the current type to $pNewType. No-op if $pNewType
	 * is already the current type; fails (returns false, error in mErrors) if that
	 * type is already taken by a different assembly the same day.
	 */
	public function changeMealType( string $pNewType ): bool {
		if( !isset( self::MEAL_TYPE_LABELS[$pNewType] ) ) {
			$this->mErrors['meal_type'] = 'Not a recognized meal type.';
			return false;
		}
		$current = $this->getMealType();
		if( $current === $pNewType ) {
			return true;
		}
		$taken = self::mealTypesTakenOnDay( $this->getField( 'event_time' ), $this->mContentId );
		if( in_array( $pNewType, $taken, true ) ) {
			$this->mErrors['meal_type'] = self::mealTypeLabel( $pNewType ).' already exists for this day.';
			return false;
		}
		// Per-row via LibertyXref::store(), not a bulk UPDATE — a raw UPDATE skips
		// last_update_date entirely (verify() only stamps it through the normal
		// insert/update path), losing any record of when this actually changed.
		foreach( $this->getItems() as $row ) {
			$xref = new LibertyXref();
			$pHash = [ 'xref_id' => $row['xref_id'], 'content_id' => $this->mContentId, 'item' => $pNewType ];
			$xref->store( $pHash );
		}
		return true;
	}

	/**
	 * Change this assembly's event_time (the meal's actual eaten time) — validates
	 * the day-uniqueness constraint against the *new* day (this meal's own type
	 * mustn't already be taken there by a different assembly), same rule
	 * changeMealType() enforces for a type change instead of a time change. A no-op
	 * shift within the same day never conflicts with itself (excluded from the check).
	 *
	 * @param  int $pNewEventTime  Unix timestamp (UTC), same convention as every
	 *                             other event_time value in this class.
	 */
	public function changeEventTime( int $pNewEventTime ): bool {
		$mealType = $this->getMealType();
		if( $mealType ) {
			$taken = self::mealTypesTakenOnDay( $pNewEventTime, $this->mContentId );
			if( in_array( $mealType, $taken, true ) ) {
				$this->mErrors['event_time'] = self::mealTypeLabel( $mealType ).' already exists for this day.';
				return false;
			}
		}
		$pHash = [ 'event_time' => $pNewEventTime ];
		return $this->store( $pHash );
	}

	/**
	 * Delete this meal — restocks every ingredient's REM balance first (same
	 * reasoning as FoodMovement::expunge() reversing receipt lines before its own
	 * raw DELETE: once the xref rows are gone there's nothing left to compute the
	 * reversal from), then hard-deletes the ingredient rows and the content itself.
	 * The counterpart to add_assembly_item.php/copy_assembly.php's REM decrement —
	 * a meal being deleted gives its ingredients back to the pantry the same way a
	 * receipt reversal or movement-line delete does.
	 */
	public function expunge(): bool {
		if( $this->isValid() ) {
			$this->StartTrans();
			$movement = new FoodMovement();
			foreach( $this->getItems() as $item ) {
				// See removeItem()'s docblock — restock the actual amount this line
				// took from REM (rem_restock_amount), not the nominal quantity, or a
				// meal consumed against an empty/dust-clamped pantry over-credits
				// stock that was never really there. Legacy rows without it fall
				// back to the nominal quantity (pre-2026-08-24 best effort).
				$restock = ( $item['rem_restock_amount'] !== null && $item['rem_restock_amount'] !== '' )
					? (float)$item['rem_restock_amount']
					: (float)$item['quantity'];
				$movement->adjustComponentRem( (int)$item['component_content_id'], $restock );
			}
			$this->mDb->getOne( "DELETE FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ?", [ $this->mContentId ] );
			if( LibertyContent::expunge() ) {
				$this->CompleteTrans();
				$this->mContentId = null;
			} else {
				$this->mDb->RollbackTrans();
			}
		}
		return true;
	}
}
