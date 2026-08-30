<?php
/**
 * JSON autocomplete endpoint — returns FoodComponents matching ?q=, each with its
 * SUP supplier joined in. Started as an exact mirror of
 * stock/includes/lookup_component.php; diverged 2026-08-21 to add the supplier
 * join — Food, unlike Stock, has real same-titled components from different
 * shops (supplier moved out of the title into its own xref on import), which the
 * plain title-only version left genuinely ambiguous to pick between.
 *
 * @package food
 */

namespace Bitweaver\Food;

require_once '../../kernel/includes/setup_inc.php';

global $gBitDb, $gBitUser;

if( !$gBitUser->hasPermission( 'p_food_view' ) ) {
	header( 'Content-Type: application/json' );
	echo '[]';
	exit;
}

$q = trim( $_GET['q'] ?? '' );
if( strlen( $q ) < 2 ) {
	header( 'Content-Type: application/json' );
	echo '[]';
	exit;
}

// Optional shop filter (added 2026-08-22 for edit_movement.tpl's receipt-scoped
// search) — the author's own call to drop the earlier "component base needs tidying
// first" deferral: he'll tag a component's SUP on the spot via edit_component.php
// whenever a genuinely new item doesn't show up filtered, rather than waiting for
// a full tidy pass. Opt-in: no shop selected (0/blank) means unfiltered, same as
// today for add_assembly_item.tpl, which never passes this param at all.
$shopId = (int)( $_GET['shop'] ?? 0 );
$shopSql = '';
$bindVars = [ 'foodcomponent', '%'.strtolower( $q ).'%' ];
if( $shopId > 0 ) {
	$shopSql = " AND EXISTS ( SELECT 1 FROM `".BIT_DB_PREFIX."liberty_xref` sf WHERE sf.content_id = lc.content_id AND sf.item = 'SUP' AND sf.xref = ? )";
	$bindVars[] = $shopId;
}

// Supplier: a correlated subquery, not a JOIN — SUP is registered multiple=1
// (a component can legitimately have several real suppliers, e.g. bought from
// both Lidl and Waitrose over time), so a plain JOIN fanned out one dropdown row
// per supplier instead of one per component. FIRST 1 just picks a representative
// supplier to disambiguate the title with; not claiming it's the *only* one.
//
// default_qty: the component's own declared WT/VOL value (its quantity group,
// e.g. "this pack = 52g"), where actually set — not every component has one
// (WT with a NULL xkey just means "tracked by weight, real figure not entered
// yet", nothing to default from). Lets add_assembly_item.tpl prefill Quantity
// on selection rather than making every add start from a blank field.
//
// quantity_item/has_sgl (added 2026-08-22 for the SGL/WT/VOL pantry redesign):
// consumed by edit_movement.tpl to build its per-line entry-mode picker (direct
// weight/volume vs. a converted count) — see FoodMovement::addComponentLine()'s
// docblock for the two-mode design. Harmless extra fields for add_assembly_item.tpl,
// which ignores them.
//
// sgl_note: SGL's own xkey_ext (e.g. "Ready Meal", "Pack of 8" — see
// list_pantry.php's own Note column, same field, same reuse of the spare
// xkey_ext column). Lets edit_movement.tpl label the count-mode option with the
// component's actual category rather than a generic "count" — has_sgl stays a
// separate field since the flag can exist with no note yet.
//
// display_url (added 2026-08-25 for list_components.tpl's own dropdown): the
// two receipt/assembly callers ignore it, same as the other harmless extra
// fields noted above.
$rows = $gBitDb->getArray(
	"SELECT FIRST 30 lc.content_id, lc.title,
			( SELECT FIRST 1 sup.title FROM `".BIT_DB_PREFIX."liberty_xref` x
			  JOIN `".BIT_DB_PREFIX."liberty_content` sup ON ( sup.content_id = x.xref )
			  WHERE x.content_id = lc.content_id AND x.item = 'SUP' ) AS supplier,
			( SELECT FIRST 1 u.xkey FROM `".BIT_DB_PREFIX."liberty_xref` u
			  WHERE u.content_id = lc.content_id AND u.item IN ('WT','VOL')
			    AND u.xkey IS NOT NULL AND u.xkey <> ''
			  ORDER BY CASE u.item WHEN 'VOL' THEN 0 ELSE 1 END ) AS default_qty,
			( SELECT FIRST 1 t.item FROM `".BIT_DB_PREFIX."liberty_xref` t
			  WHERE t.content_id = lc.content_id AND t.item IN ('WT','VOL') ) AS quantity_item,
			( SELECT FIRST 1 1 FROM `".BIT_DB_PREFIX."liberty_xref` s
			  WHERE s.content_id = lc.content_id AND s.item = 'SGL' ) AS has_sgl,
			( SELECT FIRST 1 s.xkey_ext FROM `".BIT_DB_PREFIX."liberty_xref` s
			  WHERE s.content_id = lc.content_id AND s.item = 'SGL' ) AS sgl_note
	 FROM `".BIT_DB_PREFIX."liberty_content` lc
	 WHERE lc.content_type_guid=? AND LOWER(lc.title) LIKE ?$shopSql
	 ORDER BY lc.title",
	$bindVars
);

foreach( $rows as &$row ) {
	$row['display_url'] = FoodComponent::getDisplayUrlFromHash( $row );
}
unset( $row );

header( 'Content-Type: application/json' );
echo json_encode( array_values( $rows ?? [] ) );
exit;
