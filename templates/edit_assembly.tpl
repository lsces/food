{strip}
<div class="edit food">
	<div class="header">
		<h1>{tr}Edit{/tr} {$mealLabel|escape}</h1>
	</div>
	<div class="body">
		{formfeedback error=$errors}

		{if $mealTypes|@count > 1}
			{form id="editAssemblyForm"}
				<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
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
				<div class="form-group submit">
					<input type="submit" class="btn btn-primary" name="save" value="{tr}Save{/tr}" />
				</div>
			{/form}
		{/if}

		<table class="table table-condensed">
			<thead>
				<tr>
					<th>{tr}Component{/tr}</th>
					<th>{tr}Quantity{/tr}</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				{foreach $items as $i}
				<tr>
					<td>{$i.component_title|escape}</td>
					<td>{$i.quantity|escape}</td>
					<td>
						<span class="actionicon">
							{smartlink ititle="Edit" ipackage="liberty" ifile="edit_xref.php" biticon="edit" content_id=$gContent->mContentId xref_id=$i.xref_id}
							{smartlink ititle="Remove" ipackage="liberty" ifile="edit_xref.php" biticon="user-trash" content_id=$gContent->mContentId xref_id=$i.xref_id expunge=1}
						</span>
					</td>
				</tr>
				{/foreach}
			</tbody>
		</table>

		<p><a class="btn btn-default" href="add_assembly_item.php?content_id={$gContent->mContentId}">{tr}Add ingredient{/tr}</a></p>
		<p><a href="view_assembly.php?content_id={$gContent->mContentId}">{tr}Back to meal{/tr}</a></p>
	</div>
</div>
{/strip}
