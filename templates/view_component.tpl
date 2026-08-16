{strip}
<div class="display food">
	<div class="header">
		<h1>{$gContent->getTitle()|escape}</h1>
	</div>
	<div class="body">
		{if $gXrefInfo && $gXrefInfo->mGroups}
			{jstabs}
				{foreach $gXrefInfo->mGroups as $group}
					{include file=$gContent->getXrefListTemplate($group->mTemplate) xrefGroup=$group allow_edit=true allow_add=true}
				{/foreach}
			{/jstabs}
		{/if}
		{if $gContent->hasUpdatePermission()}
			<p><a class="btn btn-default" href="edit_component.php?content_id={$gContent->mContentId}">{tr}Edit title{/tr}</a></p>
		{/if}
	</div>
</div>
{/strip}
