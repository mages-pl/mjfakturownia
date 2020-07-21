<?php
/**
 * Module Mjfakturownia
 * @author Convetis Michał Jendraszczyk
 * @copyright (c) 2020
 * @license http://convertis.pl
 */

include_once(dirname(__FILE__) . '/../../mjfakturownia.php');

class MjfakturowniaCronreturnsModuleFrontController extends ModuleFrontController
{
    public $_html;
    public $prefix;
    public $display_column_left = false;
    public $auth = false;

    
    public function __construct()
    {
        $this->name = (new Mjfakturownia())->name;
        $this->prefix = $this->name.'_';
        $this->bootstrap = true;
        parent::__construct();
    }

    public function postProcess()
    {
        parent::postProcess();

        if (Configuration::get($this->prefix.'token') == Tools::getValue('token')) {
            $fakturownia = new Mjfakturownia();
            $fakturownia->syncReturns();
            echo "Returns sync (time ".time()."s)";
            exit();
        } else {
            echo "Niepoprawny token";
            exit();
        }
    }
}
