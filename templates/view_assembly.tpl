{strip}
<div class="display food">
	<header>
		<div class="floaticon">
			{if $gContent->hasUpdatePermission()}
				<a title="{tr}Edit{/tr}" href="{$smarty.const.FOOD_PKG_URL}edit_assembly.php?content_id={$gContent->mContentId}">{biticon ipackage="icons" iname="edit" iexplain="Edit Meal"}</a>
			{/if}
		</div>
		<h1>{tr}View{/tr} {$mealLabel|escape}</h1>
		<small>{$gContent->getField('event_time')|bit_short_datetime}</small>
	</header>
	<div class="body">
		{if $items}
			<table class="table table-condensed">
				<thead>
					<tr>
						<th>{tr}Component{/tr}</th>
						<th>{tr}Quantity{/tr}</th>
					</tr>
				</thead>
				<tbody>
					{foreach $items as $i}
					<tr>
						<td>{$i.component_title|escape}</td>
						<td>{$i.quantity|escape}</td>
					</tr>
					{/foreach}
				</tbody>
			</table>
		{else}
			<p>{tr}No ingredients recorded.{/tr}</p>
		{/if}
	</div>
</div>
{/strip}
