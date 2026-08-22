{strip}
<td>{$xrefInfo.xref_title|escape}</td>
<td>
	{assign var="jsonData" value=$xrefInfo.data|json_decode:true}
	{if $jsonData}
		<table class="table-condensed table-borderless" style="margin:0">
			{foreach $jsonData as $jkey => $jval}
				{if $jkey|substr:-4 eq '_mcg'}
					{assign var="jtitle" value=$jkey|substr:0:-4}
					{assign var="jshown" value=$jval}
					{assign var="junit" value="mcg"}
				{elseif $jkey|substr:-3 eq '_mg'}
					{assign var="jtitle" value=$jkey|substr:0:-3}
					{if $jval ne '' && ( $jval >= 1000 || $jval <= -1000 )}
						{assign var="jshown" value=($jval/1000)|string_format:"%.1f"}
						{assign var="junit" value="g"}
					{else}
						{assign var="jshown" value=$jval}
						{assign var="junit" value="mg"}
					{/if}
				{else}
					{assign var="jtitle" value=$jkey}
					{assign var="jshown" value=$jval}
					{assign var="junit" value=""}
				{/if}
				<tr><th style="padding-right:.5em">{$jtitle|replace:'_':' '|capitalize}</th><td>{$jshown|escape}{$junit}</td></tr>
			{/foreach}
		</table>
	{else}
		&nbsp;
	{/if}
</td>
<td>&nbsp;</td>
{include file="bitpackage:liberty/xref/dates_cell.tpl"}
{include file="bitpackage:liberty/xref/action_icons.tpl"}
{/strip}
