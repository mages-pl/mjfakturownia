{*
 * Module Mjfakturownia
 * @author MAGES Michał Jendraszczyk
 * @copyright (c) 2020, MAGES Michał Jendraszczyk
 * @license http://mages.pl MAGES Michał Jendraszczyk
*}

<div class="clearfix"></div>
<div class="panel panel-body">
        <img src='../modules/mjfakturownia/logo.png'>{l s='Integration with Fakturownia' mod='mjfakturownia'}<a target='_blank' href='https://{$account_url|escape:'htmlall':'UTF-8'}'>{$account_url|escape:'htmlall':'UTF-8'}</a>
        <div class="alert alert-warning">
            {l s='To create WZ document you should have products on fakturownia warehouse' mod='mjfakturownia'}
            {l s='Next products from PrestaShop should have references the same as ID from faktrownia warehouse' mod='mjfakturownia'}
            {l s='In configuration panel you must set ID warehouse and ID department' mod='mjfakturownia'}
        </div>
        <table class="table">
            <thead>
                <tr>
                    <th><b>{l s='Position' mod='mjfakturownia'}</b></th>
                    <th><b>{l s='Insert' mod='mjfakturownia'}</b></th>
                    <th><b>{l s='Delete' mod='mjfakturownia'}</b></th>
                </tr>
            </thead>
            <tr>
                <td><strong>WZ</strong></td>
                <td>
                    {if empty($wz)}
                        <a href="{$link->getAdminLink('AdminMjfakturowniainvoice', true, [], ['id_order' => $id_order, 'invoice' => 'wz'])|escape:'htmlall':'UTF-8'}" class="btn btn-default">Wystaw WZ</a>
                    {else}
                        <a target='_blank' class='btn btn-default' href='{$warehouse_url|escape:'htmlall':'UTF-8'}/{$single_wz['id_wz']|escape:'htmlall':'UTF-8'}'>Zobacz dokument WZ</a>
                    {/if}
                </td>
                <td>
                    {if !empty($wz)}
                    <a class='btn btn-danger' href='{$link->getAdminLink('AdminMjfakturowniainvoice', true, [], ['id_order' => $id_order, 'invoice' => 'delete_wz'])|escape:'htmlall':'UTF-8'}'>Usuń WZ</a>
                    {/if}
                </td>
            </tr>
            <tr>
                <td><strong>Faktura</strong></td>
                <td>
                    {if empty($invoice)}
                        <a href="{$link->getAdminLink('AdminMjfakturowniainvoice', true, [], ['id_order' => $id_order, 'kind' => 'vat'])|escape:'htmlall':'UTF-8'}" class="btn btn-default">Wystaw fakturę VAT</a>
                    {else}
                        <a target='_blank' class='btn btn-default' href='{$invoice_url|escape:'htmlall':'UTF-8'}/{$single_invoice['external_id']|escape:'htmlall':'UTF-8'}'>Zobacz fakturę VAT</a>
                    {/if}
                </td>
                <td>
                    {if !empty($invoice)}
                    <a class='btn btn-danger' href='{$link->getAdminLink('AdminMjfakturowniainvoice', true, [], ['id_order' => $id_order, 'invoice' => 'delete'])|escape:'htmlall':'UTF-8'}'>Usuń fakturę</a>
                    {/if}
                </td>
            </tr>
        </table>
       
</div>
