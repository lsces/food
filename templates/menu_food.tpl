{strip}
{if !empty($packageMenuTitle)}<a class="dropdown-toggle" data-toggle="dropdown" href="#"> {tr}{$packageMenuTitle}{/tr} <b class="caret"></b></a>{/if}
<ul class="{$packageMenuClass}">
	<li><a class="item" href="{$smarty.const.FOOD_PKG_URL}index.php">{tr}Food{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FOOD_PKG_URL}view_day.php">{tr}Today{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FOOD_PKG_URL}list_components.php">{tr}Components{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FOOD_PKG_URL}list_review.php">{tr}Review Queue{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FOOD_PKG_URL}list_pantry.php">{tr}Pantry{/tr}</a></li>
	<li><a class="item" href="{$smarty.const.FOOD_PKG_URL}list_movements.php">{tr}Receipts{/tr}</a></li>
</ul>
{/strip}
