<?php
/**
 * Module Mjfakturownia
 * @author MAGES Michał Jendraszczyk
 * @copyright (c) 2020, MAGES Michał Jendraszczyk
 * @license http://mages.pl MAGES Michał Jendraszczyk
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
        $this->author = 'MAGES Michał Jendraszczyk';
        $this->version = '1.0.0';
        $this->module_key = '62c288ebff06de6c404602a1809984ee';

        $this->prefix = $this->name . "_";
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('Fakturownia - Integration');
        $this->description = $this->l('Module which can send invoice from your store to Fakturownia.pl');

        $this->confirmUninstall = $this->l('Remove module?');

        $this->api_token = Configuration::get('FAKTUROWNIA_API_TOKEN');
        $this->department_id = Configuration::get('FAKTUROWNIA_DEPARTMENT_ID');
        $this->account_prefix = Configuration::get($this->prefix . 'login');
        $this->shop_lang = Configuration::get('FAKTUROWNIA_SHOP_LANG');

        $this->api_url = Configuration::get('FAKTUROWNIA_API_URL');
        $this->issue_kind = Configuration::get('FAKTUROWNIA_ISSUE_KIND');
        $this->auto_send = Configuration::get('FAKTUROWNIA_AUTO_SEND');
        $this->auto_issue = Configuration::get('FAKTUROWNIA_AUTO_ISSUE');
        $this->api_token = trim(Configuration::get($this->prefix . 'klucz_api'));
    }

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

    public function uninstallModuleTab($tabClass)
    {
        $idTab = Tab::getIdFromClassName($tabClass);
        if ($idTab) {
            $tab = new Tab($idTab);
            $tab->delete();
        }
    }

    public function install()
    {
        Configuration::updateValue('FAKTUROWNIA_API_URL', "fakturownia.pl");
        Configuration::updateValue('FAKTUROWNIA_ISSUE_KIND', 'vat_or_receipt');
        Configuration::updateValue('FAKTUROWNIA_AUTO_SEND', 'disabled');
        Configuration::updateValue('FAKTUROWNIA_AUTO_ISSUE', 'enabled');

        Configuration::updateValue($this->prefix . 'cron_stock', Tools::getHttpHost(true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/cron_stocks.php');

        Db::getInstance()->Execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mjfakturownia_invoice` (
        `id_mjfakturownia` INT(11) UNSIGNED NOT null auto_increment,
        `id_order` INT(11) UNSIGNED NOT null,
        `view_url` VARCHAR(255),
        `external_id` INT(11),
        `id_wz` INT(11),
        PRIMARY KEY (`id_mjfakturownia`),
        KEY `id_order` (`id_order`)) DEFAULT CHARSET=utf8;');


        return parent::install() && $this->registerHook('actionOrderStatusPostUpdate') && $this->registerHook('displayAdminOrder') && $this->registerHook('actionValidateOrder') && $this->installModuleTab('AdminMjfakturowniainvoice', array(Configuration::get('PS_LANG_DEFAULT') => 'Fakturownia'), Tab::getIdFromClassName('AdminParentOrders'));
    }

    // Wysyłka WZ po otrzymaniu zamówienia
    public function hookActionValidateOrder($params)
    {
        $order = $params['order'];
        $this->sendWz($order);
    }

    public function aktualizujFakture($id_order, $id_invoice)
    {
        Db::getInstance()->Execute('UPDATE `' . _DB_PREFIX_ . 'mjfakturownia_invoice` SET `external_id` = "' . pSQL($id_invoice) . '" WHERE id_order = "' . pSQL($id_order) . '"');
    }

    public function dodajFakture($id_order, $id_wz, $typ)
    {
        if ($typ == 'wz') {
            Db::getInstance()->Execute('INSERT INTO `' . _DB_PREFIX_ . 'mjfakturownia_invoice` (`id_order`, `view_url`, `external_id`,`id_wz`) VALUES ("' . pSQL((int) $id_order) . '", "", "","' . pSQL($id_wz) . '")');
        } else {
            Db::getInstance()->Execute('INSERT INTO `' . _DB_PREFIX_ . 'mjfakturownia_invoice` (`id_order`, `view_url`, `external_id`,`id_wz`) VALUES ("' . pSQL((int) $id_order) . '", "", "'.pSQL($id_wz).'","")');
        }
    }

    // Wysyłka faktury po ustawieniu okreslonego statusu
    public function hookActionOrderStatusPostUpdate($params)
    {
        $order = new Order($params['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }

        $new_status = $params['newOrderStatus']; // nowy status zamówienia
        $last_invoice = $this->getLastinvoice($order->id); // Sprawdzenie czy w bazie jest rekord z faktura

        if ($params['newOrderStatus']->id == Configuration::get($this->prefix . 'status_zamowienia') && empty($last_invoice) && Configuration::get($this->prefix . 'automatyczne') == '1') {
            if ($order->total_paid > 0) {
                $url = $this->getInvoicesurl($this->account_prefix) . '.json';
                $invoice_data = $this->invoiceFromorder($order);
                $invoice_data['invoice']['status'] = 'paid';
                $result = $this->issueInvoice($invoice_data, $url);
                //$this->after_send_actions($result, $order);
                //$this->afterSendactions($result, $order);
                $this->aktualizujFakture($order->id, $result->id);
            }
        }
    }

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
    // Wyswietlanie opcji wysyłki faktur z szczegółów zamówienia
    public function hookDisplayAdminOrder()
    {
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

    public function uninstall()
    {
        return parent::uninstall() && $this->uninstallModuleTab('AdminMjfakturowniainvoice');
    }

    public function postProcess()
    {
        if (Tools::isSubmit('saveApi')) {
            if ((Validate::isString(Tools::getValue($this->prefix . 'klucz_api'))) && (Tools::strlen(Tools::getValue($this->prefix . 'klucz_api')) > 0) && (Tools::strlen(Tools::getValue($this->prefix . 'login')) > 0) && (Validate::isString(Tools::getValue($this->prefix . 'login')))) {
                Configuration::updateValue($this->prefix . 'klucz_api', Tools::getValue($this->prefix . 'klucz_api'));
                Configuration::updateValue($this->prefix . 'login', Tools::getValue($this->prefix . 'login'));
                return $this->displayConfirmation($this->l('Saved successfully'));
            } else {
                return $this->displayError($this->l('API key is invalid'));
            }
        } if (Tools::isSubmit('checkApi')) {
            $this->testIntegration();
            if (!$this->testIntegration()) {
                return $this->displayError($this->l('Error while API connection'));
            } else {
                return $this->displayConfirmation($this->l('Connection successfully'));
            }
        } if (Tools::isSubmit('save_fakturownia')) {
            Configuration::updateValue($this->prefix . 'cron_stock', Tools::getHttpHost(true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/cron_stocks.php');
            Configuration::updateValue($this->prefix . 'status_zamowienia', Tools::getValue($this->prefix . 'status_zamowienia'));

            Configuration::updateValue($this->prefix . 'status_zamowienia_platnosc', Tools::getValue($this->prefix . 'status_zamowienia_platnosc'));
            Configuration::updateValue($this->prefix . 'status_zamowienia_wysylka', Tools::getValue($this->prefix . 'status_zamowienia_wysylka'));

            Configuration::updateValue($this->prefix . 'automatyczne', Tools::getValue($this->prefix . 'automatyczne'));
            Configuration::updateValue($this->prefix . 'email_wysylka', Tools::getValue($this->prefix . 'email_wysylka'));
            
            Configuration::updateValue($this->prefix . 'id_warehouse', Tools::getValue($this->prefix . 'id_warehouse'));
            Configuration::updateValue($this->prefix . 'id_department', Tools::getValue($this->prefix . 'id_department'));

            return $this->displayConfirmation($this->l('Mapping saved successfully'));
        }
    }

    // Budowanie formularza
    public function renderForm()
    {
        $orderStates = (new OrderState())->getOrderStates($this->context->language->id);
        $fields_form = array();

        $url_magazyn = $this->getApiurl(Configuration::get($this->prefix . 'login'), 'warehouses') . '.json?page=1&api_token=' . $this->api_token;

        $result_magazyn = $this->makeRequest($url_magazyn, 'GET', null);

        $produkty = $this->getApiurl(Configuration::get($this->prefix . 'login'), 'products') . '.json?page=1&api_token=' . $this->api_token;

        $result_produkty = $this->makeRequest($produkty, 'GET', null);

        $magazyny = array();

        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('API Settings'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('API key'),
                    'name' => $this->prefix . 'klucz_api',
                    'disabled' => false,
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Login Fakturownia'),
                    'name' => $this->prefix . 'login',
                    'disabled' => false,
                    'required' => true,
                )
            ),
            'buttons' => array(
                'checkApi' => array(
                    'title' => $this->l('Check connection'),
                    'name' => 'checkApi',
                    'type' => 'submit',
                    'id' => 'checkSync',
                    'class' => 'btn btn-default pull-right',
                    'icon' => 'process-icon-refresh'
                ),
                'saveApi' => array(
                    'title' => $this->l('Save'),
                    'name' => 'saveApi',
                    'type' => 'submit',
                    'id' => 'saveApi',
                    'class' => 'btn btn-default pull-right',
                    'icon' => 'process-icon-save'
                ))
        );

        $fields_form[1]['form'] = array(
            'legend' => array(
                'title' => $this->l('Mapping'),
            ),
            'input' => array(
                array(
                    'type' => 'switch',
                    'label' => $this->l('Creating auto invoice?'),
                    'desc' => $this->l('When the option is available after setting contains order line, the invoice will be automatically generated'),
                    'size' => '5',
                    'name' => $this->prefix . 'automatyczne',
                    'is_bool' => true,
                    'required' => true,
                    'values' => array(
                        array(
                            'id' => $this->prefix . 'automatyczne_on',
                            'value' => 1,
                            'label' => $this->l('Yes')
                        ),
                        array(
                            'id' => $this->prefix . 'automatyczne_off',
                            'value' => 0,
                            'label' => $this->l('No')
                        )
                    ),
                ),
                array(
                    'type' => 'switch',
                    'label' => $this->l('Send invoice by email?'),
                    'size' => '5',
                    'name' => $this->prefix . 'email_wysylka',
                    'is_bool' => true,
                    'required' => true,
                    'values' => array(
                        array(
                            'id' => $this->prefix . 'email_wysylka_on',
                            'value' => 1,
                            'label' => $this->l('Yes')
                        ),
                        array(
                            'id' => $this->prefix . 'email_wysylka_off',
                            'value' => 0,
                            'label' => $this->l('No')
                        )
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Order status for sending invoice'),
                    'name' => $this->prefix . 'status_zamowienia',
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
                    'label' => $this->l('Order status for payment confirmation'),
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
                    'label' => $this->l('Order status for shipping'),
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
                    'type' => 'text',
                    'label' => $this->l('ID warehouse'),
                    'name' => $this->prefix . 'id_warehouse',
                    'disabled' => false,
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('ID department'),
                    'name' => $this->prefix . 'id_department',
                    'disabled' => false,
                    'required' => true,
                )
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'name' => 'save_fakturownia',
                'class' => 'btn btn-default pull-right',
            ),
        );

        $fields_form[3]['form'] = array(
            'legend' => array(
                'title' => $this->l('Cron Fakturownia'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('Cron for sychronization of stock levels'),
                    'name' => $this->prefix . 'cron_stock',
                    'disabled' => true,
                    'required' => true,
                ),
            )
        );

        $form = new HelperForm();
        $form->token = Tools::getAdminTokenLite('AdminModules');

        $form->tpl_vars['fields_value'][$this->prefix . 'klucz_api'] = Tools::getValue($this->prefix . 'klucz_api', Configuration::get($this->prefix . 'klucz_api'));
        $form->tpl_vars['fields_value'][$this->prefix . 'login'] = Tools::getValue($this->prefix . 'login', Configuration::get($this->prefix . 'login'));

        $form->tpl_vars['fields_value'][$this->prefix . 'status_zamowienia'] = Tools::getValue($this->prefix . 'status_zamowienia', Configuration::get($this->prefix . 'status_zamowienia'));

        $form->tpl_vars['fields_value'][$this->prefix . 'email_wysylka'] = Tools::getValue($this->prefix . 'email_wysylka', Configuration::get($this->prefix . 'email_wysylka'));
        $form->tpl_vars['fields_value'][$this->prefix . 'automatyczne'] = Tools::getValue($this->prefix . 'automatyczne', Configuration::get($this->prefix . 'automatyczne'));
        $form->tpl_vars['fields_value'][$this->prefix . 'cron_stock'] = Tools::getValue($this->prefix . 'cron_stock', Configuration::get($this->prefix . 'cron_stock'));

        $form->tpl_vars['fields_value'][$this->prefix . 'status_zamowienia_platnosc'] = Tools::getValue($this->prefix . 'status_zamowienia_platnosc', Configuration::get($this->prefix . 'status_zamowienia_platnosc'));
        $form->tpl_vars['fields_value'][$this->prefix . 'status_zamowienia_wysylka'] = Tools::getValue($this->prefix . 'status_zamowienia_zakonczenie', Configuration::get($this->prefix . 'status_zamowienia_wysylka'));

        $form->tpl_vars['fields_value'][$this->prefix . 'id_warehouse'] = Tools::getValue($this->prefix . 'id_warehouse', Configuration::get($this->prefix . 'id_warehouse'));
        $form->tpl_vars['fields_value'][$this->prefix . 'id_department'] = Tools::getValue($this->prefix . 'id_department', Configuration::get($this->prefix . 'id_department'));
        
        return $form->generateForm($fields_form);
    }

    public function getContent()
    {
        return $this->postProcess() . $this->renderForm();
    }

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

    private function getContents($url, $method, $data)
    {
        $tmp_user_agent = ini_get('user_agent');
        if (version_compare(PHP_VERSION, '5.3.0') == -1) {
            ini_set('user_agent', 'PHP/' . PHP_VERSION . "\r\n" . "Content-Type: application/json\r\nAccept: application/json");
        }

        $options = array(
            'http' => array(
                'method' => $method,
                'content' => json_encode($data),
                'header' => "Content-Type: application/json\r\n" .
                "Accept: application/json\r\n",
                'max_redirects' => '0',
                'ignore_errors' => '1'
            )
        );

        $context = stream_context_create($options);
        $response = Tools::file_get_contents($url, false, $context);
        $result = json_decode($response);

        if (version_compare(PHP_VERSION, '5.3.0') == -1) {
            ini_set('user_agent', $tmp_user_agent);
        }

        return $result;
    }

    private function makeRequest($url, $method, $data)
    {
        return $this->curl($url, $method, $data);
    }

    private function issueInvoice($invoice_data, $url)
    {
        return $this->makeRequest($url, 'POST', $invoice_data);
    }

    private function invoiceFromorder($order, $kind = '')
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

        sleep(5);

        $products = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
		SELECT *
		FROM `' . _DB_PREFIX_ . 'order_detail` od
		LEFT JOIN `' . _DB_PREFIX_ . 'product` p ON (p.id_product = od.product_id)
		LEFT JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON (ps.id_product = p.id_product AND ps.id_shop = od.id_shop)
		LEFT JOIN `' . _DB_PREFIX_ . 'tax_rule` tr ON (tr.id_tax_rules_group = ps.id_tax_rules_group)
		LEFT JOIN `' . _DB_PREFIX_ . 'tax` t ON (t.id_tax = tr.id_tax)
		WHERE od.`id_order` = ' . pSQL((int) ($order->id)). ' AND tr.`id_country` = ' . pSQL((int) (Context::getContext()->country->id)));

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

        $shippingTmp = $order->getShipping();

        $carriage = array(
            'name' => $this->l('Transport'), //$this->l('shipping'),//$shippingTmp[0]['carrier_name']
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

        if ($kind == '') { #faktura tworzona automatycznie
            if ($this->issue_kind == 'vat_or_receipt') {
                $kind = ($this->isValidnip($address->vat_number) && !empty($address->company)) ? 'vat' : 'receipt';
            } elseif ($this->issue_kind == 'always_receipt') {
                $kind = 'receipt';
            } else {
                $kind = 'vat';
            }
        } elseif (!in_array($kind, array('vat', 'receipt'))) { #faktura tworzona recznie
            $kind = ($this->isValidnip($address->vat_number) && !empty($address->company)) ? 'vat' : 'receipt';
        }

        $biezaca_data = strtotime(date('Y-m-d'));
        if ($order->module == 'mjpaylater') {
            $termin_platnosci = strtotime("+30 day", $biezaca_data);
        } else {
            $termin_platnosci = strtotime("+7 day", $biezaca_data);
        }
        //Data sprzedaży
        if ($order->module == 'pscashondelivery') {
            // wysłane
            $data_sprzedazy = $this->getDateOrderStateFromId($order->id, Configuration::get($this->prefix . 'status_zamowienia'));
        } else if (($order->module == 'payu') || ($order->module == 'mjpaylater') || ($order->module == 'ps_wirepayment')) {
            // platnosc zaakceptowana
            $data_sprzedazy = $this->getDateOrderStateFromId($order->id, Configuration::get($this->prefix . 'status_zamowienia_platnosc'));
        } else {
            // zakonczone
            $data_sprzedazy = $this->getDateOrderStateFromId($order->id, Configuration::get($this->prefix . 'status_zamowienia_wysylka'));
        }
        //Dane do faktury
        $invoiceData = array(
            'kind' => 'vat',
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
            'description' => 'Zamówienie nr #' . $order->id, //$shippingTmp[0]['carrier_name']
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

    private function getTestinvoice()
    {
        $data = array(
            'invoice' => array(
                'issue_date' => date('Y-m-d'),
                //'seller_name' => 'seller_name_test',
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

    private function getInvoicesurl($account_prefix)
    {
        return $this->getApiurl($account_prefix, 'invoices');
    }

    private function getWzurl($account_prefix)
    {
        return $this->getApiurl($account_prefix, 'warehouse_documents');
    }

    private function getClientsurl($account_prefix)
    {
        return $this->getApiurl($account_prefix, 'clients');
    }

    private function getDepartmentsurl($account_prefix)
    {
        return $this->getApiurl($account_prefix, 'departments');
    }

    private function getAccounturl($account_prefix)
    {
        return $this->getApiurl($account_prefix, 'account');
    }

    private function getApiurl($account_prefix, $controller)
    {
        $url = 'https://' . $account_prefix . '.' . $this->api_url . '/' . $controller;
        return $url;
    }

    private function isConfigured()
    {
        return !(empty(Configuration::get($this->prefix . 'klucz_api')) || empty(Configuration::get($this->prefix . 'login')));
    }

    //Sprawdzenie czy w bazie jest rekord z fakturą
    public function getLastinvoice($order_id_fakturownia)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
	        SELECT *
	        FROM `' . _DB_PREFIX_ . 'mjfakturownia_invoice`
	        WHERE `id_order` = ' . pSQL((int) ($order_id_fakturownia)) .
                        ' AND external_id != "0" ORDER BY `id_mjfakturownia` DESC LIMIT 1');
    }

    //Sprawdzenie czy w bazie jest rekord z wz
    private function getLastwz($order_id_fakturownia)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
	        SELECT *
	        FROM `' . _DB_PREFIX_ . 'mjfakturownia_invoice`
	        WHERE `id_order` = ' . pSQL((int) ($order_id_fakturownia)) .
                        ' AND id_wz != "0" ORDER BY `id_mjfakturownia` DESC LIMIT 1');
    }

    public function getLastinvoicetoprint($order_id_fakturownia)
    {
        $invoice = $this->getLastinvoice($order_id_fakturownia);
        if (empty($invoice)) {
            return 0;
        } else {
            $invoice = $invoice[0];
            return $invoice['view_url'] . ".pdf";
        }
    }

    private function afterSendactions($result, $order)
    {
        if (!(empty($result->view_url) || empty($result->id))) {
            Db::getInstance()->Execute('INSERT INTO `' . _DB_PREFIX_ . 'mjfakturownia_invoice` (`id_order`, `view_url`, `external_id`,`id_wz`) VALUES ("' . pSQL((int) $order->id) . '", "' . pSQL($result->view_url) . '", "' . pSQL((int) $result->id) . '","' . pSQL($this->id_wz) . '")');
            if (Configuration::get($this->prefix . 'email_wysylka') == '1') {
                $url = $this->getInvoicesurl($this->account_prefix) . '/' . $result->id . '/send_by_email.json?api_token=' . $this->api_token;
                $this->makeRequest($url, 'POST', null);
            }
        } else {
            Db::getInstance()->Execute('INSERT INTO `' . _DB_PREFIX_ . 'mjfakturownia_invoice` (`id_order`, `view_url`, `external_id`,`id_wz`) VALUES (' . pSQL((int) $order->id) . ", '', 0, 0)");
            $error = $this->l('invoice_creation_failed') . $order->id . '. ' . $this->l('invoice_not_created') . $this->account_prefix . '.' . $this->api_url . '!';
        }
    }

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
            //$sql2 = 'DELETE FROM `'._DB_PREFIX_.'mjfakturownia_invoice` WHERE id_order = "'.pSQL($id_order).'"';
            $sql2 = 'UPDATE `' . _DB_PREFIX_ . 'mjfakturownia_invoice` SET id_wz = "0" WHERE id_order = "' . pSQL($id_order) . '"';
            Db::getInstance()->Execute($sql2, 1, 0);
        }
    }

    public function deleteInvoice($id_order)
    {

        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'mjfakturownia_invoice` WHERE id_order = "' . pSQL($id_order) . '"';
        $api_token = trim(Configuration::get($this->prefix . 'klucz_api'));

        $id_faktury_fakturownia = '';
        foreach (Db::getInstance()->ExecuteS($sql, 1, 0) as $faktura) {
            $id_faktury_fakturownia = $faktura['external_id'];
        }

        $url = $this->getApiurl(Configuration::get($this->prefix . 'login'), 'invoices') . '/' . $id_faktury_fakturownia . '.json?page=1&api_token=' . $api_token;
        $this->makeRequest($url, 'DELETE', null);

        if (count(Db::getInstance()->ExecuteS($sql, 1, 0)) > 0) {
            //$sql2 = 'DELETE FROM `'._DB_PREFIX_.'mjfakturownia_invoice` WHERE id_order = "'.pSQL($id_order).'"';
            $sql2 = 'UPDATE `' . _DB_PREFIX_ . 'mjfakturownia_invoice` SET external_id = "0" WHERE id_order = "' . pSQL($id_order) . '"';
            Db::getInstance()->Execute($sql2, 1, 0);
        }
    }

    public function sendInvoice($id_order, $kind)
    {
        /**
         * Prepare invoice // mj
         */
        $order = new Order((int) $id_order);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }
        $url = $this->getInvoicesurl($this->account_prefix) . '.json';
        $invoice_data = $this->invoiceFromorder($order, $kind);

        $last_invoice = $this->getLastinvoice($id_order);
        if (empty($last_invoice) || (!empty($last_invoice) && $last_invoice[0]['external_id'] == 0)) { //czy w miedzyczasie nie wystawila sie automatycznie
            $result = $this->issueInvoice($invoice_data, $url);
            if (count($last_invoice) > 0) {
                $this->aktualizujFakture($id_order, $result->id);
            } else {
                $this->dodajFakture($id_order, $result->id, 'invoice');
            }
        }
    }

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

    public function assignModuleVars()
    {
        $this->context->smarty->assign(array(
            'module_path' => $this->_path
        ));
    }

    public function syncQty($nr_strony)
    {
        //Pobierz wszystkie produkty z presty które mają referencje
        // Poleć foreach po nich i zaktualizuj je w preście:
        //$url_produkty = $this->getApiurl(Configuration::get($this->prefix.'login'), 'products').'.json?api_token=' . $this->api_token.'&page='.$nr_strony;
        $getProductFromPS = @Product::getProducts($this->context->language->id, 0, 99999, 'id_product', 'ASC', false, true);

        foreach ($getProductFromPS as $PSproduct) {
            if ($PSproduct['reference'] != '') {
                $url_produkty = $this->getApiurl(Configuration::get($this->prefix . 'login'), 'products') . '/' . $PSproduct['reference'] . '.json?api_token=' . $this->api_token;
                $result_produkty = $this->makeRequest($url_produkty, 'GET', null);
                //------------------------------------------------------
                // Ustaw ilości dla zwyłych produktów
                //------------------------------------------------------
                $p = Mjfakturownia::getIdByReference($result_produkty->id);//$product->id
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
}
