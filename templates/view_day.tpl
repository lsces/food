{strip}
<div class="display food">
	<div class="header">
		<h1>{tr}Day{/tr}: {$dateStr}</h1>
	</div>
	<div class="body">
		{formfeedback error=$errors}

		<form method="get" class="form-inline" style="margin-bottom:1em">
			<input type="date" class="form-control" name="date" value="{$dateStr}" />
			<input type="submit" class="btn btn-default" value="{tr}Go{/tr}" />
		</form>

		{foreach $slots as $slot}
			<div class="panel panel-default">
				<div class="panel-heading">{$slot.label|escape}</div>
				<div class="panel-body">
					{if $slot.content_id}
						{if $slot.items}
							<table class="table table-condensed">
								<tbody>
									{foreach $slot.items as $i}
									<tr>
										<td>{$i.component_title|escape}</td>
										<td>{$i.quantity|escape}</td>
									</tr>
									{/foreach}
								</tbody>
							</table>
						{else}
							<p>{tr}No ingredients yet.{/tr}</p>
						{/if}
						<a href="view_assembly.php?content_id={$slot.content_id}">{tr}View{/tr}</a>
						&nbsp;|&nbsp;
						<a href="edit_assembly.php?content_id={$slot.content_id}">{tr}Edit{/tr}</a>
					{else}
						<p>{tr}Not logged.{/tr}</p>
						{if $canCreate}
							{form method="post"}
								<input type="hidden" name="date" value="{$dateStr}" />
								<input type="hidden" name="create_type" value="{$slot.code}" />
								<input type="submit" class="btn btn-default btn-sm" value="{tr}Log{/tr} {$slot.label|escape}" />
							{/form}
						{/if}
					{/if}
				</div>
			</div>
		{/foreach}
	</div>
</div>
{/strip}
