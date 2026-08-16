{strip}
<div class="display food">
	<div class="header">
		<h1>{$page_title|default:"Import Results"|escape}</h1>
	</div>
	<div class="body">
		<p>{tr}File{/tr}: <code>{$csvFile|escape}</code></p>

		<p>
			<strong>{$created}</strong> {tr}created{/tr}.
			<strong>{$updated}</strong> {tr}updated{/tr}.
			<strong>{$unchanged}</strong> {tr}unchanged{/tr}.
			<strong>{$skipped}</strong> {tr}skipped{/tr}.
		</p>

		{if $errors}
			<h3>{tr}Errors{/tr}</h3>
			<ul>
				{foreach $errors as $msg}
					<li>{$msg|escape}</li>
				{/foreach}
			</ul>
		{/if}

		{if $flagged}
			<h3>{tr}Flagged for curation{/tr}</h3>
			<p>{tr}Also written to{/tr} <code>storage/food/curation_needed.csv</code></p>
			<table class="table table-condensed">
				<thead><tr><th>{tr}Title{/tr}</th><th>{tr}Reason{/tr}</th></tr></thead>
				<tbody>
					{foreach $flagged as $f}
					<tr>
						<td>{$f.title|escape}</td>
						<td>{$f.reason|escape}</td>
					</tr>
					{/foreach}
				</tbody>
			</table>
		{/if}
	</div>
</div>
{/strip}
