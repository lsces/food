{strip}
<div class="edit food">
	<div class="header">
		<h1>{tr}Add Food Item{/tr}: {$mealLabel|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}

		{form id="addComponentForm" ipackage="food" ifile="add_assembly_item.php"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
			<input type="hidden" name="component_id" id="component_id" value="{$smarty.request.component_id|default:''|escape}" />

			<div class="form-group">
				{formlabel label="Food Item" for="component_title" mandatory="y"}
				{forminput}
					<div style="position:relative">
						<input type="text" class="form-control" name="component_title" id="component_title"
							autocomplete="off"
							value="{$smarty.request.component_title|default:''|escape}"
							placeholder="{tr}Type to search…{/tr}" />
						<ul id="comp_dropdown" class="dropdown-menu"
							style="display:none;position:absolute;width:100%;z-index:1000;max-height:220px;overflow-y:auto"></ul>
					</div>
					{formhelp note="Type to search existing food items, or enter a new title to create one. Where the same title exists from more than one shop, the supplier shows in brackets — pick the right one rather than retyping the plain title."}
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Quantity" for="xkey"}
				{forminput}
					<input type="text" class="form-control" name="xkey" id="xkey" style="width:6em;display:inline-block"
						value="{$smarty.request.xkey|default:''|escape}" />
					<select class="form-control" name="qty_mode" id="qty_mode" style="width:9em;display:inline-block">
						<option value="base">{tr}g/ml{/tr}</option>
					</select>
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel" value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="fAddComponent" value="{tr}Add Food Item{/tr}" />
			</div>
		{/form}
	</div>
</div>
{/strip}
<script>
(function($) {
	var $qty     = $('#xkey');
	var $qtyMode = $('#qty_mode');

	// Same shape as edit_movement.tpl's own setQtyModeOptions() — see that
	// file's comment for why SGL is appended first (selected by default) when
	// available.
	function setQtyModeOptions(quantityItem, hasSgl, sglNote) {
		var unitLabel = quantityItem === 'VOL' ? 'ml' : (quantityItem === 'WT' ? 'g' : 'g/ml');
		$qtyMode.empty();
		if (hasSgl) {
			$qtyMode.append($('<option>').val('sgl').text(sglNote || 'Count'));
		}
		$qtyMode.append($('<option>').val('base').text(unitLabel));
	}

	BitComponentTypeahead({
		input:     '#component_title',
		dropdown:  '#comp_dropdown',
		idField:   '#component_id',
		lookupUrl: '{$lookupUrl}',
		onReset:  function() { setQtyModeOptions(null, false, null); },
		onSelect: function(row) {
			var hasSgl = row.has_sgl;
			setQtyModeOptions(row.quantity_item, hasSgl, row.sgl_note);
			// Only prefill an empty Quantity — never overwrite something already typed
			// (e.g. picking a different supplier's version of an item after already
			// entering a known real quantity). SGL defaults to a single unit (the
			// component's own WT/VOL pack weight isn't a meaningful count) rather than
			// "default_qty", which is only ever the base-mode grams/ml figure.
			if (!$qty.val()) {
				var defaultQty = hasSgl ? 1 : row.default_qty;
				if (defaultQty) { $qty.val(defaultQty); }
			}
		}
	});
}(jQuery));
</script>
