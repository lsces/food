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
	{include file="bitpackage:liberty/xref/dates_cell.tpl"}
	{include file="bitpackage:liberty/xref/action_icons.tpl"}
</tr>
{/strip}
