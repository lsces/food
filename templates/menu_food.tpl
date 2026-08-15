{strip}
{if !empty($packageMenuTitle)}<a class="dropdown-toggle" data-toggle="dropdown" href="#"> {tr}{$packageMenuTitle}{/tr} <b class="caret"></b></a>{/if}
<ul class="{$packageMenuClass}">
	{* no pages built yet — see Claude memory project_food_package_scoping for the plan *}
	<li><a class="item" href="{$smarty.const.FOOD_PKG_URL}index.php">{tr}Food{/tr}</a></li>
</ul>
{/strip}
