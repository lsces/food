{strip}
<tr class="{cycle values="even,odd"}">
	<td>
		{if $xrefInfo.xref > 0}
			<a href="{$smarty.const.CONTACT_PKG_URL}view.php?content_id={$xrefInfo.xref|escape}">{$xrefInfo.linked_title|default:$xrefInfo.xref|escape}</a>
		{else}
			&nbsp;
		{/if}
	</td>
	<td>{$xrefInfo.xkey|escape}</td>
	<td>{$xrefInfo.xkey_ext|escape}</td>
	<td>{$xrefInfo.data|escape}</td>
	{if $xrefAllowEdit}
		<td>{$xrefInfo.start_date|bit_short_date}</td>
		<td>{$xrefInfo.last_update_date|bit_short_date}</td>
		<td>
			<span class="actionicon">
				{if $gContent->hasUpdatePermission() && !$isHistory}
					{smartlink ititle="Edit" ipackage="liberty" ifile="edit_xref.php" biticon="edit" content_id=$gContent->mInfo.content_id xref_id=$xrefInfo.xref_id}
				{/if}
				{if $gContent->hasExpungePermission()}
					{if $isHistory}
						{smartlink ititle="Restore" ipackage="liberty" ifile="edit_xref.php" biticon="edit" content_id=$gContent->mInfo.content_id xref_id=$xrefInfo.xref_id expunge=-1}
					{else}
						{smartlink ititle="Delete" ipackage="liberty" ifile="edit_xref.php" biticon="user-trash" content_id=$gContent->mInfo.content_id xref_id=$xrefInfo.xref_id expunge=1}
					{/if}
				{/if}
			</span>
		</td>
	{/if}
</tr>
{/strip}
