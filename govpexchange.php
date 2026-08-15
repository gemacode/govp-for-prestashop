<?php
// SPDX-License-Identifier: AFL-3.0
if ( ! defined( '_PS_VERSION_' ) ) exit;

require_once __DIR__ . '/classes/GovpExchangeClient.php';

class GovpExchange extends Module
{
    public const VERSION = '0.1.0';
    private const ENDPOINT = 'GOVP_EXCHANGE_ENDPOINT';
    private const TOKEN = 'GOVP_EXCHANGE_TOKEN';
    private const STATUS = 'GOVP_EXCHANGE_STATUS';
    private const DAYS = 'GOVP_EXCHANGE_DAYS';
    private const SHOW = 'GOVP_EXCHANGE_SHOW';
    private const CRON = 'GOVP_EXCHANGE_CRON_TOKEN';

    public function __construct()
    {
        $this->name = 'govpexchange';
        $this->tab = 'shipping_logistics';
        $this->version = self::VERSION;
        $this->author = 'Gemacode';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->trans('GOVP for PrestaShop', [], 'Modules.Govpexchange.Admin');
        $this->description = $this->trans('Generates and delivers verifiable GOVP from PrestaShop orders.', [], 'Modules.Govpexchange.Admin');
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
    }

    public function install()
    {
        return parent::install()
            && $this->installSchema()
            && Configuration::updateValue(self::ENDPOINT, 'https://partners.gemacode.org/api/exchange')
            && Configuration::updateValue(self::STATUS, (int) Configuration::get('PS_OS_DELIVERED'))
            && Configuration::updateValue(self::DAYS, 365)
            && Configuration::updateValue(self::SHOW, 1)
            && Configuration::updateValue(self::CRON, bin2hex(random_bytes(24)))
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('displayAdminOrderSide')
            && $this->registerHook('displayOrderDetail')
            && $this->registerHook('actionDispatcherBefore');
    }

    public function uninstall()
    {
        foreach ([self::ENDPOINT, self::TOKEN, self::STATUS, self::DAYS, self::SHOW, self::CRON] as $key) Configuration::deleteByName($key);
        return $this->uninstallSchema() && parent::uninstall();
    }

    private function installSchema()
    {
        $queries = [
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'govp_exchange_queue` (
                `id_queue` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_order` INT UNSIGNED NOT NULL,
                `order_status` VARCHAR(64) NOT NULL,
                `idempotency_key` VARCHAR(190) NOT NULL,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `next_attempt_at` DATETIME NOT NULL,
                `last_error` TEXT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT "pending",
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_queue`), UNIQUE KEY `uq_govp_idempotency` (`idempotency_key`),
                KEY `idx_govp_due` (`status`, `next_attempt_at`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'govp_exchange_order` (
                `id_order` INT UNSIGNED NOT NULL,
                `public_code` VARCHAR(80) NOT NULL,
                `verify_url` VARCHAR(1000) NOT NULL,
                `issued_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_order`), UNIQUE KEY `uq_govp_code` (`public_code`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4',
        ];
        foreach ($queries as $query) if (!Db::getInstance()->execute($query)) return false;
        return true;
    }

    private function uninstallSchema()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'govp_exchange_queue`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'govp_exchange_order`');
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitGovpExchange')) {
            $endpoint = rtrim((string) Tools::getValue(self::ENDPOINT), '/');
            $token = trim((string) Tools::getValue(self::TOKEN));
            if (!filter_var($endpoint, FILTER_VALIDATE_URL) || strpos($endpoint, 'https://') !== 0) {
                $output .= $this->displayError($this->trans('GOVP Exchange must use a valid HTTPS URL.', [], 'Modules.Govpexchange.Admin'));
            } else {
                Configuration::updateValue(self::ENDPOINT, $endpoint);
                if ($token !== '') Configuration::updateValue(self::TOKEN, $this->encrypt($token));
                Configuration::updateValue(self::STATUS, (int) Tools::getValue(self::STATUS));
                Configuration::updateValue(self::DAYS, max(1, min(3650, (int) Tools::getValue(self::DAYS))));
                Configuration::updateValue(self::SHOW, (int) (bool) Tools::getValue(self::SHOW));
                $output .= $this->displayConfirmation($this->trans('GOVP Exchange settings saved.', [], 'Modules.Govpexchange.Admin'));
            }
        }
        if (Tools::isSubmit('runGovpQueue')) {
            $processed = $this->processQueue(20);
            $output .= $this->displayConfirmation(sprintf($this->trans('%d queued jobs processed.', [], 'Modules.Govpexchange.Admin'), $processed));
        }
        return $output . $this->renderForm() . $this->renderQueueStatus();
    }

    private function renderForm()
    {
        $states = [];
        foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) $states[] = ['id' => $state['id_order_state'], 'name' => $state['name']];
        $fields = [[
            'form' => [
                'legend' => ['title' => $this->trans('GOVP Exchange connection', [], 'Modules.Govpexchange.Admin'), 'icon' => 'icon-link'],
                'description' => $this->trans('Create a PrestaShop connection in GOVP Exchange and paste its one-time token. Data is sent only after this explicit configuration.', [], 'Modules.Govpexchange.Admin'),
                'input' => [
                    ['type' => 'text', 'label' => $this->trans('Exchange API URL', [], 'Modules.Govpexchange.Admin'), 'name' => self::ENDPOINT, 'required' => true],
                    ['type' => 'password', 'label' => $this->trans('Connection token', [], 'Modules.Govpexchange.Admin'), 'name' => self::TOKEN, 'desc' => $this->trans('Leave blank to preserve the configured token.', [], 'Modules.Govpexchange.Admin')],
                    ['type' => 'select', 'label' => $this->trans('Generate at order status', [], 'Modules.Govpexchange.Admin'), 'name' => self::STATUS, 'options' => ['query' => $states, 'id' => 'id', 'name' => 'name']],
                    ['type' => 'text', 'label' => $this->trans('Validity in days', [], 'Modules.Govpexchange.Admin'), 'name' => self::DAYS, 'class' => 'fixed-width-sm'],
                    ['type' => 'switch', 'label' => $this->trans('Show GOVP to customer', [], 'Modules.Govpexchange.Admin'), 'name' => self::SHOW, 'is_bool' => true, 'values' => [['id' => 'on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Admin.Global')], ['id' => 'off', 'value' => 0, 'label' => $this->trans('No', [], 'Admin.Global')]]],
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions')],
            ],
        ]];
        $helper = new HelperForm();
        $helper->module = $this; $helper->name_controller = $this->name; $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name; $helper->submit_action = 'submitGovpExchange';
        $helper->fields_value = [self::ENDPOINT => Configuration::get(self::ENDPOINT), self::TOKEN => '', self::STATUS => Configuration::get(self::STATUS), self::DAYS => Configuration::get(self::DAYS), self::SHOW => Configuration::get(self::SHOW)];
        return $helper->generateForm($fields);
    }

    private function renderQueueStatus()
    {
        $pending = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'govp_exchange_queue` WHERE status = "pending"');
        $cron = $this->context->link->getModuleLink($this->name, 'cron', ['token' => Configuration::get(self::CRON)], true);
        return '<div class="panel"><h3><i class="icon-refresh"></i> ' . $this->trans('Delivery queue', [], 'Modules.Govpexchange.Admin') . '</h3><p>' . sprintf($this->trans('%d jobs waiting. The module retries during store traffic; the protected cron URL provides deterministic processing.', [], 'Modules.Govpexchange.Admin'), $pending) . '</p><p><code>' . htmlspecialchars($cron, ENT_QUOTES, 'UTF-8') . '</code></p><form method="post"><button class="btn btn-default" name="runGovpQueue"><i class="icon-play"></i> ' . $this->trans('Process now', [], 'Modules.Govpexchange.Admin') . '</button></form></div>';
    }

    public function hookActionOrderStatusPostUpdate(array $params)
    {
        $orderId = (int) ($params['id_order'] ?? 0);
        $newStatus = isset($params['newOrderStatus']) && is_object($params['newOrderStatus']) ? (int) $params['newOrderStatus']->id : (int) ($params['id_order_state'] ?? 0);
        if (!$orderId || $newStatus !== (int) Configuration::get(self::STATUS) || !Configuration::get(self::TOKEN)) return;
        $this->enqueue($orderId, (string) $newStatus);
        $this->processQueue(1);
    }

    private function enqueue($orderId, $status)
    {
        $key = 'prestashop:' . substr(hash('sha256', Tools::getShopDomainSsl(true)), 0, 16) . ':order:' . (int) $orderId . ':' . pSQL($status);
        Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'govp_exchange_queue` (`id_order`,`order_status`,`idempotency_key`,`next_attempt_at`,`created_at`) VALUES (' . (int) $orderId . ',"' . pSQL($status) . '","' . pSQL($key) . '",NOW(),NOW())');
    }

    public function processQueue($limit = 5)
    {
        $jobs = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'govp_exchange_queue` WHERE status = "pending" AND next_attempt_at <= NOW() ORDER BY id_queue ASC LIMIT ' . (int) $limit);
        $processed = 0;
        foreach ($jobs ?: [] as $job) {
            try {
                $this->issueOrder((int) $job['id_order'], (string) $job['idempotency_key']);
                Db::getInstance()->update('govp_exchange_queue', ['status' => 'completed', 'last_error' => null], 'id_queue=' . (int) $job['id_queue']);
            } catch (Throwable $error) {
                $attempts = (int) $job['attempts'] + 1;
                $status = $attempts >= 5 ? 'failed' : 'pending';
                $delay = min(3600, 60 * (2 ** max(0, $attempts - 1)));
                Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'govp_exchange_queue` SET attempts=' . $attempts . ', status="' . pSQL($status) . '", last_error="' . pSQL(substr($error->getMessage(), 0, 1000)) . '", next_attempt_at=DATE_ADD(NOW(), INTERVAL ' . (int) $delay . ' SECOND) WHERE id_queue=' . (int) $job['id_queue']);
            }
            ++$processed;
        }
        return $processed;
    }

    private function issueOrder($orderId, $idempotencyKey)
    {
        if (Db::getInstance()->getValue('SELECT public_code FROM `' . _DB_PREFIX_ . 'govp_exchange_order` WHERE id_order=' . (int) $orderId)) return;
        $order = new Order((int) $orderId);
        if (!Validate::isLoadedObject($order)) throw new RuntimeException('ORDER_NOT_FOUND');
        $customer = new Customer((int) $order->id_customer);
        $products = [];
        foreach ($order->getProducts() as $product) $products[] = ['product_id' => (int) $product['product_id'], 'reference' => (string) $product['product_reference'], 'name' => (string) $product['product_name'], 'quantity' => (int) $product['product_quantity']];
        $summary = json_encode(['order' => $order->reference, 'products' => $products], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payload = [
            'issuer' => ['name' => (string) Configuration::get('PS_SHOP_NAME'), 'email' => (string) Configuration::get('PS_SHOP_EMAIL')],
            'recipient' => ['name' => trim($customer->firstname . ' ' . $customer->lastname), 'email' => $customer->email],
            'subject' => ['type' => 'order', 'id' => $order->reference, 'name' => 'Pedido ' . $order->reference, 'description' => count($products) . ' líneas de producto'],
            'requirement' => 'Identifica el pedido y conserva una huella de sus líneas de producto en el momento de la emisión.',
            'evidence' => [['label' => 'Resumen canónico del pedido', 'sha256' => hash('sha256', (string) $summary)]],
            'validUntil' => gmdate('c', time() + (int) Configuration::get(self::DAYS) * 86400),
            'source' => ['platform' => 'prestashop', 'externalId' => 'order-' . (int) $orderId],
        ];
        $client = new GovpExchangeClient((string) Configuration::get(self::ENDPOINT), $this->decrypt((string) Configuration::get(self::TOKEN)));
        $result = $client->issue($payload, $idempotencyKey);
        Db::getInstance()->insert('govp_exchange_order', ['id_order' => (int) $orderId, 'public_code' => pSQL($result['govp']['code']), 'verify_url' => pSQL($result['govp']['verifyUrl'], true), 'issued_at' => date('Y-m-d H:i:s')]);
    }

    public function hookActionDispatcherBefore(array $params)
    {
        static $ran = false;
        if (!$ran) { $ran = true; $this->processQueue(1); }
    }

    private function orderGovp($orderId)
    {
        return Db::getInstance()->getRow('SELECT public_code, verify_url, issued_at FROM `' . _DB_PREFIX_ . 'govp_exchange_order` WHERE id_order=' . (int) $orderId);
    }

    public function hookDisplayAdminOrderSide(array $params)
    {
        $record = $this->orderGovp((int) ($params['id_order'] ?? 0));
        if (!$record) return '<div class="card"><div class="card-header"><strong>GOVP Exchange</strong></div><div class="card-body"><p>' . $this->trans('No GOVP has been issued for this order yet.', [], 'Modules.Govpexchange.Admin') . '</p></div></div>';
        return '<div class="card"><div class="card-header"><strong>GOVP Exchange</strong></div><div class="card-body"><p><code>' . htmlspecialchars($record['public_code'], ENT_QUOTES, 'UTF-8') . '</code></p><a class="btn btn-primary" target="_blank" rel="noopener" href="' . htmlspecialchars($record['verify_url'], ENT_QUOTES, 'UTF-8') . '">' . $this->trans('Verify GOVP', [], 'Modules.Govpexchange.Admin') . '</a></div></div>';
    }

    public function hookDisplayOrderDetail(array $params)
    {
        if (!Configuration::get(self::SHOW)) return '';
        $order = $params['order'] ?? null; $orderId = is_object($order) ? (int) $order->id : (int) ($params['id_order'] ?? 0);
        $record = $this->orderGovp($orderId); if (!$record) return '';
        $this->context->smarty->assign(['govp_verify_url' => $record['verify_url'], 'govp_public_code' => $record['public_code']]);
        return $this->display(__FILE__, 'views/templates/hook/order.tpl');
    }

    public function cronTokenValid($provided)
    {
        return is_string($provided) && hash_equals((string) Configuration::get(self::CRON), $provided);
    }

    private function encrypt($plain)
    {
        $key = hash('sha256', _COOKIE_KEY_, true); $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    private function decrypt($encrypted)
    {
        $raw = base64_decode($encrypted, true); if ($raw === false || strlen($raw) < 29) return '';
        $value = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', _COOKIE_KEY_, true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $value === false ? '' : $value;
    }
}
