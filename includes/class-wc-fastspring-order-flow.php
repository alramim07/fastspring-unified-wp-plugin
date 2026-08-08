<?php
/**
 * FastSpring Split Gateways - Order Flow.
 *
 * Hooks that change how FastSpring orders complete and how cancellation is
 * handled. Reads its options from `woocommerce_fs_advanced_settings`.
 *
 * @package fastspring-split-gateways
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_FastSpring_Order_Flow' ) ) :

	/**
	 * WC_FastSpring_Order_Flow
	 */
	class WC_FastSpring_Order_Flow {

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'payment_complete_status' ), 10, 2 );
			add_action( 'woocommerce_order_status_cancelled', array( $this, 'order_cancelled' ), 10, 1 );
		}

		/**
		 * Advanced settings.
		 *
		 * @return array
		 */
		private function get_advanced_settings() {
			return get_option( 'woocommerce_fs_advanced_settings', array() );
		}

		/**
		 * Applies the configured Order Completion Status.
		 *
		 * WooCommerce's own filter is passed a prefixed status (e.g. "completed").
		 * The option stores the WC status key (e.g. "wc-completed").
		 *
		 * @param string $status   Default completion status.
		 * @param int    $order_id Order ID.
		 * @return string
		 */
		public function payment_complete_status( $status, $order_id ) {
			$settings   = $this->get_advanced_settings();
			$configured = isset( $settings['order_completion_status'] ) ? $settings['order_completion_status'] : 'wc-completed';

			// Default behavior: completed.
			if ( 'wc-completed' === $configured || 'completed' === $configured ) {
				return 'completed';
			}

			// Any other configured status (strip the wc- prefix WooCommerce expects).
			$slug = str_replace( 'wc-', '', sanitize_key( $configured ) );
			return $slug ? $slug : $status;
		}

		/**
		 * Handles refund-on-cancel. Implemented in Task 3.
		 *
		 * @param int $order_id Order ID.
		 * @return void
		 */
		public function order_cancelled( $order_id ) {
			// Stub - filled in Task 3.
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
