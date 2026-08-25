{strip}
<div class="listing food">
	<header>
		<div class="floaticon hidden-print">
			{if $gBitUser->hasPermission('p_food_create')}
				<a href="{$smarty.const.FOOD_PKG_URL}edit_component.php">{biticon ipackage="icons" iname="kt-add-filters" iexplain="Create Food Item"}</a>
			{/if}
			<form class="minifind" action="{$smarty.const.FOOD_PKG_URL}list_components.php" method="get">
				<div class="form-inline">
					<div class="form-group" style="position:relative">
						<input class="form-control input-sm" type="text" name="find" id="find" autocomplete="off" placeholder="{tr}Food Items{/tr}" value="{$smarty.request.find|escape}" />
						<ul id="comp_dropdown" class="dropdown-menu"
							style="display:none;position:absolute;width:390px;z-index:1000;max-height:220px;overflow-y:auto"></ul>
					</div>
					<div class="form-group">
						<select class="form-control input-sm" name="sup" id="sup" onchange="this.form.submit()">
							<option value="">{tr}Any shop{/tr}</option>
							{foreach $shops as $shop}
								<option value="{$shop.content_id}"{if $selectedShop eq $shop.content_id} selected="selected"{/if}>{$shop.title|escape}</option>
							{/foreach}
						</select>
					</div>
					<button type="submit" class="btn btn-default btn-sm">{tr}Search{/tr}</button>
				</div>
			</form>
		</div>
		<h1>{tr}Food Items{/tr}</h1>
	</header>

	<section class="body">
		<table class="table table-striped table-hover">
			<thead>
				<tr>
					<th>{smartlink ititle="Name" isort="title" idefault=1 icontrol=$listInfo}</th>
					<th>{tr}Supplier{/tr}</th>
					<th>{smartlink ititle="Created" isort="created" icontrol=$listInfo}</th>
					<th></th>
					{if $gBitUser->hasPermission('p_food_update')}<th></th>{/if}
				</tr>
			</thead>
			<tbody>
				{foreach from=$componentList item=comp}
					<tr>
						<td><a href="{$comp.display_url|escape}">{$comp.title|escape}</a></td>
						<td>{$comp.supplier_title|escape}</td>
						<td>{$comp.created|bit_short_date}</td>
						<td>{if $comp.needs_review}<span class="label label-warning">{tr}review{/tr}</span>{/if}</td>
						{if $gBitUser->hasPermission('p_food_update')}<td><a href="{$smarty.const.FOOD_PKG_URL}edit_component.php?content_id={$comp.content_id}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a></td>{/if}
					</tr>
				{foreachelse}
					<tr><td colspan="5" class="norecords">{tr}No food items found.{/tr}</td></tr>
				{/foreach}
			</tbody>
		</table>

		<nav>
			{pagination}
		</nav>

	</section>
</div>
{/strip}
<script>
(function($) {
	// Typeahead over the same lookup_component.php used by edit_movement.tpl and
	// add_assembly_item.tpl — same JS pattern (debounce, seq counter against
	// overlapping responses, arrow-key nav), but a click here navigates
	// straight to the matched component's
	// view page instead of filling a hidden id field, since this search exists
	// to jump to (or rule out) an existing item — e.g. to spot a near-duplicate
	// before creating a new one — not to feed a form elsewhere on this page.
	var timer;
	var seq = 0;
	var $input = $('#find');
	var $dd    = $('#comp_dropdown');

	$input.on('input', function() {
		var q = $(this).val();
		clearTimeout(timer);
		$dd.hide().empty();
		if (q.length < 2) return;
		var reqId = ++seq;
		timer = setTimeout(function() {
			var shopId = $('#sup').val();
			$.getJSON('{$smarty.const.FOOD_PKG_URL}includes/lookup_component.php', {ldelim}q: q, shop: shopId{rdelim}, function(data) {
				if (reqId !== seq) return; // a newer request has since superseded this one
				$dd.empty();
				if (!data.length) return;
				$.each(data, function(i, row) {
					var label = row.supplier ? row.title + ' (' + row.supplier + ')' : row.title;
					$dd.append($('<li>').append(
						$('<a>').attr('href', row.display_url).text(label)
					));
				});
				$dd.show();
			});
		}, 250);
	});

	// mousedown, not click: mousedown fires before the input's blur, so
	// preventDefault here stops focus (and the dropdown-hiding blur handler
	// below) from ever landing on the anchor first — same reasoning as
	// edit_movement.tpl/add_assembly_item.tpl's own mousedown handlers, just
	// navigating here instead of filling a field.
	$(document).on('mousedown', '#comp_dropdown a', function(e) {
		e.preventDefault();
		window.location = $(this).attr('href');
	});

	$input.on('blur', function() { setTimeout(function() { $dd.hide(); }, 150); });

	$input.on('keydown', function(e) {
		if (!$dd.is(':visible')) return;
		var $links = $dd.find('a'), idx = $links.index($dd.find('li.active a'));
		if (e.key === 'ArrowDown') { e.preventDefault(); $links.parent().removeClass('active'); $links.eq(idx + 1 < $links.length ? idx + 1 : 0).parent().addClass('active'); }
		else if (e.key === 'ArrowUp') { e.preventDefault(); $links.parent().removeClass('active'); $links.eq(idx > 0 ? idx - 1 : $links.length - 1).parent().addClass('active'); }
		else if (e.key === 'Enter') { var $a = $dd.find('li.active a'); if ($a.length) { e.preventDefault(); window.location = $a.attr('href'); } }
		else if (e.key === 'Escape') { $dd.hide(); }
	});
}(jQuery));
</script>
