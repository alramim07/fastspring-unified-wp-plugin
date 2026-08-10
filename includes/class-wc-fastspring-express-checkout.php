<?php
/**
 * FastSpring Split Gateways - Express Checkout.
 *
 * Renders "Buy Now" buttons on product and cart pages and creates the pending
 * WooCommerce order + secure FastSpring payload for the popup checkout.
 *
 * @package fastspring-split-gateways
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_FastSpring_Express_Checkout' ) ) :

	/**
	 * WC_FastSpring_Express_Checkout
	 */
	class WC_FastSpring_Express_Checkout {

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_product_buttons' ) );
			add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_product_buttons' ) );
			add_action( 'woocommerce_before_cart_totals', array( $this, 'render_cart_buttons' ) );
			add_action( 'woocommerce_after_cart_totals', array( $this, 'render_cart_buttons' ) );
			add_action( 'wc_ajax_fssg_wc_fastspring_express_order', array( $this, 'ajax_express_order' ) );
		}

		/**
		 * Advanced settings.
		 *
		 * @return array
		 */
		private function get_settings() {
			return get_option( 'woocommerce_fs_advanced_settings', array() );
		}

		/**
		 * Whether express checkout is enabled.
		 *
		 * @return bool
		 */
		private function is_enabled() {
			$settings = $this->get_settings();
			return isset( $settings['express_checkout'] ) ? wc_string_to_bool( $settings['express_checkout'] ) : false;
		}

		/**
		 * Enabled express methods (whitelisted).
		 *
		 * @return array
		 */
		private function get_methods() {
			$settings = $this->get_settings();
			$methods  = isset( $settings['express_methods'] ) && is_array( $settings['express_methods'] ) ? $settings['express_methods'] : array();
			return array_values( array_intersect( array( 'applepay', 'googlepay', 'paypal', 'amazon', 'alipay' ), $methods ) );
		}

		/**
		 * Whether the gateway is configured (credentials present).
		 *
		 * @return bool
		 */
		private function gateway_configured() {
			return fssg_WC_FastSpring::get_setting( 'access_key' )
				&& fssg_WC_FastSpring::get_setting( 'storefront_path' )
				&& fssg_WC_FastSpring::get_setting( 'private_key' );
		}

		/**
		 * Enqueues the FastSpring SBL and the express script on product/cart pages.
		 *
		 * @return void
		 */
		public function enqueue_scripts() {
			if ( ! $this->is_enabled() ) {
				return;
			}
			if ( ! is_product() && ! is_cart() ) {
				return;
			}
			if ( ! $this->get_methods() ) {
				return;
			}
			if ( ! $this->gateway_configured() ) {
				return;
			}

			wp_enqueue_script( 'fastspring', FSSG_WC_FASTSPRING_SCRIPT, '', false, true );
			wp_enqueue_script( 'fs-express-checkout', FSSG_WC_FASTSPRING_PLUGIN_URL . '/assets/js/fs-express-checkout.js', array( 'jquery', 'fastspring' ), FSSG_WC_FASTSPRING_VERSION, true );

			$params = array(
				'ajax_url' => WC_AJAX::get_endpoint( '%%endpoint%%' ),
				'nonce'    => array(
					'receipt' => wp_create_nonce( 'wc-fastspring-receipt' ),
					'express' => wp_create_nonce( 'wc-fastspring-express' ),
				),
			);
			// A distinct global avoids clobbering the checkout script's
			// woocommerce_fastspring_params when both load on the same page.
			wp_localize_script( 'fs-express-checkout', 'woocommerce_fastspring_express_params', apply_filters( 'woocommerce_fastspring_express_params', $params ) );
		}

		/**
		 * Renders express buttons on the product page.
		 *
		 * @return void
		 */
		public function render_product_buttons() {
			if ( ! $this->should_render() || ! is_product() ) {
				return;
			}

			$settings = $this->get_settings();
			$location = isset( $settings['express_product_location'] ) ? sanitize_key( $settings['express_product_location'] ) : 'after_add_to_cart';
			$hook     = 'before_add_to_cart' === $location ? 'woocommerce_before_add_to_cart_button' : 'woocommerce_after_add_to_cart_button';

			if ( current_action() !== $hook ) {
				return;
			}

			global $product;
			if ( ! $product ) {
				return;
			}

			// Only simple, purchasable products have a definitive price for the
			// express payload (variable products need a selected variation).
			if ( ! $product->is_type( 'simple' ) || ! $product->is_purchasable() ) {
				return;
			}

			echo '<div class="fssg-express-buttons">';
			foreach ( $this->get_methods() as $method ) {
				echo $this->render_button( $method, (int) $product->get_id(), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered via esc_* below.
			}
			echo '</div>';
		}

		/**
		 * Renders express buttons on the cart page.
		 *
		 * @return void
		 */
		public function render_cart_buttons() {
			if ( ! $this->should_render() || ! is_cart() ) {
				return;
			}

			$settings = $this->get_settings();
			$location = isset( $settings['express_cart_location'] ) ? sanitize_key( $settings['express_cart_location'] ) : 'below_total';
			$hook     = 'above_total' === $location ? 'woocommerce_before_cart_totals' : 'woocommerce_after_cart_totals';

			if ( current_action() !== $hook ) {
				return;
			}

			echo '<div class="fssg-express-buttons">';
			foreach ( $this->get_methods() as $method ) {
				echo $this->render_button( $method, 0, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered via esc_* below.
			}
			echo '</div>';
		}

		/**
		 * Whether buttons may render in the current context.
		 *
		 * @return bool
		 */
		private function should_render() {
			if ( ! $this->is_enabled() ) {
				return false;
			}
			if ( ! $this->get_methods() ) {
				return false;
			}
			if ( ! $this->gateway_configured() ) {
				return false;
			}
			return true;
		}

		/**
		 * Renders a single express button.
		 *
		 * @param string $method     Payment method key.
		 * @param int    $product_id Product ID (0 on the cart page).
		 * @param bool   $is_cart    Whether rendered on the cart page.
		 * @return string
		 */
		private function render_button( $method, $product_id, $is_cart ) {
			$labels = array(
				'applepay'  => 'Apple Pay',
				'googlepay' => 'Google Pay',
				'paypal'    => 'PayPal',
				'amazon'    => 'Amazon Pay',
				'alipay'    => 'AliPay',
			);
			$label  = isset( $labels[ $method ] ) ? $labels[ $method ] : ucfirst( $method );

			return '<button type="button" class="button alt fssg-express-button" data-method="' . esc_attr( $method ) . '" data-product-id="' . esc_attr( (string) absint( $product_id ) ) . '" data-cart="' . esc_attr( $is_cart ? '1' : '0' ) . '">' . esc_html( sprintf( __( 'Buy with %s', 'fastspring-split-gateways' ), $label ) ) . '</button>';
		}

		/**
		 * Maps an express method value to a FastSpring sub-gateway id (used by
		 * the cart payload builder's method_map).
		 *
		 * @param string $method Payment method key.
		 * @return string
		 */
		private function to_gateway_id( $method ) {
			$map = array(
				'card'      => 'fastspring_card',
				'paypal'    => 'fastspring_paypal',
				'applepay'  => 'fastspring_applepay',
				'googlepay' => 'fastspring_googlepay',
				'amazon'    => 'fastspring_amazon',
				'alipay'    => 'fastspring_alipay',
			);
			return isset( $map[ $method ] ) ? $map[ $method ] : '';
		}

		/**
		 * Creates a pending order for a single product.
		 *
		 * @param int $product_id Product ID.
		 * @param int $qty        Quantity.
		 * @return WC_Order
		 */
		private function create_order_from_product( $product_id, $qty ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				wp_send_json_error( 'Invalid product' );
			}

			$order = wc_create_order();
			$order->add_product( $product, $qty );
			$order->set_payment_method( 'fastspring' );
			$order->set_payment_method_title( 'FastSpring' );
			$order->set_currency( get_woocommerce_currency() );

			if ( is_user_logged_in() ) {
				$order->set_customer_id( get_current_user_id() );
				$order->set_address(
					array(
						'first_name' => get_user_meta( get_current_user_id(), 'billing_first_name', true ),
						'last_name'  => get_user_meta( get_current_user_id(), 'billing_last_name', true ),
						'email'      => get_user_meta( get_current_user_id(), 'billing_email', true ),
						'phone'      => get_user_meta( get_current_user_id(), 'billing_phone', true ),
					),
					'billing'
				);
			}

			$order->calculate_totals();
			$order->update_status( 'pending', __( 'Express checkout initiated.', 'fastspring-split-gateways' ) );
			$order->add_meta_data( '_fssg_express_source', 'product' );
			$order->save();

			return $order;
		}

		/**
		 * Creates a pending order from the current cart.
		 *
		 * @return WC_Order
		 */
		private function create_order_from_cart() {
			$order = wc_create_order();
			$order->set_payment_method( 'fastspring' );
			$order->set_payment_method_title( 'FastSpring' );
			$order->set_currency( get_woocommerce_currency() );

			if ( is_user_logged_in() ) {
				$order->set_customer_id( get_current_user_id() );
			}

			foreach ( WC()->cart->get_cart() as $cart_item_key => $values ) {
				if ( empty( $values['data'] ) ) {
					continue;
				}
				$order->add_product(
					$values['data'],
					$values['quantity'],
					array(
						'subtotal' => $values['line_subtotal'],
						'total'    => $values['line_total'],
					)
				);
			}

			$order->calculate_totals();
			$order->update_status( 'pending', __( 'Express checkout initiated.', 'fastspring-split-gateways' ) );
			$order->add_meta_data( '_fssg_express_source', 'cart' );
			$order->save();

			// The cart payload builder reads order_awaiting_payment for tags +
			// customer details; current_order drives the receipt flow.
			WC()->session->set( 'order_awaiting_payment', $order->get_id() );
			WC()->session->set( 'current_order', $order->get_id() );

			return $order;
		}

		/**
		 * AJAX: builds the pending order + secure payload for the popup.
		 *
		 * @return void
		 */
		public function ajax_express_order() {
			check_ajax_referer( 'wc-fastspring-express', 'security' );

			$method     = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
			$is_cart    = isset( $_POST['cart'] ) ? wc_string_to_bool( wp_unslash( $_POST['cart'] ) ) : false;
			$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$qty        = isset( $_POST['qty'] ) ? max( 1, absint( $_POST['qty'] ) ) : 1;

			if ( ! in_array( $method, array( 'card', 'paypal', 'googlepay', 'applepay', 'amazon', 'alipay' ), true ) ) {
				wp_send_json_error( 'Invalid payment method' );
			}

			if ( $is_cart ) {
				if ( WC()->cart->is_empty() ) {
					wp_send_json_error( 'Cart is empty' );
				}
				$order   = $this->create_order_from_cart();
				$session = fssg_WC_Gateway_FastSpring_Builder::get_secure_json_payload( $this->to_gateway_id( $method ) );
			} else {
				if ( ! $product_id ) {
					wp_send_json_error( 'Invalid product' );
				}
				$order   = $this->create_order_from_product( $product_id, $qty );
				$session = fssg_WC_Gateway_FastSpring_Builder::get_secure_express_payload( $product_id, $qty, $method, (int) $order->get_id() );
			}

			if ( empty( $session ) || empty( $session['payload'] ) ) {
				wp_send_json_error( 'Could not build payment session' );
			}

			WC()->session->set( 'current_order', $order->get_id() );

			wp_send_json(
				array(
					'result'   => 'success',
					'session'  => $session,
					'order_id' => $order->get_id(),
				)
			);
		}
	}

endif;
