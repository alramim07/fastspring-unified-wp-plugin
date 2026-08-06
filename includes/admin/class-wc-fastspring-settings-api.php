<?php
/**
 * FastSpring Split Gateways - Branded Settings API
 *
 * Mirrors the admin settings architecture of the "Payment Plugins for Stripe"
 * plugin: branded header + tab navigation, custom field types, conditional
 * field visibility (data-show-if) and a skip-list save mechanism.
 *
 * @package fastspring-split-gateways
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_FastSpring_Admin
 *
 * Static helpers shared by the settings trait and the gateway classes so the
 * branded container/navigation only has to be written once.
 */
class WC_FastSpring_Admin {

	/**
	 * Returns the canonical tab order used to render the branded navigation.
	 *
	 * @return array
	 */
	public static function get_tab_order() {
		return array(
			'fs_api'            => __( 'API Settings', 'fastspring-split-gateways' ),
			'fs_advanced'       => __( 'Advanced Settings', 'fastspring-split-gateways' ),
			'fastspring_card'   => __( 'Credit / Debit Cards', 'fastspring-split-gateways' ),
			'fastspring_paypal' => __( 'PayPal', 'fastspring-split-gateways' ),
			'fastspring_googlepay' => __( 'Google Pay', 'fastspring-split-gateways' ),
			'fastspring_amazon' => __( 'Amazon Pay', 'fastspring-split-gateways' ),
			'fastspring_applepay' => __( 'Apple Pay', 'fastspring-split-gateways' ),
			'fastspring_wire'   => __( 'Wire Transfer', 'fastspring-split-gateways' ),
			'fastspring_venmo'  => __( 'Venmo', 'fastspring-split-gateways' ),
			'fastspring_alipay' => __( 'AliPay', 'fastspring-split-gateways' ),
			'fastspring_cashapp' => __( 'Cash App', 'fastspring-split-gateways' ),
		);
	}

	/**
	 * Returns the navigation tabs in canonical order.
	 *
	 * @return array
	 */
	public static function get_nav_tabs() {
		$tabs = apply_filters( 'wc_fs_settings_nav_tabs', array() );

		// Normalize titles and order the tabs per the canonical list.
		$canonical = self::get_tab_order();
		$ordered   = array();
		foreach ( $canonical as $id => $title ) {
			if ( isset( $tabs[ $id ] ) ) {
				$ordered[ $id ] = $title;
			}
		}
		// Include any extra tabs registered by other plugins/extensions.
		foreach ( $tabs as $id => $title ) {
			if ( ! isset( $ordered[ $id ] ) ) {
				$ordered[ $id ] = $title;
			}
		}

		return $ordered;
	}

	/**
	 * Renders the branded header (logo row + navigation tabs).
	 */
	public static function render_header() {
		global $current_section;
		$tabs       = self::get_nav_tabs();
		$last       = count( $tabs );
		$idx        = 0;
		$tab_active = false;
		include FSSG_WC_FASTSPRING_PLUGIN_DIR . '/includes/admin/views/html-settings-nav.php';
	}

	/**
	 * Whether the current admin screen is a FastSpring settings screen.
	 *
	 * @return bool
	 */
	public static function is_fs_screen() {
		global $current_section;
		return isset( $current_section ) && isset( self::get_tab_order()[ $current_section ] );
	}
}

if ( ! trait_exists( 'WC_FastSpring_Settings_Trait' ) ) :

	/**
	 * WC_FastSpring_Settings_Trait
	 *
	 * Shared settings behaviour for the API/Advanced settings classes and the
	 * payment gateway classes: branded admin_options() output, custom field
	 * types and conditional field visibility.
	 */
	trait WC_FastSpring_Settings_Trait {

		/** @var bool Guard against double output. */
		private $admin_output = false;

		/**
		 * Adds this object to the branded navigation tabs.
		 *
		 * @param array $tabs Tabs.
		 * @return array
		 */
		public function admin_nav_tab( $tabs ) {
			// The main `fastspring` gateway is intentionally not surfaced as a
			// branded tab; its settings live under API Settings now.
			if ( $this->id && $this->tab_title && 'fastspring' !== $this->id ) {
				$tabs[ $this->id ] = $this->tab_title;
			}
			return $tabs;
		}

		/**
		 * Convenience boolean getter for checkbox options.
		 *
		 * @param string $key Option key.
		 * @return bool
		 */
		public function is_active( $key ) {
			return wc_string_to_bool( $this->get_option( $key ) );
		}

		/**
		 * Renders the branded settings container.
		 */
		public function admin_options() {
			if ( $this->admin_output ) {
				return;
			}
			echo '<div class="wc-fs-settings-container ' . esc_attr( $this->id ) . '">';
			$this->display_errors();
			$this->output_settings_nav();
			printf( '<input type="hidden" id="wc_fs_prefix" name="wc_fs_prefix" value="%1$s"/>', esc_attr( $this->get_prefix() ) );
			$this->admin_table();
			echo '</div>';
			$this->admin_output = true;
		}

		/**
		 * Renders the settings table.
		 *
		 * Non-gateway settings pages fall back to the core WC_Settings_API table
		 * output. Payment gateways override this so the WooCommerce back-header
		 * (wc_back_header) is not rendered inside the branded container.
		 */
		protected function admin_table() {
			parent::admin_options();
		}

		/**
		 * Outputs the branded header + navigation.
		 */
		public function output_settings_nav() {
			WC_FastSpring_Admin::render_header();
		}

		/**
		 * Displays admin error messages collected while saving.
		 */
		public function display_errors() {
			if ( $this->get_errors() ) {
				echo '<div id="woocommerce_errors" class="error notice inline is-dismissible">';
				foreach ( $this->get_errors() as $error ) {
					echo '<p>' . wp_kses_post( $error ) . '</p>';
				}
				echo '</div>';
			}
		}

		/**
		 * Returns the field-name prefix used by the admin JS (data-show-if).
		 *
		 * @return string
		 */
		public function get_prefix() {
			return $this->plugin_id . $this->id . '_';
		}

		/**
		 * JSON-encode array custom attributes (enables data-show-if).
		 *
		 * @param array $attribs Attributes.
		 * @return string
		 */
		public function get_custom_attribute_html( $attribs ) {
			if ( ! empty( $attribs['custom_attributes'] ) && is_array( $attribs['custom_attributes'] ) ) {
				foreach ( $attribs['custom_attributes'] as $k => $v ) {
					if ( is_array( $v ) ) {
						$attribs['custom_attributes'][ $k ] = htmlspecialchars( wp_json_encode( $v ) );
					}
				}
			}
			return parent::get_custom_attribute_html( $attribs );
		}

		/**
		 * Read-only description block.
		 *
		 * @param string $key  Field key.
		 * @param array  $data Field data.
		 * @return string
		 */
		public function generate_description_html( $key, $data ) {
			$data = wp_parse_args(
				$data,
				array(
					'class'       => '',
					'style'       => '',
					'description' => '',
				)
			);
			if ( is_callable( $data['description'] ) ) {
				$data['description'] = call_user_func( $data['description'] );
			}
			ob_start();
			include FSSG_WC_FASTSPRING_PLUGIN_DIR . '/includes/admin/views/html-description.php';
			return ob_get_clean();
		}

		/**
		 * Read-only paragraph (info row).
		 *
		 * @param string $key  Field key.
		 * @param array  $data Field data.
		 * @return string
		 */
		public function generate_paragraph_html( $key, $data ) {
			$defaults = array(
				'title'             => '',
				'text'              => '',
				'class'             => '',
				'css'               => '',
				'desc_tip'          => false,
				'description'       => '',
				'custom_attributes' => array(),
			);
			$data     = wp_parse_args( $data, $defaults );
			ob_start();
			include FSSG_WC_FASTSPRING_PLUGIN_DIR . '/includes/admin/views/html-paragraph.php';
			return ob_get_clean();
		}

		/**
		 * Action button row.
		 *
		 * @param string $key  Field key.
		 * @param array  $data Field data.
		 * @return string
		 */
		public function generate_fs_button_html( $key, $data ) {
			$data = wp_parse_args(
				$data,
				array(
					'title'       => '',
					'class'       => '',
					'style'       => '',
					'description' => '',
					'desc_tip'    => false,
					'id'          => 'wc-fs-button-' . $key,
					'css'         => '',
				)
			);
			$field_key = $this->get_field_key( $key );
			ob_start();
			include FSSG_WC_FASTSPRING_PLUGIN_DIR . '/includes/admin/views/html-button.php';
			return ob_get_clean();
		}

		/**
		 * Validates and sanitizes a field value, throwing on hard errors.
		 *
		 * @param string $key   Field key.
		 * @param mixed  $value Submitted value.
		 * @param array  $field Field definition.
		 * @return mixed
		 */
		public function validate_field( $key, $value, $field ) {
			$type = $this->get_field_type( $field );

			// Custom validator methods (validate_{key}_field).
			if ( is_callable( array( $this, 'validate_' . $key . '_field' ) ) ) {
				return $this->{'validate_' . $key . '_field'}( $key, $value );
			}

			// Field-level sanitize callback.
			if ( isset( $field['sanitize_callback'] ) && is_callable( $field['sanitize_callback'] ) ) {
				return call_user_func( $field['sanitize_callback'], $value );
			}

			switch ( $type ) {
				case 'text':
				case 'password':
					return is_null( $value ) ? '' : wc_clean( $value );
				case 'email':
					return sanitize_email( $value );
				case 'textarea':
					return is_null( $value ) ? '' : sanitize_textarea_field( $value );
				case 'select':
					return array_key_exists( $value, $field['options'] ) ? $value : $this->get_option( $key, $field['default'] ?? '' );
				case 'multiselect':
					return is_array( $value ) ? array_map( 'wc_clean', array_map( 'stripslashes', $value ) ) : array();
				case 'checkbox':
					return is_null( $value ) ? 'no' : 'yes';
				case 'icon_upload':
					return is_null( $value ) ? '' : esc_url_raw( $value );
				default:
					return is_null( $value ) ? '' : wc_clean( $value );
			}
		}
	}

endif;

if ( ! class_exists( 'WC_FastSpring_Settings_API' ) ) :

	/**
	 * WC_FastSpring_Settings_API
	 *
	 * Base class for the non-gateway settings pages (API Settings, Advanced
	 * Settings). Renders through the branded container and uses a skip-list
	 * save mechanism so display-only field types are never persisted.
	 */
	class WC_FastSpring_Settings_API extends WC_Settings_API {
		use WC_FastSpring_Settings_Trait;

		/** @var string Settings page id (also the checkout section slug). */
		public $id = 'fs_api';

		/** @var string Tab title. */
		public $tab_title = '';

		/**
		 * Constructor. Initializes form fields, loads settings and registers hooks.
		 */
		public function __construct() {
			$this->init_form_fields();
			$this->init_settings();
			$this->hooks();
		}

		/**
		 * Registers the branded settings hooks for this page.
		 */
		public function hooks() {
			add_action( 'woocommerce_update_options_checkout_' . $this->id, array( $this, 'process_admin_options' ) );
			add_filter( 'wc_fs_settings_nav_tabs', array( $this, 'admin_nav_tab' ) );
			add_action( 'woocommerce_fs_settings_checkout_' . $this->id, array( $this, 'admin_options' ) );
		}

		/**
		 * Option key. Overridden by subclasses.
		 *
		 * @return string
		 */
		public function get_option_key() {
			return $this->plugin_id . $this->id . '_settings';
		}

		/**
		 * Saves only the persisted field types.
		 *
		 * @return bool
		 */
		public function process_admin_options() {
			$this->init_settings();

			$post_data = $this->get_post_data();

			$skip_types = array( 'title', 'paragraph', 'button', 'description', 'fs_button' );

			foreach ( $this->get_form_fields() as $key => $field ) {
				$skip = isset( $field['skip'] ) && true === $field['skip'];
				if ( ! in_array( $this->get_field_type( $field ), $skip_types, true ) && ! $skip ) {
					try {
						$this->settings[ $key ] = $this->validate_field( $key, $post_data[ $this->get_field_key( $key ) ] ?? null, $field );
					} catch ( Exception $e ) {
						$this->add_error( $e->getMessage() );
					}
				}
			}

			return update_option( $this->get_option_key(), apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings ), 'yes' );
		}
	}

endif;
