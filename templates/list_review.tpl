{strip}
<div class="display food">
	<div class="header">
		<h1>{$page_title|default:"Food Curation Progress"|escape}</h1>
	</div>
	<div class="body">
		<p>
			<strong>{$outstanding}</strong> {tr}of{/tr} <strong>{$totalFlagged}</strong>
			{tr}flagged components still outstanding{/tr}
			{if $totalFlagged}({$percentDone}% {tr}done{/tr}){/if}.
		</p>
		<p>{tr}Mark a component done: open it and click the tick icon once it's fully fixed.{/tr}</p>

		{if $reviewItems}
			<table class="table table-condensed">
				<thead>
					<tr>
						<th>{tr}Title{/tr}</th>
						<th>{tr}Meals affected{/tr}</th>
						<th>{tr}Note{/tr}</th>
					</tr>
				</thead>
				<tbody>
					{foreach $reviewItems as $c}
					<tr>
						<td><a href="view_component.php?content_id={$c.content_id}">{$c.title|escape}</a></td>
						<td>{$c.usage_count}</td>
						<td>{$c.note|escape}</td>
					</tr>
					{/foreach}
				</tbody>
			</table>
		{else}
			<p>{tr}Nothing outstanding.{/tr}</p>
		{/if}
	</div>
</div>
{/strip}
