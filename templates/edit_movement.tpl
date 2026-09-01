{strip}
<div class="edit food">
	<header>
		{if $gContent->isValid()}
			<div class="floaticon">
				<a title="{tr}Back to receipt{/tr}" href="view_movement.php?content_id={$gContent->mContentId}">{biticon ipackage="icons" iname="go-previous" iexplain="Back to receipt"}</a>
			</div>
		{/if}
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
					<input type="date" class="form-control input-small" name="purchase_date" id="purchase_date" value="{$purchaseDateVal|escape}" />
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Note" for="note"}
				{forminput}
					<input type="text" class="form-control" name="note" id="note" value="{$gContent->mInfo.ref_note|default:''|escape}" />
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-primary" name="save" value="{tr}Save details{/tr}" />
			</div>
		{/form}

		{if $gContent->isValid()}
			<table class="table table-condensed">
				<thead>
					<tr>
						<th>{tr}Food Item{/tr}</th>
						<th>{tr}Quantity{/tr}</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					{foreach $lines as $l}
					<tr>
						<td><a href="{$l.component_display_url|escape}">{$l.component_title|escape}</a></td>
						<td>
							{form id="qty-{$l.xref_id}" ipackage="food" ifile="edit_movement.php" class="form-inline" style="display:inline"}
								<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
								<input type="hidden" name="update_xref_id" value="{$l.xref_id}" />
								<input type="text" class="form-control input-sm" name="new_quantity" value="{$l.quantity|escape}" style="width:5em;display:inline-block" />
								<button type="submit" class="btn btn-link btn-xs" title="{tr}Save{/tr}">{biticon iname="filesave" iexplain="Save"}</button>{$l.quantity_unit|escape}
							{/form}
						</td>
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
					<input type="hidden" name="component_id" id="component_id" value="" />
					<div class="form-inline">
						<div class="form-group" style="position:relative">
							<input type="text" class="form-control" name="component_title" id="component_title"
								autocomplete="off" placeholder="{tr}Food Item…{/tr}" />
							<ul id="comp_dropdown" class="dropdown-menu"
								style="display:none;position:absolute;width:390px;z-index:1000;max-height:220px;overflow-y:auto"></ul>
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
					{formhelp note="Type to search existing food items, or enter a new title to create one. Where the same title exists from more than one shop, the supplier shows in brackets — pick the right one rather than retyping the plain title. Adding a line returns you straight here, ready for the next one."}
				{/form}
			</div>
		{/if}
	</div>
</div>
{/strip}
{if $gContent->isValid()}
<script>
(function($) {
	var $qtyMode = $('#qty_mode');

	function setQtyModeOptions(quantityItem, hasSgl, sglNote) {
		var unitLabel = quantityItem === 'VOL' ? 'ml' : (quantityItem === 'WT' ? 'g' : 'g/ml');
		$qtyMode.empty();
		if (hasSgl) {
			// Appended first (and so selected by default, no explicit .prop('selected')
			// needed) - SGL is the common case, WT/VOL the exception, and defaulting to
			// SGL when it's available saves a click on every normal add. Labelled with
			// the component's own SGL note (e.g. "Ready Meal", "Pack of 8" — see
			// list_pantry.php's Note column, same xkey_ext field) rather than a generic
			// "count", so the two-tab workflow (list_components in one tab, this receipt
			// in the other) doesn't need cross-checking which category a component was
			// tagged with. Falls back to "Count" if SGL is flagged but no note has been
			// added yet.
			$qtyMode.append($('<option>').val('sgl').text(sglNote || 'Count'));
		}
		$qtyMode.append($('<option>').val('base').text(unitLabel));
	}

	BitComponentTypeahead({
		input:     '#component_title',
		dropdown:  '#comp_dropdown',
		idField:   '#component_id',
		lookupUrl: '{$smarty.const.FOOD_PKG_URL}includes/lookup_component.php',
		autofocus: true,
		// Scoped to whichever shop is currently selected on the receipt, if
		// any — read live at query time so switching the Shop dropdown mid-
		// receipt re-scopes the very next search without a page reload.
		extraParams: function() { return { shop: $('#shop_content_id').val() }; },
		onReset:  function() { setQtyModeOptions(null, false, null); },
		onSelect: function(row) {
			setQtyModeOptions(row.quantity_item, row.has_sgl, row.sgl_note);
			$('#quantity').trigger('focus');
		}
	});
}(jQuery));
</script>
{/if}
