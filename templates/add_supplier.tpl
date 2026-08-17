{strip}
<div class="edit food">
	<div class="header">
		<h1>{tr}Add Supplier{/tr}: {$gContent->getTitle()|escape}</h1>
	</div>

	<div class="body">
		{formfeedback error=$errors}

		{form id="addSupplierForm"}
			<input type="hidden" name="content_id" value="{$gContent->mContentId}" />

			<div class="form-group">
				{formlabel label="Supplier" for="supplier_content_id" mandatory="y"}
				{forminput}
					<select class="form-control" name="supplier_content_id" id="supplier_content_id">
						<option value="">{tr}-- select --{/tr}</option>
						{foreach $suppliers as $sup}
							<option value="{$sup.content_id}">{$sup.title|escape}</option>
						{/foreach}
					</select>
					{formhelp note="Not listed yet? Add it as a business Contact first (Contact > Add Business, tagged Supplier)."}
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Product Code" for="product_code"}
				{forminput}
					<input type="text" class="form-control input-small" name="product_code" id="product_code" value="" />
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Price" for="price"}
				{forminput}
					<input type="text" class="form-control input-small" name="price" id="price" value="" />
				{/forminput}
			</div>

			<div class="form-group">
				{formlabel label="Note" for="note"}
				{forminput}
					<input type="text" class="form-control" name="note" id="note" value="" />
				{/forminput}
			</div>

			<div class="form-group submit">
				<input type="submit" class="btn btn-default" name="fCancel"      value="{tr}Cancel{/tr}" />
				<input type="submit" class="btn btn-primary" name="fAddSupplier" value="{tr}Save{/tr}" />
			</div>
		{/form}
	</div>
</div>
{/strip}
