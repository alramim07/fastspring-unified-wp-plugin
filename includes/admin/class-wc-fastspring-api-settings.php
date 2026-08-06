<?php
/**
 * FastSpring Split Gateways - API Settings.
 *
 * Renders the branded API Settings screen. The form persists to the existing
 * `woocommerce_fastspring_settings` option so the main gateway keeps working
 * without a migration.
 *
 * @package fastspring-split-gateways
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_FastSpring_API_Settings' ) ) :

	/**
	 * WC_FastSpring_API_Settings
	 */
	class WC_FastSpring_API_Settings extends WC_FastSpring_Settings_API {

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id        = 'fs_api';
			$this->tab_title = __( 'API Settings', 'fastspring-split-gateways' );
			parent::__construct();
		}

		/**
		 * Option key maps to the existing main gateway option.
		 *
		 * @return string
		 */
		public function get_option_key() {
			return 'woocommerce_fastspring_settings';
		}

		/**
		 * Field-name prefix for the admin JS (data-show-if) and POST data.
		 *
		 * @return string
		 */
		public function get_prefix() {
			return 'woocommerce_fastspring_';
		}

		/**
		 * Field names must match the keys stored in the main gateway option.
		 *
		 * @param string $key Field key.
		 * @return string
		 */
		public function get_field_key( $key ) {
			return 'woocommerce_fastspring_' . $key;
		}

		/**
		 * Registers hooks (extends the branded settings hooks with admin AJAX).
		 */
		public function hooks() {
			parent::hooks();
			add_action( 'wp_ajax_fssg_connection_test', array( $this, 'ajax_connection_test' ) );
			add_action( 'wp_ajax_nopriv_fssg_connection_test', array( $this, 'ajax_connection_test' ) );
			add_action( 'wp_ajax_fssg_generate_secret', array( $this, 'ajax_generate_secret' ) );
			add_action( 'wp_ajax_nopriv_fssg_generate_secret', array( $this, 'ajax_generate_secret' ) );
		}

		/**
		 * Initializes the form fields.
		 */
		public function init_form_fields() {
			$this->form_fields = array(
				'general'            => array(
					'type'  => 'title',
					'title' => __( 'General Settings', 'fastspring-split-gateways' ),
				),
				'general_description' => array(
					'type'        => 'description',
					'description' => __( 'Gateway display and behaviour settings, previously available under the General tab.', 'fastspring-split-gateways' ),
				),
				'enabled'            => array(
					'title'       => __( 'Enable/Disable', 'woocommerce-gateway-fastspring' ),
					'label'       => __( 'Enable FastSpring payment gateway', 'woocommerce-gateway-fastspring' ),
					'type'        => 'checkbox',
					'description' => '',
					'default'     => 'no',
				),
				'title'              => array(
					'title'       => __( 'Title', 'woocommerce-gateway-fastspring' ),
					'type'        => 'text',
					'description' => __( 'The title which the user sees during checkout. Keep it brief to avoid potential layout issues with icons.', 'woocommerce-gateway-fastspring' ),
					'default'     => __( 'Pay with FastSpring', 'woocommerce-gateway-fastspring' ),
					'desc_tip'    => false,
				),
				'description'        => array(
					'title'       => __( 'Description', 'woocommerce-gateway-fastspring' ),
					'type'        => 'text',
					'description' => __( 'The lengthier description which the user sees once selecting this payment gateway.', 'woocommerce-gateway-fastspring' ),
					'default'     => __( 'Pay with credit card, PayPal, Amazon Pay and more.', 'woocommerce-gateway-fastspring' ),
					'desc_tip'    => false,
				),
				'icons'              => array(
					'title'       => __( 'Payment Icons', 'woocommerce-gateway-fastspring' ),
					'type'        => 'multiselect',
					'description' => __( 'Select the payment method icons to show.', 'woocommerce-gateway-fastspring' ),
					'default'     => array( 'paypal', 'visa', 'mastercard', 'amex' ),
					'desc_tip'    => false,
					'class'       => 'wc-enhanced-select',
					'options'     => array(
						'paypal'     => 'paypal',
						'visa'       => 'visa',
						'mastercard' => 'mastercard',
						'amex'       => 'amex',
						'discover'   => 'discover',
						'jcb'        => 'jcb',
						'diners'     => 'diners',
						'ideal'      => 'ideal',
						'unionpay'   => 'unionpay',
						'sofort'     => 'sofort',
						'giropay'    => 'giropay',
					),
				),
				'billing_address'    => array(
					'title'       => __( 'Billing Address Fields', 'woocommerce-gateway-fastspring' ),
					'label'       => __( 'Remove billing address fields from checkout.', 'woocommerce-gateway-fastspring' ),
					'type'        => 'checkbox',
					'description' => __( 'If no other payment gateway requires these fields they can be removed as FastSpring does not use them.', 'woocommerce-gateway-fastspring' ),
					'default'     => 'yes',
				),
				'api_settings_title' => array(
					'type'  => 'title',
					'title' => __( 'API Settings', 'fastspring-split-gateways' ),
				),
				'api_description'  => array(
					'type'        => 'description',
					'description' => __( 'Enter your FastSpring storefront path and access credentials. These encrypt the order data sent to FastSpring and are stored securely in your WordPress database.', 'fastspring-split-gateways' ),
				),
				'mode'             => array(
					'type'        => 'select',
					'title'       => __( 'Mode', 'fastspring-split-gateways' ),
					'class'       => 'wc-enhanced-select',
					'options'     => array(
						'test' => __( 'Test', 'fastspring-split-gateways' ),
						'live' => __( 'Live', 'fastspring-split-gateways' ),
					),
					'default'     => $this->default_mode(),
					'desc_tip'    => true,
					'description' => __( 'Test mode routes transactions to your test storefront and lets you simulate payments with the card numbers from the FastSpring test panel.', 'fastspring-split-gateways' ),
				),
				'storefront_path'  => array(
					'type'        => 'text',
					'title'       => __( 'Storefront', 'fastspring-split-gateways' ),
					'default'     => '',
					'desc_tip'    => true,
					'description' => __( 'The path of your FastSpring storefront (e.g. mystore.onfastspring.com/mystore). Hosted and popup storefronts are supported.', 'fastspring-split-gateways' ),
				),
				'access_key'       => array(
					'type'        => 'text',
					'title'       => __( 'Access Key', 'fastspring-split-gateways' ),
					'default'     => '',
					'desc_tip'    => true,
					'description' => __( 'Your FastSpring access key. Found in the FastSpring dashboard under Integrations > Store Builder Library.', 'fastspring-split-gateways' ),
				),
				'private_key'      => array(
					'type'        => 'textarea',
					'title'       => __( 'Private Key', 'fastspring-split-gateways' ),
					'class'       => 'fs-private-key',
					'default'     => '',
					'desc_tip'    => true,
					'description' => __( 'Your RSA private certificate used to encrypt the secure payload. Generated per the FastSpring Store Builder Library documentation.', 'fastspring-split-gateways' ),
				),
				'connection_test'  => array(
					'type'        => 'fs_button',
					'title'       => __( 'Connection Test', 'fastspring-split-gateways' ),
					'label'       => __( 'Connection Test', 'fastspring-split-gateways' ),
					'class'       => 'button-secondary wc-fs-connection-test',
					'description' => __( 'Click this button to verify your storefront is reachable. Save your settings first.', 'fastspring-split-gateways' ),
				),
				'verification'     => array(
					'type'        => 'title',
					'title'       => __( 'Order Verification', 'fastspring-split-gateways' ),
					'description' => __( 'FastSpring can mark orders as complete via a webhook and/or the FastSpring API. For popup storefronts either method works; hosted storefronts require the webhook method.', 'fastspring-split-gateways' ),
				),
				'webhook_url'      => array(
					'type'        => 'paragraph',
					'title'       => __( 'Webhook URL', 'fastspring-split-gateways' ),
					'class'       => 'wc-fs-webhook',
					'text'        => '',
					'desc_tip'    => true,
					'description' => __( 'Enter this URL and the secret below in the FastSpring dashboard under Integrations > Webhooks (HMAC SHA256 Secret field).', 'fastspring-split-gateways' ),
				),
				'webhook_secret'   => array(
					'type'        => 'text',
					'title'       => __( 'Webhook Secret', 'fastspring-split-gateways' ),
					'default'     => '',
					'description' => __( 'A random sequence of characters used to authenticate webhook calls from FastSpring.', 'fastspring-split-gateways' ),
				),
				'generate_secret'  => array(
					'type'        => 'fs_button',
					'title'       => __( 'Generate Webhook Secret', 'fastspring-split-gateways' ),
					'label'       => __( 'Generate', 'fastspring-split-gateways' ),
					'class'       => 'button-secondary wc-fs-generate-secret',
					'description' => __( 'Generates a new random webhook secret and fills the field above. Remember to update the FastSpring dashboard and save your settings.', 'fastspring-split-gateways' ),
				),
				'api_username'     => array(
					'type'        => 'text',
					'title'       => __( 'API Username', 'fastspring-split-gateways' ),
					'default'     => '',
					'desc_tip'    => true,
					'description' => __( 'FastSpring API username used to check order completion. Generated under Integrations > API Credentials.', 'fastspring-split-gateways' ),
				),
				'api_password'     => array(
					'type'        => 'password',
					'title'       => __( 'API Password', 'fastspring-split-gateways' ),
					'default'     => '',
					'desc_tip'    => true,
					'description' => __( 'FastSpring API password used to check order completion.', 'fastspring-split-gateways' ),
				),
				'debug'            => array(
					'type'  => 'title',
					'title' => __( 'Debug', 'fastspring-split-gateways' ),
				),
				'logging'          => array(
					'title'       => __( 'Debug Log', 'fastspring-split-gateways' ),
					'type'        => 'checkbox',
					'default'     => 'no',
					'desc_tip'    => true,
					'description' => __( 'When enabled, the plugin logs important errors and info to the WooCommerce System Status log.', 'fastspring-split-gateways' ),
				),
			);
		}

		/**
		 * Default mode matches the current testmode flag.
		 *
		 * @return string
		 */
		private function default_mode() {
			$settings = get_option( 'woocommerce_fastspring_settings', array() );
			return isset( $settings['testmode'] ) && 'yes' === $settings['testmode'] ? 'test' : 'live';
		}

		/**
		 * Outputs the settings; sets the webhook URL just before rendering.
		 */
		public function admin_options() {
			$this->form_fields['webhook_url']['text'] = site_url( '?wc-api=fssg_WC_Gateway_FastSpring', 'https' );
			parent::admin_options();
		}

		/**
		 * Validates the mode and keeps the legacy `testmode` flag in sync.
		 *
		 * @param string $key   Field key.
		 * @param string $value Submitted value.
		 * @return string
		 */
		protected function validate_mode_field( $key, $value ) {
			$value = 'test' === $value ? 'test' : 'live';
			$this->settings['testmode'] = 'test' === $value ? 'yes' : 'no';
			return $value;
		}

		/**
		 * Validates the storefront path.
		 *
		 * @param string $key   Field key.
		 * @param string $value Submitted value.
		 * @return string
		 */
		protected function validate_storefront_path_field( $key, $value ) {
			$value = wc_clean( $value );
			if ( empty( $value ) ) {
				throw new Exception( __( 'Enter a valid storefront path.', 'fastspring-split-gateways' ) );
			}
			return preg_replace( '#^https?://#', '', rtrim( $value, '/' ) );
		}

		/**
		 * Validates the access key.
		 *
		 * @param string $key   Field key.
		 * @param string $value Submitted value.
		 * @return string
		 */
		protected function validate_access_key_field( $key, $value ) {
			if ( empty( $value ) ) {
				throw new Exception( __( 'A FastSpring access key is required.', 'fastspring-split-gateways' ) );
			}
			return wc_clean( $value );
		}

		/**
		 * Validates the RSA private key when one is provided.
		 *
		 * @param string $key   Field key.
		 * @param string $value Submitted value.
		 * @return string
		 */
		protected function validate_private_key_field( $key, $value ) {
			if ( ! empty( $value ) && ! @openssl_private_encrypt( 'abc', $aes_key_encrypted, openssl_pkey_get_private( $value ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				throw new Exception( __( 'The RSA private key field is invalid.', 'fastspring-split-gateways' ) );
			}
			return $value;
		}

		/**
		 * AJAX handler: verifies the configured storefront is reachable.
		 */
		public function ajax_connection_test() {
			check_ajax_referer( 'wc_fs_admin', '_ajax_nonce' );

			$settings = get_option( 'woocommerce_fastspring_settings', array() );
			$path     = isset( $settings['storefront_path'] ) ? sanitize_text_field( $settings['storefront_path'] ) : '';
			if ( empty( $path ) ) {
				wp_send_json_error( array( 'message' => __( 'Storefront path is empty. Save your settings first.', 'fastspring-split-gateways' ) ) );
			}

			$testmode = isset( $settings['testmode'] ) && 'yes' === $settings['testmode'];
			$path     = $testmode
				? str_replace( 'onfastspring.com', 'test.onfastspring.com', $path )
				: str_replace( 'test.onfastspring.com', 'onfastspring.com', $path );

			$response = wp_remote_get( 'https://' . $path, array( 'timeout' => 15 ) );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => $response->get_error_message() ) );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 400 ) {
				wp_send_json_success( array( 'message' => sprintf( __( 'Storefront responded with HTTP %s.', 'fastspring-split-gateways' ), $code ) ) );
			}

			if ( $code >= 400 && $code < 600 ) {
				wp_send_json_success( array( 'message' => sprintf( __( 'Storefront is reachable (HTTP %s). Popup storefronts may not respond to direct HTTP requests.', 'fastspring-split-gateways' ), $code ) ) );
			}

			wp_send_json_error( array( 'message' => sprintf( __( 'Storefront returned an unexpected HTTP %s.', 'fastspring-split-gateways' ), $code ) ) );
		}

		/**
		 * AJAX handler: generates a new random webhook secret and saves it.
		 */
		public function ajax_generate_secret() {
			check_ajax_referer( 'wc_fs_admin', '_ajax_nonce' );

			$secret   = wp_generate_password( 32, false, false );
			$settings = get_option( 'woocommerce_fastspring_settings', array() );
			$settings['webhook_secret'] = $secret;
			update_option( 'woocommerce_fastspring_settings', $settings );

			wp_send_json_success( array( 'secret' => $secret ) );
		}
	}

endif;
