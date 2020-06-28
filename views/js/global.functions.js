/**
 * Module Mjfakturownia
 * @author MAGES Michał Jendraszczyk
 * @copyright (c) 2020, MAGES Michał Jendraszczyk
 * @license http://mages.pl MAGES Michał Jendraszczyk
 */

function pslAddToolbarFakturowniaBtn()
{
	$('div.btn-toolbar > ul').prepend('<li style="line-height: 40px; vertical-align: middle; color: #222222 !important; opacity: 0.3; filter: alpha(opacity=30);">|</li>');
	$('div.btn-toolbar > ul').prepend('<li><a id="fakturownia_pl" class="psl toolbar_btn" title="Generuj faktury" href="javascript:{}"><i style="margin-top: 2px !important;" class="fa fa-file-code-o fa-2x"></i><div style="margin-top: 4px !important;">Generuj faktury</div></a></li>');
	$('div.page-head').append('<div id="progressBar"><div></div></div>');	
}