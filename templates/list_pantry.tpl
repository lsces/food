{strip}
<div class="listing food">
	<header>
		<div class="floaticon hidden-print">
			<form class="minifind" action="{$smarty.const.FOOD_PKG_URL}list_pantry.php" method="get">
				<div class="form-inline">
					<div class="form-group">
						<input class="form-control input-sm" type="text" name="find" placeholder="{tr}Pantry{/tr}" value="{$find|escape}" />
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
					<th>{tr}Component{/tr}</th>
					<th>{tr}In stock{/tr}</th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$pantryList item=p}
					<tr>
						<td><a href="{$p.display_url|escape}">{$p.title|escape}</a></td>
						<td>{$p.display_quantity|escape}{$p.display_unit|escape}</td>
					</tr>
				{foreachelse}
					<tr><td colspan="2" class="norecords">{tr}Nothing currently tracked as in stock.{/tr}</td></tr>
				{/foreach}
			</tbody>
		</table>
	</section>
</div>
{/strip}
