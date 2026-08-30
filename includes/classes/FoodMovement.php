<?php
/**
 * A pantry receipt — inbound stock added to the FoodComponent pantry ledger.
 *
 * Stored as a pure liberty_content record (content_type_guid='foodmovement'), no
 * companion schema table (see Claude memory feedback_content_id_only). The shop/
 * date/note for the receipt lives in the 'reference' xref group (item RECEIPT);
 * each purchased component is a 'quantity' group line (item SGL/WT/VOL, matching
 * the component's own declared quantity type — see FoodComponent's quantity
 * group). Adding or removing a line also adjusts the referenced FoodComponent's
 * own REM balance — Food's REM is a stored/mutable value, not derived by summing
 * movements the way Stock's list_stock.php aggregates (see food/CLAUDE.md), so
 * this class is the one place that keeps the two in sync. The outbound half
 * (meals consuming ingredients) is built piecemeal in FoodAssembly rather than
 * as one explodeFromAssembly()-style method — see that class — but every write
 * to REM anywhere in the package still goes through adjustComponentRem() below.
 *
 * @package food
 */
namespace Bitweaver\Food;

use Bitweaver\Liberty\LibertyContent;

defined( 'FOODMOVEMENT_CONTENT_TYPE_GUID' ) || define( 'FOODMOVEMENT_CONTENT_TYPE_GUID', 'foodmovement' );

#[\AllowDynamicProperties]
class FoodMovement extends LibertyContent {

	/** Quantity-line item codes — mirrors foodcomponent's own quantity group markers
	 *  (see admin/schema_inc.php), since a movement line's unit must match whichever
	 *  single type the referenced component declares. */
	public const QUANTITY_ITEMS = [ 'SGL', 'WT', 'VOL' ];

	/**
	 * @param int|null $pDummy      Unused — see FoodComponent's own constructor
	 *                              docblock for why this second-slot contract exists
	 *                              (LibertyBase::getNewObject() hardcodes
	 *                              `new $class(null, $contentId)`).
	 * @param int|null $pContentId  liberty_content.content_id to load.
	 */
	public function __construct( $pDummy = null, $pContentId = null ) {
		parent::__construct();
		$this->mContentTypeGuid = FOODMOVEMENT_CONTENT_TYPE_GUID;
		$this->mContentId = (int)( $pContentId ?? $pDummy );

		$this->registerContentType(
			FOODMOVEMENT_CONTENT_TYPE_GUID, [
				'content_type_guid' => FOODMOVEMENT_CONTENT_TYPE_GUID,
				'content_name' => 'Movement',
				'content_name_plural' => 'Movements',
				'handler_class' => 'FoodMovement',
				'handler_package' => 'food',
				'handler_file' => 'FoodMovement.php',
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
			$ret = static::getLibertyObject( $lookupContentId, FOODMOVEMENT_CONTENT_TYPE_GUID );
		}
		return $ret;
	}

	/**
	 * Load movement data into $this->mInfo, including the RECEIPT reference xref's
	 * scalars (ref_contact_id/ref_contact_name/ref_key/ref_start_date/ref_note) —
	 * same shape as StockMovement::load(), trimmed to Food's single reference item.
	 *
	 * @return int|null  Row count (> 0) on success, or null if mContentId is not set.
	 */
	public function load() {
		if( $this->verifyId( $this->mContentId ) ) {
			$selectSql = $joinSql = $whereSql = '';
			$bindVars = [];

			$whereSql = " WHERE lc.`content_id` = ? AND lc.`content_type_guid` = '".FOODMOVEMENT_CONTENT_TYPE_GUID."'";
			$bindVars[] = $this->mContentId;

			$this->getServicesSql( 'content_load_sql_function', $selectSql, $joinSql, $whereSql, $bindVars, $this );

			$sql = "SELECT lc.* $selectSql
						, uue.`login` AS `modifier_user`, uue.`real_name` AS `modifier_real_name`
						, uuc.`login` AS `creator_user`, uuc.`real_name` AS `creator_real_name`
						, (SELECT FIRST 1 x.`xref` FROM `".BIT_DB_PREFIX."liberty_xref` x
						   WHERE x.`content_id` = lc.`content_id` AND x.`item` = 'RECEIPT') AS ref_contact_id
						, (SELECT FIRST 1 lc2.`title` FROM `".BIT_DB_PREFIX."liberty_xref` x
						   JOIN `".BIT_DB_PREFIX."liberty_content` lc2 ON lc2.`content_id` = x.`xref`
						   WHERE x.`content_id` = lc.`content_id` AND x.`item` = 'RECEIPT') AS ref_contact_name
						, (SELECT FIRST 1 x.`xkey` FROM `".BIT_DB_PREFIX."liberty_xref` x
						   WHERE x.`content_id` = lc.`content_id` AND x.`item` = 'RECEIPT') AS ref_key
						, (SELECT FIRST 1 x.`start_date` FROM `".BIT_DB_PREFIX."liberty_xref` x
						   WHERE x.`content_id` = lc.`content_id` AND x.`item` = 'RECEIPT') AS ref_start_date
						, (SELECT FIRST 1 x.`data` FROM `".BIT_DB_PREFIX."liberty_xref` x
						   WHERE x.`content_id` = lc.`content_id` AND x.`item` = 'RECEIPT') AS ref_note
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
	protected function verifyMovementData( array &$pParamHash ): bool {
		$pParamHash['content_type_guid'] = FOODMOVEMENT_CONTENT_TYPE_GUID;
		if( $this->isValid() ) {
			$pParamHash['content_id'] = $this->mContentId;
		}
		if( empty( $pParamHash['title'] ) ) {
			$this->mErrors['title'] = 'A title is required.';
		}
		return count( $this->mErrors ) == 0;
	}

	/**
	 * Persist movement data inside a transaction via LibertyContent::store().
	 *
	 * @param  array $pParamHash  Data to persist; modified in place.
	 * @return bool
	 */
	public function store( array &$pParamHash ): bool {
		if( $this->verifyMovementData( $pParamHash ) ) {
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
			[ $this->mContentId, FOODMOVEMENT_CONTENT_TYPE_GUID ]
		);
	}

	/**
	 * Delete this movement — reverses every line's REM contribution first, since
	 * once LibertyContent::expunge() removes the xref rows there'd be nothing left
	 * to compute the reversal from.
	 *
	 * @return bool Always TRUE (errors recorded in $this->mErrors).
	 */
	public function expunge(): bool {
		if( $this->isValid() ) {
			$this->StartTrans();
			foreach( $this->getLines() as $line ) {
				$delta = $this->resolveRemDelta( $line['item'], (float)$line['quantity'], (int)$line['component_content_id'] );
				$this->adjustComponentRem( (int)$line['component_content_id'], -$delta );
			}
			if( LibertyContent::expunge() ) {
				$this->CompleteTrans();
				$this->mContentId = null;
			} else {
				$this->mDb->RollbackTrans();
			}
		}
		return true;
	}

	/**
	 * Set/update the RECEIPT reference — which shop, an optional receipt/order
	 * reference, purchase date, and a free-text note. Upserts the single reference
	 * row (there's only ever one per movement, unlike Stock's REQN/TRANS/ORDER
	 * type choice, so no type-selection logic is needed here).
	 *
	 * @param  int|null $pShopContentId  Contact content_id, or null to leave unset.
	 * @param  string   $pRefKey         Free-text receipt/order reference.
	 * @param  int|null $pPurchaseDate   Unix timestamp, or null to leave unset.
	 * @param  string   $pNote           Free-text note.
	 * @return bool
	 */
	public function setReceiptReference( ?int $pShopContentId, string $pRefKey, ?int $pPurchaseDate, string $pNote ): bool {
		if( !$this->isValid() ) {
			return false;
		}
		$values = [
			'xkey' => $pRefKey,
			'edit' => $pNote,
		];
		if( $pShopContentId ) {
			$values['xref'] = $pShopContentId;
		}
		if( $pPurchaseDate !== null ) {
			$values['start_date'] = $pPurchaseDate;
		}
		return $this->upsertXref( $this->mContentId, 'RECEIPT', $values );
	}

	/**
	 * Add one purchased component to this receipt — inserts the quantity line AND
	 * increments the referenced FoodComponent's own REM balance in the same
	 * transaction, which is the whole point of this class over a generic xref add
	 * (see class docblock — REM is stored/mutable, nothing recomputes it from
	 * movement history the way Stock's list_stock.php does).
	 *
	 * Per-line entry mode (2026-08-22 SGL/WT/VOL redesign — see Claude memory
	 * project_food_package_scoping's "SGL/WT/VOL pantry-display redesign" entry):
	 * a component now always has a real WT-or-VOL weight/volume declared, and
	 * *separately* may be flagged SGL (a display switch, not a competing type —
	 * see admin/schema_inc.php). Two entry modes follow from that:
	 * - 'base' (default): $pQuantity IS the real weight/volume, stored under the
	 *   component's own WT/VOL item code, added straight to REM — no conversion,
	 *   no drift risk (e.g. Strawberries: type the real 400g).
	 * - 'sgl': $pQuantity is a count (a multi-pack), stored under item='SGL',
	 *   converted through the component's *current* declared WT/VOL value before
	 *   touching REM (e.g. "8" ice cream sandwiches × Gelatelli's WT=52 → +416g).
	 *   Only valid if the component is actually SGL-flagged and has a real WT/VOL
	 *   value to convert through.
	 *
	 * @param  int    $pComponentContentId
	 * @param  float  $pQuantity  In the chosen mode's own unit (see above).
	 * @param  string $pMode      'base' or 'sgl'.
	 * @return bool  FALSE if the movement/component id is invalid, the quantity
	 *               isn't positive, or the mode can't be resolved against the
	 *               component's current quantity data (see $this->mErrors).
	 */
	public function addComponentLine( int $pComponentContentId, float $pQuantity, string $pMode = 'base' ): bool {
		if( !$this->isValid() || !$this->verifyId( $pComponentContentId ) || $pQuantity <= 0 ) {
			return false;
		}
		$baseRow = LibertyContent::lookupXrefByItem( $pComponentContentId, [ 'WT', 'VOL' ], 'foodcomponent' );

		if( $pMode === 'sgl' ) {
			$hasSgl = LibertyContent::lookupXrefByItem( $pComponentContentId, 'SGL', 'foodcomponent' ) !== null;
			if( !$hasSgl ) {
				$this->mErrors['component'] = 'This component is not flagged for count-based (SGL) tracking.';
				return false;
			}
			if( !$baseRow || !is_numeric( $baseRow['xkey'] ?? null ) ) {
				$this->mErrors['component'] = 'This component has no declared weight/volume (WT/VOL) to convert a count through.';
				return false;
			}
			$qtyItem  = 'SGL';
			$remDelta = $pQuantity * (float)$baseRow['xkey'];
		} else {
			if( !$baseRow ) {
				$this->mErrors['component'] = 'This component has no declared quantity type (WT/VOL) — set one on the component first.';
				return false;
			}
			$qtyItem  = $baseRow['item'];
			$remDelta = $pQuantity;
		}

		$nextXorder = (int)$this->mDb->getOne(
			"SELECT COALESCE( MAX(x.`xorder`) + 1, 1 ) FROM `".BIT_DB_PREFIX."liberty_xref` x
			 WHERE x.`content_id` = ? AND x.`item` IN ('".implode( "','", self::QUANTITY_ITEMS )."')",
			[ $this->mContentId ]
		) ?: 1;

		$this->StartTrans();
		$lineHash = [
			'content_id' => $this->mContentId,
			'item'       => $qtyItem,
			'xref'       => $pComponentContentId,
			'xkey'       => (string)$pQuantity,
			'xorder'     => $nextXorder,
		];
		$ok = $this->storeXref( $lineHash );
		if( $ok ) {
			$this->adjustComponentRem( $pComponentContentId, $remDelta );
			$this->CompleteTrans();
		} else {
			$this->mDb->RollbackTrans();
		}
		return $ok;
	}

	/**
	 * The REM delta a quantity-line's own stored xkey represents, resolving SGL
	 * (count) lines through the component's *current* declared WT/VOL value —
	 * shared by removeComponentLine()/expunge() so both reverse a receipt exactly
	 * the way addComponentLine() would compute it fresh today (see the class
	 * docblock's note on why reversal is a recompute, not a frozen snapshot).
	 *
	 * @param  string $pItem                'SGL', 'WT', or 'VOL'.
	 * @param  float  $pXkey                The line's own stored quantity.
	 * @param  int    $pComponentContentId
	 * @return float
	 */
	private function resolveRemDelta( string $pItem, float $pXkey, int $pComponentContentId ): float {
		if( $pItem !== 'SGL' ) {
			return $pXkey;
		}
		$baseRow = LibertyContent::lookupXrefByItem( $pComponentContentId, [ 'WT', 'VOL' ], 'foodcomponent' );
		return $pXkey * (float)( $baseRow['xkey'] ?? 0 );
	}

	/**
	 * Remove one purchased-component line and reverse its REM contribution —
	 * companion to addComponentLine(), needed because liberty's generic
	 * edit_xref.php delete path knows nothing about the REM side-effect (same
	 * reasoning as why addComponentLine() exists instead of a generic add_xref.php
	 * form for this group). Hard-deletes the row via stepXref() (expunge=3), not
	 * an archive — a mis-entered receipt line (wrong quantity type, duplicate,
	 * typo) should actually go away, not linger in the item's History tab forever
	 * (switched from expunge=1 archive 2026-08-22 after the archive behaviour
	 * silently left the line showing in edit_movement.php's own line list — see
	 * getLines()'s end_date filter, added same day as a second, independent fix).
	 * Caller must gate this behind expunge permission, not just update — see
	 * edit_movement.php's remove_xref_id branch.
	 *
	 * @param  int $pXrefId
	 * @return bool  FALSE if no such line exists on this movement.
	 */
	public function removeComponentLine( int $pXrefId ): bool {
		$row = $this->getOwnedXrefRow( $pXrefId );
		if( !$row ) {
			return false;
		}
		$this->StartTrans();
		$pHash = [ 'xref_id' => $pXrefId, 'expunge' => 3 ];
		$ok = $this->stepXref( $pHash );
		if( $ok ) {
			$delta = $this->resolveRemDelta( $row['item'], (float)$row['xkey'], (int)$row['xref'] );
			$this->adjustComponentRem( (int)$row['xref'], -$delta );
			$this->CompleteTrans();
		} else {
			$this->mDb->RollbackTrans();
		}
		return $ok;
	}

	/**
	 * Change an existing line's quantity — e.g. correcting a mis-typed receipt
	 * value after the fact — without touching which component/mode (SGL/WT/VOL)
	 * the line is stored under. Reverses the line's old REM contribution and
	 * applies the new one via the same resolveRemDelta() an SGL-mode line would
	 * need converting through, so this stays correct whether the line is a direct
	 * weight/volume or a converted count.
	 *
	 * @param  int   $pXrefId
	 * @param  float $pNewQuantity  Must be positive, in the line's own existing unit.
	 * @return bool  FALSE if no such line exists, or the quantity isn't positive.
	 */
	public function updateComponentLine( int $pXrefId, float $pNewQuantity ): bool {
		if( $pNewQuantity <= 0 ) {
			return false;
		}
		$row = $this->getOwnedXrefRow( $pXrefId );
		if( !$row ) {
			return false;
		}
		$this->StartTrans();
		$pHash = [
			'xref_id'    => $pXrefId,
			'content_id' => $this->mContentId,
			'item'       => $row['item'],
			'xref'       => (int)$row['xref'],
			'xkey'       => (string)$pNewQuantity,
		];
		$ok = $this->storeXref( $pHash );
		if( $ok ) {
			$oldDelta = $this->resolveRemDelta( $row['item'], (float)$row['xkey'], (int)$row['xref'] );
			$newDelta = $this->resolveRemDelta( $row['item'], $pNewQuantity, (int)$row['xref'] );
			$this->adjustComponentRem( (int)$row['xref'], $newDelta - $oldDelta );
			$this->CompleteTrans();
		} else {
			$this->mDb->RollbackTrans();
		}
		return $ok;
	}

	/** Consumption (negative-delta) results landing below this fraction of the
	 *  component's own declared WT/VOL portion size get zeroed rather than left
	 *  as an untrackable dust remainder — a shrinking pack rarely gets weighed
	 *  out to the exact last gram, so a REM of "12g" lingering forever is noise,
	 *  not a real usable amount (the author, 2026-08-24). Applies to any negative
	 *  delta through this method, receipt reversals included, not just meal
	 *  consumption — the same "too small to be real" reasoning holds either way.
	 *  A component with no declared WT/VOL falls back to a plain floor-at-zero
	 *  (dust threshold 0). */
	public const DUST_THRESHOLD_RATIO = 0.25;

	/**
	 * Add (or subtract, for a negative delta) $pDelta to a FoodComponent's REM
	 * xref, in place — the one spot that actually writes to the pantry balance,
	 * called from both the movement side (receipts) and FoodAssembly's ingredient
	 * add/copy/remove/delete paths (meals consuming ingredients — negative delta,
	 * same shared write-path so both stay consistent). Creates a REM row at
	 * $pDelta if the component doesn't have one yet (e.g. first-ever receipt for
	 * a component the importer never flagged). Only $pDelta and
	 * $pComponentContentId's own current xkey are touched — any existing
	 * xkey_ext (e.g. a still-outstanding REVIEW flag) is left alone, since
	 * associateUpdate() only writes the columns present in the hash passed to
	 * LibertyXref::store().
	 *
	 * Returns the delta actually applied (new − old), which can differ from
	 * $pDelta itself once clamping (floor-at-zero or the dust threshold below)
	 * kicks in — callers that need to reverse this exact change later (see
	 * FoodAssembly::addItem()'s $pRemRestockAmount / removeItem() / expunge())
	 * must store and reuse this returned value rather than re-deriving it from
	 * the nominal quantity, or a reversal can over-credit stock that was never
	 * actually there to begin with.
	 *
	 * @param  int   $pComponentContentId
	 * @param  float $pDelta
	 * @return float  The actual change in REM (new value − old value).
	 */
	public function adjustComponentRem( int $pComponentContentId, float $pDelta ): float {
		$existing = LibertyContent::lookupXrefByItem( $pComponentContentId, 'REM', 'foodcomponent' );
		$current = $existing ? (float)$existing['xkey'] : 0.0;
		$raw = $current + $pDelta;
		if( $pDelta < 0 ) {
			$baseRow = LibertyContent::lookupXrefByItem( $pComponentContentId, [ 'WT', 'VOL' ], 'foodcomponent' );
			$portionSize = ( $baseRow && $baseRow['xkey'] !== null && $baseRow['xkey'] !== '' ) ? (float)$baseRow['xkey'] : 0.0;
			$dustThreshold = $portionSize ? self::DUST_THRESHOLD_RATIO * $portionSize : 0.0;
			$new = $raw < $dustThreshold ? 0.0 : $raw;
		} else {
			// Stock can't go negative, but the dust threshold above only ever
			// applies in the consumption direction — a receipt always keeps
			// exactly what it adds.
			$new = max( 0.0, $raw );
		}
		LibertyContent::upsertXrefByContentId( $pComponentContentId, 'REM', [ 'xkey' => (string)$new ] );
		return $new - $current;
	}

	/**
	 * This receipt's purchased-component lines, with each line's component title/
	 * display URL and unit suffix joined in — same shape as FoodAssembly::getItems().
	 *
	 * @return array  Each row: xref_id, item, component_content_id (xref), quantity
	 *                (xkey), xorder, component_title, component_display_url,
	 *                quantity_unit ('g'/'ml'/' '.note/' x').
	 */
	public function getLines(): array {
		if( !$this->isValid() ) {
			return [];
		}
		$rows = $this->mDb->getAll(
			"SELECT x.`xref_id`, x.`item`, x.`xref` AS component_content_id, x.`xkey` AS quantity, x.`xorder`,
					lc.`title` AS component_title,
					(SELECT FIRST 1 s.`xkey_ext` FROM `".BIT_DB_PREFIX."liberty_xref` s
					 WHERE s.`content_id` = x.`xref` AND s.`item` = 'SGL') AS sgl_note
				FROM `".BIT_DB_PREFIX."liberty_xref` x
				JOIN `".BIT_DB_PREFIX."liberty_content` lc ON ( lc.`content_id` = x.`xref` )
				WHERE x.`content_id` = ? AND x.`item` IN ('".implode( "','", self::QUANTITY_ITEMS )."')
				  AND x.`end_date` IS NULL
				ORDER BY x.`xorder`",
			[ $this->mContentId ]
		);
		foreach( $rows as &$row ) {
			// SGL lines are labelled with the component's own note (e.g. "Ready Meal")
			// rather than a bare 'x' — same reuse of xkey_ext as list_pantry.php's Note
			// column and edit_movement.tpl's qty_mode picker (2026-08-22).
			$row['quantity_unit'] = match( $row['item'] ) {
				'WT' => 'g', 'VOL' => 'ml',
				'SGL' => ' '.( $row['sgl_note'] ?: 'x' ),
				default => '',
			};
			$urlHash = [ 'content_id' => $row['component_content_id'] ];
			$row['component_display_url'] = FoodComponent::getDisplayUrlFromHash( $urlHash );
		}
		return $rows;
	}

	/**
	 * Return a paged, keyed list of movements — mirrors StockMovement::getList(),
	 * trimmed to Food's simpler single-reference-type shape (no assembly/BOM
	 * kit-count join — Food has no BOM-shaped movements).
	 *
	 * Recognised filter keys: component_content_id, find, sort_mode.
	 * Sets $pListHash['cant'] on return.
	 *
	 * @param  array $pListHash  Filter and pagination params; modified in place.
	 * @return array             content_id-keyed result rows.
	 */
	public function getList( array &$pListHash ): array {
		LibertyContent::prepGetList( $pListHash );
		$ret = $bindVars = [];
		$selectSql = $whereSql = $joinSql = '';

		$whereSql = " AND lc.`content_type_guid` = '".FOODMOVEMENT_CONTENT_TYPE_GUID."'";

		if( $this->verifyId( $pListHash['component_content_id'] ?? 0 ) ) {
			$partId = (int)$pListHash['component_content_id'];
			$whereSql .= " AND EXISTS (SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` xcf
				WHERE xcf.`content_id` = lc.`content_id` AND xcf.`item` IN ('".implode( "','", self::QUANTITY_ITEMS )."') AND xcf.`xref` = $partId)";
		}
		if( !empty( $pListHash['find'] ) ) {
			$whereSql .= " AND UPPER(lc.`title`) LIKE ?";
			$bindVars[] = '%'.strtoupper( $pListHash['find'] ).'%';
		}

		$this->getServicesSql( 'content_list_sql_function', $selectSql, $joinSql, $whereSql, $bindVars );

		$sortMode = $pListHash['sort_mode'] ?? '';
		$orderby = !empty( $sortMode )
			? ' ORDER BY '.$this->mDb->convertSortmode( $sortMode )
			: ' ORDER BY lc.`last_modified` DESC';

		if( !empty( $whereSql ) ) {
			$whereSql = substr_replace( $whereSql, ' WHERE ', 0, 4 );
		}

		$pListHash['cant'] = (int)$this->mDb->getOne(
			"SELECT COUNT(DISTINCT lc.`content_id`)
			 FROM `".BIT_DB_PREFIX."liberty_content` lc
				INNER JOIN `".BIT_DB_PREFIX."users_users` uu ON uu.`user_id` = lc.`user_id`
				$joinSql
			 $whereSql",
			$bindVars
		);

		$query = "SELECT lc.`content_id`, lc.`title`, lc.`created`, lc.`last_modified`, lc.`event_time`,
						lc.`user_id`, uu.`login`, uu.`real_name`,
						(SELECT FIRST 1 x.`xref` FROM `".BIT_DB_PREFIX."liberty_xref` x
						 WHERE x.`content_id` = lc.`content_id` AND x.`item` = 'RECEIPT') AS ref_contact_id,
						(SELECT FIRST 1 lc2.`title` FROM `".BIT_DB_PREFIX."liberty_xref` x
						 JOIN `".BIT_DB_PREFIX."liberty_content` lc2 ON lc2.`content_id` = x.`xref`
						 WHERE x.`content_id` = lc.`content_id` AND x.`item` = 'RECEIPT') AS ref_contact_name,
						(SELECT FIRST 1 x.`start_date` FROM `".BIT_DB_PREFIX."liberty_xref` x
						 WHERE x.`content_id` = lc.`content_id` AND x.`item` = 'RECEIPT') AS ref_start_date
						$selectSql
				FROM `".BIT_DB_PREFIX."liberty_content` lc
					INNER JOIN `".BIT_DB_PREFIX."users_users` uu ON uu.`user_id` = lc.`user_id`
					$joinSql
				$whereSql $orderby";

		if( $rows = $this->mDb->query( $query, $bindVars, $pListHash['max_records'], $pListHash['offset'] ) ) {
			foreach( $rows as $row ) {
				$row['display_url']      = static::getDisplayUrlFromHash( $row );
				$ret[$row['content_id']] = $row;
			}
		}
		LibertyContent::postGetList( $pListHash );
		return $ret;
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
				? 'movement/'.$pParamHash['content_id']
				: 'view_movement.php?content_id='.$pParamHash['content_id'];
		}
		return $ret;
	}

	/** @return string  Display URL for this movement. */
	public function getDisplayUrl(): string {
		return static::getDisplayUrlFromHash( $this->mInfo );
	}

	/** @return string  URL to edit_movement.php for this movement. */
	public function getEditUrl( $pContentId = null, $pMixed = null ): string {
		if( $this->verifyId( $this->mContentId ) ) {
			return FOOD_PKG_URL.'edit_movement.php?content_id='.$this->mContentId;
		}
		return FOOD_PKG_URL.'edit_movement.php';
	}
}
