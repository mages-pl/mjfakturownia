<?php
/**
 * @author MAGES Michał Jendraszczyk
 * @copyright (c) 2019, MAGES Michał Jendraszczyk
 * @license http://mages.pl MAGES Michał Jendraszczyk
 */

include_once(dirname(__FILE__) . '/../../mjfakturownia.php');

class MjfakturowniaStocksModuleFrontController extends ModuleFrontControllerCore
{
    public $_html;
    public $prefix;
    public $display_column_left = false;
    public $auth = true;
    public $authRedirection = true;

    public $orders_detail = array();
    
    public  $limit_od;
    public  $limit_do;
    
    public function __construct()
    {

        $this->prefix = 'mjfakturownia_';
        $this->name = 'mjfakturownia';
        $this->bootstrap = true;
        parent::__construct();
    }

    public function postProcess()
    {
        parent::postProcess();

        $fakturownia = new Mjfakturownia();
        $fakturownia->syncQty(2);
        echo "OK (time ".time()."s)";
        exit();
    }
}