{strip}
<div class="edit liberty">
	<div class="header">
		<h1>{tr}Edit{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>
	<div class="body">
		{formfeedback error=$errors}
		{form id="editXrefForm"}
			<input type="hidden" name="content_id" value="{$xrefInfo.content_id|escape}" />
			<input type="hidden" name="xref_id"    value="{$xrefInfo.xref_id|escape}" />
			<input type="hidden" name="item"       value="{$xrefInfo.item|escape}" />

			<div class="form-group">
				{formlabel label="Type"}
				{forminput}
					<p class="form-control-static">{$xrefInfo.template_title|escape}</p>
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Salt (g)" for="sod_salt"}
				{forminput}
					<input type="text" class="form-control input-small" name="sod_salt" id="sod_salt" value="" />
					{formhelp note="UK label value. If filled in, this is used and converted (salt \xf7 2.5 = sodium) — the Sodium field below is ignored."}
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Sodium (mg)" for="sod_sodium"}
				{forminput}
					<input type="text" class="form-control input-small" name="sod_sodium" id="sod_sodium" value="{$xrefInfo.xkey|escape}" />
					{formhelp note="Used only if Salt above is left blank."}
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Notes" for="xkey_ext"}
				{forminput}
					<input type="text" class="form-control input-small" name="xkey_ext" id="xkey_ext" value="{$xrefInfo.xkey_ext|escape}" />
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Detail" for="edit"}
				{forminput}
					<textarea class="form-control" name="edit" id="edit" rows="4">{$xrefInfo.data|escape}</textarea>
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel"   value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="fSaveXref" value="{tr}Save{/tr}" />
			</div>
		{/form}
	</div>
</div>
{/strip}
