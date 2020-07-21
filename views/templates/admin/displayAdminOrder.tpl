{*
  * Module Mjfakturownia
 * @author Convetis Michał Jendraszczyk
 * @copyright (c) 2020
 * @license http://convertis.pl
*}

<div class="clearfix"></div>
<div class="panel panel-body">
        <img src='../modules/mjfakturownia/logo.png'>{l s='Integracja z Fakturownia' mod='mjfakturownia'}<a target='_blank' href='https://{$account_url|escape:'htmlall':'UTF-8'}'>{$account_url|escape:'htmlall':'UTF-8'}</a>
        <table class="table">
            <thead>
                <tr>
                    <th><b>{l s='Pozycja' mod='mjfakturownia'}</b></th>
                    <th><b>{l s='Akcja' mod='mjfakturownia'}</b></th>
                    <th><b>{l s='Usunięcie' mod='mjfakturownia'}</b></th>
                </tr>
            </thead>
            <tr>
                <td><strong>Dokument sprzedaży</strong></td>
                <td>
                    {if empty($invoice)}
                        <form method="POST" action="{$link->getAdminLink('AdminMjfakturowniainvoice', true, [], ['id_order' => $id_order, 'status' => 'invoice'])|escape:'htmlall':'UTF-8'}">
                            <select name="kind" style="display:inline-block;width:auto;">
                                <option value="vat">Faktura VAT</option>
                                <option value="receipt">Paragon</option>
                                <option value="bill">Rachunek</option>
                            </select>
                            <button type="submit" class="btn btn-primary">Wystaw dokument sprzedaży</button>
                        </form>
                    {else}
                        <a target='_blank' class='btn btn-default' href='{$invoice_url|escape:'htmlall':'UTF-8'}/{$single_invoice['id_fv']|escape:'htmlall':'UTF-8'}'>Zobacz dokument sprzedaży</a>
                        {if empty($single_invoice['id_kor'])}
                            <a target='_blank' class='btn btn-primary' href='{$link->getAdminLink('AdminMjfakturowniainvoice', true, [], ['id_order' => $id_order, 'invoice' => 'correction'])|escape:'htmlall':'UTF-8'}'>Wystaw korektę</a>
                        {/if}
                    {/if}
                    {if !empty($single_invoice['id_kor'])}
                        <a target='_blank' class='btn btn-default' href='{$invoice_url|escape:'htmlall':'UTF-8'}/{$single_invoice['id_kor']|escape:'htmlall':'UTF-8'}'>Zobacz korektę</a>
                    {/if}
                </td>
                <td>
                    {if !empty($invoice)}
                    <a class='btn btn-danger' href='{$link->getAdminLink('AdminMjfakturowniainvoice', true, [], ['id_order' => $id_order, 'invoice' => 'delete'])|escape:'htmlall':'UTF-8'}'>Usuń dokument sprzedaży</a>
                    {/if}
                    {if !empty($single_invoice['id_kor'])}
                    <a class='btn btn-danger' href='{$link->getAdminLink('AdminMjfakturowniainvoice', true, [], ['id_order' => $id_order, 'invoice' => 'delete_kor'])|escape:'htmlall':'UTF-8'}'>Usuń korektę</a>
                    {/if}
                </td>
            </tr>
        </table>
       
</div>
