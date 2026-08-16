{strip}
<div class="edit food">
	<div class="header">
		<h1>{tr}Add Ingredient{/tr}: {$mealLabel|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}

		{form id="addComponentForm" ipackage="food" ifile="add_assembly_item.php"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />

			<div class="form-group">
				{formlabel label="Component" for="component_title" mandatory="y"}
				{forminput}
					<div style="position:relative">
						<input type="text" class="form-control" name="component_title" id="component_title"
							autocomplete="off"
							value="{$smarty.request.component_title|default:''|escape}"
							placeholder="{tr}Type to search…{/tr}" />
						<ul id="comp_dropdown" class="dropdown-menu"
							style="display:none;position:absolute;width:100%;z-index:1000;max-height:220px;overflow-y:auto"></ul>
					</div>
					{formhelp note="Type to search existing components, or enter a new title to create one."}
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Quantity (g/ml)" for="xkey"}
				{forminput}
					<input type="text" class="form-control" name="xkey" id="xkey"
						value="{$smarty.request.xkey|default:''|escape}" />
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel" value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="fAddComponent" value="{tr}Add Ingredient{/tr}" />
			</div>
		{/form}
	</div>
</div>
{/strip}
<script>
(function($) {
	var timer;
	var $input = $('#component_title');
	var $dd    = $('#comp_dropdown');

	$input.on('input', function() {
		var q = $(this).val();
		clearTimeout(timer);
		$dd.hide().empty();
		if (q.length < 2) return;
		timer = setTimeout(function() {
			$.getJSON('{$lookupUrl}', {ldelim}q: q{rdelim}, function(data) {
				if (!data.length) return;
				$.each(data, function(i, row) {
					$dd.append($('<li>').append(
						$('<a>').attr('href','#').data('title', row.title).text(row.title)
					));
				});
				$dd.show();
			});
		}, 250);
	});

	$(document).on('mousedown', '#comp_dropdown a', function(e) {
		e.preventDefault();
		$input.val($(this).data('title'));
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
