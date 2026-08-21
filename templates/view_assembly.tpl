{strip}
<div class="display food">
	<header>
		<div class="floaticon">
			{if $gContent->hasUpdatePermission()}
				<a title="{tr}Edit{/tr}" href="{$smarty.const.FOOD_PKG_URL}edit_assembly.php?content_id={$gContent->mContentId}">{biticon ipackage="icons" iname="edit" iexplain="Edit Meal"}</a>
			{/if}
			{if $gContent->hasCreatePermission()}
				<a title="{tr}Copy to another date{/tr}" href="{$smarty.const.FOOD_PKG_URL}copy_assembly.php?content_id={$gContent->mContentId}">{biticon ipackage="icons" iname="edit-copy" iexplain="Copy to another date"}</a>
			{/if}
		</div>
		<h1>{tr}View{/tr} {$mealLabel|escape}</h1>
		<small><a href="{$smarty.const.FOOD_PKG_URL}view_day.php?date={$dateStr|escape}">{$gContent->getField('event_time')|bit_short_datetime}</a></small>
	</header>
	<div class="body">
		{if $items}
			<div class="table-responsive">
				<table class="table table-condensed">
					<thead>
						<tr>
							<th>{tr}Component{/tr}</th>
							<th>{tr}Quantity{/tr}</th>
							{foreach $nutritionFields as $key => $meta}
								<th>{$meta.label|escape}</th>
							{/foreach}
						</tr>
					</thead>
					<tbody>
						{foreach $items as $i}
						<tr>
							<td><a href="{$i.component_display_url|escape}">{$i.component_title|escape}</a></td>
							<td>{$i.quantity|escape}{$i.quantity_unit|escape}</td>
							{foreach $nutritionFields as $key => $meta}
								<td>{$i.nutrition[$key]|escape}</td>
							{/foreach}
						</tr>
						{/foreach}
					</tbody>
					<tfoot>
						<tr>
							<th>{tr}Total{/tr}</th>
							<th></th>
							{foreach $nutritionFields as $key => $meta}
								<th>{$nutritionTotal[$key]|escape}</th>
							{/foreach}
						</tr>
					</tfoot>
				</table>
			</div>
		{else}
			<p>{tr}No ingredients recorded.{/tr}</p>
		{/if}
	</div>
</div>
{/strip}
