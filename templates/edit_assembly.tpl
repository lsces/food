{strip}
<div class="edit food">
	<div class="header">
		<h1>{tr}Edit{/tr} {$mealLabel|escape}</h1>
	</div>
	<div class="body">
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
				<input type="submit" class="btn btn-primary" name="save" value="{tr}Save{/tr}" />
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
					<td>{$i.quantity|escape}{$i.quantity_unit|escape}</td>
					<td>
						<span class="actionicon">
							{smartlink ititle="Edit" ipackage="liberty" ifile="edit_xref.php" biticon="edit" content_id=$gContent->mContentId xref_id=$i.xref_id}
							{smartlink ititle="Remove" ipackage="liberty" ifile="edit_xref.php" biticon="user-trash" content_id=$gContent->mContentId xref_id=$i.xref_id expunge=3}
						</span>
					</td>
				</tr>
				{/foreach}
			</tbody>
		</table>

		<p><a class="btn btn-default" href="add_assembly_item.php?content_id={$gContent->mContentId}">{tr}Add Food Item{/tr}</a></p>
	</div>
</div>
{/strip}
