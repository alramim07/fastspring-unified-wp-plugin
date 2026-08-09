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
			add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'payment_complete_status' ), 10, 3 );
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
		 * WooCommerce's own filter is passed a bare status (e.g. "completed").
		 * The option stores the WC status key (e.g. "wc-completed").
		 *
		 * Only affects orders paid through the FastSpring gateway.
		 *
		 * @param string   $status   Default completion status.
		 * @param int      $order_id Order ID.
		 * @param WC_Order $order    Order object.
		 * @return string
		 */
		public function payment_complete_status( $status, $order_id, $order ) {
			if ( ! $order || 'fastspring' !== $order->get_payment_method() ) {
				return $status;
			}

			$settings   = $this->get_advanced_settings();
			$configured = isset( $settings['order_completion_status'] ) ? $settings['order_completion_status'] : 'wc-completed';

			// Default behavior: completed.
			if ( 'wc-completed' === $configured || 'completed' === $configured ) {
				return 'completed';
			}

			// Any other configured status (strip the wc- prefix WooCommerce expects).
			$slug = preg_replace( '/^wc-/', '', sanitize_key( $configured ) );
			return $slug ? $slug : $status;
		}

		/**
		 * Processes a FastSpring refund when an order is cancelled.
		 *
		 * Idempotent via the _fs_refund_requested meta. FastSpring then fires
		 * return.created, which flips the order to refunded.
		 *
		 * @param int $order_id Order ID.
		 * @return void
		 */
		public function order_cancelled( $order_id ) {
			$settings = $this->get_advanced_settings();

			if ( empty( $settings['refund_cancel'] ) || 'yes' !== $settings['refund_cancel'] ) {
				return;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			// Only refund orders paid through the FastSpring gateway.
			if ( 'fastspring' !== $order->get_payment_method() ) {
				return;
			}

			$fs_order_id = $order->get_meta( 'fs_order_id' );
			if ( ! $fs_order_id ) {
				$fs_order_id = $order->get_transaction_id();
			}
			if ( ! $fs_order_id ) {
				$this->log( sprintf( 'Refund on cancel skipped for order %s - no FastSpring reference', $order_id ) );
				return;
			}

			// Idempotency: only request a refund once.
			if ( $order->get_meta( '_fs_refund_requested' ) ) {
				return;
			}

			$api_username = fssg_WC_FastSpring::get_setting( 'api_username' );
			$api_password = fssg_WC_FastSpring::get_setting( 'api_password' );
			if ( empty( $api_username ) || empty( $api_password ) ) {
				$this->log( 'Refund on cancel skipped - no API credentials' );
				return;
			}

			$body = array(
				'orderId'  => sanitize_text_field( $fs_order_id ),
				'reason'   => 'Order cancelled',
				'amount'   => (float) $order->get_total(),
				'currency' => $order->get_currency(),
			);

			$response = wp_remote_post(
				'https://api.fastspring.com/returns',
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
				$this->log( sprintf( 'Refund on cancel failed for order %s: %s', $order_id, $response->get_error_message() ) );
				$order->add_order_note( __( 'FastSpring refund request failed (network error).', 'fastspring-split-gateways' ) );
				$order->save();
				return;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				$this->log( sprintf( 'Refund on cancel failed for order %s (HTTP %s)', $order_id, $code ) );
				$order->add_order_note( __( 'FastSpring refund request failed. Please refund manually.', 'fastspring-split-gateways' ) );
				$order->save();
				return;
			}

			$order->update_meta_data( '_fs_refund_requested', current_time( 'mysql' ) );
			$order->add_order_note( __( 'FastSpring refund requested for cancelled order.', 'fastspring-split-gateways' ) );
			$order->save();

			$this->log( sprintf( 'Refund on cancel requested for order %s (FS %s)', $order_id, $fs_order_id ) );
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
