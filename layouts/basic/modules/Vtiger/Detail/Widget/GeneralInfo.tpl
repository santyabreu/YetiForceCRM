{*<!-- {[The file is published on the basis of YetiForce Public License 3.0 that can be found in the following directory: licenses/LicenseEN.txt or yetiforce.com]} -->*}
{strip}
	{assign var=WIDTHTYPE value=$USER_MODEL->get('rowheight')}
	<div class="c-detail-widget mb-3 c-detail-widget--general-info js-widget-general-info" data-js="edit/save">
		<div class="c-detail-widget__header">
			<h4> {\App\Language::translate('LBL_RECORD_SUMMARY',$MODULE_NAME)}</h4>
			<hr />
		</div>

		{include file=\App\Layout::getTemplatePath('Detail/Widget/GeneralInfoContent.tpl', $MODULE_NAME)}
	</div>
{/strip}
