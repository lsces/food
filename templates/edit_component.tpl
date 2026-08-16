{strip}
<div class="edit food">
	<div class="header">
		<h1>{tr}Edit Component{/tr}{if $gContent->isValid()}: {$gContent->getTitle()|escape}{/if}</h1>
	</div>
	<div class="body">
		{formfeedback error=$errors}
		{form id="editComponentForm"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId|escape}" />

			<div class="form-group">
				{formlabel label="Title" for="title"}
				{forminput}
					<input type="text" class="form-control" name="title" id="title" value="{$gContent->getTitle()|escape}" />
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-primary" name="save" value="{tr}Save{/tr}" />
			</div>
		{/form}

		{if $gContent->isValid()}
			<p><a href="view_component.php?content_id={$gContent->mContentId}">{tr}Back to component{/tr}</a></p>
		{/if}
	</div>
</div>
{/strip}
