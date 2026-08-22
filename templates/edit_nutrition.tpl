{strip}
<div class="edit food">
	<div class="header">
		<h1>{tr}Edit Nutrition{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>

	<div class="body">
		{form id="editNutritionForm"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />

			{foreach $scalarFields as $item => $meta}
				{if $meta.kind eq 'scalar'}
					<div class="form-group">
						{formlabel label=$meta.label for="val_`$item`"}
						{forminput}
							<input type="text" class="form-control input-small" name="val_{$item}" id="val_{$item}"
								value="{$displayValues[$item]|default:''|escape}" />
							{if $meta.suffix} <span class="help-inline">{$meta.suffix}</span>{/if}
						{/forminput}
					</div>
				{elseif $meta.kind eq 'fat'}
					<div class="form-group">
						{formlabel label="Fat, total (g)" for="fat_total"}
						{forminput}
							<input type="text" class="form-control input-small" name="fat_total" id="fat_total" value="{$existingFat.total_g|escape}" />
						{/forminput}
					</div>
					<div class="form-group">
						{formlabel label="Fat, saturated (g)" for="fat_saturated"}
						{forminput}
							<input type="text" class="form-control input-small" name="fat_saturated" id="fat_saturated" value="{$existingFat.saturated_g|escape}" />
							{formhelp note="Mono/poly/trans fat and cholesterol aren't edited here — use the Fat row's own Edit link on the Nutrition tab for those."}
						{/forminput}
					</div>
				{elseif $meta.kind eq 'salt'}
					<div class="form-group">
						{formlabel label="Salt (g)" for="sod_salt"}
						{forminput}
							<input type="text" class="form-control input-small" name="sod_salt" id="sod_salt" value="" />
							{formhelp note="UK label value. If filled in, this is used and converted (salt ÷ 2.5 = sodium) — the Sodium field below is ignored."}
						{/forminput}
					</div>
					<div class="form-group">
						{formlabel label="Sodium (mg)" for="sod_sodium"}
						{forminput}
							<input type="text" class="form-control input-small" name="sod_sodium" id="sod_sodium" value="{$existing.SOD.xkey|default:''|escape}" />
							{formhelp note="Used only if Salt above is left blank."}
						{/forminput}
					</div>
				{/if}
			{/foreach}

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel"        value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="fSaveNutrition" value="{tr}Save{/tr}" />
			</div>
		{/form}

		<p>{tr}Fat's mono/poly/trans/cholesterol sub-fields, plus Vitamins/Minerals, have their own combined edit — use the row's own Edit link on the Nutrition tab for those.{/tr}</p>
	</div>
</div>
{/strip}
