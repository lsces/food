{strip}
<div class="edit food">
	<header>
		{if $gContent->isValid() && $gContent->isFlaggedForReview()}
			<div class="floaticon">
				<a title="{tr}Mark review done{/tr}" href="edit_component.php?content_id={$gContent->mContentId}&amp;clear_review=1">{biticon ipackage="icons" iname="dialog-ok-apply" iexplain="Mark review done"}</a>
			</div>
		{/if}
		<h1>{tr}Edit Component{/tr}{if $gContent->isValid()}: {$gContent->getTitle()|escape}{/if}</h1>
	</header>
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

			<div class="form-group">
				{formlabel label="Notes" for="component-notes"}
				{forminput}
					<textarea name="edit" class="form-control" id="component-notes" rows="4">{$gContent->mInfo.data|escape}</textarea>
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-primary" name="save" value="{tr}Save{/tr}" />
			</div>
		{/form}

		{if $gContent->isValid()}
			<p><a href="view_component.php?content_id={$gContent->mContentId}">{tr}Back to component{/tr}</a></p>
		{/if}

		{if $gXrefInfo && $gXrefInfo->mGroups}
			{jstabs}
				{foreach $gXrefInfo->mGroups as $group}
					{include file=$gContent->getXrefListTemplate($group->mTemplate) xrefGroup=$group allow_edit=true allow_add=true}
				{/foreach}
			{/jstabs}
		{/if}

		{if $gContent->isValid() && $gContent->hasExpungePermission()}
			<fieldset class="merge-duplicate">
				<legend>{tr}Merge duplicate{/tr}</legend>
				{form id="mergeComponentForm"}
					<input type="hidden" name="content_id" value="{$gContent->mContentId}" />
					<div class="form-inline">
						<div class="form-group">
							{formlabel label="Merge into content_id" for="merge_target_id"}
							{forminput}
								<input type="text" class="form-control input-small" name="merge_target_id" id="merge_target_id" placeholder="{tr}e.g. 97{/tr}" />
							{/forminput}
						</div>
						<button type="submit" class="btn btn-danger" name="fMerge" value="1">{tr}Merge and delete this{/tr}</button>
					</div>
					{formhelp note="Repoints every reference to THIS component (meals, receipts) onto the target, then permanently deletes this one. Enter the good copy's content_id — no search yet, check its URL/edit page for the number."}
				{/form}
			</fieldset>
		{/if}
	</div>
</div>
{/strip}
