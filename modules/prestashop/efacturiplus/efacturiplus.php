<?php
/**
 * eFacturiPlus for PrestaShop.
 *
 * @author    CommerceStream Innovate SRL
 * @copyright 2026 CommerceStream Innovate SRL
 * @license   https://opensource.org/licenses/MIT MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/EfacturiplusClient.php';
require_once __DIR__ . '/classes/EfacturiplusMapper.php';

class Efacturiplus extends Module
{
    const CONFIG_KEYS = [
        'EFACTURIPLUS_API_KEY', 'EFACTURIPLUS_SERIES', 'EFACTURIPLUS_ORDER_STATE', 'EFACTURIPLUS_DEFAULT_VAT',
        'EFACTURIPLUS_UNIT', 'EFACTURIPLUS_SHIPPING_NAME', 'EFACTURIPLUS_DISCOUNT_NAME', 'EFACTURIPLUS_SEND_SPV',
    ];

    public function __construct()
    {
        $this->name = 'efacturiplus';
        $this->tab = 'billing_invoicing';
        $this->version = '3.0.0';
        $this->author = 'CommerceStream Innovate SRL';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.7.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->l('eFacturiPlus');
        $this->description = $this->l('Emite facturi în eFacturiPlus din comenzi și le trimite în e-Factura (SPV).');
        $this->confirmUninstall = $this->l('Dezinstalezi modulul? Facturile rămân în contul eFacturiPlus.');
    }

    public function install()
    {
        return parent::install()
            && $this->installTable()
            && Configuration::updateValue('EFACTURIPLUS_SERIES', 'WEB')
            && Configuration::updateValue('EFACTURIPLUS_ORDER_STATE', (int) Configuration::get('PS_OS_PAYMENT'))
            && Configuration::updateValue('EFACTURIPLUS_DEFAULT_VAT', 21)
            && Configuration::updateValue('EFACTURIPLUS_UNIT', 'buc')
            && Configuration::updateValue('EFACTURIPLUS_SHIPPING_NAME', 'Transport')
            && Configuration::updateValue('EFACTURIPLUS_DISCOUNT_NAME', 'Discount')
            && Configuration::updateValue('EFACTURIPLUS_SEND_SPV', 0)
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('displayAdminOrderSide')
            && $this->registerHook('displayOrderDetail');
    }

    public function uninstall()
    {
        foreach (self::CONFIG_KEYS as $key) {
            Configuration::deleteByName($key);
        }
        // The link order - invoice stays in the table only while the module exists; invoices stay in eFacturiPlus.
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'efacturiplus_order`') && parent::uninstall();
    }

    private function installTable()
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'efacturiplus_order` (
            `id_order` INT UNSIGNED NOT NULL PRIMARY KEY,
            `invoice_id` INT UNSIGNED NULL,
            `label` VARCHAR(50) NULL,
            `spv` VARCHAR(100) NULL,
            `storno_id` INT UNSIGNED NULL,
            `storno_label` VARCHAR(50) NULL,
            `error` TEXT NULL,
            `date_upd` DATETIME NOT NULL
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    /** @return array<string, mixed> */
    private function row($idOrder)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'efacturiplus_order` WHERE id_order = ' . (int) $idOrder);

        return $row ?: [];
    }

    private function save($idOrder, array $values)
    {
        $data = ['id_order' => (int) $idOrder, 'date_upd' => date('Y-m-d H:i:s')] + array_map(static function ($v) {
            return is_string($v) ? pSQL($v) : $v;
        }, $values);
        $current = $this->row($idOrder);
        if ($current) {
            return Db::getInstance()->update('efacturiplus_order', $data, 'id_order = ' . (int) $idOrder, 0, true);
        }

        return Db::getInstance()->insert('efacturiplus_order', $data, true);
    }

    public function hookActionOrderStatusPostUpdate(array $params)
    {
        $state = isset($params['newOrderStatus']) ? (int) $params['newOrderStatus']->id : 0;
        if ($state && $state === (int) Configuration::get('EFACTURIPLUS_ORDER_STATE')) {
            $this->issue((int) $params['id_order']);
        }
    }

    /** The API deduplicates on external_id, so repeating is safe. */
    public function issue($idOrder)
    {
        $row = $this->row($idOrder);
        if (!empty($row['invoice_id'])) {
            return true;
        }
        $order = new Order((int) $idOrder);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }

        $client = new EfacturiplusClient();
        $account = $client->account();
        if (!$account['ok']) {
            return $this->fail($idOrder, $account['error']);
        }
        try {
            $payload = EfacturiplusMapper::invoice($order, $account['body']);
        } catch (RuntimeException $e) {
            return $this->fail($idOrder, $e->getMessage());
        }
        $result = $client->createInvoice($payload);
        if (!$result['ok']) {
            return $this->fail($idOrder, $result['error']);
        }
        $this->store($idOrder, $result['body']);
        if (Configuration::get('EFACTURIPLUS_SEND_SPV')) {
            $client->sendToSpv((int) $result['body']['invoice_id']);
        }

        return true;
    }

    private function store($idOrder, array $body)
    {
        $this->save($idOrder, [
            'invoice_id' => (int) $body['invoice_id'],
            'label' => (string) $body['invoice']['label'],
            'spv' => isset($body['efactura']['label']) ? (string) $body['efactura']['label'] : '',
            'error' => null,
        ]);
    }

    private function fail($idOrder, $error)
    {
        $this->save($idOrder, ['error' => (string) $error]);
        PrestaShopLogger::addLog('eFacturiPlus: ' . $error, 2, null, 'Order', (int) $idOrder, true);

        return false;
    }

    private function storno($idOrder)
    {
        $row = $this->row($idOrder);
        if (empty($row['invoice_id']) || !empty($row['storno_id'])) {
            return;
        }
        $client = new EfacturiplusClient();
        $result = $client->storno((int) $row['invoice_id']);
        if (!$result['ok']) {
            $this->fail($idOrder, $result['error']);

            return;
        }
        $this->save($idOrder, ['storno_id' => (int) $result['body']['invoice_id'], 'storno_label' => (string) $result['body']['invoice']['label'], 'error' => null]);
        if (Configuration::get('EFACTURIPLUS_SEND_SPV')) {
            $client->sendToSpv((int) $result['body']['invoice_id']);
        }
    }

    private function taskLink($idOrder, $task)
    {
        return $this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => $this->name, 'efacturiplus_task' => $task, 'id_order' => (int) $idOrder,
        ]);
    }

    public function hookDisplayAdminOrderSide(array $params)
    {
        $idOrder = (int) $params['id_order'];
        $row = $this->row($idOrder);
        $tasks = [];
        foreach (['issue', 'pdf', 'refresh', 'spv', 'storno', 'spv_storno'] as $task) {
            $tasks[$task] = $this->taskLink($idOrder, $task);
        }
        $this->context->smarty->assign(['efp' => $row, 'efp_tasks' => $tasks]);

        return $this->display(__FILE__, 'views/templates/hook/admin_order.tpl');
    }

    public function hookDisplayOrderDetail(array $params)
    {
        $order = $params['order'];
        $row = $this->row((int) $order->id);
        if (empty($row['invoice_id'])) {
            return '';
        }
        $this->context->smarty->assign(['efp_pdf' => $this->context->link->getModuleLink($this->name, 'pdf', ['id_order' => (int) $order->id], true)]);

        return $this->display(__FILE__, 'views/templates/hook/order_detail.tpl');
    }

    /** Fresh PDF link for an order; the API link expires. */
    public function pdfUrl($idOrder)
    {
        $row = $this->row($idOrder);
        if (empty($row['invoice_id'])) {
            return null;
        }
        $result = (new EfacturiplusClient())->invoice((int) $row['invoice_id']);

        return $result['ok'] && !empty($result['body']['pdf_url']) ? (string) $result['body']['pdf_url'] : null;
    }

    private function runTask($task, $idOrder)
    {
        $row = $this->row($idOrder);
        $client = new EfacturiplusClient();
        switch ($task) {
            case 'issue':
                $this->issue($idOrder);
                break;
            case 'storno':
                $this->storno($idOrder);
                break;
            case 'spv':
            case 'refresh':
                $id = (int) ($row['invoice_id'] ?? 0);
                $result = $task === 'spv' ? $client->sendToSpv($id) : $client->invoice($id);
                if ($result['ok']) {
                    $this->store($idOrder, $result['body']);
                } else {
                    $this->fail($idOrder, $result['error']);
                }
                break;
            case 'spv_storno':
                $result = $client->sendToSpv((int) ($row['storno_id'] ?? 0));
                if (!$result['ok']) {
                    $this->fail($idOrder, $result['error']);
                }
                break;
            case 'pdf':
                $url = $this->pdfUrl($idOrder);
                if ($url !== null) {
                    Tools::redirect($url);
                }
                $this->fail($idOrder, $this->l('Factura nu este disponibilă acum.'));
                break;
        }
    }

    public function getContent()
    {
        $task = (string) Tools::getValue('efacturiplus_task');
        $idOrder = (int) Tools::getValue('id_order');
        if ($task !== '' && $idOrder > 0) {
            $this->runTask($task, $idOrder);
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminOrders', true, [], ['id_order' => $idOrder, 'vieworder' => 1]));
        }

        $output = '';
        if (Tools::isSubmit('submitEfacturiplus')) {
            Configuration::updateValue('EFACTURIPLUS_API_KEY', trim((string) Tools::getValue('EFACTURIPLUS_API_KEY')));
            $series = preg_replace('/[^A-Za-z0-9\-\/]/', '', (string) Tools::getValue('EFACTURIPLUS_SERIES'));
            Configuration::updateValue('EFACTURIPLUS_SERIES', $series === '' ? 'WEB' : substr($series, 0, 20));
            Configuration::updateValue('EFACTURIPLUS_ORDER_STATE', (int) Tools::getValue('EFACTURIPLUS_ORDER_STATE'));
            Configuration::updateValue('EFACTURIPLUS_DEFAULT_VAT', (int) Tools::getValue('EFACTURIPLUS_DEFAULT_VAT'));
            Configuration::updateValue('EFACTURIPLUS_UNIT', pSQL((string) Tools::getValue('EFACTURIPLUS_UNIT')));
            Configuration::updateValue('EFACTURIPLUS_SHIPPING_NAME', pSQL((string) Tools::getValue('EFACTURIPLUS_SHIPPING_NAME')));
            Configuration::updateValue('EFACTURIPLUS_DISCOUNT_NAME', pSQL((string) Tools::getValue('EFACTURIPLUS_DISCOUNT_NAME')));
            Configuration::updateValue('EFACTURIPLUS_SEND_SPV', (int) Tools::getValue('EFACTURIPLUS_SEND_SPV'));
            $output .= $this->displayConfirmation($this->l('Setările au fost salvate.'));
        }

        return $output . $this->connectionStatus() . $this->renderForm();
    }

    private function connectionStatus()
    {
        if (!Configuration::get('EFACTURIPLUS_API_KEY')) {
            return $this->displayInformation($this->l('Creează cheia în contul eFacturiPlus, la Firma mea → Integrări și API.'));
        }
        $result = (new EfacturiplusClient())->account();
        if (!$result['ok']) {
            return $this->displayError($result['error']);
        }
        $b = $result['body'];
        $lines = [sprintf($this->l('Conectat la %s (CUI %s).'), $b['company']['name'], $b['company']['cui'])];
        $lines[] = $b['company']['platitor_tva'] ? $this->l('Firma este plătitoare de TVA.') : $this->l('Firma nu este plătitoare de TVA: facturile pleacă fără TVA.');
        if (!$b['account']['can_invoice']) {
            $lines[] = $this->l('Contul nu poate emite facturi: pornește sau prelungește abonamentul în eFacturiPlus.');
        }
        if (!$b['spv']['connected']) {
            $lines[] = $this->l('Firma nu este conectată la SPV: facturile nu pot pleca la ANAF până nu autorizezi aplicația.');
        }

        return $this->displayConfirmation(implode(' ', array_map('htmlspecialchars', $lines)));
    }

    private function renderForm()
    {
        $yesNo = [['id' => 'on', 'value' => 1, 'label' => $this->l('Da')], ['id' => 'off', 'value' => 0, 'label' => $this->l('Nu')]];
        $fields = ['form' => [
            'legend' => ['title' => 'eFacturiPlus', 'icon' => 'icon-cogs'],
            'input' => [
                ['type' => 'text', 'label' => $this->l('Cheia API'), 'name' => 'EFACTURIPLUS_API_KEY', 'required' => true, 'class' => 'fixed-width-xxl'],
                ['type' => 'text', 'label' => $this->l('Seria facturilor'), 'name' => 'EFACTURIPLUS_SERIES', 'class' => 'fixed-width-sm', 'desc' => $this->l('Dacă seria nu există în cont, se creează la prima factură.')],
                ['type' => 'select', 'label' => $this->l('Emite factura la starea'), 'name' => 'EFACTURIPLUS_ORDER_STATE', 'options' => ['query' => array_merge([['id_order_state' => 0, 'name' => $this->l('doar manual, din comandă')]], OrderState::getOrderStates((int) $this->context->language->id)), 'id' => 'id_order_state', 'name' => 'name']],
                ['type' => 'text', 'label' => $this->l('Cota TVA implicită'), 'name' => 'EFACTURIPLUS_DEFAULT_VAT', 'class' => 'fixed-width-xs', 'suffix' => '%', 'desc' => $this->l('Pentru comenzile fără taxe calculate în magazin, cu prețuri care conțin deja TVA.')],
                ['type' => 'text', 'label' => $this->l('Unitate de măsură'), 'name' => 'EFACTURIPLUS_UNIT', 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'label' => $this->l('Nume linie transport'), 'name' => 'EFACTURIPLUS_SHIPPING_NAME'],
                ['type' => 'text', 'label' => $this->l('Nume linie reducere'), 'name' => 'EFACTURIPLUS_DISCOUNT_NAME'],
                ['type' => 'switch', 'label' => $this->l('Trimite în SPV după emitere'), 'name' => 'EFACTURIPLUS_SEND_SPV', 'is_bool' => true, 'values' => $yesNo, 'desc' => $this->l('Trimiterea la ANAF nu se poate retrage. Lasă opțiunea oprită până verifici primele facturi.')],
            ],
            'submit' => ['title' => $this->l('Salvează')],
        ]];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->submit_action = 'submitEfacturiplus';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        foreach (self::CONFIG_KEYS as $key) {
            $helper->fields_value[$key] = Configuration::get($key);
        }

        return $helper->generateForm([$fields]);
    }
}
