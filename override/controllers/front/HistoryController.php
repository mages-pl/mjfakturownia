<?php
/**
 * Module Mjfakturownia
 * @author Convetis Michał Jendraszczyk
 * @copyright (c) 2020
 * @license http://convertis.pl
 */

class HistoryController extends HistoryControllerCore
{
    /**
     * Metoda statyczna zwracająca url do faktury
     * @param type $id_order
     * @return boolean
     */
    public static function getMjfakturowniaInvoiceUrl($id_order)
    {
        if ($id_order != null) {
            $query = 'SELECT * FROM `' . _DB_PREFIX_ . 'mjfakturownia_invoice` WHERE id_order = "' . $id_order . '"';
            if (count(DB::getInstance()->ExecuteS($query, 1, 0)) > 0) {
                if (DB::getInstance()->ExecuteS($query, 1, 0)[0]['id_fv'] > 0) {
                    return 'https://' . Configuration::get('mjfakturownia_login') . '.' . Configuration::get('FAKTUROWNIA_API_URL') . '/invoices/' . DB::getInstance()->ExecuteS($query, 1, 0)[0]['id_fv'] . '.pdf?api_token=' . Configuration::get('mjfakturownia_klucz_api');
                } else {
                    return false;
                }
            } else {
                return false;
            }
        }
    }

    /**
     * Zwrócenie linku do faktury w panelu klienta
     * @param type $order
     * @param type $context
     * @return type
     */
    public static function getUrlToInvoice($order, $context)
    {
        $url_to_invoice = '';

        if ((bool) Configuration::get('PS_INVOICE') && OrderState::invoiceAvailable($order->current_state) && count($order->getInvoicesCollection())) {
            $url_to_invoice = $context->link->getPageLink('pdf-invoice', true, null, 'id_order=' . $order->id);
            if ($context->cookie->is_guest) {
                $url_to_invoice .= '&amp;secure_key=' . $order->secure_key;
            }
        }

        return HistoryController::getMjfakturowniaInvoiceUrl($order->id);
    }
}
