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
		<p>{tr}Mark a component done by setting its REM xref's xkey_ext to{/tr} <code>CORRECT</code>.</p>

		{if $corrections}
			<table class="table table-condensed">
				<thead>
					<tr>
						<th>{tr}Title{/tr}</th>
						<th>{tr}Meals affected{/tr}</th>
						<th>{tr}Note{/tr}</th>
					</tr>
				</thead>
				<tbody>
					{foreach $corrections as $c}
					{* No view_component.php yet — plain text + content_id until an edit/view page exists *}
					<tr>
						<td>{$c.title|escape} <small>(#{$c.content_id})</small></td>
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
