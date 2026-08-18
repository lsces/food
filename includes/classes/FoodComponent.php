<?php
/**
 * A single food ingredient — imported from Samsung Health's food_info.csv.
 *
 * Stored as a pure liberty_content record (content_type_guid='foodcomponent'), no
 * companion schema table — content_id is the only identifier (see Claude memory
 * feedback_content_id_only). Nutrition facts live in the 'nutrition' xref group;
 * the Samsung datauuid used for re-import dedupe lives in the package-level
 * 'external' xref group ('DUID' item), not a schema column.
 *
 * @package food
 */
namespace Bitweaver\Food;

use Bitweaver\Liberty\LibertyContent;
use Bitweaver\Liberty\LibertyXref;

defined( 'FOODCOMPONENT_CONTENT_TYPE_GUID' ) || define( 'FOODCOMPONENT_CONTENT_TYPE_GUID', 'foodcomponent' );

#[\AllowDynamicProperties]
class FoodComponent extends LibertyContent {

	/**
	 * The "selected list" of nutrition fields shown together wherever a nutrition
	 * summary is needed (view_day.php's meal/day totals and per-item rows, view_
	 * assembly.php, view_component.php, and any future consumer) — matches UK
	 * front-of-pack label order (Energy/Fat/Saturates/Carbohydrate/Sugars/Fibre/
	 * Protein/Sodium), with 5AD (five-a-day) appended last. FAT_TOTAL/FAT_SAT are
	 * the only two sub-fields pulled out of FAT's json-list blob; VIT/MIN are
	 * deliberately excluded (detail-level, not headline nutrition).
	 *
	 * 'format' picks how formatNutrition() renders the value — three genuinely
	 * different kinds of number share this one list, not just a mass/non-mass split:
	 *   'mg'       — genuine mg-mass values, >=1000mg renders as "X.Xg" (formatMg()).
	 *   'kcal'     — CAL only; energy, not a mass, never goes through formatMg().
	 *   'portions' — 5AD only; NOT a per-100g additive nutrient like the other eight
	 *                (see scaleNutrition()'s special case for the actual formula) —
	 *                a fixed portion-size adjustment factor, so it needs 2 decimal
	 *                places, not formatMg()'s mg/g rounding or CAL's whole-number one.
	 */
	public const NUTRITION_SUMMARY_FIELDS = [
		'CAL'       => [ 'label' => 'Energy',       'format' => 'kcal' ],
		'FAT_TOTAL' => [ 'label' => 'Fat',          'format' => 'mg' ],
		'FAT_SAT'   => [ 'label' => 'Saturates',    'format' => 'mg' ],
		'CARB'      => [ 'label' => 'Carbohydrate', 'format' => 'mg' ],
		'SUGR'      => [ 'label' => 'Sugars',       'format' => 'mg' ],
		'FIBR'      => [ 'label' => 'Fibre',        'format' => 'mg' ],
		'PROT'      => [ 'label' => 'Protein',      'format' => 'mg' ],
		'SOD'       => [ 'label' => 'Sodium',       'format' => 'mg' ],
		'5AD'       => [ 'label' => 'Five-a-day',   'format' => 'portions' ],
	];

	/**
	 * @param int|null $pDummy      Unused — LibertyBase::getNewObject() (the default
	 *                              factory behind getLibertyObject()/lookup()) always
	 *                              calls `new $class(null, $contentId)`, content_id in
	 *                              the second slot. Food never had a real second id
	 *                              (unlike Stock's now-retired component_id), this
	 *                              param exists purely to match that contract.
	 * @param int|null $pContentId  liberty_content.content_id to load.
	 */
	public function __construct( $pDummy = null, $pContentId = null ) {
		parent::__construct();
		$this->mContentTypeGuid = FOODCOMPONENT_CONTENT_TYPE_GUID;
		$this->mContentId = (int)( $pContentId ?? $pDummy );

		$this->registerContentType(
			FOODCOMPONENT_CONTENT_TYPE_GUID, [
				'content_type_guid' => FOODCOMPONENT_CONTENT_TYPE_GUID,
				'content_name' => 'Component',
				'content_name_plural' => 'Components',
				'handler_class' => 'FoodComponent',
				'handler_package' => 'food',
				'handler_file' => 'FoodComponent.php',
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
			$ret = static::getLibertyObject( $lookupContentId, FOODCOMPONENT_CONTENT_TYPE_GUID );
		}
		return $ret;
	}

	/**
	 * Find an existing component by its Samsung Health datauuid, via the 'DUID'
	 * xref item rather than a schema column — used by the food_info importer to
	 * decide upsert vs insert.
	 *
	 * @param  string $pDatauuid
	 * @return int|null  content_id if found, else null.
	 */
	public static function lookupByDatauuid( string $pDatauuid ): ?int {
		global $gBitDb;
		// liberty_xref itself has no x_group column (only liberty_xref_item does) —
		// join to scope the 'DUID' item lookup to foodcomponent rather than matching
		// any content type's same-named item. datauuid is a 36-char UUID, which
		// exceeds xkey's 32-char limit (Firebird fatal, not a silent truncation) —
		// stored/matched via xkey_ext instead, see ImportFoodInfo.php::foodStoreXref.
		$contentId = $gBitDb->getOne(
			"SELECT x.`content_id` FROM `".BIT_DB_PREFIX."liberty_xref` x
				JOIN `".BIT_DB_PREFIX."liberty_xref_item` s ON s.`item` = x.`item` AND s.`content_type_guid` = '".FOODCOMPONENT_CONTENT_TYPE_GUID."'
				WHERE x.`item` = 'DUID' AND x.`xkey_ext` = ?",
			[ $pDatauuid ]
		);
		return $contentId ? (int)$contentId : null;
	}

	/**
	 * Load component data into $this->mInfo from liberty_content.
	 *
	 * @return int|null  Row count (> 0) on success, or null if mContentId is not set.
	 */
	public function load() {
		if( $this->verifyId( $this->mContentId ) ) {
			$selectSql = $joinSql = $whereSql = '';
			$bindVars = [];

			$whereSql = " WHERE lc.`content_id` = ? AND lc.`content_type_guid` = '".FOODCOMPONENT_CONTENT_TYPE_GUID."'";
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
	protected function verifyComponentData( array &$pParamHash ): bool {
		$pParamHash['content_type_guid'] = FOODCOMPONENT_CONTENT_TYPE_GUID;
		if( $this->isValid() ) {
			$pParamHash['content_id'] = $this->mContentId;
		}
		// Notes is always plain text, never rich HTML — forced regardless of the
		// site's default_format (bithtml), since there's no CKEditor on this field,
		// just a bare textarea. 'simpletext' is the actual registered plugin guid
		// (liberty/plugins/format.simpletext.php) — its verify_function stores 'edit'
		// as-is (no wrapping needed), its load_function does nl2br(htmlentities()) at
		// display time, so plain multi-line notes render safely without needing HTML
		// hand-written into the stored data.
		$pParamHash['format_guid'] = 'simpletext';
		if( empty( $pParamHash['title'] ) ) {
			$this->mErrors['title'] = 'A title is required.';
		}
		return count( $this->mErrors ) == 0;
	}

	/**
	 * Persist component data inside a transaction via LibertyContent::store().
	 *
	 * @param  array $pParamHash  Data to persist; modified in place.
	 * @return bool
	 */
	public function store( array &$pParamHash ): bool {
		if( $this->verifyComponentData( $pParamHash ) ) {
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
	 * @param  array $pParamHash  Must contain 'content_id'; used to build the URL.
	 * @return string
	 */
	public static function getDisplayUrlFromHash( &$pParamHash ) {
		global $gBitSystem;
		$ret = '';
		if( static::verifyId( $pParamHash['content_id'] ?? 0 ) ) {
			$ret = FOOD_PKG_URL;
			$ret .= $gBitSystem->isFeatureActive( 'pretty_urls' )
				? 'component/'.$pParamHash['content_id']
				: 'view_component.php?content_id='.$pParamHash['content_id'];
		}
		return $ret;
	}

	/** @return string  Display URL for this component. */
	public function getDisplayUrl() {
		return static::getDisplayUrlFromHash( $this->mInfo );
	}

	/**
	 * Overrides LibertyContent's default (which points at a plain 'edit.php' every
	 * package is assumed to have) — Food has more than one content type
	 * (FoodComponent/FoodAssembly), same reason Stock overrides this on each of its
	 * own content classes rather than relying on the generic default.
	 *
	 * @return string  URL to edit_component.php for this component.
	 */
	public function getEditUrl( $pContentId = null, $pMixed = null ): string {
		if( $this->verifyId( $this->mContentId ) ) {
			return FOOD_PKG_URL.'edit_component.php?content_id='.$this->mContentId;
		}
		return FOOD_PKG_URL.'edit_component.php';
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
			[ $this->mContentId, FOODCOMPONENT_CONTENT_TYPE_GUID ]
		);
	}

	/**
	 * The REM xref row currently flagging this component 'REVIEW' (needs
	 * review — see list_review.php's docblock for the full semantics), found by
	 * scanning the already-loaded $this->mXrefInfo rather than a fresh query —
	 * the caller (edit_component.php) has always already called loadXrefInfo()
	 * to render the Quantity tab, so this is the same data.
	 *
	 * @return int|null  The REM row's xref_id if flagged, else null (either
	 *                    unflagged, or loadXrefInfo() hasn't been called).
	 */
	public function getReviewXrefId(): ?int {
		foreach( $this->mXrefInfo->mGroups ?? [] as $group ) {
			foreach( $group->mXrefs as $xref ) {
				if( $xref['item'] === 'REM' && $xref['xkey_ext'] === 'REVIEW' ) {
					return (int)$xref['xref_id'];
				}
			}
		}
		return null;
	}

	/** @return bool  Whether this component is still on list_review.php's outstanding list. */
	public function isFlaggedForReview(): bool {
		return $this->getReviewXrefId() !== null;
	}

	/**
	 * Clear the outstanding-review flag, once a human has fully fixed this
	 * component (see edit_component.tpl's tick floaticon). Updates the existing
	 * REM row via LibertyXref::store() rather than a raw UPDATE, so verify()
	 * stamps last_update_date normally — clearing is itself a real, timestamped
	 * edit, not a data-hack. The row itself (and the 'REM' item's very existence)
	 * stays in place permanently — that's what keeps list_review.php's
	 * total-flagged count accurate even after every outstanding item is cleared.
	 *
	 * @return bool  TRUE if a flagged REM row was found and cleared, FALSE if
	 *               there was nothing to clear.
	 */
	public function clearReviewFlag(): bool {
		$xrefId = $this->getReviewXrefId();
		if( !$xrefId ) {
			return false;
		}
		$xref = new LibertyXref();
		$pHash = [ 'xref_id' => $xrefId, 'content_id' => $this->mContentId, 'xkey_ext' => '' ];
		return (bool)$xref->store( $pHash );
	}

	/**
	 * Return a paged, keyed list of components — every FoodComponent, not just the
	 * ones flagged for review (see list_review.php for that narrower view).
	 *
	 * Recognised filter keys: search (title match), sup (supplier content_id — only
	 * components with a matching SUP xref), sort_mode.
	 * Sets $pListHash['cant'] on return.
	 *
	 * @param  array $pListHash  Filter and pagination params; modified in place.
	 * @return array             content_id-keyed result rows.
	 */
	public function getList( &$pListHash ) {
		// Set before prepGetList() runs — it defaults an empty sort_mode to
		// last_modified_desc itself, so checking/setting after would always be too
		// late to have any effect.
		if( empty( $pListHash['sort_mode'] ) ) {
			$pListHash['sort_mode'] = 'title_asc';
		}
		LibertyContent::prepGetList( $pListHash );
		$ret = $bindVars = [];
		$selectSql = $whereSql = $joinSql = '';

		$whereSql .= " AND lc.`content_type_guid` = '".FOODCOMPONENT_CONTENT_TYPE_GUID."'";

		if( !empty( $pListHash['search'] ) ) {
			$term = '%'.strtoupper( $pListHash['search'] ).'%';
			$whereSql .= " AND (UPPER(lc.`title`) LIKE ? OR UPPER(lc.`data`) LIKE ?) ";
			$bindVars[] = $term;
			$bindVars[] = $term;
		}

		if( $this->verifyId( $pListHash['sup'] ?? 0 ) ) {
			$whereSql .= " AND EXISTS (
				SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` sx
				WHERE sx.`content_id` = lc.`content_id` AND sx.`item` = 'SUP' AND sx.`xref` = ?
			)";
			$bindVars[] = (int)$pListHash['sup'];
		}

		$this->getServicesSql( 'content_list_sql_function', $selectSql, $joinSql, $whereSql, $bindVars );

		$X = BIT_DB_PREFIX;
		$selectSql .= ", (SELECT FIRST 1 shop.`title` FROM `{$X}liberty_xref` sx
			INNER JOIN `{$X}liberty_content` shop ON ( shop.`content_id` = sx.`xref` )
			WHERE sx.`content_id` = lc.`content_id` AND sx.`item` = 'SUP' ) AS `supplier_title`";
		$selectSql .= ", (SELECT COUNT(*) FROM `{$X}liberty_xref` rx WHERE rx.`content_id` = lc.`content_id` AND rx.`item` = 'REM' AND rx.`xkey_ext` = 'REVIEW') AS `needs_review`";

		$orderby = !empty( $pListHash['sort_mode'] )
			? " ORDER BY ".$this->mDb->convertSortmode( $pListHash['sort_mode'] )
			: " ORDER BY lc.`title`";

		if( !empty( $whereSql ) ) {
			$whereSql = substr_replace( $whereSql, ' WHERE ', 0, 4 );
		}

		$pListHash['cant'] = (int)$this->mDb->getOne(
			"SELECT COUNT(DISTINCT lc.`content_id`)
			 FROM `".BIT_DB_PREFIX."liberty_content` lc
				INNER JOIN `".BIT_DB_PREFIX."users_users` uu ON (uu.`user_id` = lc.`user_id`) $joinSql
			$whereSql",
			$bindVars
		);

		$query = "SELECT lc.`content_id` AS `hash_key`, lc.*, uu.`login`, uu.`real_name` $selectSql
				FROM `".BIT_DB_PREFIX."liberty_content` lc
					INNER JOIN `".BIT_DB_PREFIX."users_users` uu ON (uu.`user_id` = lc.`user_id`) $joinSql
				$whereSql $orderby";
		if( $rows = $this->mDb->query( $query, $bindVars, $pListHash['max_records'], $pListHash['offset'] ) ) {
			foreach( $rows as $row ) {
				$row['display_url'] = static::getDisplayUrlFromHash( $row );
				$ret[$row['hash_key']] = $row;
			}
		}
		LibertyContent::postGetList( $pListHash );
		return $ret;
	}

	public function expunge(): bool {
		if( $this->isValid() ) {
			$this->StartTrans();
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
	 * Batch per-100g lookup of NUTRITION_SUMMARY_FIELDS for a set of components — one
	 * query regardless of how many components, avoiding an N+1 per ingredient row.
	 * FAT's json-list blob is decoded here so callers never need to know it's a
	 * compound field — FAT_TOTAL/FAT_SAT come back as plain scalars like every other
	 * field. 5AD comes back as its raw stored adjustment factor (see
	 * admin/schema_inc.php's own comment) — NOT a per-100g nutrient amount, despite
	 * living in this same batch structure for convenience; scaleNutrition() below
	 * knows to treat it differently.
	 *
	 * @param int[] $pContentIds
	 * @return array<int,array<string,float>>  content_id => field => value. Every
	 *         requested content_id gets all 9 keys, defaulting to 0.0 for whichever
	 *         fields that component has no xref row for (for 5AD, 0.0 correctly
	 *         means "not flagged, doesn't count towards five-a-day").
	 */
	public static function getNutritionBatch( array $pContentIds ): array {
		global $gBitDb;
		$fieldKeys = array_keys( self::NUTRITION_SUMMARY_FIELDS );
		$ret = [];
		foreach( $pContentIds as $contentId ) {
			$ret[(int)$contentId] = array_fill_keys( $fieldKeys, 0.0 );
		}
		if( !$pContentIds ) {
			return $ret;
		}
		$placeholders = implode( ',', array_fill( 0, count( $pContentIds ), '?' ) );
		$rows = $gBitDb->getAll(
			"SELECT x.`content_id`, x.`item`, x.`xkey`, x.`data`
				FROM `".BIT_DB_PREFIX."liberty_xref` x
				JOIN `".BIT_DB_PREFIX."liberty_xref_item` s ON s.`item` = x.`item` AND s.`content_type_guid` = '".FOODCOMPONENT_CONTENT_TYPE_GUID."'
				WHERE x.`content_id` IN ($placeholders) AND x.`item` IN ('CAL','PROT','CARB','FIBR','SUGR','SOD','FAT','5AD')",
			array_map( 'intval', $pContentIds )
		);
		foreach( $rows as $row ) {
			$contentId = (int)$row['content_id'];
			if( $row['item'] === 'FAT' ) {
				$fat = json_decode( (string)$row['data'], true ) ?: [];
				$ret[$contentId]['FAT_TOTAL'] = (float)( $fat['total_mg'] ?? 0 );
				$ret[$contentId]['FAT_SAT']   = (float)( $fat['saturated_mg'] ?? 0 );
			} else {
				$ret[$contentId][$row['item']] = (float)$row['xkey'];
			}
		}
		return $ret;
	}

	/**
	 * Scale a per-100g nutrition set (from getNutritionBatch()) to an actual gram
	 * quantity — for the eight additive nutrients this is the obvious value*grams/100.
	 * 5AD is deliberately different: it's not a per-100g amount, it's a fixed
	 * portion-size adjustment factor (true_portion_g/80 — see admin/schema_inc.php),
	 * so running it through the same linear scaling would be wrong. The correct
	 * portions contributed by eating $pGrams of a food whose true portion size is
	 * (80*factor)g is grams/(80*factor); 0 (not flagged) correctly gives 0 portions.
	 * Once computed, portions ARE additive like everything else — sumNutrition()
	 * needs no special case, only this one scaling step does.
	 */
	public static function scaleNutrition( array $pPer100g, float $pGrams ): array {
		$ret = [];
		foreach( $pPer100g as $key => $val ) {
			if( $key === '5AD' ) {
				$ret[$key] = $val > 0 ? $pGrams / ( 80 * $val ) : 0.0;
			} else {
				$ret[$key] = (float)$val * $pGrams / 100;
			}
		}
		return $ret;
	}

	/** Sum any number of raw nutrition sets (e.g. several ingredients into a meal total). */
	public static function sumNutrition( array ...$pSets ): array {
		$ret = array_fill_keys( array_keys( self::NUTRITION_SUMMARY_FIELDS ), 0.0 );
		foreach( $pSets as $set ) {
			foreach( $set as $key => $val ) {
				$ret[$key] = ( $ret[$key] ?? 0.0 ) + $val;
			}
		}
		return $ret;
	}

	/**
	 * Format a raw nutrition set (scaleNutrition()/sumNutrition() output) into
	 * display strings, per NUTRITION_SUMMARY_FIELDS's 'format': 'mg' through
	 * formatMg() (>=1000mg -> "X.Xg"), 'kcal' as plain rounded kcal, 'portions'
	 * (5AD) to 2 decimal places — a whole-number round would silently destroy the
	 * whole point of a fractional adjustment factor like dried fruit's 0.375.
	 */
	public static function formatNutrition( array $pValues ): array {
		$ret = [];
		foreach( self::NUTRITION_SUMMARY_FIELDS as $key => $meta ) {
			$val = $pValues[$key] ?? 0.0;
			$ret[$key] = match( $meta['format'] ) {
				'mg'       => self::formatMg( $val ),
				'portions' => number_format( $val, 2 ),
				default    => round( $val ).' kcal',
			};
		}
		return $ret;
	}

	/**
	 * >=1000mg switches to grams (1500 -> "1.5g"), otherwise plain rounded mg
	 * (250 -> "250mg"). Only for genuine mg-mass values — see NUTRITION_SUMMARY_FIELDS'
	 * 'mass' flag; CAL (kcal, not a mass) must never be passed through this.
	 */
	public static function formatMg( $pMg ): string {
		$mg = (float)$pMg;
		if( abs( $mg ) >= 1000 ) {
			return number_format( $mg / 1000, 1 ).'g';
		}
		return round( $mg ).'mg';
	}
}
