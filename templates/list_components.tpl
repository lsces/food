{strip}
<div class="listing food">
	<header>
		<div class="floaticon hidden-print">
			{if $gBitUser->hasPermission('p_food_create')}
				<a href="{$smarty.const.FOOD_PKG_URL}edit_component.php">{biticon ipackage="icons" iname="kt-add-filters" iexplain="Create Food Item"}</a>
			{/if}
			<form class="minifind" action="{$smarty.const.FOOD_PKG_URL}list_components.php" method="get">
				<div class="form-inline">
					<div class="form-group">
						<input class="form-control input-sm" type="text" name="find" placeholder="{tr}Food Items{/tr}" value="{$smarty.request.find|escape}" />
					</div>
					<div class="form-group">
						<select class="form-control input-sm" name="sup" onchange="this.form.submit()">
							<option value="">{tr}Any shop{/tr}</option>
							{foreach $shops as $shop}
								<option value="{$shop.content_id}"{if $selectedShop eq $shop.content_id} selected="selected"{/if}>{$shop.title|escape}</option>
							{/foreach}
						</select>
					</div>
					<button type="submit" class="btn btn-default btn-sm">{tr}Search{/tr}</button>
				</div>
			</form>
		</div>
		<h1>{tr}Food Items{/tr}</h1>
	</header>

	<section class="body">
		<table class="table table-striped table-hover">
			<thead>
				<tr>
					<th>{smartlink ititle="Name" isort="title" idefault=1 icontrol=$listInfo}</th>
					<th>{tr}Supplier{/tr}</th>
					<th>{smartlink ititle="Created" isort="created" icontrol=$listInfo}</th>
					<th></th>
					{if $gBitUser->hasPermission('p_food_update')}<th></th>{/if}
				</tr>
			</thead>
			<tbody>
				{foreach from=$componentList item=comp}
					<tr>
						<td><a href="{$comp.display_url|escape}">{$comp.title|escape}</a></td>
						<td>{$comp.supplier_title|escape}</td>
						<td>{$comp.created|bit_short_date}</td>
						<td>{if $comp.needs_review}<span class="label label-warning">{tr}review{/tr}</span>{/if}</td>
						{if $gBitUser->hasPermission('p_food_update')}<td><a href="{$smarty.const.FOOD_PKG_URL}edit_component.php?content_id={$comp.content_id}">{biticon ipackage="icons" iname="edit" iexplain="Edit"}</a></td>{/if}
					</tr>
				{foreachelse}
					<tr><td colspan="5" class="norecords">{tr}No food items found.{/tr}</td></tr>
				{/foreach}
			</tbody>
		</table>

		<nav>
			{pagination}
		</nav>

	</section>
</div>
{/strip}
