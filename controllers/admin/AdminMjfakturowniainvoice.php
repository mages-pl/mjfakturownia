<?php
/**
 * Module Mjfakturownia
 * @author MAGES Michał Jendraszczyk
 * @copyright (c) 2020, MAGES Michał Jendraszczyk
 * @license http://mages.pl MAGES Michał Jendraszczyk
 */

include_once(dirname(__FILE__).'/../../mjfakturownia.php');

class AdminMjfakturowniainvoiceController extends ModuleAdminController
{
    public $id_order;
    public $kind;
    public $invoice;

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

    public function postProcess()
    {
        parent::postProcess();
    }

    public function init()
    {
        parent::init();
    }

    public function initContent()
    {
        parent::initContent();
        $order = new Order($this->id_order);
        $f = new Mjfakturownia();
        if (!empty($this->invoice) && $this->invoice == 1) {
            $f->sendInvoice($this->id_order, $this->kind);
        } else if ($this->invoice == 'delete') {
            $f->deleteInvoice($this->id_order);
        } else if ($this->invoice == 'delete_wz') {
            $f->deleteWz($this->id_order);
        } else if ($this->invoice == 'wz') {
            $f->sendWz($order);
        } else {
            $f->sendInvoice($this->id_order, $this->kind);
        }
        // Back to order
        $link = new Link();
        $order_link = $link->getAdminLink('AdminOrders', true, [], ['vieworder' => '', 'id_order' => $this->id_order]);
        return Tools::redirect($order_link);
    }
}
