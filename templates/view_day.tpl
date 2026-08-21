{strip}
<div class="display food">
	<div class="header">
		<h1>{tr}Day{/tr}: {$dateStr}</h1>
	</div>
	<div class="body">
		{formfeedback error=$errors}

		<form method="get" class="form-inline" style="margin-bottom:1em">
			<a class="btn btn-default" title="{tr}Previous day{/tr}" href="{$smarty.const.FOOD_PKG_URL}view_day.php?date={$prevDateStr}">{biticon ipackage="icons" iname="go-previous" iexplain="Previous day"}</a>
			<input type="date" class="form-control" name="date" value="{$dateStr}" onchange="this.form.submit()" />
			<a class="btn btn-default" title="{tr}Next day{/tr}" href="{$smarty.const.FOOD_PKG_URL}view_day.php?date={$nextDateStr}">{biticon ipackage="icons" iname="go-next" iexplain="Next day"}</a>
		</form>

		<div class="panel panel-primary">
			<div class="panel-heading">{tr}Day total{/tr}</div>
			<div class="panel-body table-responsive">
				<table class="table table-condensed">
					<thead>
						<tr>
							{foreach $nutritionFields as $key => $meta}
								<th>{$meta.label|escape}</th>
							{/foreach}
						</tr>
					</thead>
					<tbody>
						<tr>
							{foreach $nutritionFields as $key => $meta}
								<td>{$dayTotal[$key]|escape}</td>
							{/foreach}
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		{foreach $slots as $slot}
			<div class="panel panel-default">
				<div class="panel-heading">
					{if $slot.content_id}
						<div class="floaticon">
							<a title="{tr}View{/tr}" href="view_assembly.php?content_id={$slot.content_id}">{biticon ipackage="icons" iname="view-preview" iexplain="View"}</a>
							<a title="{tr}Edit{/tr}" href="edit_assembly.php?content_id={$slot.content_id}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a>
							{if $canCreate}
								<a title="{tr}Copy to another date{/tr}" href="copy_assembly.php?content_id={$slot.content_id}">{biticon ipackage="icons" iname="edit-copy" iexplain="Copy to another date"}</a>
							{/if}
						</div>
					{/if}
					{$slot.label|escape}&nbsp;
					{if $slot.content_id}
						<small class="text-muted">
							&mdash;&nbsp;
							{foreach $nutritionFields as $key => $meta name=nf}
								{$meta.label|escape} {$slot.nutrition_total[$key]|escape}{if !$smarty.foreach.nf.last}&nbsp;&middot;&nbsp;{/if}
							{/foreach}
						</small>
					{/if}
				</div>
				<div class="panel-body">
					{if $slot.content_id}
						{if $slot.items}
							<div class="table-responsive">
								<table class="table table-condensed">
									<thead>
										<tr>
											<th>{tr}Item{/tr}</th>
											<th>{tr}Qty{/tr}</th>
											{foreach $nutritionFields as $key => $meta}
												<th>{$meta.label|escape}</th>
											{/foreach}
										</tr>
									</thead>
									<tbody>
										{foreach $slot.items as $i}
										<tr>
											<td><a href="{$i.component_display_url|escape}">{$i.component_title|escape}</a></td>
											<td>{$i.quantity|escape}{$i.quantity_unit|escape}</td>
											{foreach $nutritionFields as $key => $meta}
												<td>{$i.nutrition[$key]|escape}</td>
											{/foreach}
										</tr>
										{/foreach}
									</tbody>
								</table>
							</div>
						{else}
							<p>{tr}No ingredients yet.{/tr}</p>
						{/if}
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
