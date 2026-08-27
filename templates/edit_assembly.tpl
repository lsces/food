{strip}
<div class="edit food">
	<div class="header">
		{if $gContent->hasUpdatePermission()}
			<div class="floaticon">
				<a title="{tr}Take a second portion from the pantry{/tr}" href="{$smarty.const.FOOD_PKG_URL}edit_assembly.php?content_id={$gContent->mContentId}&amp;second_take=1" onclick="return confirm('{tr}Take a second portion of every ingredient in this meal out of the pantry, for an extra guest?{/tr}')">{biticon ipackage="icons" iname="contact-new-symbolic" iexplain="Take Second Portion From Pantry"}</a>
			</div>
		{/if}
		<h1>{tr}Edit{/tr} {$mealLabel|escape}</h1>
	</div>
	<div class="body">
		{if $secondTakeDone}
			{formfeedback success="Second portion taken from the pantry."}
		{/if}
		{formfeedback error=$errors}

		{form id="editAssemblyForm"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
			{if $mealTypes|@count > 1}
				<div class="form-group">
					{formlabel label="Meal type"}
					{forminput}
						{foreach $mealTypes as $code => $label}
							<label class="radio-inline">
								<input type="radio" name="meal_type" value="{$code}"{if $code eq $mealType} checked="checked"{/if} />
								{$label|escape}
							</label>
						{/foreach}
						{formhelp note="Only types not already used on this day are offered."}
					{/forminput}
				</div>
			{/if}
			<div class="form-group">
				{formlabel label="Time" for="event_time"}
				{forminput}
					<input type="time" class="form-control input-small" name="event_time" id="event_time" value="{$timeDisplay|escape}" />
					<span class="help-inline">{tr}on{/tr} {$dateFixed|escape}</span>
					{formhelp note="Date isn't editable here — use the copy icon on the View page to put this meal on a different date instead."}
				{/forminput}
			</div>
			<div class="form-group submit">
				<input type="submit" class="btn btn-primary" name="save" value="{tr}Save details{/tr}" />
			</div>
		{/form}

		<table class="table table-condensed">
			<thead>
				<tr>
					<th>{tr}Food Item{/tr}</th>
					<th>{tr}Quantity{/tr}</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				{foreach $items as $i}
				<tr>
					<td><a href="{$i.component_display_url|escape}">{$i.component_title|escape}</a></td>
					<td>
						{form id="qty-{$i.xref_id}" ipackage="food" ifile="edit_assembly.php" class="form-inline" style="display:inline"}
							<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
							<input type="hidden" name="update_xref_id" value="{$i.xref_id}" />
							<input type="text" class="form-control input-sm" name="new_quantity" value="{$i.quantity|escape}" style="width:5em;display:inline-block" />
							<button type="submit" class="btn btn-link btn-xs" title="{tr}Save{/tr}">{biticon iname="filesave" iexplain="Save"}</button>{$i.quantity_unit|escape}
						{/form}
					</td>
					<td>
						<span class="actionicon">
							{smartlink ititle="Remove" ipackage="food" ifile="edit_assembly.php" biticon="user-trash" content_id=$gContent->mContentId remove_xref_id=$i.xref_id}
						</span>
					</td>
				</tr>
				{/foreach}
			</tbody>
		</table>

		{form id="addComponentForm" ipackage="food" ifile="edit_assembly.php"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
			<input type="hidden" name="component_id" id="component_id" value="" />
			<div class="form-inline">
				<div class="form-group" style="position:relative">
					<input type="text" class="form-control" name="component_title" id="component_title"
						autocomplete="off" placeholder="{tr}Food Item…{/tr}" />
					<ul id="comp_dropdown" class="dropdown-menu"
						style="display:none;position:absolute;width:390px;z-index:1000;max-height:220px;overflow-y:auto"></ul>
				</div>
				<div class="form-group">
					<input type="text" class="form-control" name="xkey" id="xkey" placeholder="{tr}Quantity{/tr}" style="width:6em" />
				</div>
				<div class="form-group">
					<select class="form-control" name="qty_mode" id="qty_mode" style="width:9em">
						<option value="base">{tr}g/ml{/tr}</option>
					</select>
				</div>
				<button type="submit" class="btn btn-primary" name="fAddComponent" value="1">{tr}Add{/tr}</button>
			</div>
			{formhelp note="Type to search existing food items, or enter a new title to create one. Where the same title exists from more than one shop, the supplier shows in brackets — pick the right one rather than retyping the plain title. Adding a line returns you straight here, ready for the next one."}
		{/form}
	</div>
</div>
{/strip}
{if $gContent->isValid()}
<script>
(function($) {
	var timer;
	var seq = 0; // request-generation counter — same overlapping-response race
	             // add_assembly_item.tpl/edit_movement.tpl were both fixed for.
	var $input   = $('#component_title');
	var $dd      = $('#comp_dropdown');
	var $id      = $('#component_id');
	var $qty     = $('#xkey');
	var $qtyMode = $('#qty_mode');

	// Same shape as edit_movement.tpl's own setQtyModeOptions().
	function setQtyModeOptions(quantityItem, hasSgl, sglNote) {
		var unitLabel = quantityItem === 'VOL' ? 'ml' : (quantityItem === 'WT' ? 'g' : 'g/ml');
		$qtyMode.empty();
		if (hasSgl) {
			$qtyMode.append($('<option>').val('sgl').text(sglNote || 'Count'));
		}
		$qtyMode.append($('<option>').val('base').text(unitLabel));
	}

	$input.trigger('focus');

	$input.on('input', function() {
		$id.val('');
		setQtyModeOptions(null, false, null);
		var q = $(this).val();
		clearTimeout(timer);
		$dd.hide().empty();
		if (q.length < 2) return;
		var reqId = ++seq;
		timer = setTimeout(function() {
			$.getJSON('{$smarty.const.FOOD_PKG_URL}includes/lookup_component.php', {ldelim}q: q{rdelim}, function(data) {
				if (reqId !== seq) return; // a newer request has since superseded this one
				$dd.empty();
				if (!data.length) return;
				$.each(data, function(i, row) {
					var label = row.supplier ? row.title + ' [' + row.supplier + ']' : row.title;
					$dd.append($('<li>').append(
						$('<a>').attr('href','#')
							.data('id', row.content_id).data('label', label)
							.data('quantity-item', row.quantity_item).data('has-sgl', row.has_sgl)
							.data('sgl-note', row.sgl_note)
							.text(label)
					));
				});
				$dd.show();
			});
		}, 250);
	});

	$(document).on('mousedown', '#comp_dropdown a', function(e) {
		e.preventDefault();
		$input.val($(this).data('label'));
		$id.val($(this).data('id'));
		setQtyModeOptions($(this).data('quantity-item'), $(this).data('has-sgl'), $(this).data('sgl-note'));
		$dd.hide().empty();
		$qty.trigger('focus');
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
