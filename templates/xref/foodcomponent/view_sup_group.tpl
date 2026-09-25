{assign var=xrefAllowEdit value=$allow_edit|default:false}
{assign var=isHistory value=($xrefGroup->mXGroup eq 'history')}
{jstab title="`$xrefGroup->mTitle` ({$xrefGroup->mXrefs|@count})"}
{legend legend=$xrefGroup->mTitle}
<div class="form-group table-responsive">
	<table class="table table-condensed">
		<thead>
			<tr>
				<th>{tr}Supplier{/tr}</th>
				<th>{tr}Product Code{/tr}</th>
				<th>{tr}Price{/tr}</th>
				<th>{tr}Note{/tr}</th>
				{if $xrefAllowEdit}<th>{tr}Added{/tr}</th><th>{tr}Updated{/tr}</th><th>{tr}Edit{/tr}</th>{/if}
			</tr>
		</thead>
		<tbody>
			{if $xrefGroup->mXrefs}
				{foreach $xrefGroup->mXrefs as $xrefInfo}
					{include file=$gContent->getXrefRecordTemplate($xrefInfo.template)}
				{/foreach}
			{else}
				<tr class="norecords">
					<td colspan="{if $xrefAllowEdit}7{else}4{/if}">{tr}No supplier records found{/tr}</td>
				</tr>
			{/if}
		</tbody>
	</table>
</div>
{if $allow_add && $gContent->isValid() && $gContent->hasUpdatePermission() && !$isHistory}
	<div>
		{smartlink ititle="Add supplier" ipackage="food" ifile="add_supplier.php" biticon="list-add" content_id=$gContent->mInfo.content_id}
	</div>
{/if}
{/legend}
{/jstab}
