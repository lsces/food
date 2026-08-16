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
	 * Validate $pParamHash before storing — requires a non-empty title.
	 *
	 * @param  array $pParamHash  Data to validate; modified in place.
	 * @return bool
	 */
	protected function verifyAssemblyData( array &$pParamHash ): bool {
		$pParamHash['content_type_guid'] = FOODASSEMBLY_CONTENT_TYPE_GUID;
		if( $this->isValid() ) {
			$pParamHash['content_id'] = $this->mContentId;
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
	 * @param string   $pMealTypeItem     BREAKFAST/LUNCH/DINNER/MSNK/ESNK.
	 * @param int      $pComponentId      The FoodComponent's content_id (stored in xref).
	 * @param int      $pGrams            Quantity, in the component's own base unit (xkey).
	 * @param int      $pPosition         xorder — position within the meal.
	 * @param int|null $pEntryDate        Unix timestamp — mirrors the source row's own
	 *                                    create_time rather than import-run time.
	 * @param int|null $pLastUpdateDate   Unix timestamp — mirrors update_time.
	 */
	public function addItem( string $pMealTypeItem, int $pComponentId, int $pGrams, int $pPosition, ?int $pEntryDate = null, ?int $pLastUpdateDate = null ): void {
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
		$xref = new LibertyXref();
		$xref->store( $pHash );
	}

	/**
	 * Remove every ingredient row of one meal-type item code for this assembly —
	 * used by the importer to rebuild an existing meal's item list from scratch each
	 * run rather than trying to diff it (safe: re-running with unchanged source data
	 * just reproduces the same rows).
	 */
	public function clearItems( string $pMealTypeItem ): void {
		if( $this->isValid() ) {
			$this->mDb->getOne(
				"DELETE FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = ?",
				[ $this->mContentId, $pMealTypeItem ]
			);
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
	 * populated), ordered by position, with the referenced FoodComponent's title
	 * joined in.
	 *
	 * @return array  Each row: xref_id, item, component_content_id (xref), quantity
	 *                (xkey), xorder, component_title.
	 */
	public function getItems(): array {
		if( !$this->isValid() ) {
			return [];
		}
		return $this->mDb->getAll(
			"SELECT x.`xref_id`, x.`item`, x.`xref` AS component_content_id, x.`xkey` AS quantity, x.`xorder`,
					lc.`title` AS component_title
				FROM `".BIT_DB_PREFIX."liberty_xref` x
				JOIN `".BIT_DB_PREFIX."liberty_content` lc ON ( lc.`content_id` = x.`xref` )
				WHERE x.`content_id` = ? AND x.`item` IN ('".implode( "','", array_keys( self::MEAL_TYPE_LABELS ) )."')
				ORDER BY x.`xorder`",
			[ $this->mContentId ]
		);
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
			$xref->store( [ 'xref_id' => $row['xref_id'], 'content_id' => $this->mContentId, 'item' => $pNewType ] );
		}
		return true;
	}

	public function expunge(): bool {
		if( $this->isValid() ) {
			$this->StartTrans();
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
