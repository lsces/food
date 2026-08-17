{strip}
<div class="edit food">
	<div class="header">
		<h1>{tr}Edit Supplier{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>
	<div class="body">
		{formfeedback error=$errors}
		{form id="editXrefForm"}
			<input type="hidden" name="content_id" value="{$xrefInfo.content_id|escape}" />
			<input type="hidden" name="xref_id"    value="{$xrefInfo.xref_id|escape}" />
			<input type="hidden" name="item"       value="{$xrefInfo.item|escape}" />
			<input type="hidden" name="xorder"     value="{$xrefInfo.xorder|escape}" />

			{if $xrefInfo.xref}
			<div class="form-group">
				{formlabel label="Supplier"}
				{forminput}
					<p class="form-control-static">
						<a href="{$smarty.const.CONTACT_PKG_URL}view.php?content_id={$xrefInfo.xref|escape}">{$xrefInfo.linked_title|default:$xrefInfo.xref|escape}</a>
					</p>
				{/forminput}
			</div>
			{/if}

			<div class="form-group">
				{formlabel label="Product Code" for="xkey"}
				{forminput}
					<input type="text" class="form-control input-small" name="xkey" id="xkey" value="{$xrefInfo.xkey|escape}" />
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Price" for="xkey_ext"}
				{forminput}
					<input type="text" class="form-control input-small" name="xkey_ext" id="xkey_ext" value="{$xrefInfo.xkey_ext|escape}" />
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Note" for="edit"}
				{forminput}
					<input type="text" class="form-control" name="edit" id="edit" value="{$xrefInfo.data|escape}" />
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
