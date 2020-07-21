<?php
/**
 * Module Mjfakturownia
 * @author Convetis Michał Jendraszczyk
 * @copyright (c) 2020
 * @license http://convertis.pl
 */

set_time_limit(0);

if (!defined('_PS_VERSION_')) {
    exit;
}

class Mjfakturownia extends Module
{
    public $prefix;
    public $id_wz;

    public function __construct()
    {
        $this->name = 'mjfakturownia';
        $this->tab = 'billing_invoicing';
        $this->author = 'Convertis Michał Jendraszczyk';
        $this->version = '1.0.0';
        $this->module_key = '62c288ebff06de6c404602a1809984ee';

        $this->prefix = $this->name . "_";
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('Fakturownia - Integracja');
        $this->description = $this->l('Moduł umożliwiający wysyłanie faktur do Fakturownia.pl');

        $this->confirmUninstall = $this->l('Usunąć?');

        $this->account_prefix = Configuration::get($this->prefix . 'login');

        $this->api_url = Configuration::get($this->prefix.'api_url');
        $this->issue_kind = Configuration::get('FAKTUROWNIA_ISSUE_KIND');
        $this->api_token = trim(Configuration::get($this->prefix . 'klucz_api'));
        
        Configuration::updateValue($this->prefix.'cron_returns', Tools::getHttpHost(true) . __PS_BASE_URI__ . 'index.php?fc=module&module='.$this->name.'&controller=cronreturns&token='.Configuration::get($this->prefix.'token'));
        Configuration::updateValue($this->prefix.'cron_invoices', Tools::getHttpHost(true) . __PS_BASE_URI__ . 'index.php?fc=module&module='.$this->name.'&controller=croninvoices&token='.Configuration::get($this->prefix.'token'));
    }

    /**
     * Instalacja zakładki
     * @param type $tabClass
     * @param type $tabName
     * @param type $idTabParent
     * @return boolean
     */
    private function installModuleTab($tabClass, $tabName, $idTabParent)
    {
        $tab = new Tab();
        $tab->name = $tabName;
        $tab->class_name = $tabClass;
        $tab->module = $this->name;
        $tab->id_parent = $idTabParent;
        $tab->position = 98;
        if (!$tab->save()) {
            return false;
        }
        return true;
    }
    
    /**
     * Usunięcie zakładki
     * @param type $tabClass
     */
    public function uninstallModuleTab($tabClass)
    {
        $idTab = Tab::getIdFromClassName($tabClass);
        if ($idTab) {
            $tab = new Tab($idTab);
            $tab->delete();
        }
    }

    /**
     * Proces instalacji
     * @return type
     */
    public function install()
    {
        $createTable = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mjfakturownia_invoice` (
        `id_mjfakturownia` INT(11) UNSIGNED NOT null auto_increment,
        `id_order` INT(11) UNSIGNED NOT null,
        `id_fv` INT(11),
        `id_wz` INT(11),
        `id_kor` INT(11),
        PRIMARY KEY (`id_mjfakturownia`),
        KEY `id_order` (`id_order`)) DEFAULT CHARSET=utf8;';

        return parent::install()
                && $this->registerHook('actionOrderStatusPostUpdate')
                && $this->registerHook('displayAdminOrder')
                && $this->registerHook('actionValidateOrder')
                && $this->registerHook('actionOrderReturn')
                && $this->registerHook('actionObjectUpdateAfter')
                && Db::getInstance()->Execute($createTable)
                && Configuration::updateValue($this->prefix.'token', Tools::passwdGen(8))
                && Configuration::updateValue($this->prefix.'api_url', "fakturownia.pl")
                && Configuration::updateValue('FAKTUROWNIA_ISSUE_KIND', 'vat_or_receipt')
                && Configuration::updateValue($this->prefix . 'cron_stock', Tools::getHttpHost(true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/cron_stocks.php')
                && $this->installModuleTab('AdminMjfakturowniainvoice', array(Configuration::get('PS_LANG_DEFAULT') => 'Fakturownia'), Tab::getIdFromClassName('AdminParentOrders'));
    }

    /**
     * Zmiana statusu zwrotu przez klienta
     * @param type $params
     */
    public function hookActionObjectUpdateAfter()
    {
        if (Tools::isSubmit('submitAddorder_return')) {
            if (Tools::getValue('id_order_return')) {
                if (Configuration::get('mjfakturownia_status_zamowienia_korekta') == Tools::getValue('state')) {
                    $getOrderReturn = new OrderReturn(Tools::getValue('id_order_return'));
                    $id_order = $getOrderReturn->id_order;
                    $order = new Order($id_order);
                    $last_invoice = $this->getLastinvoice($order->id);

                    $this->makeCorrection($order, $last_invoice[0]['id_fv']);
                }
            }
        }
    }
    
    /**
     * Inicjacja zwrotu przez klienta
     * @param type $params
     */
    public function hookActionOrderReturn($params)
    {
        // echo "Otrzymanie zwrotu zwrotu";
        // echo "ORDER:".Tools::getValue('state');
        // exit();
    }
    /**
     * Wystawienie dokumentu sprzedaży po realizacji zamówienia
     * @param type $params
     */
    public function hookActionValidateOrder($params)
    {
        // Wysyłka dokumentu sprzedaży po realizacji zamówienia
        $order = $params['order'];
        if (Configuration::get('mjfakturownia_automatyczne') == 1) {
            $this->sendInvoice($order->id, 'auto');
        }
    }

    /**
     * Aktualizacja danych do faktury w bazie na podstawie wcześniej wygenerowanej wz
     * @param type $id_order
     * @param type $id_invoice
     */
    public function aktualizujFakture($id_order, $id_invoice)
    {
        Db::getInstance()->Execute('UPDATE `' . _DB_PREFIX_ . 'mjfakturownia_invoice` SET `id_fv` = "' . pSQL($id_invoice) . '" WHERE id_order = "' . pSQL($id_order) . '"');
    }
    /**
     * Zapisuje dodaną fakturę w bazie
     * @param type $id_order
     * @param type $id_wz
     * @param type $typ
     */
    public function dodajFakture($id_order, $id_wz, $typ)
    {
        if ($typ == 'wz') {
            Db::getInstance()->Execute('INSERT INTO `' . _DB_PREFIX_ . 'mjfakturownia_invoice` (`id_order`, `id_fv`,`id_wz`, `id_kor`) VALUES ("' . pSQL((int) $id_order) . '", "","' . pSQL($id_wz) . '","")');
        } else {
            Db::getInstance()->Execute('INSERT INTO `' . _DB_PREFIX_ . 'mjfakturownia_invoice` (`id_order`, `id_fv`,`id_wz`, `id_kor`) VALUES ("' . pSQL((int) $id_order) . '", "'.pSQL($id_wz).'","","")');
        }
    }

    /**
     * Wysyła faktury po ustawieniu określonego statusu w BO
     * @param type $params
     * @return boolean
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        // Wysyłka faktury po ustawieniu okreslonego statusu
        $order = new Order($params['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }
        $lastInvoice = $this->getLastinvoice($order->id);
        $checkInvoice = $this->checkInvoice($order->id); // Sprawdzenie czy w bazie jest rekord z faktura
        /**
         * Gdy status zamówienia będzie ustawiony na zgodny z statusem do wysyłki faktury + gdy nie ma wcześniej wystawionej fv + gdy jest opcja wstawiani fv automatycznie
         */
//        echo "COUNT:".count($checkInvoice);
        $allow_states = unserialize(Configuration::get($this->prefix.'status_zamowienia_store'));
        if ((count($lastInvoice) == 0)) { //??tu cos nie tak //&& Configuration::get($this->prefix . 'automatyczne') == '1') {
            if ((in_array($params['newOrderStatus']->id, $allow_states))) {
                $url = $this->getInvoicesurl($this->account_prefix) . '.json';
                $invoice_data = $this->invoiceFromorder($order, 'auto');
                /**
                 * Zdefiniowanie że fv jest opłacona
                 */
                $invoice_data['invoice']['status'] = 'paid';
                $result = $this->issueInvoice($invoice_data, $url);

                $this->aktualizujFakture($order->id, $result->id);
                /**
                * Wyślij email
                */
                $this->sendEmail($result);
            }
        }
        /**
         * Gdy chcemy wystawić korektę
         */
//        echo $params['newOrderStatus']->id." + ".Configuration::get($this->prefix . 'status_zamowienia_korekta'). " + ".(!empty($lastInvoice));
        if ($params['newOrderStatus']->id == Configuration::get($this->prefix . 'status_zamowienia_korekta') && !empty($lastInvoice)) {
            $this->makeCorrection($order, $lastInvoice[0]['id_fv']);
        }
    }
    
    /**
     * Wystawia dokument WZ
     * @param type $order
     */
    public function sendWz($order)
    {
        $this->id_wz = '';
        $invoiceAddress = new Address($order->id_address_invoice);
        $positions = array();

        // Listuj wszystkie produkty z zamówienia
        foreach ($order->getProducts() as $key => $product) {
            // Rozbijanie oferty wielowariantowej na poszczególne pozycje do WZ
            // Dodanie zwykłego produktu jako pozycji do WZ
            $p = new Product($product['id_product']);
            $position = array(
                'product_id' => $p->reference,
                'tax' => $product['tax_rate'],
                'price_net' => $p->price,
                'quantity' => $product['product_quantity']
            );
            $positions[] = $position;
        }

        $host = Configuration::get('mjfakturownia_login').'.fakturownia.pl';
        $token = $this->api_token;
        $json = '{ "api_token": "' . $token . '", "warehouse_document": { "kind":"wz", "number": null,"oid":"' . $order->id . '", "description":"Zamówienie nr #' . $order->id . '", "warehouse_id": "'.Configuration::get('mjfakturownia_id_warehouse').'", "issue_date": "' . date('Y-m-d') . '", "department_id": "'.Configuration::get('mjfakturownia_id_department').'", "client_name": "' . $invoiceAddress->firstname . " " . $invoiceAddress->lastname . '", "client_street":"' . $invoiceAddress->address1 . ' ' . $invoiceAddress->address2 . '", "client_post_code": "' . $invoiceAddress->postcode . '","client_city":"' . $invoiceAddress->city . '", "client_tax_no": "' . $invoiceAddress->vat_number . '", "warehouse_actions": ' . json_encode($positions) . ' }}';

        $c = curl_init();
        curl_setopt($c, CURLOPT_URL, 'https://' . $host . '/warehouse_documents.json');
        $head = array();
        $head[] = 'Accept: application/json';
        $head[] = 'Content-Type: application/json';
        curl_setopt($c, CURLOPT_HTTPHEADER, $head);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($c, CURLOPT_POSTFIELDS, $json);

        $this->id_wz = @json_decode(curl_exec($c), 1)['id'];

        $this->dodajFakture($order->id, $this->id_wz, 'wz');
    }
    
    /**
     * Wyświetlanie w szczegółach zamówienia opcji wysyłki faktur
     * @return type
     */
    public function hookDisplayAdminOrder()
    {
        // Wyswietlanie opcji wysyłki faktur z szczegółów zamówienia
        $order_id_fakturownia = Tools::getValue('id_order');
        $order = new Order((int) $order_id_fakturownia);
        $account_url = $this->account_prefix . '.' . $this->api_url;

        if (!$this->isConfigured()) {
            return $this->display(__file__, '/views/templates/admin/displayerrorfakturownia.tpl');
        } else {
            $invoice = $this->getLastinvoice($order_id_fakturownia);
            $wz = $this->getLastwz($order_id_fakturownia);

            $this->context->smarty->assign(array(
                'account_url' => $account_url,
                'invoice' => $invoice,
                'wz' => $wz,
                'single_invoice' => count($invoice) > 0 ? $invoice[0] : $invoice,
                'single_wz' => count($wz) > 0 ? $wz[0] : $wz,
                'id_order' => $order_id_fakturownia,
                'invoice_url' => $this->getInvoicesurl($this->account_prefix),
                'warehouse_url' => $this->getWzurl($this->account_prefix),
                'languages' => $this->context->controller->_languages,
                'default_language' => (int) Configuration::get('PS_LANG_DEFAULT'),
                'order_product' => $order->getCustomer()
            ));
            return $this->display(__file__, '/views/templates/admin/displayAdminOrder.tpl');
        }
    }

    /**
     * Deinstalacja
     * @return type
     */
    public function uninstall()
    {
        return parent::uninstall()
        && $this->uninstallModuleTab('AdminMjfakturowniainvoice');
    }
    
    /**
     * Przeprocesowanie requestów
     * @return type
     */
    public function postProcess()
    {
        if (Tools::isSubmit('saveApi')) {
            if ((Validate::isString(Tools::getValue($this->prefix . 'klucz_api'))) && (Tools::strlen(Tools::getValue($this->prefix . 'klucz_api')) > 0) && (Tools::strlen(Tools::getValue($this->prefix . 'login')) > 0) && (Validate::isString(Tools::getValue($this->prefix . 'login')))) {
                Configuration::updateValue($this->prefix . 'klucz_api', Tools::getValue($this->prefix . 'klucz_api'));
                Configuration::updateValue($this->prefix . 'login', Tools::getValue($this->prefix . 'login'));
                return $this->displayConfirmation($this->l('Saved successfully', 'mjfakturownia'));
            } else {
                return $this->displayError($this->l('API key is invalid', 'mjfakturownia'));
            }
        } if (Tools::isSubmit('checkApi')) {
            $this->testIntegration();
            if (!$this->testIntegration()) {
                return $this->displayError($this->l('Error while API connection', 'mjfakturownia'));
            } else {
                return $this->displayConfirmation($this->l('Połącznie nawiązane poprawnie', 'mjfakturownia'));
            }
        } if (Tools::isSubmit('save_fakturownia')) {
            Configuration::updateValue($this->prefix . 'cron_stock', Tools::getHttpHost(true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/cron_stocks.php');

            Configuration::updateValue($this->prefix . 'status_zamowienia_platnosc', Tools::getValue($this->prefix . 'status_zamowienia_platnosc'));
            Configuration::updateValue($this->prefix . 'status_zamowienia_wysylka', Tools::getValue($this->prefix . 'status_zamowienia_wysylka'));

            Configuration::updateValue($this->prefix . 'status_zamowienia_korekta', Tools::getValue($this->prefix . 'status_zamowienia_korekta'));
            Configuration::updateValue($this->prefix . 'status_zwrotu_korekta', Tools::getValue($this->prefix . 'status_zwrotu_korekta'));
            
            Configuration::updateValue($this->prefix.'status_zamowienia_store', serialize(Tools::getValue($this->prefix.'status_zamowienia')));
            
            Configuration::updateValue($this->prefix . 'automatyczne', Tools::getValue($this->prefix . 'automatyczne'));
            Configuration::updateValue($this->prefix . 'email_wysylka', Tools::getValue($this->prefix . 'email_wysylka'));
            
            Configuration::updateValue($this->prefix . 'id_warehouse', Tools::getValue($this->prefix . 'id_warehouse'));
            Configuration::updateValue($this->prefix . 'id_department', Tools::getValue($this->prefix . 'id_department'));

            Configuration::updateValue($this->prefix . 'cron_returns', Tools::getValue($this->prefix . 'cron_returns'));
            Configuration::updateValue($this->prefix . 'cron_invoices', Tools::getValue($this->prefix . 'cron_invoices'));
            Configuration::updateValue($this->prefix.'status_zamowienia', serialize(Tools::getValue($this->prefix.'status_zamowienia[]')));
            
            return $this->displayConfirmation($this->l('Mapping saved successfully', 'mjfakturownia'));
        }
    }

    /**
     * Budowa formularza i jego wyrenderowanie
     * @return type
     */
    public function renderForm()
    {
        $orderStates = (new OrderState())->getOrderStates($this->context->language->id);
        $returnStates = OrderReturnState::getOrderReturnStates($this->context->language->id);
        $fields_form = array();

        $fields_form[]['form'] = array(
            'legend' => array(
                'title' => $this->l('API Settings', 'mjfakturownia'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('API key', 'mjfakturownia'),
                    'name' => $this->prefix . 'klucz_api',
                    'disabled' => false,
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Login Fakturownia', 'mjfakturownia'),
                    'name' => $this->prefix . 'login',
                    'disabled' => false,
                    'required' => true,
                )
            ),
            'buttons' => array(
                'checkApi' => array(
                    'title' => $this->l('Check connection', 'mjfakturownia'),
                    'name' => 'checkApi',
                    'type' => 'submit',
                    'id' => 'checkSync',
                    'class' => 'btn btn-default pull-right',
                    'icon' => 'process-icon-refresh'
                ),
                'saveApi' => array(
                    'title' => $this->l('Save', 'mjfakturownia'),
                    'name' => 'saveApi',
                    'type' => 'submit',
                    'id' => 'saveApi',
                    'class' => 'btn btn-default pull-right',
                    'icon' => 'process-icon-save'
                ))
        );

        $fields_form[]['form'] = array(
            'legend' => array(
                'title' => $this->l('Mapping', 'mjfakturownia'),
            ),
            'input' => array(
                array(
                    'type' => 'switch',
                    'label' => $this->l('Creating auto invoice?', 'mjfakturownia'),
                    'desc' => $this->l('When the option is available after setting contains order line, the invoice will be automatically generated', 'mjfakturownia'),
                    'size' => '5',
                    'name' => $this->prefix . 'automatyczne',
                    'is_bool' => true,
                    'required' => true,
                    'values' => array(
                        array(
                            'id' => $this->prefix . 'automatyczne_on',
                            'value' => 1,
                            'label' => $this->l('Yes', 'mjfakturownia'),
                        ),
                        array(
                            'id' => $this->prefix . 'automatyczne_off',
                            'value' => 0,
                            'label' => $this->l('No', 'mjfakturownia'),
                        )
                    ),
                ),
                array(
                    'type' => 'switch',
                    'label' => $this->l('Send invoice by email?', 'mjfakturownia'),
                    'size' => '5',
                    'name' => $this->prefix . 'email_wysylka',
                    'is_bool' => true,
                    'required' => true,
                    'values' => array(
                        array(
                            'id' => $this->prefix . 'email_wysylka_on',
                            'value' => 1,
                            'label' => $this->l('Yes', 'mjfakturownia')
                        ),
                        array(
                            'id' => $this->prefix . 'email_wysylka_off',
                            'value' => 0,
                            'label' => $this->l('No', 'mjfakturownia'),
                        )
                    ),
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'name' => 'save_fakturownia',
                'class' => 'btn btn-default pull-right',
            ),
        );

        $fields_form[]['form'] = array(
            'legend' => array(
                'title' => $this->l('Statusy zamówień'),
            ),
            'input' => array(
                array(
                    'type' => 'select',
                    'label' => $this->l('Order status for sending invoice', 'mjfakturownia'),
                    'name' => $this->prefix . 'status_zamowienia[]',
                    'disabled' => false,
                    'multiple' => true,
                    'required' => true,
                    'options' => array(
                        'query' => $orderStates,
                        'id' => 'id_order_state',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Order status for payment confirmation', 'mjfakturownia'),
                    'name' => $this->prefix . 'status_zamowienia_platnosc',
                    'disabled' => false,
                    'required' => true,
                    'options' => array(
                        'query' => $orderStates,
                        'id' => 'id_order_state',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Order status for shipping', 'mjfakturownia'),
                    'name' => $this->prefix . 'status_zamowienia_wysylka',
                    'disabled' => false,
                    'required' => true,
                    'options' => array(
                        'query' => $orderStates,
                        'id' => 'id_order_state',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Order return for correction', 'mjfakturownia'),
                    'name' => $this->prefix . 'status_zwrotu_korekta',
                    'disabled' => false,
                    'required' => true,
                    'options' => array(
                        'query' => $returnStates,
                        'id' => 'id_order_return_state',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Order status for correction', 'mjfakturownia'),
                    'name' => $this->prefix . 'status_zamowienia_korekta',
                    'disabled' => false,
                    'required' => true,
                    'options' => array(
                        'query' => $orderStates,
                        'id' => 'id_order_state',
                        'name' => 'name',
                    ),
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'name' => 'save_fakturownia',
                'class' => 'btn btn-default pull-right',
            ),
        );
        
        $fields_form[]['form'] = array(
            'legend' => array(
                'title' => $this->l('Cron'),
            ),
            'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Check returns and make correction', 'mjfakturownia'),
                        'name' => $this->prefix . 'cron_returns',
                        'disabled' => false,
                        'required' => true,
                    ),
                array(
                        'type' => 'text',
                        'label' => $this->l('Check orders and invices', 'mjfakturownia'),
                        'name' => $this->prefix . 'cron_invoices',
                        'disabled' => false,
                        'required' => true,
                    ),
                )
            );

        $form = new HelperForm();
        $form->token = Tools::getAdminTokenLite('AdminModules');

        $form->tpl_vars['fields_value'][$this->prefix . 'klucz_api'] = Tools::getValue($this->prefix . 'klucz_api', Configuration::get($this->prefix . 'klucz_api'));
        $form->tpl_vars['fields_value'][$this->prefix . 'login'] = Tools::getValue($this->prefix . 'login', Configuration::get($this->prefix . 'login'));

        $form->tpl_vars['fields_value'][$this->prefix . 'status_zamowienia[]'] =  Tools::getValue($this->prefix . 'status_zamowienia[]', unserialize(Configuration::get($this->prefix.'status_zamowienia_store')));

  
        $form->tpl_vars['fields_value'][$this->prefix . 'email_wysylka'] = Tools::getValue($this->prefix . 'email_wysylka', Configuration::get($this->prefix . 'email_wysylka'));
        $form->tpl_vars['fields_value'][$this->prefix . 'automatyczne'] = Tools::getValue($this->prefix . 'automatyczne', Configuration::get($this->prefix . 'automatyczne'));
        $form->tpl_vars['fields_value'][$this->prefix . 'cron_stock'] = Tools::getValue($this->prefix . 'cron_stock', Configuration::get($this->prefix . 'cron_stock'));

        $form->tpl_vars['fields_value'][$this->prefix . 'status_zamowienia_platnosc'] = Tools::getValue($this->prefix . 'status_zamowienia_platnosc', Configuration::get($this->prefix . 'status_zamowienia_platnosc'));
        $form->tpl_vars['fields_value'][$this->prefix . 'status_zamowienia_wysylka'] = Tools::getValue($this->prefix . 'status_zamowienia_wysylka', Configuration::get($this->prefix . 'status_zamowienia_wysylka'));

        $form->tpl_vars['fields_value'][$this->prefix . 'status_zamowienia_korekta'] = Tools::getValue($this->prefix . 'status_zamowienia_korekta', Configuration::get($this->prefix . 'status_zamowienia_korekta'));
        $form->tpl_vars['fields_value'][$this->prefix . 'status_zwrotu_korekta'] = Tools::getValue($this->prefix . 'status_zwrotu_korekta', Configuration::get($this->prefix . 'status_zwrotu_korekta'));
        
        $form->tpl_vars['fields_value'][$this->prefix . 'id_warehouse'] = Tools::getValue($this->prefix . 'id_warehouse', Configuration::get($this->prefix . 'id_warehouse'));
        $form->tpl_vars['fields_value'][$this->prefix . 'id_department'] = Tools::getValue($this->prefix . 'id_department', Configuration::get($this->prefix . 'id_department'));
        
        $form->tpl_vars['fields_value'][$this->prefix . 'cron_returns'] = Tools::getValue($this->prefix . 'cron_returns', Configuration::get($this->prefix . 'cron_returns'));
        $form->tpl_vars['fields_value'][$this->prefix . 'cron_invoices'] = Tools::getValue($this->prefix . 'cron_invoices', Configuration::get($this->prefix . 'cron_invoices'));
        
        
        return $form->generateForm($fields_form)." TEST: ".Configuration::get($this->prefix.'status_zamowienia_store');
    }

    /**
     * Wyświetla content
     * @return type
     */
    public function getContent()
    {
        return $this->postProcess() . $this->renderForm();
    }

    /**
     * Wykonuje testowego requesta w ramach sprawdzenia połączenia
     * @return boolean
     */
    private function testIntegration()
    {
        $api_token = trim(Configuration::get($this->prefix . 'klucz_api'));

        $url = $this->getApiurl(Configuration::get($this->prefix . 'login'), 'invoices') . '.json?page=1&api_token=' . $api_token;

        $result = $this->makeRequest($url, 'GET', null);

        if (gettype($result) == 'array') {
        } else {
            return false;
        }
        return true;
    }

    /**
     * Przygotowanie ustawień dla curl zwrócenie wykonanego requesta
     * @param type $url
     * @param type $method
     * @param type $data
     * @return type
     */
    private function curl($url, $method, $data)
    {
        $data = json_encode($data);
        $cu = curl_init($url);
        curl_setopt($cu, CURLOPT_VERBOSE, 0);
        curl_setopt($cu, CURLOPT_HEADER, 0);
        curl_setopt($cu, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($cu, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($cu, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($cu, CURLOPT_POSTFIELDS, $data);
        curl_setopt($cu, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-Type: application/json'));
        $response = curl_exec($cu);
        curl_close($cu);

        $result = json_decode($response);
        return $result;
    }

    /**
     * Wykonuje requesta
     * @param type $url
     * @param type $method
     * @param type $data
     * @return type
     */
    private function makeRequest($url, $method, $data)
    {
        return $this->curl($url, $method, $data);
    }

    /**
     * Wykonuje requesta, wysyłając fakturę do fakturowni
     * @param type $invoice_data
     * @param type $url
     * @return type
     */
    private function issueInvoice($invoice_data, $url)
    {
        return $this->makeRequest($url, 'POST', $invoice_data);
    }

    /**
     * Tworzy fakturę dla fakturowni na podstawie wskazanego zamówienia
     * @param type $order
     * @param type $kind
     * @return boolean
     */
    private function invoiceFromorder($order, $kind)
    {
        $address = new Address((int) $order->id_address_invoice);
        if (!Validate::isLoadedObject($address)) {
            return false;
        }
        $country = new Country((int) $address->id_country);
        if (!Validate::isLoadedObject($country)) {
            return false;
        }
        $lang = new Language((int) $order->id_lang);
        if (!Validate::isLoadedObject($lang)) {
            return false;
        }
        $currency = new Currency((int) $order->id_currency);
        if (!Validate::isLoadedObject($currency)) {
            return false;
        }
        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            return false;
        }

        $genders = array('', 'mr', 'mrs');
        $buyer_title = $genders[$customer->id_gender];

        $lang_invoice = Tools::strtolower($lang->iso_code);

        $products = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
		SELECT *
		FROM `' . _DB_PREFIX_ . 'order_detail` od
		LEFT JOIN `' . _DB_PREFIX_ . 'product` p ON (p.id_product = od.product_id)
		LEFT JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON (ps.id_product = p.id_product AND ps.id_shop = od.id_shop)
		LEFT JOIN `' . _DB_PREFIX_ . 'tax_rule` tr ON (tr.id_tax_rules_group = ps.id_tax_rules_group)
		LEFT JOIN `' . _DB_PREFIX_ . 'tax` t ON (t.id_tax = tr.id_tax)
		WHERE od.`id_order` = ' . pSQL((int) ($order->id)).' AND tr.`id_country` = '.(int)(Context::getContext()->country->id));

        $positions = array();

        $quantity_unit = 'szt.';

        foreach ($products as $pr) {
            $zestaw = '';
            if ($pr['cache_is_pack'] != 0) {
                $pack_items = (new Pack())->getItems($pr['id_product'], $this->context->language->id);
                foreach ($pack_items as $key => $pack) {
                    $zestaw .= ' ' . $pack->name . ' (' . $pack->reference . ') x' . $pack->pack_quantity . ' ';
                }
            }

            $nazwa = (($pr['cache_is_pack'] != 0) ? '[Zestaw] ' : '') . $pr['product_name'] . ' (' . $pr['product_reference'] . ')' . (($pr['cache_is_pack'] != 0) ? $zestaw : '');

            $position = array(
                'name' => $nazwa,
                'quantity' => $pr['product_quantity'],
                'quantity_unit' => $quantity_unit,
                'total_price_gross' => $pr['total_price_tax_incl'],
                'tax' => Tools::strtolower($country->iso_code) == 'ch' ? '0% exp.' : $pr['rate'] + 0,
                'code' => $pr['product_reference'],
            );

            $positions[] = $position; // <- tablica z pozycjami na fakturze
        }

        $carriage = array(
            'name' => $this->l('Transport'),
            'quantity' => 1,
            'quantity_unit' => $quantity_unit,
            'total_price_gross' => $order->total_shipping_tax_incl,
            'tax' => Tools::strtolower($country->iso_code) == 'ch' ? '0% exp.' : $pr['rate'] + 0
        );
        if ($order->total_shipping_tax_incl > 0) {
            $positions[] = $carriage;
        }

        if ((float) $order->total_discounts != 0.00) {
            foreach ($order->getDiscounts() as $disc) {
                $discount = array(
                    'name' => $disc['name'],
                    'quantity' => 1,
                    'quantity_unit' => $quantity_unit,
                    'total_price_gross' => -(float) $disc['value'],
                    'tax' => Tools::strtolower($country->iso_code) == 'ch' ? '0% exp.' : $pr['rate'] + 0
                );

                $positions[] = $discount;
            }
        }
        
        if ($kind == 'auto') { #faktura tworzona automatycznie
            if ($this->issue_kind == 'vat_or_receipt') {
                $kind = ($this->isValidnip($address->vat_number) && !empty($address->company)) ? 'vat' : 'receipt';
            } elseif ($this->issue_kind == 'always_receipt') {
                $kind = 'receipt';
            } else {
                $kind = 'vat';
            }
        }

        $biezaca_data = strtotime(date('Y-m-d'));
        if ($order->module == 'mjpaylater') {
            $termin_platnosci = strtotime("+30 day", $biezaca_data);
        } else {
            $termin_platnosci = strtotime("+7 day", $biezaca_data);
        }
        /**
         * Ustawianie daty sprzedaży w zależności od typu płaności
         */
        if ($order->module == 'pscashondelivery') {
            /**
             * Pobranie daty sprzedaży na podstawie czasu kiedy pojawił się status wysłania faktury
             */
            $data_sprzedazy = $this->getDateOrderStateFromId($order->id, Configuration::get($this->prefix . 'status_zamowienia_wysylka'));
        } else if (($order->module == 'payu') || ($order->module == 'mjpaylater') || ($order->module == 'ps_wirepayment') || ($order->module == 'paypal')) {
            /**
             * Pobranie daty sprzedaży na podstawie czasu kiedy pojawił się status płatność zaakceptowana
             */
            $data_sprzedazy = $this->getDateOrderStateFromId($order->id, Configuration::get($this->prefix . 'status_zamowienia_platnosc'));
        } else {
            /**
             * Pobranie daty sprzedaży na podstawie czasu kiedy pojawił się status wysyłki zamówienia
             */
            $data_sprzedazy = $this->getDateOrderStateFromId($order->id, Configuration::get($this->prefix . 'status_zamowienia_wysylka'));
        }
        /**
         * Dane do faktury
         */
        $invoiceData = array(
            'kind' => $kind,
            'sell_date' => date('Y-m-d', strtotime($data_sprzedazy)), //date('Y-m-d', strtotime($order->date_add)),
            'status' => ($order->module == 'mjpaylater') ? 'issued' : ($order->hasBeenPaid() > 0 ? 'paid' : 'issued'),
            'buyer_first_name' => $address->firstname,
            'buyer_last_name' => $address->lastname,
            'payment_to' => date('Y-m-d', $termin_platnosci),
            'buyer_name' => (!empty($address->company) ? $address->company : $address->firstname . ' ' . $address->lastname),
            'buyer_city' => $address->city,
            'buyer_phone' => $address->phone,
            'buyer_country' => $country->iso_code,
            'buyer_post_code' => $address->postcode,
            'buyer_street' => (empty($address->address2) ? $address->address1 : $address->address1 . ', ' . $address->address2),
            'oid' => $order->id, //getUniqReference(),
            'buyer_email' => $customer->email,
            'positions' => $positions,
            'currency' => $currency->iso_code,
            'buyer_title' => $buyer_title,
            'buyer_company' => (empty($address->company) ? false : true),
            'description' => 'Zamówienie nr #' . $order->id,
        );

        if (Tools::strtolower($currency->iso_code) != 'pln') {
            $invoiceData['exchange_currency'] = 'PLN';
        }


        if ($order->payment == 'PayPal') {
            $invoiceData['payment_type'] = 'Pay Pal';
        } else {
            $invoiceData['payment_type'] = $order->payment;
        }

        if ($address->vat_number != null || $address->vat_number != "undefined") {
            $invoiceData['buyer_tax_no'] = $address->vat_number;
        }

        if (!empty($this->department_id)) {
            $invoiceData['department_id'] = (int) $this->department_id;
        }

        if (!empty($this->shop_lang) && $this->shop_lang != $lang_invoice) {
            if ($lang_invoice == 'cs') {
                $invoiceData['lang'] = 'cz/' . $this->shop_lang;
            } elseif ($this->shop_lang == 'cs') {
                $invoiceData['lang'] = $lang_invoice . '/cz';
            } elseif ($lang_invoice == 'gb') {
                $invoiceData['lang'] = 'en-GB/' . $this->shop_lang;
            } elseif ($this->shop_lang == 'gb') {
                $invoiceData['lang'] = $lang_invoice . '/en-GB';
            } else {
                $invoiceData['lang'] = $lang_invoice . '/' . $this->shop_lang;
            }
        } else {
            if ($lang_invoice == 'cs') {
                $invoiceData['lang'] = 'cz';
            } elseif ($lang_invoice == 'gb') {
                $invoiceData['lang'] = 'en-GB';
            } else {
                $invoiceData['lang'] = $lang_invoice;
            }
        }

        $data = array(
            'api_token' => $this->api_token,
            'invoice' => $invoiceData
        );
        return $data;
    }

    /**
     * Wysyła testową fakturę
     * @return array
     */
    private function getTestinvoice()
    {
        $data = array(
            'invoice' => array(
                'issue_date' => date('Y-m-d'),
                'number' => 'prestashop_integration_test',
                'kind' => 'vat',
                'buyer_first_name' => 'buyer_first_name_test',
                'buyer_last_name' => 'buyer_last_name_test',
                'buyer_name' => 'prestashop_integration_test',
                'buyer_city' => 'buyer_city',
                'buyer_phone' => '221234567',
                'buyer_country' => 'PL',
                'buyer_post_code' => '01-345',
                'buyer_street' => 'buyer_street',
                'oid' => 'test_oid',
                'buyer_email' => 'buyer_email@test.pl',
                'buyer_tax_no' => '2923019583',
                'payment_type' => 'transfer',
                'lang' => 'pl',
                'currency' => 'PLN',
                'positions' => array(array('name' => 'prestashop integration test', 'kind' => 'text_separator', 'tax' => 'disabled', 'total_price_gross' => 0, 'quantity' => 0))
            )
        );
        return $data;
    }

    /**
     * Zwraca url dla faktur
     * @param type $account_prefix
     * @return type
     */
    private function getInvoicesurl($account_prefix)
    {
        return $this->getApiurl($account_prefix, 'invoices');
    }

    /**
     * Zwraca url dla dokumentów WZ
     * @param type $account_prefix
     * @return type
     */
    private function getWzurl($account_prefix)
    {
        return $this->getApiurl($account_prefix, 'warehouse_documents');
    }

    /**
     * Zwraca url dla api
     * @param type $account_prefix
     * @param type $controller
     * @return string
     */
    private function getApiurl($account_prefix, $controller)
    {
        $url = 'https://' . $account_prefix . '.' . $this->api_url . '/' . $controller;
        return $url;
    }

    /**
     * Sprawdza czy ustawiliśmy sobie klucz api i login
     * @return type
     */
    private function isConfigured()
    {
        return !(empty(Configuration::get($this->prefix . 'klucz_api')) || empty(Configuration::get($this->prefix . 'login')));
    }

    /**
     * Sprwadza czy w bazie siedzi wystawiona fv
     * @param type $order_id_fakturownia
     * @return type
     */
    public function getLastinvoice($order_id_fakturownia)
    {
        //Sprawdzenie czy w bazie jest rekord z fakturą
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
	        SELECT *
	        FROM `' . _DB_PREFIX_ . 'mjfakturownia_invoice`
	        WHERE `id_order` = ' . pSQL((int) ($order_id_fakturownia)) .
                ' AND id_fv != "0" ORDER BY `id_mjfakturownia` DESC LIMIT 1');
    }

    /**
     * Sprawdzenie czy istnieje rekord w tabeli dot fakturowni
     * @param type $id_order
     * @return type
     */
    public function checkInvoice($id_order)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
	        SELECT *
	        FROM `' . _DB_PREFIX_ . 'mjfakturownia_invoice`
	        WHERE `id_order` = ' . pSQL((int) ($id_order)) .
                ' ORDER BY `id_mjfakturownia` DESC LIMIT 1');
    }
    /**
     * Sprawdza czy w bazie siedzi wygenerowanie WZ
     * @param type $order_id_fakturownia
     * @return type
     */
    private function getLastwz($order_id_fakturownia)
    {
        //Sprawdzenie czy w bazie jest rekord z wz
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
	        SELECT *
	        FROM `' . _DB_PREFIX_ . 'mjfakturownia_invoice`
	        WHERE `id_order` = ' . pSQL((int) ($order_id_fakturownia)) .
                ' AND id_wz != "0" ORDER BY `id_mjfakturownia` DESC LIMIT 1');
    }

    /**
     * Usuwa fakture korygującą
     * @param type $id_order
     */
    public function deleteKor($id_order)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'mjfakturownia_invoice` WHERE id_order = "' . pSQL($id_order) . '"';
        $api_token = trim(Configuration::get($this->prefix . 'klucz_api'));

        $id_kor = '';
        foreach (Db::getInstance()->ExecuteS($sql, 1, 0) as $kor) {
            $id_kor = $kor['id_kor'];
        }
        
        $url_kor = $this->getApiurl(Configuration::get($this->prefix . 'login'), 'invoices') . '/' . $id_kor . '.json?page=1&api_token=' . $api_token;
        $this->makeRequest($url_kor, 'DELETE', null);

        if (count(Db::getInstance()->ExecuteS($sql, 1, 0)) > 0) {
            $sqlKor = 'UPDATE `' . _DB_PREFIX_ . 'mjfakturownia_invoice` SET id_kor = "0" WHERE id_order = "' . pSQL($id_order) . '"';
            Db::getInstance()->Execute($sqlKor, 1, 0);
        }
    }
    
    /**
     * Usunięcie WZ
     * @param type $id_order
     */
    public function deleteWz($id_order)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'mjfakturownia_invoice` WHERE id_order = "' . pSQL($id_order) . '"';
        $api_token = trim(Configuration::get($this->prefix . 'klucz_api'));

        $id_wz = '';
        foreach (Db::getInstance()->ExecuteS($sql, 1, 0) as $wz) {
            $id_wz = $wz['id_wz'];
        }

        $url_wz = $this->getApiurl(Configuration::get($this->prefix . 'login'), 'warehouse_documents') . '/' . $id_wz . '.json?page=1&api_token=' . $api_token;
        $this->makeRequest($url_wz, 'DELETE', null);

        if (count(Db::getInstance()->ExecuteS($sql, 1, 0)) > 0) {
            $sql2 = 'UPDATE `' . _DB_PREFIX_ . 'mjfakturownia_invoice` SET id_wz = "0" WHERE id_order = "' . pSQL($id_order) . '"';
            Db::getInstance()->Execute($sql2, 1, 0);
        }
    }

    /**
     * Usunięcie faktury
     * @param type $id_order
     */
    public function deleteInvoice($id_order)
    {

        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'mjfakturownia_invoice` WHERE id_order = "' . pSQL($id_order) . '"';
        $api_token = trim(Configuration::get($this->prefix . 'klucz_api'));

        $id_faktury_fakturownia = '';
        foreach (Db::getInstance()->ExecuteS($sql, 1, 0) as $faktura) {
            $id_faktury_fakturownia = $faktura['id_fv'];
        }

        $url = $this->getApiurl(Configuration::get($this->prefix . 'login'), 'invoices') . '/' . $id_faktury_fakturownia . '.json?page=1&api_token=' . $api_token;
        $this->makeRequest($url, 'DELETE', null);

        if (count(Db::getInstance()->ExecuteS($sql, 1, 0)) > 0) {
            //$sql2 = 'DELETE FROM `'._DB_PREFIX_.'mjfakturownia_invoice` WHERE id_order = "'.pSQL($id_order).'"';
            $sql2 = 'UPDATE `' . _DB_PREFIX_ . 'mjfakturownia_invoice` SET id_fv = "0" WHERE id_order = "' . pSQL($id_order) . '"';
            Db::getInstance()->Execute($sql2, 1, 0);
        }
    }

    /**
     * Wysłanie faktury
     * @param type $id_order
     * @param type $kind
     * @return boolean
     */
    public function sendInvoice($id_order, $kind)
    {
        $order = new Order((int) $id_order);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }
        $url = $this->getInvoicesurl($this->account_prefix) . '.json';
        $invoice_data = $this->invoiceFromorder($order, $kind);

        $check_invoice = $this->checkInvoice($id_order);
        $last_invoice = $this->getLastinvoice($id_order);
        if (empty($last_invoice) || (!empty($last_invoice) && $last_invoice[0]['id_fv'] == 0)) { //czy w miedzyczasie nie wystawila sie automatycznie
            $result = $this->issueInvoice($invoice_data, $url);

            if (count($check_invoice) > 0) {
                $this->aktualizujFakture($id_order, $result->id);
            } else {
                $this->dodajFakture($id_order, $result->id, 'invoice', '');
            }
        } else {
                $this->dodajFakture($id_order, $result->id, 'invoice');
        }
        /**
        * Wyślij email
        */
        $this->sendEmail($result);
    }

    /**
     * Wysyła klientowi emaila
     * @param type $result
     */
    private function sendEmail($result)
    {
        if (Configuration::get($this->prefix.'email_wysylka') == '1') {
            $url = $this->getInvoicesurl($this->account_prefix).'/'.$result->id.'/send_by_email.json?api_token='.$this->api_token;
            $this->makeRequest($url, 'POST', null);
        }
    }
    /**
     * Sprawdzenie nipu
     * @param type $nip
     * @return boolean
     */
    private function isValidnip($nip)
    {
        $nip = preg_replace("/[A-Za-z]/", "", $nip);
        $nip = str_replace('-', '', $nip);
        $nip = str_replace(' ', '', $nip);

        if (Tools::strlen($nip) != 10) {
            return false;
        }

        $weights = array(6, 5, 7, 2, 3, 4, 5, 6, 7);
        $control = 0;

        for ($i = 0; $i < 9; $i++) {
            $control += $weights[$i] * $nip[$i];
        }
        $control = $control % 11;

        if ($control == $nip[9]) {
            return true;
        }
        return false;
    }
    /**
     * Zwrot ścieżki modułu
     */
    public function assignModuleVars()
    {
        $this->context->smarty->assign(array(
            'module_path' => $this->_path
        ));
    }

    /**
     * Synchronizacja ilości między PS a fakturownią
     */
    public function syncQty()
    {
        //Pobierz wszystkie produkty z presty które mają referencje
        // Poleć foreach po nich i zaktualizuj je w preście:
        $getProductFromPS = @Product::getProducts($this->context->language->id, 0, 99999, 'id_product', 'ASC', false, true);

        foreach ($getProductFromPS as $PSproduct) {
            if ($PSproduct['reference'] != '') {
                $url_produkty = $this->getApiurl(Configuration::get($this->prefix . 'login'), 'products') . '/' . $PSproduct['reference'] . '.json?api_token=' . $this->api_token;
                $result_produkty = $this->makeRequest($url_produkty, 'GET', null);
                //------------------------------------------------------
                // Ustaw ilości dla zwyłych produktów
                //------------------------------------------------------
                $p = @Mjfakturownia::getIdByReference($result_produkty->id);//$product->id
                if ($p) {
                    $prod = new Product($p);
                    $prod->quantity = (int) $result_produkty->warehouse_quantity;
                    $prod->update();
                    StockAvailable::setQuantity($p, null, (int) $result_produkty->warehouse_quantity);
                }
            }
        }
    }
    public function getDateOrderStateFromId($id_order, $id_order_state)
    {
        $query = "SELECT * FROM " . _DB_PREFIX_ . "order_history WHERE id_order = '" . pSQL($id_order) . "' AND id_order_state = '" . pSQL($id_order_state) . "' ORDER BY id_order_history DESC LIMIT 1";
        if (count(DB::getInstance()->ExecuteS($query, 1, 0)) > 0) {
            return DB::getInstance()->ExecuteS($query, 1, 0)[0]['date_add'];
        } else {
            return date('Y-m-d');
        }
    }

    public function getLastDateOrderState($id_order)
    {
        $query = "SELECT * FROM " . _DB_PREFIX_ . "order_history WHERE id_order = '" . pSQL($id_order) . "' ORDER BY id_order_history DESC";
        return DB::getInstance()->ExecuteS($query, 1, 0)[0]['date_add'];
    }
    /**
     * Pobranie id produktu na podstawie referencji
     * @param type $reference
     * @return int
     */
    public static function getIdByReference($reference)
    {
        if (empty($reference)) {
            return 0;
        }

        if (!Validate::isReference($reference)) {
            return 0;
        }

        $query = new DbQuery();
        $query->select('p.id_product');
        $query->from('product', 'p');
        $query->where('p.reference = \'' . pSQL($reference) . '\'');

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($query);
    }
    /**
     * Wystawienie korekty
     * @param type $order
     * @param type $id_invoice
     * @return type
     */
    public function makeCorrection($order, $id_invoice)
    {
    // Dodaj fakturę korygującą
        $address = new Address($order->id_address_invoice);
        $customer = new Customer($order->id_customer);
        $products = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
            SELECT *
            FROM `'._DB_PREFIX_.'order_detail` od
            LEFT JOIN `'._DB_PREFIX_.'product` p ON (p.id_product = od.product_id)
            LEFT JOIN `'._DB_PREFIX_.'product_shop` ps ON (ps.id_product = p.id_product AND ps.id_shop = od.id_shop)
            LEFT JOIN `'._DB_PREFIX_.'tax_rule` tr ON (tr.id_tax_rules_group = ps.id_tax_rules_group)
            LEFT JOIN `'._DB_PREFIX_.'tax` t ON (t.id_tax = tr.id_tax)
            WHERE od.`id_order` = '.(int)($order->id).' AND tr.`id_country` = '.(int)(Context::getContext()->country->id));

            $positions = array();

            $quantity_unit = 'szt.';

            $tax_rate = 0;
            foreach ($products as $pr) {
                $tax_rate = $pr['rate'];
                $zestaw = '';

                if ($pr['cache_is_pack'] != 0) {

                        $pack_items = (new Pack())->getItems($pr['id_product'], $this->context->language->id);

                        foreach ($pack_items as $key => $pack) {
                                $zestaw .= ' '.$pack->name.' ('.$pack->reference.') x'.$pack->pack_quantity.' ';
                        }
                }

                $nazwa = (($pr['cache_is_pack']!=0) ? '[Zestaw] ' : '').$pr['product_name'].' ('.$pr['product_reference'].')'.(($pr['cache_is_pack']!=0) ? $zestaw : '');
                        
                $position = array(
                    'name' => $nazwa,
                    'quantity' => $pr['product_quantity']*(-1),
                    'kind' => "correction",
                    'total_price_gross' => $pr['total_price_tax_incl']*(-1),
                    'tax' => $pr['rate'],
                    'correction_before_attributes' => array(
                        'name' => $nazwa,
                        'quantity' => $pr['product_quantity'],
                        'total_price_gross' => $pr['total_price_tax_incl'],
                        'tax' => $pr['rate'],
                        "kind" =>  "correction_before"
                    ),
                    'correction_after_attributes' => array(
                        'name' => $nazwa,
                        'quantity' => 0,
                        'total_price_gross' => $pr['total_price_tax_incl'],
                        'tax' => $pr['rate'],
                        "kind" => "correction_after"
                    )
                );

                    $positions[] = $position; // <- tablica z pozycjami na fakturze
                }
            
            //Pobranie wysyłki
            $carriage = array(
                    'name' => "Wysyłka",//$this->l('shipping'),//$shippingTmp[0]['carrier_name']
                    'quantity' => -1,
                    'kind' => "correction",
                    'total_price_gross' => $order->total_shipping_tax_incl*(-1),
                    'tax' => $tax_rate,
                    'correction_before_attributes' => array(
                        'name' => "Wysyłka",
                        'quantity' => 1,
                        'total_price_gross' => $order->total_shipping_tax_incl,
                        'tax' => $tax_rate,
                        "kind" =>  "correction_before"
                    ),
                    'correction_after_attributes' => array(
                        'name' => "Wysyłka",
                        'quantity' => 0,
                        'total_price_gross' => $pr['total_price_tax_incl'],
                        'tax' => $tax_rate,
                        "kind" => "correction_after"
                    )
		);
		if ($order->total_shipping_tax_incl > 0) {
			$positions[] = $carriage;
		}

            // Pobranie zniżek
            if ((float)$order->total_discounts != 0.00) {
                    foreach ($order->getDiscounts() as $disc) {
                            $discount = array(
                                'name' => $disc['name'],
                                'quantity' => -1,
                                'kind' => "correction",
                                'total_price_gross' => (float)$disc['value'],
                                'tax' => $tax_rate,
                                'correction_before_attributes' => array(
                                    'name' => $disc['name'],
                                    'quantity' => 1,
                                    'total_price_gross' => -(float)$disc['value'],
                                    'tax' => $tax_rate,
                                    "kind" =>  "correction_before"
                                ),
                                'correction_after_attributes' => array(
                                    'name' => $disc['name'],
                                    'quantity' => 0,
                                    'total_price_gross' => 0,
                                    'tax' => $tax_rate,
                                    "kind" => "correction_after"
                                )
                            );

                            $positions[] = $discount;
                    }
            }

        $country = new Country($address->id_country);
        $buyer_name = (!empty($address->company) ? $address->company : $address->firstname . ' ' . $address->lastname);
        $buyer_street = (empty($address->address2) ? $address->address1 : $address->address1 . ', ' . $address->address2);
        $host = Configuration::get('mjfakturownia_login').'.fakturownia.pl';
        $token = $this->api_token;
        $json ='{ "api_token": "'.$token.'", "invoice": { "kind":"correction", '
                . '"status" : "paid", '
                . '"correction_reason": "Zwrot zamówienia #'.$order->reference.'", '
                . '"invoice_id": "'.$id_invoice.'", '
                . '"from_invoice_id": "'.$id_invoice.'", '
                . '"buyer_name" : "'.$buyer_name.'",'
                . '"buyer_city" : "'.$address->city.'",'
                . '"buyer_phone" : "'.$address->phone.'",'
                . '"buyer_country" : "'.$country->iso_code.'",'
                . '"buyer_post_code" : "'.$address->postcode.'",'
                . '"buyer_email" : "'.$customer->email.'",'
                . '"buyer_street" : "'.$buyer_street.'",'
                . '"positions": '.json_encode($positions).'}}';

        $c = curl_init();
        curl_setopt($c, CURLOPT_URL, 'https://'.$host.'/invoices.json');
        $head = array();
        $head[] ='Accept: application/json';
        $head[] ='Content-Type: application/json';
        curl_setopt($c, CURLOPT_HTTPHEADER, $head);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($c, CURLOPT_POSTFIELDS, $json);

        $response = @json_decode(curl_exec($c));

        // update id_fv_kor
        $sql = "UPDATE "._DB_PREFIX_."mjfakturownia_invoice SET id_kor = '".pSQL($response->id)."' WHERE id_order = '".pSQL($order->id)."'";
        DB::getInstance()->Execute($sql, 1, 0);

        /**
        * Wyślij email
        */
        $this->sendEmail($response);

        return $this->displayConfirmation($this->l('Correction has correctly sent', 'mjfakturownia'));
    }
    
    /**
     * Automatyczne wystawianie korekt wg zalożeń
     */
    public function syncReturns()
    {
        //Pobierz wszystkie zwroty z ostatnich 14 dni i statusem (zaakceptowane)
        // Sprawdź które z nich są w tabeli z fakturownią
        // Te niestniejące w tabeli a istniejące w modelu Returns wystaw pętlą
        foreach ($this->getReturns() as $return) {
            $checkIfExistFakturownia = "SELECT * FROM "._DB_PREFIX_."mjfakturownia_invoice WHERE id_kor = '0' AND id_fv != '0'";
            foreach (DB::getInstance()->ExecuteS($checkIfExistFakturownia, 1, 0) as $doKorekty) {
                $order = new Order($return['id_order']);
                $this->makeCorrection($order, $doKorekty['id_fv']);
            }
            
        }
    }
   
    /**
     * Zwrócenie listy zwrotów z ostatnich 14 dni z okreslonym statuse (zaakceptowane)
     * @return type
     */
    private function getReturns()
    {
        $prev_date = date('Y-m-d', strtotime("-14 day", strtotime(date('Y-m-d'))));
        $sql = "SELECT * FROM "._DB_PREFIX_."order_return WHERE date_add >= '".$prev_date."' AND state = '".Configuration::get($this->prefix.'status_zwrotu_korekta')."'";
        return DB::getInstance()->ExecuteS($sql, 1, 0);
    }


    /**
     * Synchronizacja dla crona wystawiania faktur
     */
    public function syncInvoices()
    {
        // Pobierz zamówienia bez faktury
        // Sprawdź staus zamówienia wyekstrahuj go z tablicy dozwolonych statusów
        // Dla zdefiniowanych w konfigu statusów wystaw faktury w pętli
        $getEmptyOrders = "SELECT * FROM "._DB_PREFIX_."mjfakturownia_invoice WHERE id_fv = '0'";
        foreach (DB::getInstance()->Executes($getEmptyOrders, 1, 0) as $eo) {
            $order = new Order($eo['id_order']);
            $allow_states = unserialize(Configuration::get($this->prefix.'status_zamowienia_store'));
            if ((in_array($order->current_state, $allow_states))) {
                $this->sendInvoice($order->id, 'auto');
            }
        }
    }
}
