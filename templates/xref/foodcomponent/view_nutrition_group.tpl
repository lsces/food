{* Copy of liberty/templates/list_xref.tpl with one addition: an "Edit all" icon
   next to "Add record", linking to edit_nutrition.php — the combined one-form
   edit for every scalar nutrition item (see admin/schema_inc.php's nutrition
   group comment for why). Keep row rendering identical to the generic template
   so FAT/VIT/MIN/SOD's own item templates still work exactly as before — this
   only adds the one link, it doesn't change how any row displays. *}
{assign var=xrefAllowEdit value=$allow_edit|default:true}
{assign var=tabTitle value=$xrefGroup->mTitle}
{assign var=isHistory value=($xrefGroup->mXGroup eq 'history')}
{jstab title="`$tabTitle` ({$xrefGroup->mXrefs|@count})"}
{legend legend=$tabTitle}
<div class="form-group table-responsive">
	<table class="table table-condensed">
		<thead>
			<tr>
				<th>{tr}Type{/tr}</th>
				<th>{tr}Value{/tr}</th>
				<th>{tr}Notes{/tr}</th>
				{if $xrefAllowEdit}
					{if $isHistory}<th>{tr}Ended{/tr}</th>{else}<th>{tr}Started{/tr}</th>{/if}
					<th>{tr}Updated{/tr}</th>
					<th>{tr}Edit{/tr}</th>
				{/if}
			</tr>
		</thead>
		<tbody>
			{if $xrefGroup->mXrefs}
				{foreach $xrefGroup->mXrefs as $xrefInfo}
					<tr class="{cycle values="even,odd"}">
						{include file=$gContent->getXrefRecordTemplate($xrefInfo.template)}
					</tr>
				{/foreach}
			{else}
				<tr class="norecords">
					<td colspan="{if $xrefAllowEdit}6{else}3{/if}">{tr}No {$tabTitle} records found{/tr}</td>
				</tr>
			{/if}
		</tbody>
	</table>
</div>
{if $allow_add && $gContent->isValid() && $gContent->hasUpdatePermission() && !$isHistory}
	<div>
		{smartlink ititle="Add record" ipackage="liberty" ifile="add_xref.php" biticon="list-add" content_id=$gContent->mInfo.content_id group=$xrefGroup->mSortOrder}
		<a title="{tr}Edit all nutrition fields at once{/tr}" href="{$smarty.const.FOOD_PKG_URL}edit_nutrition.php?content_id={$gContent->mInfo.content_id}">{biticon ipackage="icons" iname="edit" iexplain="Edit all nutrition fields"}</a>
	</div>
{/if}
{/legend}
{/jstab}
