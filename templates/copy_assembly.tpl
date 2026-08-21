{strip}
<div class="edit food">
	<div class="header">
		<h1>{tr}Copy{/tr} {$mealLabel|escape} {tr}to another date{/tr}</h1>
	</div>
	<div class="body">
		{formfeedback error=$errors}

		{if $items}
			<ul>
				{foreach $items as $i}
					<li>{$i.component_title|escape} — {$i.quantity|escape}{$i.quantity_unit|escape}</li>
				{/foreach}
			</ul>

			{form id="copyAssemblyForm"}
				<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
				<div class="form-group">
					{formlabel label="Date" for="date"}
					{forminput}
						<input type="date" class="form-control input-small" name="date" id="date" value="{$dateValue|escape}" required="required" />
						{formhelp note="Same time of day as this meal, on the date you pick here."}
					{/forminput}
				</div>
				<div class="form-group submit">
					<input type="submit" class="btn btn-primary" name="save" value="{tr}Copy{/tr}" />
				</div>
			{/form}
		{else}
			<p>{tr}This meal has no ingredients to copy.{/tr}</p>
		{/if}

		<p><a href="view_assembly.php?content_id={$gContent->mContentId}">{tr}Back to meal{/tr}</a></p>
	</div>
</div>
{/strip}
