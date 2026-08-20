{strip}
<div class="display food">
	<header>
		<div class="floaticon">
			{if $gContent->hasUpdatePermission()}
				<a title="{tr}Edit{/tr}" href="{$smarty.const.FOOD_PKG_URL}edit_movement.php?content_id={$gContent->mContentId}">{biticon ipackage="icons" iname="edit" iexplain="Edit Receipt"}</a>
			{/if}
		</div>
		<h1>{$gContent->getTitle()|escape}</h1>
	</header>
	<div class="body">
		<dl class="dl-horizontal">
			<dt>{tr}Shop{/tr}</dt>
			<dd>{if $gContent->mInfo.ref_contact_name}<a href="{$smarty.const.CONTACT_PKG_URL}view.php?content_id={$gContent->mInfo.ref_contact_id}">{$gContent->mInfo.ref_contact_name|escape}</a>{else}—{/if}</dd>
			<dt>{tr}Reference{/tr}</dt>
			<dd>{$gContent->mInfo.ref_key|default:'—'|escape}</dd>
			<dt>{tr}Purchase date{/tr}</dt>
			<dd>{if $gContent->mInfo.ref_start_date}{$gContent->mInfo.ref_start_date|bit_short_date}{else}—{/if}</dd>
			{if $gContent->mInfo.ref_note}
				<dt>{tr}Note{/tr}</dt>
				<dd>{$gContent->mInfo.ref_note|escape}</dd>
			{/if}
		</dl>

		<table class="table table-condensed">
			<thead>
				<tr>
					<th>{tr}Component{/tr}</th>
					<th>{tr}Quantity{/tr}</th>
				</tr>
			</thead>
			<tbody>
				{foreach $lines as $l}
				<tr>
					<td><a href="{$l.component_display_url|escape}">{$l.component_title|escape}</a></td>
					<td>{$l.quantity|escape}{$l.quantity_unit|escape}</td>
				</tr>
				{foreachelse}
				<tr><td colspan="2" class="norecords">{tr}No items on this receipt.{/tr}</td></tr>
				{/foreach}
			</tbody>
		</table>

		<p><a href="{$smarty.const.FOOD_PKG_URL}list_movements.php">{tr}Back to movements{/tr}</a></p>
	</div>
</div>
{/strip}
