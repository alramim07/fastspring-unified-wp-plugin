<?php
if (!defined('ABSPATH')) {
    exit;
}

include_once dirname(__FILE__) . '/class-wc-gateway-fastspring-builder.php';

/**
 * fssg_WC_Gateway_FastSpring class.
 *
 * @extends WC_Payment_Gateway
 */
class fssg_WC_Gateway_FastSpring extends WC_Payment_Gateway
{
    use WC_FastSpring_Settings_Trait;

    /**
     * @var string
     */
    public $default_title;

    /**
     * @var string Branded navigation tab title.
     */
    public $tab_title;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->id = 'fastspring';
        $this->method_title = __('FastSpring', 'woocommerce-gateway-fastspring');
        $this->default_title = __('FastSpring', 'woocommerce-gateway-fastspring');
        $this->method_description = __('This plugin provides checkout payment processing by <a href="https://fastspring.com" target="_blank">FastSpring</a> using their hosted or popup storefronts. ');
        $this->tab_title = __('General', 'fastspring-split-gateways');

        $this->has_fields = true;
        $this->supports = array(
            'products',
            'refunds',
            // 'tokenization',
            // 'add_payment_method',
            'subscriptions', // subscription.activated
            'subscription_cancellation', // FS subscription.canceled
            'subscription_suspension', // FS subscription.deactivated,
            'subscription_reactivation', // FS  subscription.activated
            'subscription_amount_changes', // FS subscription.updated
            'subscription_date_changes', // FS subscription.updated
            // 'subscription_payment_method_change',
            // 'subscription_payment_method_change_customer',
            // 'subscription_payment_method_change_admin',
            'multiple_subscriptions',
            //'pre-orders',
        );

        // FS not implemented for subscriptions:
        // subscription.trial.reminder
        // subscription.payment.reminder
        // subscription.payment.overdue
        // subscription.charge.completed
        // subscription.charge.failed

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get setting values.
        $this->title = self::get_setting('title');
        $this->description = self::get_setting('description');

        // Give the admin Payments overview a real logo (it reads $this->icon as a URL).
        $this->icon = FSSG_WC_FASTSPRING_PLUGIN_URL . '/assets/img/fastspring-logo.png';

        if (self::get_setting('testmode')) {
            $this->description .= "\n" . sprintf(__('TEST MODE ENABLED. In test mode, you can use the card numbers provided in the test panel of the FastSpring dashboard. Please check the documentation "<a target="_blank" href="%s">Testing Orders</a>" for more information.', 'woocommerce-gateway-fastspring'), 'http://docs.fastspring.com/activity-events-orders-and-subscriptions/test-orders');

            $this->description = trim($this->description);
        }

        // Hooks.
        add_action('wc_ajax_fssg_wc_fastspring_order_complete', array($this, 'ajax_order_complete'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_wc_gateway_fastspring_commerce', array($this, 'return_handler'));
        add_filter('wc_fs_settings_nav_tabs', array($this, 'admin_nav_tab'));
    }

    /**
     * Renders the branded settings table without the WooCommerce back-header.
     */
    protected function admin_table()
    {
        echo wp_kses_post(wpautop($this->get_method_description()));
        echo '<table class="form-table">' . $this->generate_settings_html($this->get_form_fields(), false) . '</table>'; // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
    }

    /**
     * Validate access key settings field
     *
     * @params $value
     */
    public function validate_access_key_field($key, $value)
    {
        if (!empty($value)) {
            return $value;
        }
        WC_Admin_Settings::add_error(esc_html__('A FastSpring access key is required.', 'woocommerce-gateway-fastspring'));
    }

    /**
     * Validate private key settings field
     *
     * @params $value
     */
    public function validate_private_key_field($key, $value)
    {
        if (@openssl_private_encrypt('abc', $aes_key_encrypted, openssl_pkey_get_private($value))) {
            return $value;
        }

        WC_Admin_Settings::add_error(esc_html__('The RSA private key field is invalid.', 'woocommerce-gateway-fastspring'));
    }

    /**
     * Validate title settings field
     *
     * @params $value
     */
    public function validate_title_field($key, $value)
    {
        if (empty($value)) {
            return $this->default_title;
        }
        return $value;
    }

    /**
     * Validate storefront path settings field
     *
     * @params $value
     */
    public function validate_storefront_path_field($key, $value)
    {
        if (empty($value)) {
            WC_Admin_Settings::add_error(esc_html__('Enter a valid storefront path.', 'woocommerce-gateway-fastspring'));
        } elseif (!empty($value)) {
            return preg_replace('#^https?://#', '', rtrim($value, '/'));
        }
    }

    /**
     * Check if this gateway is enabled
     */
    public function is_available()
    {
        if (!self::get_setting('enabled')) {
            return false;
        }

        if (self::get_setting('access_key') && self::get_setting('private_key') && self::get_setting('storefront_path')) {
            return true;
        }
        return false;
    }

    /**
     * Get_icon function.
     *
     * @return string
     */
    public function get_icon()
    {
        $icons = $this->payment_icons();
        $icons_enabled = self::get_setting('icons');

        $icons_str = '';
        $icons_str .= in_array('paypal', $icons_enabled) ? $icons['paypal'] : '';
        $icons_str .= in_array('visa', $icons_enabled) ? $icons['visa'] : '';
        $icons_str .= in_array('amex', $icons_enabled) ? $icons['amex'] : '';
        $icons_str .= in_array('mastercard', $icons_enabled) ? $icons['mastercard'] : '';
        $icons_str .= in_array('discover', $icons_enabled) ? $icons['discover'] : '';
        $icons_str .= in_array('jcb', $icons_enabled) ? $icons['jcb'] : '';
        $icons_str .= in_array('diners', $icons_enabled) ? $icons['diners'] : '';
        $icons_str .= in_array('ideal', $icons_enabled) ? $icons['ideal'] : '';
        $icons_str .= in_array('unionpay', $icons_enabled) ? $icons['unionpay'] : '';
        $icons_str .= in_array('sofort', $icons_enabled) ? $icons['sofort'] : '';
        $icons_str .= in_array('giropay', $icons_enabled) ? $icons['giropay'] : '';

        return apply_filters('woocommerce_gateway_icon', $icons_str, $this->id);
    }

    /**
     * All payment icons that work with Stripe. Some icons references
     * WC core icons.
     *
     * @return array
     */
    public function payment_icons()
    {
        return apply_filters(
            'fssg_wc_fastspring_payment_icons',
            array(
                'paypal' => '<img src="' . FSSG_WC_FASTSPRING_PLUGIN_URL . '/assets/img/paypal.svg" class="fastspring-visa-icon fastspring-icon" alt="PayPal" />',
                'visa' => '<img src="' . FSSG_WC_FASTSPRING_PLUGIN_URL . '/assets/img/visa.svg" class="fastspring-visa-icon fastspring-icon" alt="Visa" />',
                'amex' => '<img src="' . FSSG_WC_FASTSPRING_PLUGIN_URL . '/assets/img/amex.svg" class="fastspring-amex-icon fastspring-icon" alt="American Express" />',
                'mastercard' => '<img src="' . FSSG_WC_FASTSPRING_PLUGIN_URL . '/assets/img/mastercard.svg" class="fastspring-mastercard-icon fastspring-icon" alt="Mastercard" />',
                'discover' => '<img src="' . FSSG_WC_FASTSPRING_PLUGIN_URL . '/assets/img/discover.svg" class="fastspring-discover-icon fastspring-icon" alt="Discover" />',
                'diners' => '<img src="' . FSSG_WC_FASTSPRING_PLUGIN_URL . '/assets/img/diners.svg" class="fastspring-diners-icon fastspring-icon" alt="Diners" />',
                'jcb' => '<img src="' . FSSG_WC_FASTSPRING_PLUGIN_URL . '/assets/img/jcb.svg" class="fastspring-jcb-icon fastspring-icon" alt="JCB" />',
                'ideal' => '<img src="' . FSSG_WC_FASTSPRING_PLUGIN_URL . '/assets/img/ideal.svg" class="fastspring-ideal-icon fastspring-icon" alt="iDeal" />',
                'giropay' => '<img src="' . FSSG_WC_FASTSPRING_PLUGIN_URL . '/assets/img/giropay.svg" class="fastspring-giropay-icon fastspring-icon" alt="Giropay" />',
                'sofort' => '<img src="' . FSSG_WC_FASTSPRING_PLUGIN_URL . '/assets/img/sofort.svg" class="fastspring-sofort-icon fastspring-icon" alt="SOFORT" />',
                'unionpay' => '<img src="' . FSSG_WC_FASTSPRING_PLUGIN_URL . '/assets/img/unionpay.svg" class="fastspring-unionpay-icon fastspring-icon" alt="Union Pay" />',
            )
        );
    }

    /**
     * Initialise gateway settings form fields.
     *
     * The main "General" settings page was retired; all settings (enabled,
     * title, description, icons, billing_address, credentials, mode) are
     * managed from the branded API Settings screen, which persists to the
     * same `woocommerce_fastspring_settings` option.
     */
    public function init_form_fields()
    {
        $this->form_fields = array();
    }

    /**
     * Payment_scripts function.
     *
     * Outputs scripts used for fastspring payment
     */
    public function payment_scripts()
    {
        $load_scripts = false;

        if (is_checkout()) {
            $load_scripts = true;
        }

        if ($this->is_available()) {
            $load_scripts = true;
        }

        if (false === $load_scripts) {
            return;
        }

        $suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';

        if (self::get_setting('enabled')) {
            wp_enqueue_script('fastspring', FSSG_WC_FASTSPRING_SCRIPT, '', false, true);

            wp_enqueue_script('woocommerce_fastspring', plugins_url('assets/js/fastspring-checkout' . $suffix . '.js', FSSG_WC_FASTSPRING_MAIN_FILE), array('jquery', 'fastspring'), FSSG_WC_FASTSPRING_VERSION, true);
        }

        $fastspring_params = array(
            'ajax_url' => WC_AJAX::get_endpoint('%%endpoint%%'),
            'nonce' => array(
                'receipt' => wp_create_nonce('wc-fastspring-receipt'),
            ),
        );

        $custom_css = '.woocommerce-checkout #payment ul.payment_methods li img.fastspring-icon { max-width: 40px; padding-left: 3px; margin: 0; }';
        $custom_css .= '.woocommerce-checkout #payment ul.payment_methods li img.fastspring-ideal-icon { max-height: 26px; }';
        $custom_css .= '.woocommerce-checkout #payment ul.payment_methods li img.fastspring-sofort-icon { max-width: 55px; magin-left: 3px; }';

        wp_add_inline_style('woocommerce-inline', $custom_css);

        wp_localize_script('woocommerce_fastspring', 'woocommerce_fastspring_params', apply_filters('woocommerce_fastspring_params', $fastspring_params));
    }

    /**
     * Process the payment.
     *
     * @param int $order_id
     *
     * @return array|void
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $payload = fssg_WC_Gateway_FastSpring_Builder::get_secure_json_payload();
        return array(
            'result' => 'success',
            'session' => $payload,
        );
    }

    /**
     * Get FS Transaction URL
     *
     * @param  \WC_Order $order
     *
     * @return string
     */
    public function get_transaction_url($order)
    {
        $transaction_id = $order->get_transaction_id();

        if ($order->meta_exists('fs_order_id')) {
            return 'https://dashboard.fastspring.com/order/home.xml?mRef=AcquisitionTransaction:' . $order->get_meta('fs_order_id');
        }

        return '';
    }

    /**
     * Options
     *
     * @param string $option option name
     * @return mixed option value
     */
    public static function get_setting($option)
    {
        return fssg_WC_FastSpring::get_setting($option);
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

    /**
     * Payment form on checkout page.
     */
    public function payment_fields()
    {
        $description = $this->get_description();

        if ($description) {
            echo wpautop(wptexturize(trim($description)));
        }
    }
}

