<?php
/**
 * FastSpring Split Gateways - Customer Creation.
 *
 * Creates a FastSpring customer account when a WooCommerce customer
 * registers, via the Accounts API. Reads its options from
 * `woocommerce_fs_advanced_settings`. Registration is never blocked
 * by API failures; failures are logged and recorded on the user.
 *
 * @package fastspring-split-gateways
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_FastSpring_Customer_Creation' ) ) :

	/**
	 * WC_FastSpring_Customer_Creation
	 */
	class WC_FastSpring_Customer_Creation {

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'woocommerce_created_customer', array( $this, 'maybe_create_account' ), 10, 3 );
		}

		/**
		 * Creates a FastSpring account for a newly registered customer.
		 *
		 * Idempotent via the _fs_account_id user meta. Failures never block
		 * the WooCommerce registration itself.
		 *
		 * @param int   $customer_id        New customer user ID.
		 * @param array $new_customer_data  Customer data.
		 * @param bool  $password_generated Whether a password was auto-generated.
		 * @return void
		 */
		public function maybe_create_account( $customer_id, $new_customer_data, $password_generated ) {
			$settings = get_option( 'woocommerce_fs_advanced_settings', array() );
			if ( empty( $settings['customer_creation'] ) || 'yes' !== $settings['customer_creation'] ) {
				return;
			}

			$customer_id = absint( $customer_id );
			$user        = get_userdata( $customer_id );
			if ( ! $user ) {
				return;
			}

			// Idempotency: never re-create for the same user.
			if ( get_user_meta( $customer_id, '_fs_account_id', true ) ) {
				return;
			}

			$api_username = fssg_WC_FastSpring::get_setting( 'api_username' );
			$api_password = fssg_WC_FastSpring::get_setting( 'api_password' );
			if ( empty( $api_username ) || empty( $api_password ) ) {
				$this->log( sprintf( 'Customer creation skipped for user %s - no API credentials', $customer_id ) );
				return;
			}

			$locale   = WC_FastSpring_Advanced_Settings::get_site_locale();
			$language = isset( $locale['language'] ) ? $locale['language'] : substr( get_locale(), 0, 2 );
			$country  = strtoupper( substr( get_option( 'woocommerce_default_country', '' ), 0, 2 ) );

			$body = array(
				'contact'  => array(
					'first' => sanitize_text_field( $user->first_name ? $user->first_name : $user->display_name ),
					'last'  => sanitize_text_field( $user->last_name ),
					'email' => sanitize_email( $user->user_email ),
				),
				'language' => sanitize_key( $language ),
				'country'  => $country,
				'lookup'   => array( 'custom' => 'woo:' . $customer_id ),
			);

			$response = wp_remote_post(
				'https://api.fastspring.com/accounts',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Basic ' . base64_encode( $api_username . ':' . $api_password ),
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				update_user_meta( $customer_id, '_fs_account_error', $response->get_error_message() );
				$this->log( sprintf( 'Customer creation failed for user %s: %s', $customer_id, $response->get_error_message() ) );
				return;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				update_user_meta( $customer_id, '_fs_account_error', 'HTTP ' . $code );
				$this->log( sprintf( 'Customer creation failed for user %s (HTTP %s)', $customer_id, $code ) );
				return;
			}

			$data       = json_decode( wp_remote_retrieve_body( $response ) );
			$account_id = ( $data && isset( $data->account ) ) ? sanitize_text_field( $data->account ) : ( ( $data && isset( $data->id ) ) ? sanitize_text_field( $data->id ) : '' );

			if ( $account_id ) {
				update_user_meta( $customer_id, '_fs_account_id', $account_id );
				delete_user_meta( $customer_id, '_fs_account_error' );
				$this->log( sprintf( 'FastSpring account %s created for user %s', $account_id, $customer_id ) );
			}
		}

		/**
		 * Logs.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		private function log( $message ) {
			fssg_WC_FastSpring::log( $message );
		}
	}

endif;
