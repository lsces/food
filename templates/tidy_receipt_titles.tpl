{strip}
<div class="display food">
	<div class="header">
		<h1>{tr}Tidy Receipt Titles{/tr}{if !$apply} ({tr}dry run{/tr}){/if}</h1>
	</div>
	<div class="body">
		<p>
			<strong>{$changes|@count}</strong> {if $apply}{tr}processed{/tr}{else}{tr}would be renamed{/tr}{/if}.
			<strong>{$unchanged}</strong> {tr}already tidy (left alone){/tr}.
		</p>

		{if !$apply && $changes}
			<p><a class="btn btn-warning" href="?apply=1" onclick="return confirm('{tr}Rename all of these receipts?{/tr}');">{tr}Apply renames{/tr}</a></p>
		{/if}

		{if $changes}
			<table class="table table-condensed">
				<thead><tr><th>{tr}Receipt{/tr}</th><th>{tr}Old title{/tr}</th><th>{tr}New title{/tr}</th><th>{tr}Status{/tr}</th></tr></thead>
				<tbody>
					{foreach $changes as $c}
					<tr class="{if $c.status eq 'renamed'}success{elseif $c.status eq 'FAILED'}danger{/if}">
						<td><a href="{$smarty.const.FOOD_PKG_URL}view_movement.php?content_id={$c.content_id}">{$c.content_id}</a></td>
						<td>{$c.old|escape}</td>
						<td>{$c.new|escape}</td>
						<td>{$c.status|escape}</td>
					</tr>
					{/foreach}
				</tbody>
			</table>
		{/if}
	</div>
</div>
{/strip}
