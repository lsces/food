{strip}
<div class="edit food">
	<header>
		<h1>{tr}Edit Receipt{/tr}{if $gContent->isValid()}: {$gContent->getTitle()|escape}{/if}</h1>
	</header>
	<div class="body">
		{formfeedback error=$errors}

		{form id="editMovementForm"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId|escape}" />

			<div class="form-group">
				{formlabel label="Title" for="title"}
				{forminput}
					<input type="text" class="form-control" name="title" id="title" value="{$gContent->getTitle()|escape}" placeholder="{tr}Receipt — YYYY-MM-DD{/tr}" />
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Shop" for="shop_content_id"}
				{forminput}
					<select class="form-control" name="shop_content_id" id="shop_content_id">
						<option value="">{tr}-- select --{/tr}</option>
						{foreach $shops as $shop}
							<option value="{$shop.content_id}"{if $gContent->mInfo.ref_contact_id eq $shop.content_id} selected="selected"{/if}>{$shop.title|escape}</option>
						{/foreach}
					</select>
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Reference" for="ref_key"}
				{forminput}
					<input type="text" class="form-control input-small" name="ref_key" id="ref_key" value="{$gContent->mInfo.ref_key|default:''|escape}" />
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Purchase date" for="purchase_date"}
				{forminput}
					<input type="text" class="form-control input-small" name="purchase_date" id="purchase_date" value="{$purchaseDateVal|escape}" placeholder="dd/mm/yyyy" />
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Note" for="note"}
				{forminput}
					<input type="text" class="form-control" name="note" id="note" value="{$gContent->mInfo.ref_note|default:''|escape}" />
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-primary" name="save" value="{tr}Save{/tr}" />
			</div>
		{/form}

		{if $gContent->isValid()}
			<table class="table table-condensed">
				<thead>
					<tr>
						<th>{tr}Component{/tr}</th>
						<th>{tr}Quantity{/tr}</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					{foreach $lines as $l}
					<tr>
						<td><a href="{$l.component_display_url|escape}">{$l.component_title|escape}</a></td>
						<td>{$l.quantity|escape}{$l.quantity_unit|escape}</td>
						<td>
							{smartlink ititle="Remove" ipackage="food" ifile="edit_movement.php" biticon="user-trash" content_id=$gContent->mContentId remove_xref_id=$l.xref_id}
						</td>
					</tr>
					{foreachelse}
					<tr><td colspan="3" class="norecords">{tr}No items on this receipt.{/tr}</td></tr>
					{/foreach}
				</tbody>
			</table>

			<div id="add-component">
				{formfeedback error=$addErrors}
				{form id="addComponentForm" ipackage="food" ifile="edit_movement.php"}
					<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
					<div class="form-inline">
						<div class="form-group" style="position:relative">
							<input type="text" class="form-control" name="component_title" id="component_title"
								autocomplete="off" placeholder="{tr}Component…{/tr}" />
							<ul id="comp_dropdown" class="dropdown-menu"
								style="display:none;position:absolute;width:260px;z-index:1000;max-height:220px;overflow-y:auto"></ul>
						</div>
						<div class="form-group">
							<input type="text" class="form-control" name="quantity" id="quantity" placeholder="{tr}Quantity{/tr}" style="width:6em" />
						</div>
						<div class="form-group">
							<select class="form-control" name="qty_mode" id="qty_mode" style="width:9em">
								<option value="base">{tr}g/ml{/tr}</option>
							</select>
						</div>
						<button type="submit" class="btn btn-primary" name="fAddComponent" value="1">{tr}Add{/tr}</button>
					</div>
					{formhelp note="Type to search existing components, or enter a new title to create one. Adding a line returns you straight here, ready for the next one."}
				{/form}
			</div>

			<p><a href="view_movement.php?content_id={$gContent->mContentId}">{tr}Back to receipt{/tr}</a></p>
		{/if}
	</div>
</div>
{/strip}
{if $gContent->isValid()}
<script>
(function($) {
	var timer;
	var $input   = $('#component_title');
	var $dd      = $('#comp_dropdown');
	var $qtyMode = $('#qty_mode');

	function setQtyModeOptions(quantityItem, hasSgl) {
		var unitLabel = quantityItem === 'VOL' ? 'ml' : (quantityItem === 'WT' ? 'g' : 'g/ml');
		$qtyMode.empty();
		$qtyMode.append($('<option>').val('base').text(unitLabel));
		if (hasSgl) {
			$qtyMode.append($('<option>').val('sgl').text('x (count)'));
		}
	}

	$input.trigger('focus');

	$input.on('input', function() {
		var q = $(this).val();
		clearTimeout(timer);
		$dd.hide().empty();
		// Manual retyping invalidates whatever component was previously selected —
		// same reasoning as add_assembly_item.tpl's component_id invalidation —
		// so a stale unit label can't silently survive a hand-edit.
		setQtyModeOptions(null, false);
		if (q.length < 2) return;
		timer = setTimeout(function() {
			$.getJSON('{$smarty.const.FOOD_PKG_URL}includes/lookup_component.php', {ldelim}q: q{rdelim}, function(data) {
				if (!data.length) return;
				$.each(data, function(i, row) {
					$dd.append($('<li>').append(
						$('<a>').attr('href','#').data('title', row.title)
							.data('quantity-item', row.quantity_item).data('has-sgl', row.has_sgl).text(row.title)
					));
				});
				$dd.show();
			});
		}, 250);
	});

	$(document).on('mousedown', '#comp_dropdown a', function(e) {
		e.preventDefault();
		$input.val($(this).data('title'));
		setQtyModeOptions($(this).data('quantity-item'), $(this).data('has-sgl'));
		$dd.hide().empty();
		$('#quantity').trigger('focus');
	});

	$input.on('blur', function() { setTimeout(function() { $dd.hide(); }, 150); });

	$input.on('keydown', function(e) {
		if (!$dd.is(':visible')) return;
		var $links = $dd.find('a'), idx = $links.index($dd.find('li.active a'));
		if (e.key === 'ArrowDown') { e.preventDefault(); $links.parent().removeClass('active'); $links.eq(idx + 1 < $links.length ? idx + 1 : 0).parent().addClass('active'); }
		else if (e.key === 'ArrowUp') { e.preventDefault(); $links.parent().removeClass('active'); $links.eq(idx > 0 ? idx - 1 : $links.length - 1).parent().addClass('active'); }
		else if (e.key === 'Enter') { var $a = $dd.find('li.active a'); if ($a.length) { e.preventDefault(); $a.trigger('mousedown'); } }
		else if (e.key === 'Escape') { $dd.hide(); }
	});
}(jQuery));
</script>
{/if}
