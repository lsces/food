{strip}
<div class="listing food">
	<header>
		<h1>{tr}Food{/tr}</h1>
	</header>

	<section class="body">
		<div class="bitnav">
			<ul class="pagination">
				<li class="bitnav-picker">
					<form method="get" action="{$smarty.const.FOOD_PKG_URL}view_day.php" id="foodGoToDayForm">
						<label for="date">{tr}Go to day{/tr}</label>
						<input type="date" name="date" id="date" onchange="foodUpdateCalendarLink(this.value)" />
					</form>
				</li>
				<li class="bitnav-gap"><button type="submit" form="foodGoToDayForm">{tr}Day{/tr}</button></li>
				<li class="bitnav-gap"><a id="foodCalendarLink" href="{$smarty.const.CALENDAR_PKG_URL}package_page.php?pkg=food">{tr}Calendar{/tr}</a></li>
			</ul>

			<ul class="pagination">
				<li class="bitnav-item"><a href="{$smarty.const.FOOD_PKG_URL}list_components.php">{tr}Food Items{/tr}</a></li>
				<li class="bitnav-gap"><a href="{$smarty.const.FOOD_PKG_URL}list_pantry.php">{tr}Pantry{/tr}</a></li>
				<li class="bitnav-gap"><a href="{$smarty.const.FOOD_PKG_URL}list_movements.php">{tr}Receipts{/tr}</a></li>
			</ul>
		</div>
	</section>
</div>
{/strip}
<script>
// Same convention as health/templates/index.tpl's healthUpdateCalendarLink() - keeps the
// Calendar link jumping to whatever date is currently picked, rather than always today.
var foodCalendarBaseUrl = '{$smarty.const.CALENDAR_PKG_URL}package_page.php?pkg=food';
function foodUpdateCalendarLink( pDateVal ) {
	if ( !pDateVal ) return;
	document.getElementById( 'foodCalendarLink' ).href = foodCalendarBaseUrl + '&todate=' + pDateVal;
}
</script>
