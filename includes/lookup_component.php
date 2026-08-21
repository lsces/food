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

// Supplier joined in so same-titled components (e.g. the same generic sandwich
// name imported separately from several shops, once the supplier itself moved
// out of the title into its own xref — see project_food_package_scoping memory)
// are actually distinguishable in the dropdown, not just duplicate-looking rows.
//
// default_qty: the component's own declared WT/VOL value (its quantity group,
// e.g. "this pack = 52g"), where actually set — not every component has one
// (WT with a NULL xkey just means "tracked by weight, real figure not entered
// yet", nothing to default from). Lets add_assembly_item.tpl prefill Quantity
// on selection rather than making every add start from a blank field.
$rows = $gBitDb->getArray(
	"SELECT FIRST 30 lc.content_id, lc.title, sup.title AS supplier,
			( SELECT FIRST 1 u.xkey FROM liberty_xref u
			  WHERE u.content_id = lc.content_id AND u.item IN ('WT','VOL')
			    AND u.xkey IS NOT NULL AND u.xkey <> ''
			  ORDER BY CASE u.item WHEN 'VOL' THEN 0 ELSE 1 END ) AS default_qty
	 FROM liberty_content lc
	 LEFT JOIN liberty_xref x ON ( x.content_id = lc.content_id AND x.item = 'SUP' )
	 LEFT JOIN liberty_content sup ON ( sup.content_id = x.xref )
	 WHERE lc.content_type_guid=? AND LOWER(lc.title) LIKE ?
	 ORDER BY lc.title, sup.title",
	[ 'foodcomponent', '%'.strtolower( $q ).'%' ]
);

header( 'Content-Type: application/json' );
echo json_encode( array_values( $rows ?? [] ) );
exit;
