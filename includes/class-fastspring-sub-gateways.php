<?php
/**
 * FastSpring Split Gateways Logic Module
 * Part of the FastSpring Unified Plugin
 */

if (!defined('ABSPATH')) {
  exit;
}
define('FS_SPLIT_GATEWAY_URL', plugin_dir_url(dirname(__FILE__)));

add_action('plugins_loaded', function () {
  if (!class_exists('fssg_WC_Gateway_FastSpring')) {
    return;
  }
  class fssg_WC_FS_Manual_Gateway extends fssg_WC_Gateway_FastSpring
  {
    public $default_title = '';
    public $default_icon;
    public $default_description = '';

    public function __construct()
    {
      $stored_id = $this->id;
      $stored_method_title = $this->method_title;
      $stored_default_title = isset($this->default_title) ? $this->default_title : '';
      $stored_method_desc = isset($this->method_description) ? $this->method_description : '';


      $this->has_fields = true;
      parent::__construct();

      if (!empty($stored_id)) {
        $this->id = $stored_id;
      }
      if (!empty($stored_method_title)) {
        $this->method_title = $stored_method_title;
      }
      if (!empty($stored_default_title)) {
        $this->default_title = $stored_default_title;
      }
      $this->method_description = $stored_method_desc;
      $this->tab_title = preg_replace('/^FS:\s*/', '', (string) $this->method_title);

      $this->init_form_fields();
      $this->init_settings();

      // 1. Load the saved status from the database
      $saved_settings = get_option('woocommerce_' . $this->id . '_settings', array());
      $is_already_enabled = (isset($saved_settings['enabled']) && $saved_settings['enabled'] === 'yes');

      // 2. Check if currently SAVING this specific gateway
      $is_saving_this_gateway = isset($_POST['woocommerce_' . $this->id . '_enabled']);
      $current_section = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : '';

      if ($current_section === $this->id || $is_saving_this_gateway) {
        // Editing/saving this specific gateway, so load the new value
        $this->enabled = $this->get_option('enabled', 'no');
      } else {
        // Saving the MAIN settings or something else. 
        $this->enabled = $is_already_enabled ? 'yes' : 'no';
      }

      // Fallback for title
      $this->title = $this->get_option('title');
      if (empty($this->title)) {
        $this->title = !empty($stored_default_title) ? $stored_default_title : $this->default_title;
      }

      $this->description = $this->get_option('description', $this->default_description);
      // Expose a resolvable URL for the admin Payments overview (WooCommerce reads
      // $gateway->icon as a URL) while the stored value stays a slug/array.
      $this->icon = $this->resolve_icon_url($this->get_option('icon', $this->default_icon));

      // 3. ONLY hook the save action if we are actually in this section
      if ($current_section === $this->id) {
        add_action("woocommerce_update_options_payment_gateways_{$this->id}", array($this, 'process_admin_options'));
      }
    }

    public function process_admin_options()
    {
      $current_section = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : '';

      // Only save if the URL section matches this gateway ID
      if ($current_section === $this->id) {
        parent::process_admin_options();
      }
    }

    public function init_form_fields()
    {
      $this->form_fields = array(
        'enabled' => array(
          'title' => __('Enable/Disable', 'woocommerce'),
          'label' => __('Enable this gateway', 'woocommerce'),
          'type' => 'checkbox',
          'default' => 'no'
        ),
        'title' => array(
          'title' => __('Title', 'woocommerce'),
          'type' => 'text',
          'default' => $this->default_title,
        ),
        'description' => array(
          'title' => __('Description', 'woocommerce'),
          'type' => 'textarea',
          'default' => $this->default_description,
        ),
        'icon' => array(
          'title' => __('Icon', 'woocommerce'),
          'type' => 'gateway_icon',
          'description' => __('Choose a preset icon or upload a custom image for this gateway.', 'woocommerce'),
          'default' => $this->default_icon,
          'options' => $this->icon_options(),
        ),
      );
    }

    /**
     * Preset icons available to the gateway icon picker.
     *
     * @return array slug => label
     */
    protected function icon_options()
    {
      return array(
        'paypal' => __('PayPal', 'woocommerce'),
        'alipay' => __('AliPay', 'woocommerce'),
        'amazon-pay' => __('Amazon Pay', 'woocommerce'),
        'google-pay' => __('Google Pay', 'woocommerce'),
        'wire-transfer' => __('Wire Transfer', 'woocommerce'),
        'visa' => __('Visa', 'woocommerce'),
        'mastercard' => __('MasterCard', 'woocommerce'),
        'amex' => __('Amex', 'woocommerce'),
        'discover' => __('Discover', 'woocommerce'),
        'jcb' => __('JCB', 'woocommerce'),
        'unionpay' => __('Union Pay', 'woocommerce'),
        'maestro' => __('Maestro', 'woocommerce'),
        'dinersclub' => __('Diners Club', 'woocommerce'),
      );
    }

    /**
     * Custom field type for WooCommerce settings to render the preset
     * icon picker (radio cards + custom media uploader).
     */
    public function generate_gateway_icon_html($key, $data)
    {
      $field_key = $this->get_field_key($key);
      $value = $this->get_option($key, $this->default_icon);
      $data = wp_parse_args($data, array(
        'title' => '',
        'description' => '',
        'options' => $this->icon_options(),
      ));

      ob_start();
      include dirname(__FILE__) . '/admin/views/html-gateway-icon-picker.php';
      return ob_get_clean();
    }

    /**
     * Validates the gateway icon value.
     *
     * Accepts a preset slug or a `custom:<url>` value. Anything else falls
     * back to the gateway default icon.
     *
     * @param string $key   Field key.
     * @param mixed  $value Submitted value.
     * @return string
     */
    public function validate_gateway_icon($key, $value)
    {
      $options = $this->icon_options();
      if (is_string($value) && isset($options[$value])) {
        return $value;
      }
      if (is_string($value) && strpos($value, 'custom:') === 0) {
        $url = esc_url_raw(substr($value, 7));
        if (!empty($url)) {
          return 'custom:' . $url;
        }
      }
      return $this->default_icon;
    }

    /**
     * Resolves a stored icon value to a displayable URL.
     *
     * Accepts a preset slug, a `custom:<url>` value, a raw URL or (for the
     * credit card gateway) an array of slugs, in which case the first selected
     * slug is used.
     *
     * @param mixed $value Stored icon value.
     * @return string
     */
    protected function resolve_icon_url($value)
    {
      if (is_array($value)) {
        $value = count($value) ? (string) reset($value) : '';
      }
      $options = $this->icon_options();
      if (is_string($value) && isset($options[$value])) {
        return FS_SPLIT_GATEWAY_URL . 'assets/icons/' . $value . '.svg';
      }
      if (is_string($value) && strpos($value, 'custom:') === 0) {
        return substr($value, 7);
      }
      return is_string($value) ? $value : '';
    }

    public function is_available()
    {
      $parent_settings = get_option('woocommerce_fastspring_settings', array());
      return $this->enabled === 'yes' && !empty($parent_settings['access_key']);
    }

    public function get_icon()
    {
      $icon_html = '';
      $icons_folder_url = FS_SPLIT_GATEWAY_URL . 'assets/icons/';
      $stored_icon = $this->get_option('icon', $this->default_icon);

      if (is_array($stored_icon)) {
        $icon_html .= '<span class="fs-card-inline-icons-container">';
        foreach ($stored_icon as $card) {
          $icon_url = $icons_folder_url . strtolower($card) . '.svg';
          // Wrap each image in a 'box' class
          $icon_html .= '<img src="' . esc_url($icon_url) . '" alt="' . esc_attr($card) . '" class="fs-inline-icon" />';
        }
        $icon_html .= '</span>';
      } elseif (!empty($this->icon) && is_string($this->icon)) {
        // $this->icon is already a resolved URL (see constructor).
        $icon_html = '<img src="' . esc_url($this->icon) . '" alt="' . esc_attr($this->title) . '" class="fastspring-icon" />';
      }

      return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
    }


    public function payment_fields()
    {
      if ($this->description)
        echo wpautop(wptexturize($this->description));
    }

    public function process_payment($order_id)
    {
      
      $order = wc_get_order($order_id);

      
      $order->set_payment_method('fastspring');
      $order->save();

      
      $result = parent::process_payment($order_id);
      return $result;
    }

    
    public function payment_scripts()
    {
      // 1. Basic check
      if (!is_checkout() && !is_add_payment_method_page() && !isset($_GET['pay_for_order'])) {
        return;
      }

      // 2. Enqueue Style (Use filemtime to force refresh cache)
      $style_path = plugin_dir_path(dirname(__FILE__)) . 'assets/css/style.css';
      $style_url = plugin_dir_url(dirname(__FILE__)) . 'assets/css/style.css';

      wp_enqueue_style(
        'fs-split-gateways-style',
        $style_url,
        array(),
        file_exists($style_path) ? filemtime($style_path) : '1.0.0'
      );

      // 3. FIX THE FATAL ERROR: 
      // Check if the constant exists; if not, use the direct URL to FastSpring
wp_enqueue_script(
    'fastspring',
    'https://sbl.onfastspring.com/sbl/1.0.6/fastspring-builder.min.js',
    array('jquery'),
    null,
    true
);

wp_script_add_data(
    'fastspring',
    'data-storefront',
    'prospct.onfastspring.com/popup-microprokey'
);
    }
  }

  class fssg_WC_Gateway_FastSpring_PayPal extends fssg_WC_FS_Manual_Gateway
  {
    public function __construct()
    {
      $this->id = 'fastspring_paypal';
      $this->method_title = 'FS: PayPal'; // This is what shows in the Admin List
      $this->default_title = 'Pay with PayPal';    // Default for the User
      $this->default_icon = 'paypal';
      $this->default_description = 'Securely complete your payment using PayPal. You can pay with your PayPal balance or linked card/bank account.';

      parent::__construct();

      // After parent loads settings, if the user hasn't saved a custom title, use default
      if (empty($this->title)) {
        $this->title = $this->default_title;
      }
    }
  }
  class fssg_WC_Gateway_FastSpring_CreditCard extends fssg_WC_FS_Manual_Gateway
  {
    public function __construct()
    {
      $this->id = 'fastspring_card';
      $this->method_title = 'FS: Credit Card';
      $this->default_title = 'Pay with Card (Credit/Debit)';
      $this->default_icon = array('visa', 'mastercard');
      $this->default_description = 'Pay securely using your Visa, MasterCard, or other supported debit/credit cards. Fast and reliable payment processing.';

      parent::__construct();

      if (empty($this->title)) {
        $this->title = $this->default_title;
      }
    }
    public function init_form_fields()
    {
      parent::init_form_fields();

      $this->form_fields['icon'] = array(
        'title' => __('Credit Card Icons', 'woocommerce'),
        'type' => 'multiselect',
        'class' => 'wc-enhanced-select',
        'description' => __('Select which card logos to display.', 'woocommerce'),
        'options' => array(
          'amex' => 'Amex',
          'discover' => 'Discover',
          'visa' => 'Visa',
          'mastercard' => 'MasterCard',
          'unionpay' => 'Union Pay',
          'maestro' => 'Maestro',
          'jcb' => 'JCB',
          'dinersclub' => 'Diners Club',
        ),
        'default' => array('visa', 'mastercard'),
      );
    }
  }

  class fssg_WC_Gateway_FastSpring_Amazon extends fssg_WC_FS_Manual_Gateway
  {
    public function __construct()
    {
      $this->id = 'fastspring_amazon';
      $this->method_title = 'FS: Amazon Pay';
      $this->default_title = 'Pay with Amazon Pay';
      $this->default_icon = 'amazon-pay';
      $this->default_description = 'Use your Amazon account to pay quickly and securely without entering your payment details again.';

      parent::__construct();

      if (empty($this->title)) {
        $this->title = $this->default_title;
      }
    }
  }

  class fssg_WC_Gateway_FastSpring_Wire extends fssg_WC_FS_Manual_Gateway
  {
    public function __construct()
    {
      $this->id = 'fastspring_wire';
      $this->method_title = 'FS: Wire Transfer';
      $this->default_title = 'Pay via Bank Transfer';
      $this->default_icon = 'wire-transfer';
      $this->default_description = 'Transfer the payment directly to our bank account. Your order will be processed after the payment is confirmed.';

      parent::__construct();

      if (empty($this->title)) {
        $this->title = $this->default_title;
      }
    }
  }

  class fssg_WC_Gateway_FastSpring_GooglePay extends fssg_WC_FS_Manual_Gateway
  {
    public function __construct()
    {
      $this->id = 'fastspring_googlepay';
      $this->method_title = 'FS: Google Pay';
      $this->default_title = 'Pay with Google Pay';
      $this->default_icon = 'google-pay';
      $this->default_description = 'Checkout quickly using Google Pay. Safe, fast, and convenient on mobile and desktop devices.';

      parent::__construct();

      if (empty($this->title)) {
        $this->title = $this->default_title;
      }
    }
  }

  class fssg_WC_Gateway_FastSpring_AliPay extends fssg_WC_FS_Manual_Gateway
  {
    public function __construct()
    {
      $this->id = 'fastspring_alipay';
      $this->method_title = 'FS: AliPay';
      $this->default_title = 'Pay with AliPay';
      $this->default_icon = 'alipay';
      $this->default_description = 'Pay securely with AliPay using your Alipay account, linked card, or bank account. Fast and reliable for customers in China and across Asia.';

      parent::__construct();

      if (empty($this->title)) {
        $this->title = $this->default_title;
      }
    }
  }

  // --- WOOCOMMERCE BLOCKS INTEGRATION ---
  // Now that the classes are defined, we can define the blocks integration
  if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {

    class fssg_FS_Blocks_Integration extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
    {
      private $gateway;

      public function __construct($gateway)
      {
        $this->gateway = $gateway;
      }

      public function initialize()
      {
        $this->settings = get_option("woocommerce_{$this->gateway->id}_settings", []);
      }

      public function get_name()
      {
        return $this->gateway->id;
      }

      public function is_active()
      {
        return $this->gateway->is_available();
      }

      public function get_payment_method_script_handles()
      {
        wp_register_script(
          "fs-blocks-{$this->gateway->id}",
          '',
          ['wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n'],
          '1.0',
          true
        );

        $script = "
                const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
                const { decodeEntities } = window.wp.htmlEntities;
                
                const Label = (props) => {
                    const { PaymentMethodLabel } = props.components;
                    return window.wp.element.createElement(PaymentMethodLabel, { text: '" . esc_js($this->gateway->title) . "' });
                };

                const Content = () => {
                    return window.wp.element.createElement('div', null, decodeEntities('" . esc_js($this->gateway->description) . "'));
                };

                registerPaymentMethod({
                    name: '" . esc_js($this->gateway->id) . "',
                    label: window.wp.element.createElement(Label, null),
                    content: window.wp.element.createElement(Content, null),
                    edit: window.wp.element.createElement(Content, null),
                    canMakePayment: () => true,
                    ariaLabel: '" . esc_js($this->gateway->title) . "',
                    supports: {
                        features: ['products']
                    }
                });
            ";
        wp_add_inline_script("fs-blocks-{$this->gateway->id}", $script);
        return ["fs-blocks-{$this->gateway->id}"];
      }

      public function get_payment_method_data()
      {
        return [
          'title' => $this->gateway->title,
          'description' => $this->gateway->description,
        ];
      }
    }

    // Hook into the block registration
    add_action('woocommerce_blocks_payment_method_type_registration', function (\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
      $payment_method_registry->register(new fssg_FS_Blocks_Integration(new fssg_WC_Gateway_FastSpring_PayPal()));
      $payment_method_registry->register(new fssg_FS_Blocks_Integration(new fssg_WC_Gateway_FastSpring_CreditCard()));
      $payment_method_registry->register(new fssg_FS_Blocks_Integration(new fssg_WC_Gateway_FastSpring_Amazon()));
      $payment_method_registry->register(new fssg_FS_Blocks_Integration(new fssg_WC_Gateway_FastSpring_Wire()));
      $payment_method_registry->register(new fssg_FS_Blocks_Integration(new fssg_WC_Gateway_FastSpring_GooglePay()));
      $payment_method_registry->register(new fssg_FS_Blocks_Integration(new fssg_WC_Gateway_FastSpring_AliPay()));
    });
  }

}, 11);

// 2. REGISTER THE GATEWAYS (with Sorting Hook)
add_filter('woocommerce_payment_gateways', 'fssg_woocommerce_payment_gateways_order');
function fssg_woocommerce_payment_gateways_order($gateways)
{
  $fastspring_gateways = array(
    'fastspring_paypal' => 'fssg_WC_Gateway_FastSpring_PayPal',
    'fastspring_card' => 'fssg_WC_Gateway_FastSpring_CreditCard',
    'fastspring_amazon' => 'fssg_WC_Gateway_FastSpring_Amazon',
    'fastspring_wire' => 'fssg_WC_Gateway_FastSpring_Wire',
    'fastspring_googlepay' => 'fssg_WC_Gateway_FastSpring_GooglePay',
    'fastspring_alipay' => 'fssg_WC_Gateway_FastSpring_AliPay',
  );

  $ordering = (array) get_option('woocommerce_gateway_order');

  uksort($fastspring_gateways, function ($a, $b) use ($ordering) {
    $pos_a = isset($ordering[$a]) && is_numeric($ordering[$a]) ? (int) $ordering[$a] : 999;
    $pos_b = isset($ordering[$b]) && is_numeric($ordering[$b]) ? (int) $ordering[$b] : 999;
    return $pos_a - $pos_b;
  });

  foreach ($fastspring_gateways as $class) {
    $gateways[] = $class;
  }

  return $gateways;
}

// 3. HIDE THE MAIN "OPTION 1" FROM CHECKOUT
add_filter('woocommerce_available_payment_gateways', function ($gateways) {
  if (isset($gateways['fastspring'])) {
    unset($gateways['fastspring']);
  }
  return $gateways;
}, 99);

// 4. ADMIN TABS VISIBILITY
add_filter('woocommerce_get_sections_checkout', function ($sections) {
  $current_section = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : '';
  $fs_sections = array('fastspring_paypal', 'fastspring_card', 'fastspring_amazon', 'fastspring_wire', 'fastspring_googlepay', 'fastspring_alipay');

  // The main "General" gateway no longer has its own section; all its settings
  // are managed from API Settings.
  unset($sections['fastspring']);

  if (in_array($current_section, $fs_sections)) {
    $sections['fastspring_paypal'] = __('FS: PayPal', 'woocommerce');
    $sections['fastspring_card'] = __('FS: Credit Card', 'woocommerce');
    $sections['fastspring_amazon'] = __('FS: Amazon Pay', 'woocommerce');
    $sections['fastspring_wire'] = __('FS: Wire Transfer', 'woocommerce');
    $sections['fastspring_googlepay'] = __('FS: Google Pay', 'woocommerce');
    $sections['fastspring_alipay'] = __('FS: AliPay', 'woocommerce');
  }
  return $sections;
}, 999);

// 5. JS OVERRIDE FOR SHORTCODE CHECKOUT
add_action('wp_enqueue_scripts', function () {
  if (is_checkout()) {
    // 1. Remove the original plugin's script
    wp_dequeue_script('woocommerce_fastspring');
    wp_deregister_script('woocommerce_fastspring');

    // 2. Register your NEW JS file
    $js_url = plugin_dir_url(dirname(__FILE__)) . 'assets/js/fs-split-gateways.js';
    wp_register_script('woocommerce_fastspring_custom', $js_url, ['jquery', 'fastspring'], '2.3.1', true);

    // 3. Localize the parameters exactly like the original plugin
    $fastspring_params = array(
      'ajax_url' => WC_AJAX::get_endpoint('%%endpoint%%'),
      'nonce' => array(
        'receipt' => wp_create_nonce('wc-fastspring-receipt'),
      ),
    );
    wp_localize_script('woocommerce_fastspring_custom', 'woocommerce_fastspring_params', apply_filters('woocommerce_fastspring_params', $fastspring_params));

    // 4. Fire it up
    wp_enqueue_script('woocommerce_fastspring_custom');
  }
}, 99);
