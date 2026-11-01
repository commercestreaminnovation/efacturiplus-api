<?php
/**
 * Customer download of the invoice PDF.
 *
 * @author    CommerceStream Innovate SRL
 * @copyright 2026 CommerceStream Innovate SRL
 * @license   https://opensource.org/licenses/MIT MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class EfacturiplusPdfModuleFrontController extends ModuleFrontController
{
    public $auth = true;
    public $ssl = true;

    public function initContent()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order) || (int) $order->id_customer !== (int) $this->context->customer->id) {
            Tools::redirect('index.php?controller=history');
        }
        $url = $this->module->pdfUrl($idOrder);
        Tools::redirect($url !== null ? $url : 'index.php?controller=history');
    }
}
