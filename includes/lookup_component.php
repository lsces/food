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
$rows = $gBitDb->getArray(
	"SELECT FIRST 30 lc.content_id, lc.title,
			( SELECT FIRST 1 sup.title FROM liberty_xref x
			  JOIN liberty_content sup ON ( sup.content_id = x.xref )
			  WHERE x.content_id = lc.content_id AND x.item = 'SUP' ) AS supplier,
			( SELECT FIRST 1 u.xkey FROM liberty_xref u
			  WHERE u.content_id = lc.content_id AND u.item IN ('WT','VOL')
			    AND u.xkey IS NOT NULL AND u.xkey <> ''
			  ORDER BY CASE u.item WHEN 'VOL' THEN 0 ELSE 1 END ) AS default_qty,
			( SELECT FIRST 1 t.item FROM liberty_xref t
			  WHERE t.content_id = lc.content_id AND t.item IN ('WT','VOL') ) AS quantity_item,
			( SELECT FIRST 1 1 FROM liberty_xref s
			  WHERE s.content_id = lc.content_id AND s.item = 'SGL' ) AS has_sgl,
			( SELECT FIRST 1 s.xkey_ext FROM liberty_xref s
			  WHERE s.content_id = lc.content_id AND s.item = 'SGL' ) AS sgl_note
	 FROM liberty_content lc
	 WHERE lc.content_type_guid=? AND LOWER(lc.title) LIKE ?
	 ORDER BY lc.title",
	[ 'foodcomponent', '%'.strtolower( $q ).'%' ]
);

header( 'Content-Type: application/json' );
echo json_encode( array_values( $rows ?? [] ) );
exit;
