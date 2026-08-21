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
$rows = $gBitDb->getArray(
	"SELECT FIRST 30 lc.content_id, lc.title,
			( SELECT FIRST 1 sup.title FROM liberty_xref x
			  JOIN liberty_content sup ON ( sup.content_id = x.xref )
			  WHERE x.content_id = lc.content_id AND x.item = 'SUP' ) AS supplier,
			( SELECT FIRST 1 u.xkey FROM liberty_xref u
			  WHERE u.content_id = lc.content_id AND u.item IN ('WT','VOL')
			    AND u.xkey IS NOT NULL AND u.xkey <> ''
			  ORDER BY CASE u.item WHEN 'VOL' THEN 0 ELSE 1 END ) AS default_qty
	 FROM liberty_content lc
	 WHERE lc.content_type_guid=? AND LOWER(lc.title) LIKE ?
	 ORDER BY lc.title",
	[ 'foodcomponent', '%'.strtolower( $q ).'%' ]
);

header( 'Content-Type: application/json' );
echo json_encode( array_values( $rows ?? [] ) );
exit;
