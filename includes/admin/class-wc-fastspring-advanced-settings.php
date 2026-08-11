<?php
/**
 * FastSpring Split Gateways - Advanced Settings.
 *
 * Express checkout controls, order completion behaviour and checkout display
 * options. Persists to its own `woocommerce_fs_advanced_settings` option.
 *
 * @package fastspring-split-gateways
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_FastSpring_Advanced_Settings' ) ) :

	/**
	 * WC_FastSpring_Advanced_Settings
	 */
	class WC_FastSpring_Advanced_Settings extends WC_FastSpring_Settings_API {

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id        = 'fs_advanced';
			$this->tab_title = __( 'Advanced Settings', 'fastspring-split-gateways' );
			parent::__construct();
		}

		/**
		 * Initializes the form fields.
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'title'                   => array(
					'type'  => 'title',
					'title' => __( 'Advanced Settings', 'fastspring-split-gateways' ),
				),
				'settings_description'    => array(
					'type'        => 'description',
					'description' => __( 'Configure express checkout buttons, order completion behaviour and the payment icons shown at checkout.', 'fastspring-split-gateways' ),
				),
				'express'                 => array(
					'type'  => 'title',
					'title' => __( 'Express Checkout', 'fastspring-split-gateways' ),
				),
				'express_checkout'        => array(
					'title'       => __( 'Express Checkout', 'fastspring-split-gateways' ),
					'type'        => 'checkbox',
					'default'     => 'no',
					'desc_tip'    => true,
					'description' => __( 'If enabled, express "Buy Now" buttons are displayed on the product and cart pages.', 'fastspring-split-gateways' ),
				),
				'express_methods'         => array(
					'title'             => __( 'Payment Methods', 'fastspring-split-gateways' ),
					'type'              => 'multiselect',
					'class'             => 'wc-enhanced-select',
					'default'           => array( 'applepay', 'googlepay', 'paypal', 'amazon', 'alipay' ),
					'options'           => array(
						'applepay'  => __( 'Apple Pay', 'fastspring-split-gateways' ),
						'googlepay' => __( 'Google Pay', 'fastspring-split-gateways' ),
						'paypal'    => __( 'PayPal', 'fastspring-split-gateways' ),
						'amazon'    => __( 'Amazon Pay', 'fastspring-split-gateways' ),
						'alipay'    => __( 'AliPay', 'fastspring-split-gateways' ),
					),
					'desc_tip'          => true,
					'description'       => __( 'The payment methods available as express buttons.', 'fastspring-split-gateways' ),
					'custom_attributes' => array(
						'data-show-if' => array( 'express_checkout' => true ),
					),
				),
				'express_product_location' => array(
					'title'             => __( 'Product Page Button Location', 'fastspring-split-gateways' ),
					'type'              => 'select',
					'default'           => 'after_add_to_cart',
					'options'           => array(
						'after_add_to_cart'  => __( 'After Add to Cart Button', 'fastspring-split-gateways' ),
						'before_add_to_cart' => __( 'Before Add to Cart Button', 'fastspring-split-gateways' ),
					),
					'desc_tip'          => true,
					'description'       => __( 'Where the express buttons are displayed on the product page.', 'fastspring-split-gateways' ),
					'custom_attributes' => array(
						'data-show-if' => array( 'express_checkout' => true ),
					),
				),
				'express_cart_location'   => array(
					'title'             => __( 'Cart Page Button Location', 'fastspring-split-gateways' ),
					'type'              => 'select',
					'default'           => 'below_total',
					'options'           => array(
						'below_total' => __( 'Below Cart Total', 'fastspring-split-gateways' ),
						'above_total' => __( 'Above Cart Total', 'fastspring-split-gateways' ),
					),
					'desc_tip'          => true,
					'description'       => __( 'Where the express buttons are displayed on the cart page.', 'fastspring-split-gateways' ),
					'custom_attributes' => array(
						'data-show-if' => array( 'express_checkout' => true ),
					),
				),
				'order'                   => array(
					'type'  => 'title',
					'title' => __( 'Order Settings', 'fastspring-split-gateways' ),
				),
				'order_completion_status' => array(
					'title'       => __( 'Order Completion Status', 'fastspring-split-gateways' ),
					'type'        => 'select',
					'default'     => 'wc-completed',
					'options'     => wc_get_order_statuses(),
					'desc_tip'    => true,
					'description' => __( 'The status assigned to an order when FastSpring confirms the payment.', 'fastspring-split-gateways' ),
				),
				'locale'                  => array(
					'type'  => 'title',
					'title' => __( 'Locale', 'fastspring-split-gateways' ),
				),
				'locale_type'             => array(
					'title'       => __( 'Locale Type', 'fastspring-split-gateways' ),
					'type'        => 'select',
					'default'     => 'auto',
					'options'     => array(
						'auto'   => __( 'Auto', 'fastspring-split-gateways' ),
						'custom' => __( 'Custom', 'fastspring-split-gateways' ),
					),
					'desc_tip'    => true,
					'description' => __( 'Auto lets FastSpring detect the buyer\'s language automatically. Custom forces the Site Locale language in the checkout popup.', 'fastspring-split-gateways' ),
				),
				'site_locale'             => array(
					'title'             => __( 'Site Locale', 'fastspring-split-gateways' ),
					'type'              => 'select',
					'default'           => 'en',
					'options'           => self::get_supported_languages(),
					'desc_tip'          => true,
					'description'       => __( 'The language used in the FastSpring checkout popup.', 'fastspring-split-gateways' ),
					'custom_attributes' => array(
						'data-show-if' => array( 'locale_type' => 'custom' ),
					),
				),
				'refund_cancel'           => array(
					'title'       => __( 'Refund On Cancel', 'fastspring-split-gateways' ),
					'type'        => 'checkbox',
					'default'     => 'no',
					'desc_tip'    => true,
					'description' => __( 'If enabled, the plugin will process a refund in FastSpring when the order status is set to cancelled.', 'fastspring-split-gateways' ),
				),
				'disputes'                => array(
					'type'  => 'title',
					'title' => __( 'Dispute Settings', 'fastspring-split-gateways' ),
				),
				'dispute_created'         => array(
					'title'       => __( 'Dispute Created', 'fastspring-split-gateways' ),
					'type'        => 'checkbox',
					'default'     => 'yes',
					'desc_tip'    => true,
					'description' => __( 'If enabled, the plugin listens for the <strong>chargeback.created</strong> webhook and sets the order\'s status to the configured value.', 'fastspring-split-gateways' ),
				),
				'dispute_created_status'  => array(
					'title'             => __( 'Dispute Created Order Status', 'fastspring-split-gateways' ),
					'type'              => 'select',
					'default'           => 'wc-on-hold',
					'options'           => wc_get_order_statuses(),
					'desc_tip'          => true,
					'description'       => __( 'The status assigned to an order when a chargeback is filed.', 'fastspring-split-gateways' ),
					'custom_attributes' => array(
						'data-show-if' => array( 'dispute_created' => true ),
					),
				),
				'dispute_closed'          => array(
					'title'       => __( 'Dispute Closed', 'fastspring-split-gateways' ),
					'type'        => 'checkbox',
					'default'     => 'yes',
					'desc_tip'    => true,
					'description' => __( 'If enabled, the plugin restores the order to its pre-chargeback status when FastSpring closes the chargeback.', 'fastspring-split-gateways' ),
				),
				'customers'               => array(
					'type'  => 'title',
					'title' => __( 'Customer Settings', 'fastspring-split-gateways' ),
				),
				'customer_creation'       => array(
					'title'       => __( 'Customer Creation', 'fastspring-split-gateways' ),
					'type'        => 'checkbox',
					'default'     => 'no',
					'desc_tip'    => true,
					'description' => __( 'If enabled, a FastSpring customer account is created when a customer registers on the site.', 'fastspring-split-gateways' ),
				),
				'display'                 => array(
					'type'  => 'title',
					'title' => __( 'Display Settings', 'fastspring-split-gateways' ),
				),
				'payment_icons'           => array(
					'title'       => __( 'Payment Icons', 'fastspring-split-gateways' ),
					'type'        => 'multiselect',
					'class'       => 'wc-enhanced-select',
					'default'     => array( 'paypal', 'visa', 'mastercard', 'amex' ),
					'options'     => $this->get_icon_options(),
					'desc_tip'    => true,
					'description' => __( 'The payment method icons shown next to the FastSpring payment methods at checkout.', 'fastspring-split-gateways' ),
				),
			);
		}

		/**
		 * FastSpring-supported checkout languages (ISO 639-1).
		 *
		 * @return array Code => label.
		 */
		public static function get_supported_languages() {
			return array(
				'en' => 'English',
				'de' => 'Deutsch',
				'fr' => 'Français',
				'es' => 'Español',
				'it' => 'Italiano',
				'pt' => 'Português',
				'nl' => 'Nederlands',
				'ja' => '日本語',
				'zh' => '中文',
				'ko' => '한국어',
				'ru' => 'Русский',
				'pl' => 'Polski',
				'sv' => 'Svenska',
				'da' => 'Dansk',
				'no' => 'Norsk',
				'fi' => 'Suomi',
				'tr' => 'Türkçe',
				'cs' => 'Čeština',
				'el' => 'Ελληνικά',
				'hu' => 'Magyar',
				'ro' => 'Română',
				'uk' => 'Українська',
				'ar' => 'العربية',
				'id' => 'Bahasa Indonesia',
			);
		}

		/**
		 * Configured checkout locale for the popup, or empty when Auto.
		 *
		 * @return array { 'language' => 'xx' } or array().
		 */
		public static function get_site_locale() {
			$settings = get_option( 'woocommerce_fs_advanced_settings', array() );
			if ( empty( $settings['locale_type'] ) || 'custom' !== $settings['locale_type'] || empty( $settings['site_locale'] ) ) {
				return array();
			}
			$lang = sanitize_key( $settings['site_locale'] );
			return $lang ? array( 'language' => $lang ) : array();
		}

		/**
		 * Available checkout payment icons.
		 *
		 * @return array
		 */
		private function get_icon_options() {
			return array(
				'paypal'     => __( 'PayPal', 'fastspring-split-gateways' ),
				'visa'       => __( 'Visa', 'fastspring-split-gateways' ),
				'mastercard' => __( 'Mastercard', 'fastspring-split-gateways' ),
				'amex'       => __( 'American Express', 'fastspring-split-gateways' ),
				'discover'   => __( 'Discover', 'fastspring-split-gateways' ),
				'jcb'        => __( 'JCB', 'fastspring-split-gateways' ),
				'diners'     => __( 'Diners Club', 'fastspring-split-gateways' ),
				'unionpay'   => __( 'UnionPay', 'fastspring-split-gateways' ),
				'sofort'     => __( 'SOFORT', 'fastspring-split-gateways' ),
				'giropay'    => __( 'Giropay', 'fastspring-split-gateways' ),
				'ideal'      => __( 'iDeal', 'fastspring-split-gateways' ),
			);
		}

		/**
		 * Keeps the checkout icons in sync with the main gateway option.
		 *
		 * @param string $key   Field key.
		 * @param mixed  $value Submitted value.
		 * @return array
		 */
		protected function validate_payment_icons_field( $key, $value ) {
			$value    = is_array( $value ) ? array_map( 'wc_clean', array_map( 'stripslashes', $value ) ) : array();
			$settings = get_option( 'woocommerce_fastspring_settings', array() );
			$settings['icons'] = $value;
			update_option( 'woocommerce_fastspring_settings', $settings );
			return $value;
		}

		/**
		 * Whether express checkout is enabled.
		 *
		 * @return bool
		 */
		public function is_express_enabled() {
			return $this->is_active( 'express_checkout' );
		}

		/**
		 * Whether refunds should be processed when an order is cancelled.
		 *
		 * @return bool
		 */
		public function is_refund_cancel_enabled() {
			return $this->is_active( 'refund_cancel' );
		}

		/**
		 * Whether the plugin should react to chargeback.created webhooks.
		 *
		 * @return bool
		 */
		public function is_dispute_created_enabled() {
			return $this->is_active( 'dispute_created' );
		}

		/**
		 * Whether the plugin should restore the pre-chargeback status.
		 *
		 * @return bool
		 */
		public function is_dispute_closed_enabled() {
			return $this->is_active( 'dispute_closed' );
		}
	}

endif;
