<?php
/**
 *
 * @link              https://www.simpay.pl
 * @since             1.0.0
 * @package           darkg-woocommerce-simpay
 *
 * @wordpress-plugin
 * Plugin Name:       SimPay.pl - Płatności WooCommerce
 * Plugin URI:        https://www.simpay.pl
 * Description:       Direct billing to jedna z najbardziej nowoczesnych metod płatności, która pozwala w szybki sposób uzyskać dostęp do różnego typu usług.
 * Version:           1.0.0
 * Author:            Krzysztof Grzelak
 * Author URI:        https://github.com/kgrzelak
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 *
 **/

if (!defined('ABSPATH')) {
    exit();
}

require_once(plugin_dir_path(__FILE__) . 'req/class/SimPay.class.php');

add_action('plugins_loaded', 'simpay_payment_init');

function simpay_add_gateway($gateways)
{
    $gateways[] = 'simpay_payment';
    return $gateways;
}
add_filter('woocommerce_payment_gateways', 'simpay_add_gateway');

add_action('admin_menu', 'simpay_menu_add');

function simpay_menu_add()
{
    add_menu_page("SimPay", "SimPay", "manage_options", 'admin.php?page=wc-settings&tab=checkout&section=simpay_payment', null, 99);
}

function simpay_payment_init()
{
    class simpay_payment extends WC_Payment_Gateway
    {
    
        public function __construct()
        {
            //parent::__construct();
        
            $this->id = "simpay_payment";
        
            $this->title = "Zapłać za pomocą Telefonu z SimPay.pl";
        
            $this->method_title = 'SimPay.pl - Płatności DirectBilling (SMS+)';
            $this->method_description = 'Direct billing to jedna z najbardziej nowoczesnych metod płatności, która pozwala w szybki sposób uzyskać dostęp do różnego typu usług.';
        
            $this->init_form_fields();
            $this->init_settings();
        
            add_action('woocommerce_api_' . $this->id, array($this, 'callback_payment'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields()
        {
     
            $this->form_fields = array(
            'simpaywc_service' => array(
                'title' => 'ID Usługi',
                'description' => 'ID Usługi Direct Billing',
                'type' => 'text',
                'desc_tip' => true
            ),
            'simpaywc_api_key' => array(
                'title' => 'API Key',
                'description' => 'Klucz API usługi z panelu simpay',
                'type' => 'text',
                'desc_tip' => true
            ),
            );
        }
    
        public function process_payment($order_id)
        {
        
            $order = new WC_Order($order_id);
            $serviceId = $this->get_option('simpaywc_service');
            $apiKey = $this->get_option('simpaywc_api_key');
        
            $simpayTransaction = new SimPayDBTransaction();
            $simpayTransaction->setDebugMode(true);
            $simpayTransaction->setServiceID($serviceId);
            $simpayTransaction->setApiKey($apiKey);
            $simpayTransaction->setControl($order_id);
            $simpayTransaction->setCompleteLink($this->get_return_url($order));
            $simpayTransaction->setFailureLink($order->get_cancel_order_url());
            $simpayTransaction->setAmountGross(floatval($order->get_total()));
            $simpayTransaction->generateTransaction();
        
            if ($simpayTransaction->getResults()->status == "success") {
                do_action('woocommerce_thankyou', $order_id);
                WC()->cart->empty_cart();
            
                return [
                'result' => 'success',
                'redirect' => $simpayTransaction->getResults()->link
                ];
            } else {
                echo 'Generowanie transakcji nie powiodło się!';
            }
        }
    
        public function callback_payment()
        {
        
            $simPay = new SimPayDB();
        
            $simPay->setApiKey($this->get_option('simpaywc_api_key'));
        
            if (!$simPay->checkIp($simPay->getRemoteAddr())) {
                $simPay->okTransaction();
                exit();
            }
        
            if (!$simPay->parse($_POST)) {
                exit();
            }
        
            if ($simPay->isError()) {
                $simPay->okTransaction();
                exit();
            }

            if (!$simPay->isTransactionPaid()) {
                $simPay->okTransaction();
                exit();
            }
            if (!$order = wc_get_order($simPay->getControl())) {
                $simPay->okTransaction();
                exit();
            }
        
            if ($order->get_total() != $simPay->getValueGross()) {
                $simPay->okTransaction();
                exit();
            }
        
            if (!in_array($order->get_status(), ['completed', 'processing'])) {
                $simPay->okTransaction();
                exit();
            }
        
            $order->payment_complete();
            $order->add_order_note('Płatności na kwotę ' . $simPay->getValueGross() . ', została przetworzona. Control:' . $simPay->getControl());
        
            $order->save();
        
            $simPay->okTransaction();
            exit();
        }
    }
}
