<?php
class GovpExchangeCronModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ajax = true;
    public $ssl = true;

    public function display()
    {
        if (!$this->module->cronTokenValid((string) Tools::getValue('token'))) {
            header('HTTP/1.1 403 Forbidden'); $this->ajaxRender(json_encode(['ok' => false, 'error' => 'forbidden'])); return;
        }
        $processed = $this->module->processQueue(20);
        header('Content-Type: application/json');
        $this->ajaxRender(json_encode(['ok' => true, 'processed' => $processed]));
    }
}

