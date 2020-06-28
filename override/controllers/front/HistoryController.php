<?php
/**
 * Module Mjfakturownia
 * @author MAGES Michał Jendraszczyk
 * @copyright (c) 2020, MAGES Michał Jendraszczyk
 * @license http://mages.pl MAGES Michał Jendraszczyk
 */

class HistoryController extends HistoryControllerCore
{
    public static function getMjfakturowniaInvoiceUrl($id_order)
    {

        if ($id_order != null) {
            $query = 'SELECT * FROM `' . _DB_PREFIX_ . 'mjfakturownia_invoice` WHERE id_order = "' . $id_order . '"';

            if (count(DB::getInstance()->ExecuteS($query, 1, 0)) > 0) {
                if (DB::getInstance()->ExecuteS($query, 1, 0)[0]['external_id'] > 0) {
                    return 'https://' . Configuration::get('mjfakturownia_login') . '.' . Configuration::get('FAKTUROWNIA_API_URL') . '/invoices/' . DB::getInstance()->ExecuteS($query, 1, 0)[0]['external_id'] . '.pdf?api_token=' . Configuration::get('FAKTUROWNIA_API_TOKEN');
                } else {
                    return false;
                }
            } else {
                return false;
            }
        }
    }
}
