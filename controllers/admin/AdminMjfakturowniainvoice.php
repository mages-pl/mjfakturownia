<?php
/**
 * Module Mjfakturownia
 * @author Convetis Michał Jendraszczyk
 * @copyright (c) 2020
 * @license http://convertis.pl
 */

include_once(dirname(__FILE__).'/../../mjfakturownia.php');

class AdminMjfakturowniainvoiceController extends ModuleAdminController
{
    public $id_order;
    public $kind;
    public $invoice;

    /**
     * Jeśli nie mamy wybranego żadnego zamówienia przekierowanie do konfiguracji modułu
     */
    public function __construct()
    {
        if (empty(Tools::getValue('id_order'))) {
            Tools::redirectAdmin('index.php?controller=AdminModules&configure=mjfakturownia&token=' . Tools::getAdminTokenLite('AdminModules'));
        } else {
            $this->id_order = Tools::getValue('id_order');
            $this->kind = Tools::getValue('kind');
            $this->invoice = Tools::getValue('invoice');

            $this->bootstrap = true;
            parent::__construct();
        }
    }

    /**
     * Procesowanie requesta
     */
    public function postProcess()
    {
        parent::postProcess();
    }

    /**
     * Inicjalizacja kontrollera
     */
    public function init()
    {
        parent::init();
    }
    
    /**
     * Wykonanie poszczególnej metody w zależności od polecenia użytkownika i powrót do szczegółów zamówienia
     * @return type
     */
    public function initContent()
    {
        parent::initContent();
        $order = new Order($this->id_order);
        $f = new Mjfakturownia();
        if (Tools::getValue('status') == 'invoice') {
            $f->sendInvoice($this->id_order, Tools::getValue('kind'));
        } else {
            if (!empty($this->invoice) && $this->invoice == 1) {
                $f->sendInvoice($this->id_order, $this->kind);
            } else if ($this->invoice == 'delete') {
                $f->deleteInvoice($this->id_order);
            } else if ($this->invoice == 'delete_wz') {
                $f->deleteWz($this->id_order);
            } else if ($this->invoice == 'delete_kor') {
                $f->deleteKor($this->id_order);
            } else if ($this->invoice == 'wz') {
                $f->sendWz($order);
            } else if ($this->invoice == 'correction') {
                $last_invoice = $f->getLastinvoice($order->id);
                $f->makeCorrection($order, $last_invoice[0]['id_fv']);
            } else {
                $f->sendInvoice($this->id_order, $this->kind);
            }
        }
        /**
         * Powrót do zamówienia
         */
        $link = new Link();
        $order_link = $link->getAdminLink('AdminOrders', true, [], ['vieworder' => '', 'id_order' => $this->id_order]);
        return Tools::redirect($order_link);
    }
}
