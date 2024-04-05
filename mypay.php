<?php
/**
 * Plugin Name: MyPay Payment Gateway
 * Description: Integrates MyPay payment gateway into WooCommerce.
 * Version: 1.1
 * Author: Your Name
 */

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;

add_action('plugins_loaded', 'mypay_payment_gateway_init', 11);

function mypay_payment_gateway_init() {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Gateway_MyPay extends WC_Payment_Gateway {
        private $api_key;
        private $username;
        private $password;
        private $merchant_id; // Added for storing merchant ID
        private $sandbox_url = 'https://stagingapi1.mypay.com.np/api/use-mypay-payments';
        private $live_url = 'https://smartdigitalnepal.com/api/use-mypay-payments';
        private $is_sandbox = true;

        public function __construct() {
            $this->id = 'mypay';
            $this->method_title = 'MyPay Payment Gateway';
            $this->method_description = 'Accept payments via MyPay payment gateway.';
            $this->has_fields = true;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->api_key = $this->get_option('api_key');
            $this->username = $this->get_option('username');
            $this->password = $this->get_option('password');
            $this->merchant_id = $this->get_option('merchant_id'); // Initialize merchant ID from settings
            $this->is_sandbox = 'yes' === $this->get_option('sandbox');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable MyPay Payment Gateway',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'default' => 'MyPay Payment',
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'default' => 'Pay via MyPay.',
                ),
                'api_key' => array(
                    'title' => 'API Key',
                    'type' => 'text',
                ),
                'username' => array(
                    'title' => 'Username',
                    'type' => 'text',
                ),
                'password' => array(
                    'title' => 'Password',
                    'type' => 'password',
                ),
                'merchant_id' => array( // Add merchant ID field
                    'title' => 'Merchant ID',
                    'type' => 'text',
                ),
                'sandbox' => array(
                    'title' => 'Sandbox mode',
                    'type' => 'checkbox',
                    'label' => 'Enable Sandbox Mode',
                    'description' => 'If enabled, the gateway will be in test mode.',
                    'default' => 'yes',
                ),
            );
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            $payload = $this->get_payment_payload($order);

            $response = wp_remote_post($this->get_api_url(), array(
                'method' => 'POST',
                'headers' => $this->get_headers(),
                'body' => json_encode($payload),
                'timeout' => 45,
                'sslverify' => false,
            ));

            if (is_wp_error($response)) {
                wc_add_notice(__('Payment error:', 'wc-gateway-mypay') . ' ' . $response->get_error_message(), 'error');
                return;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);

            if ($data->status) {
                $order->update_status('on-hold', __('Awaiting MyPay payment.', 'wc-gateway-mypay'));
                wc_reduce_stock_levels($order_id);
                return array(
                    'result' => 'success',
                    'redirect' => $data->RedirectURL,
                );
            } else {
                wc_add_notice(__('Payment error:', 'wc-gateway-mypay') . ' ' . $data->responseMessage, 'error');
                return;
            }
        }

        private function get_payment_payload($order) {
            $orderId = str_pad($order->get_id(), 6, '0', STR_PAD_LEFT);
            $amount = $order->get_total();

            return array(
                "UserName" => $this->username,
                "Password" => $this->password,
                "Amount" => $amount,
                "MerchantTransactionId" => $order->get_order_number(),
                "OrderId" => $orderId,
                "MerchantId" => $this->merchant_id, // Include merchant ID in the payload
                // Add other necessary fields as per the API documentation
            );
        }

        private function get_headers() {
            return array(
                'Content-Type' => 'application/json',
                'API_KEY' => $this->api_key,
            );
        }

        private function get_api_url() {
            return $this->is_sandbox ? $this->sandbox_url : $this->live_url;
        }
    }

    function add_mypay_gateway_class($methods) {
        $methods[] = 'WC_Gateway_MyPay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_mypay_gateway_class');
}
