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
	 * The REM xref row currently flagging this component 'CORRECT' (needs
	 * correcting — see list_corrections.php's docblock for the full semantics),
	 * found by scanning the already-loaded $this->mXrefInfo rather than a fresh
	 * query — the caller (edit_component.php) has always already called
	 * loadXrefInfo() to render the Quantity tab, so this is the same data.
	 *
	 * @return int|null  The REM row's xref_id if flagged, else null (either
	 *                    unflagged, or loadXrefInfo() hasn't been called).
	 */
	public function getCorrectionXrefId(): ?int {
		foreach( $this->mXrefInfo->mGroups ?? [] as $group ) {
			foreach( $group->mXrefs as $xref ) {
				if( $xref['item'] === 'REM' && $xref['xkey_ext'] === 'CORRECT' ) {
					return (int)$xref['xref_id'];
				}
			}
		}
		return null;
	}

	/** @return bool  Whether this component is still on list_corrections.php's outstanding list. */
	public function isFlaggedForCorrection(): bool {
		return $this->getCorrectionXrefId() !== null;
	}

	/**
	 * Clear the outstanding-correction flag, once a human has fully fixed this
	 * component (see edit_component.tpl's tick floaticon). Updates the existing
	 * REM row via LibertyXref::store() rather than a raw UPDATE, so verify()
	 * stamps last_update_date normally — clearing is itself a real, timestamped
	 * edit, not a data-hack. The row itself (and the 'REM' item's very existence)
	 * stays in place permanently — that's what keeps list_corrections.php's
	 * total-flagged count accurate even after every outstanding item is cleared.
	 *
	 * @return bool  TRUE if a flagged REM row was found and cleared, FALSE if
	 *               there was nothing to clear.
	 */
	public function clearCorrectionFlag(): bool {
		$xrefId = $this->getCorrectionXrefId();
		if( !$xrefId ) {
			return false;
		}
		$xref = new LibertyXref();
		$pHash = [ 'xref_id' => $xrefId, 'content_id' => $this->mContentId, 'xkey_ext' => '' ];
		return (bool)$xref->store( $pHash );
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
}
