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
	var timer;
	var seq = 0; // request-generation counter — see reqId below
	var $input   = $('#component_title');
	var $dd      = $('#comp_dropdown');
	var $id      = $('#component_id');
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

	$input.on('input', function() {
		// Any manual retyping invalidates whatever was previously selected —
		// otherwise a stale component_id could silently survive a hand-edit and
		// point at the wrong (same-titled, different-supplier) component.
		$id.val('');
		setQtyModeOptions(null, false, null);
		var q = $(this).val();
		clearTimeout(timer);
		$dd.hide().empty();
		if (q.length < 2) return;
		// clearTimeout above only cancels a fetch that hasn't fired yet — an
		// already-in-flight one from a previous keystroke can still land after
		// this one and, without this check, get appended on top instead of
		// replacing it (duplicate-looking rows from two overlapping responses,
		// not a duplicate in the data — real bug hit 2026-08-21, reproducible
		// only as a race, never via a direct query no matter the data state).
		var reqId = ++seq;
		timer = setTimeout(function() {
			$.getJSON('{$lookupUrl}', {ldelim}q: q{rdelim}, function(data) {
				if (reqId !== seq) return; // a newer request has since superseded this one
				$dd.empty();
				if (!data.length) return;
				$.each(data, function(i, row) {
					var label = row.supplier ? row.title + ' [' + row.supplier + ']' : row.title;
					$dd.append($('<li>').append(
						$('<a>').attr('href','#')
							.data('id', row.content_id)
							.data('label', label)
							.data('qty', row.default_qty)
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
		var hasSgl = $(this).data('has-sgl');
		setQtyModeOptions($(this).data('quantity-item'), hasSgl, $(this).data('sgl-note'));
		// Only prefill an empty Quantity — never overwrite something already typed
		// (e.g. picking a different supplier's version of an item after already
		// entering a known real quantity). SGL defaults to a single unit (the
		// component's own WT/VOL pack weight isn't a meaningful count) rather than
		// the "qty" data value, which is only ever the base-mode grams/ml figure.
		if (!$qty.val()) {
			var defaultQty = hasSgl ? 1 : $(this).data('qty');
			if (defaultQty) { $qty.val(defaultQty); }
		}
		$dd.hide().empty();
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
