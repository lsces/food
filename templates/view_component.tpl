{strip}
<div class="display food">
	<header>
		<div class="floaticon">
			{if $gContent->hasUpdatePermission()}
				<a title="{tr}Edit{/tr}" href="{$smarty.const.FOOD_PKG_URL}edit_component.php?content_id={$gContent->mContentId}">{biticon ipackage="icons" iname="edit" iexplain="Edit Component"}</a>
			{/if}
		</div>
		<h1>{$gContent->getTitle()|escape}</h1>
	</header>
	<div class="body">
		{if $gContent->mInfo.data ne ''}
			<div class="description">{$gContent->mInfo.parsed_data}</div>
		{/if}

		<div class="table-responsive">
			<table class="table table-condensed">
				<caption>{tr}Nutrition (per 100g){/tr}</caption>
				<thead>
					<tr>
						{foreach $nutritionFields as $key => $meta}
							<th>{$meta.label|escape}</th>
						{/foreach}
					</tr>
				</thead>
				<tbody>
					<tr>
						{foreach $nutritionFields as $key => $meta}
							<td>{$nutritionSummary[$key]|escape}</td>
						{/foreach}
					</tr>
				</tbody>
			</table>
		</div>

		{if $gXrefInfo && $gXrefInfo->mGroups}
			{jstabs}
				{foreach $gXrefInfo->mGroups as $group}
					{include file=$gContent->getXrefListTemplate($group->mTemplate) xrefGroup=$group allow_edit=false}
				{/foreach}
			{/jstabs}
		{/if}
	</div>
</div>
{/strip}
