{strip}
<td>{$xrefInfo.xref_title|escape}</td>
<td>
	{if $xrefInfo.xkey ne '' && ( $xrefInfo.xkey >= 1000 || $xrefInfo.xkey <= -1000 )}
		{($xrefInfo.xkey/1000)|string_format:"%.1f"}g
	{else}
		{$xrefInfo.xkey|escape}mg
	{/if}
	{$xrefInfo.xkey_ext|escape}
</td>
<td>{$xrefInfo.data|escape}</td>
{include file="bitpackage:liberty/xref/dates_cell.tpl"}
{include file="bitpackage:liberty/xref/action_icons.tpl"}
{/strip}
