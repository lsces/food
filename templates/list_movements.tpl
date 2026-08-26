{strip}
<div class="listing food">
	<header>
		<div class="floaticon hidden-print">
			{if $gBitUser->hasPermission('p_food_create')}
				<a href="{$smarty.const.FOOD_PKG_URL}edit_movement.php">{biticon ipackage="icons" iname="kt-add-filters" iexplain="Add Receipt"}</a>
			{/if}
			<form class="minifind" action="{$smarty.const.FOOD_PKG_URL}list_movements.php" method="get">
				<div class="form-inline">
					<div class="form-group">
						<input class="form-control input-sm" type="text" name="find" placeholder="{tr}Movements{/tr}" value="{$smarty.request.find|escape}" />
					</div>
					<button type="submit" class="btn btn-default btn-sm">{tr}Search{/tr}</button>
				</div>
			</form>
		</div>
		<h1>{tr}Movements{/tr}</h1>
	</header>

	<section class="body">
		<table class="table table-striped table-hover">
			<thead>
				<tr>
					<th>{smartlink ititle="Title" isort="title" idefault=1 icontrol=$listInfo}</th>
					<th>{tr}Shop{/tr}</th>
					<th>{smartlink ititle="Date" isort="last_modified" icontrol=$listInfo}</th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$movementList item=mv}
					<tr>
						<td><a href="{$mv.display_url|escape}">{$mv.title|escape}</a></td>
						<td>{$mv.ref_contact_name|default:'—'|escape}</td>
						<td>{$mv.last_modified|bit_short_date}</td>
					</tr>
				{foreachelse}
					<tr><td colspan="3" class="norecords">{tr}No movements found.{/tr}</td></tr>
				{/foreach}
			</tbody>
		</table>

		{pagination}
	</section>
</div>
{/strip}
