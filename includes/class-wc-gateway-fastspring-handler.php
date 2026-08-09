<?php
if (!defined('ABSPATH')) {
    exit;
}

// Polyfill for nginx
if (!function_exists('getallheaders')) {
    function getallheaders()
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}


/**
 * Base class to handle ajax and webhook request from FastSpring.
 *
 * @since 1.0.0
 */
class fssg_WC_Gateway_FastSpring_Handler
{

  /**
   * Gateway options
   *
   * @var array FastSpring gateway options
   */
    protected static $settings;

    /**
     * Constructor
     */
    public function __construct()
    {
        self::set_settings();
        $this->init();
    }

    /**
     * Fetch plugin option
     *
     * @param $o Option key
     * @return mixed option value
     */
    public static function get_setting($o)
    {
        return isset(self::$settings[$o]) ? (self::$settings[$o] === 'yes' ? true : (self::$settings[$o] === 'no' ? false : self::$settings[$o])) : null;
    }

    /**
     * Set plugin option
     */
    public static function set_settings()
    {
        self::$settings  = get_option('woocommerce_fastspring_settings', array());
    }

    /**
     * If API credentials provided we can check for order completion on popup close
     */
    public function get_order_status($id)
    {
        if (empty(self::get_setting('api_username')) || empty(self::get_setting('api_password'))) {
            $this->log('No API credentials - skipping API order confirm');
            return 'pending';
        }

        $url = 'https://api.fastspring.com/orders/' . rawurlencode(sanitize_text_field($id));

        $auth = base64_encode(sanitize_text_field(self::get_setting('api_username')) . ':' . sanitize_text_field(self::get_setting('api_password')));

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'User-Agent'    => 'Mozilla/5.0',
            ),
        ));

        if (is_wp_error($response)) {
            $this->log(sprintf('API order %s check failed: %s', $id, $response->get_error_message()));
            return 'pending';
        }

        $code = wp_remote_retrieve_response_code($response);
        if (200 !== (int) $code) {
            $this->log(sprintf('API order %s not found (HTTP %s)', $id, $code));
            return 'pending';
        }

        $data = json_decode(wp_remote_retrieve_body($response));

        if ($data && isset($data->completed) && true === $data->completed) {
            $this->log(sprintf('API order %s completion checked', $id));
            return 'completed';
        }
        $this->log(sprintf('API order %s not found', $id));
        return 'pending';
    }

    /**
     * AjAX call to mark order as complete (but pending payment) and return payment page
     */
    public function ajax_get_receipt()
    {
        $payload = json_decode(file_get_contents('php://input'));

        $security = isset($payload->security) ? sanitize_text_field(wp_unslash($payload->security)) : '';

        $allowed = wp_verify_nonce($security, 'wc-fastspring-receipt');

        if (!$allowed) {
            wp_send_json_error('Access denied');
        }

        $order_id = absint(WC()->session->get('current_order'));

        $this->log(sprintf('Generating receipt for order %s', $order_id));

        $order = wc_get_order($order_id);
        $data = ['order_id' => $order->get_id()];

        // Check for double calls
        $order_status = $order->get_status();

        // Popup closed with payment
        if ($order && !empty($payload->reference)) {

            // Get API order status if available
            $status = $this->get_order_status(isset($payload->id) ? $payload->id : '');

            // Remove cart
            WC()->cart->empty_cart();

            $order->set_transaction_id(sanitize_text_field($payload->reference));
            $order->update_meta_data('fs_order_id', sanitize_text_field($payload->id));

            if ($status === 'completed' && $order->payment_complete($payload->reference)) {
                $this->log(sprintf('Marking order ID %s as completed', $order->get_id()));
                $order->add_order_note(sprintf(__('FastSpring payment approved (ID: %1$s)', 'woocommerce'), $order->get_id()));
            }
            // We could have a race condition where FS already called webhook so lets not assume its pending
            elseif ($order_status != 'completed') {
                $order->update_status('pending', __('Order pending payment approval.', 'woocommerce'));
            }

            $data = ["redirect_url" => fssg_WC_Gateway_FastSpring_Handler::get_return_url($order), 'order_id' => $order_id];

            wp_send_json($data);
        } else {
            wp_send_json_error('Order not found - Order ID was' . $order_id);
        }
    }

    /**
     * Get receipt URL
     *
     * @param object $order A Woo order
     * @return string Receipt URL
     */
    public static function get_return_url($order = null)
    {
        if ($order) {
            $return_url = $order->get_checkout_order_received_url();
            self::log(sprintf('Receipt URL for order set to %s', $return_url));
        } else {
            $return_url = wc_get_endpoint_url('order-received', '', wc_get_page_permalink('checkout'));
            self::log(sprintf('Receipt URL set to %s', $return_url));
        }

        if (is_ssl() || get_option('woocommerce_force_ssl_checkout') == 'yes') {
            $return_url = str_replace('http:', 'https:', $return_url);
        }

        $filtered = apply_filters('woocommerce_get_return_url', $return_url, $order);
        
        self::log(sprintf('Final filtered receipt URL set to %s', $filtered));

        return $filtered;
    }

    /**
     * Handle the FastSpring webhook
     */
    public function init()
    {
        add_action('wc_ajax_fssg_wc_fastspring_get_receipt', array($this, 'ajax_get_receipt'));
        add_action('wc_ajax_wc_fastspring_get_receipt', array($this, 'ajax_get_receipt'));
        //add_action('wc_ajax_fssg_wc_fastspring_get_payload', array($this, 'ajax_get_payload'));

        add_action('woocommerce_api_wc_gateway_fastspring', array($this, 'listen_webhook_request'));
        add_action('woocommerce_fastspring_handle_webhook_request', array($this, 'handle_webhook_request'));
    }

    /**
     * Listens for webhook request
     */
    public function listen_webhook_request()
    {
        $body = json_decode(file_get_contents('php://input'));

        if (!$this->is_valid_webhook_request()) {
            $this->log('Invalid webhook request - check secret');
            return wp_send_json_error();
        }

        // FastSpring delivers an envelope: {"events":[{type,data}, ...]}.
        $events = (isset($body->events) && is_array($body->events)) ? $body->events : array();

        if (empty($events)) {
            $this->log('No events found in webhook request');
            return wp_send_json_error();
        }

        foreach ($events as $event) {
            if (!is_object($event)) {
                continue;
            }
            $this->handle_webhook_request($event);
        }

        return wp_send_json_success();
    }

    /**
     * Finds one WC order by FastSpring custom tag
     *
     * @throws Exception
     *
     * @param string $id FastSpring transaction ID
     * @return WC_Order WooCommerce order
     */
    public function find_order_by_fastspring_tag($payload)
    {
        $id = @$payload->data->tags->store_order_id;
        $this->log(sprintf('Order tag found for %s', $id));

        if (!isset($id)) {
            $this->log('No order ID found in webhook');
            throw new Exception('No order ID found in webhook');
        }

        $order = wc_get_order($id);

        if (!$order) {
            $this->log(sprintf('No order found with transaction ID %s', $id));
            throw new Exception(sprintf('Unable to locate order with FS transaction ID %s', $id));
        }
        return $order;
    }

    /**
     * Finds one WC order by FastSpring order reference (fs_order_id meta or
     * transaction id). Used by events that carry no tags, e.g. chargebacks.
     *
     * @throws Exception
     *
     * @param string $fs_order_id FastSpring order ID.
     * @return WC_Order WooCommerce order
     */
    public function find_order_by_fastspring_id($fs_order_id)
    {
        $fs_order_id = sanitize_text_field($fs_order_id);

        if (!$fs_order_id) {
            throw new Exception('No FastSpring order ID provided');
        }

        $orders = wc_get_orders(
            array(
                'limit'      => 1,
                'type'       => 'shop_order',
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key'   => 'fs_order_id',
                        'value' => $fs_order_id,
                    ),
                    array(
                        'key'   => '_transaction_id',
                        'value' => $fs_order_id,
                    ),
                ),
            )
        );

        if (empty($orders)) {
            $this->log(sprintf('No order found with FS order ID %s', $fs_order_id));
            throw new Exception(sprintf('Unable to locate order with FS order ID %s', $fs_order_id));
        }

        return $orders[0];
    }

    /**
     * Handles the validated FS webhook request
     *
     * @throws Exception
     *
     * @param array $payload Webhook data
     * @return array JSON response
     */
    public function handle_webhook_request($payload)
    {
        try {
            switch ($payload->type) {

                case 'order.completed':
                  $this->handle_webhook_request_order_completed($payload);
                  break;

                case 'return.created':
                  $this->handle_webhook_request_order_refunded($payload);
                  break;

                case 'subscription.canceled':
                  $this->handle_webhook_request_subscription_canceled($payload);
                  break;

                case 'subscription.deactivated':
                  $this->handle_webhook_request_subscription_deactivate($payload);
                  break;

                case 'subscription.activated':
                  $this->handle_webhook_request_subscription_activate($payload);
                  break;

                case 'chargeback.created':
                  $this->handle_webhook_request_chargeback_created($payload);
                  break;

                case 'subscription.updated':
                //$this->handle_webhook_request_subscription_canceled($payload);
                //break;

                default:
                  $this->log(sprintf('No webhook handler found for %s', $payload->type));
                  break;
                }

            $this->log(json_encode($payload));
            return true;
        } catch (Exception $e) {
            $this->log(sprintf('Webhook handler error: %s', $e->getMessage()));
            return false;
        }
    }

    /**
     * Handles the order.completed webhook
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_order_completed($payload)
    {
        $order = $this->find_order_by_fastspring_tag($payload);

        // Only mark complete if not already - webhook can hit multiple times
        if ($order->get_status() !== 'completed' && $order->payment_complete($payload->reference)) {
            $this->log(sprintf('Marking order ID %s as complete', $order->get_id()));
            $order->add_order_note(sprintf(__('FastSpring payment approved (ID: %1$s)', 'woocommerce'), $order->get_id()));
        } else {
            $this->log(sprintf('Failed marking order ID %s as complete', $order->get_id()));
        }
    }

    /**
     * Handles the chargeback.created webhook.
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_chargeback_created($payload)
    {
        $data = isset($payload->data) && is_object($payload->data) ? $payload->data : $payload;

        $fs_order_id = isset($data->order) ? sanitize_text_field($data->order) : '';
        $order       = $this->find_order_by_fastspring_id($fs_order_id);

        $settings = get_option('woocommerce_fs_advanced_settings', array());
        if (empty($settings['chargeback_created']) || 'yes' !== $settings['chargeback_created']) {
            $this->log(sprintf('Chargeback created webhook received but handler disabled for order %s', $order->get_id()));
            return;
        }

        $order->update_meta_data('_fs_chargeback_prev_status', $order->get_status());
        $order->save();

        $status   = isset($settings['chargeback_created_status']) ? $settings['chargeback_created_status'] : 'wc-on-hold';
        $reason   = isset($data->reasonDescription) ? sanitize_text_field($data->reasonDescription) : '';
        $code     = isset($data->reasonCode) ? sanitize_text_field($data->reasonCode) : '';
        $amount   = isset($data->chargebackAmount) ? wc_format_decimal($data->chargebackAmount) : '';
        $currency = isset($data->currency) ? sanitize_text_field($data->currency) : '';

        $order->update_status(
            $status,
            sprintf(__('FastSpring chargeback filed (reason: %1$s, code: %2$s, amount: %3$s %4$s).', 'fastspring-split-gateways'), $reason, $code, $amount, $currency)
        );

        $this->log(sprintf('Order %s set to %s on chargeback', $order->get_id(), $status));
    }

    /**
     * Handles the return.created webhook.
     *
     * A chargeback-originated return (data.type = CHARGEBACK) restores the
     * pre-chargeback status when chargeback_closed is enabled. All other
     * returns mark the order refunded.
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_order_refunded($payload)
    {
        $data = isset($payload->data) && is_object($payload->data) ? $payload->data : $payload;

        $fs_order_id = '';
        if (isset($data->original) && isset($data->original->order)) {
            $fs_order_id = sanitize_text_field($data->original->order);
        } elseif (isset($data->reference)) {
            $fs_order_id = sanitize_text_field($data->reference);
        }

        $order = $this->find_order_by_fastspring_id($fs_order_id);

        $type        = isset($data->type) ? strtoupper(sanitize_text_field($data->type)) : 'RETURN';
        $prev_status = $order->get_meta('_fs_chargeback_prev_status');

        if ('CHARGEBACK' === $type && $prev_status) {
            $settings = get_option('woocommerce_fs_advanced_settings', array());
            $enabled  = isset($settings['chargeback_closed']) ? ('yes' === $settings['chargeback_closed']) : true;

            if ($enabled) {
                $order->update_status($prev_status, __('FastSpring chargeback closed - order status restored.', 'fastspring-split-gateways'));
                $order->delete_meta_data('_fs_chargeback_prev_status');
                $order->save();
                $this->log(sprintf('Order %s restored to %s after chargeback closed', $order->get_id(), $prev_status));
                return;
            }
        }

        $this->log(sprintf('Marking order ID %s as refunded', $order->get_id()));
        $order->update_status('refunded');
    }

    /**
     * Handles subscription cancellation
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_subscription_canceled($payload)
    {
        $order = $this->find_order_by_fastspring_tag($payload);
        $this->log(sprintf('Marking subscription order ID %s as canceled', $order->get_id()));
        $order->update_status('cancelled');
    }

    /**
     * Handles subscription (re)activation
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_subscription_activate($payload)
    {
        $order = $this->find_order_by_fastspring_tag($payload);
        $this->log(sprintf('Marking subscription order ID %s as (re)activated', $order->get_id()));
        $order->update_status('active');
    }

    /**
     * Handles subscription deactivation
     *
     * @param array $payload Webhook data
     */
    public function handle_webhook_request_subscription_deactivate($payload)
    {
        $order = $this->find_order_by_fastspring_tag($payload);
        $this->log(sprintf('Marking subscription order ID %s as deactivated', $order->get_id()));
        $order->update_status('on-hold');
    }

    /**
     * Check with FastSpring whether posted data is valid FastSpring webhook
     *
     * @throws Exception
     *
     * @param array $payload Webhook data
     * @return bool True if payload is valid FastSpring webhook
     */
    public function is_valid_webhook_request()
    {
        $this->log(sprintf('%s: %s', __FUNCTION__, 'Checking FastSpring webhook validity'));

        $secret = self::get_setting('webhook_secret');

        // Without a configured secret we cannot verify signatures. Accept
        // unverified requests (legacy/dev behavior) so the endpoint stays usable.
        if (empty($secret)) {
            $this->log('Webhook secret not configured - accepting unverified request');
            return true;
        }

        $sig = isset($_SERVER['HTTP_X_FS_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FS_SIGNATURE'])) : '';

        // A secret is configured: a signed request is mandatory.
        if (empty($sig)) {
            $this->log('Missing X-FS-Signature header for webhook request');
            return false;
        }

        $raw_body = file_get_contents('php://input');
        $hash = base64_encode(hash_hmac('sha256', $raw_body, $secret, true));

        return hash_equals($sig, $hash);
    }

    /**
     * Logs
     *
     * @param string $message
     */
    public static function log($message)
    {
        fssg_WC_FastSpring::log($message);
    }
}

new fssg_WC_Gateway_FastSpring_Handler();
