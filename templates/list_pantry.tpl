{strip}
<div class="listing food">
	<header>
		<div class="floaticon hidden-print">
			<form class="minifind" action="{$smarty.const.FOOD_PKG_URL}list_pantry.php" method="get">
				<div class="form-inline">
					<div class="form-group">
						<input class="form-control input-sm" type="text" name="find" placeholder="{tr}Pantry{/tr}" value="{$find|escape}" />
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
		<h1>{tr}Pantry{/tr}</h1>
	</header>

	<section class="body">
		<table class="table table-striped table-hover">
			<thead>
				<tr>
					<th>{tr}Food Item{/tr}</th>
					<th>{tr}In stock{/tr}</th>
					<th>{tr}Note{/tr}</th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$pantryList item=p}
					<tr>
						<td><a href="{$p.display_url|escape}">{$p.title|escape}</a>{if $p.supplier_title} ({$p.supplier_title|escape}){/if}</td>
						<td>{$p.display_quantity|escape}{$p.display_unit|escape}</td>
						<td>{$p.note|escape}</td>
					</tr>
				{foreachelse}
					<tr><td colspan="3" class="norecords">{tr}Nothing currently tracked as in stock.{/tr}</td></tr>
				{/foreach}
			</tbody>
		</table>

		<nav>
			{pagination find=$find|default:'' sup=$selectedShop|default:''}
		</nav>
	</section>
</div>
{/strip}
