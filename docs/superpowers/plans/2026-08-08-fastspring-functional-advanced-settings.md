# FastSpring Functional Advanced Settings — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the FastSpring plugin's Advanced Settings page (`fs_advanced`, option `woocommerce_fs_advanced_settings`) fully functional: Chargeback Settings (status on `chargeback.created` + restore on chargeback-originated `return.created`), wire the dead Order Completion Status and Refund On Cancel options, and implement real Express Checkout "Buy Now" buttons — all **local-only** (no VPS deployment).

**Architecture:** Three existing dead options get real behavior through WordPress hooks. A new `WC_FastSpring_Order_Flow` class filters `woocommerce_payment_complete_order_status` (completion status) and hooks `woocommerce_order_status_cancelled` (refund on cancel → FastSpring `POST /returns`). A new `WC_FastSpring_Express_Checkout` class renders buttons on product/cart pages and exposes a `wc-ajax` order-builder endpoint; the payload reuses the existing `fssg_WC_Gateway_FastSpring_Builder` secure-payload helpers and the existing popup-close → `get_receipt` flow. The webhook handler is first repaired (envelope dispatch bug) and then extended with `chargeback.created` and `return.created` handling plus an order-details address copy in `ajax_get_receipt`.

**Tech Stack:** WordPress hooks, WooCommerce (HPOS-aware order APIs), FastSpring Store Builder Library (popup), FastSpring REST API (`wp_remote_post`/`wp_remote_get` with Basic auth), WordPress Transients/caching not required (webhooks + one-off API calls only). No raw cURL anywhere. No PHPUnit exists in this repo — per-task verification uses `php -l` plus manual local browser/curl checks (see "Verification approach" below).

---

## Verification approach (no test framework in repo)

Every task ends with these local checks (no VPS). Adjust the local base URL if your Laragon hostname differs (`http://micropro.test` used below).

- Syntax: `php -l <file>`
- Settings round-trip: open **WooCommerce → Settings → Checkout → Advanced Settings** (`/wp-admin/admin.php?page=wc-settings&tab=checkout&section=fs_advanced`), change a value, Save, reload — value must persist. Option row is `woocommerce_fs_advanced_settings` in `wp_options`.
- Webhook simulation: POST a FastSpring-style envelope to the classic WC endpoint:
  `curl -i -X POST "http://micropro.test/?wc-api=wc_gateway_fastspring" -H "Content-Type: application/json" --data-binary @payload.json`
  (`is_valid_webhook_request()` returns `true` when no `X-FS-Signature` header is present, so unsigned local tests pass.)
- Logs: enable logging or check `wp-content/uploads/wc-logs/woocommerce-gateway-fastspring-*.log` — the handler logs via `fssg_WC_FastSpring::log()`.
- Refund-on-cancel: cancel an order in admin, confirm the `/returns` call appears in the log + an order note is added.
- Express: enable Express Checkout in Advanced Settings, load a product and the cart page, confirm buttons render and clicking one opens the FastSpring popup (test mode: `data-test="yes"` on the `#fsc-api` script).

**Frequent commits:** commit after every task. Working tree currently has an uncommitted `readme.txt` change — do not include it unless you changed it.

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `includes/admin/class-wc-fastspring-advanced-settings.php` | Modify | Add Chargeback Settings section fields + getters |
| `includes/class-wc-fastspring-order-flow.php` | Create | Completion-status filter + refund-on-cancel hook |
| `includes/class-wc-gateway-fastspring-handler.php` | Modify | Envelope fix, get_receipt alias, chargeback/return handlers, order lookup, address copy |
| `includes/class-wc-fastspring-express-checkout.php` | Create | Express button renderers, AJAX order builder, script enqueue |
| `assets/js/fs-express-checkout.js` | Create | Button click → AJAX → popup; popup-close → get_receipt → redirect |
| `woocommerce-gateway-fastspring.php` | Modify | Include + instantiate the two new classes in `init()` |

Design doc (source of truth): `docs/superpowers/specs/2026-08-08-fastspring-functional-advanced-settings-design.md`.

### Confirmed API/webhook facts (do not re-derive)

- Webhook envelope: `{"events": [ {id, type, created, live, processed, data} ]}`. Events are **objects in an array under `events`** — not the decoded body itself. Current code `is_array($events)` therefore never dispatches anything.
- `chargeback.created` → event `data` = `{id, reasonCode, reasonDescription, fraudType, creationDate, chargebackAmount, currency, order, processorCaseId, status, paymentMethod, paymentMethodVariant}`. `data.order` is the FastSpring order ID (matches `fs_order_id` meta set in `ajax_get_receipt`). No tags.
- `return.created` → event `data` = `{return, reference, completed, currency, totalReturn, reason, note, type, original:{id, order, reference}, customer:{...}, account:{...}}`. `data.type` ∈ `RETURN` (manual) / `ALERT` / `CHARGEBACK` (automated). `data.original.order` is the FS order ID. No tags.
- `data-popup-closed` callback object only contains `{id, reference}` — **no** customer/address. Customer/address for a completed order is obtained from `GET https://api.fastspring.com/orders/{id}` (Basic auth), whose response has a `customer` object (`first,last,email,phone,subscribed`) and an `address` object (`city, regionCode, country, postalCode, addressLine1, addressLine2`).
- Refund: `POST https://api.fastspring.com/returns`, Basic auth (`api_username`/`api_password` gateway settings), JSON body `{orderId, reason, amount, currency}`, `timeout => 15`.
- Frontend JS builds AJAX URLs via `WC_AJAX::get_endpoint('%%endpoint%%')` then `.replace('%%endpoint%%', 'wc_fastspring_get_receipt')`, producing `?wc-ajax=wc_fastspring_get_receipt`. The registered hook is `wc_ajax_fssg_wc_fastspring_get_receipt` — a **latent mismatch**. We register the alias `wc_ajax_wc_fastspring_get_receipt` so the existing (and new express) popup-close flow works.

### Existing helper signatures reused (do not modify unless the step says so)

- `fssg_WC_Gateway_FastSpring_Builder::get_secure_express_payload($product_id, $quantity, $method, $order_id)` → `array('payload'=>..., 'key'=>...)`. `$method` is the raw FS method value (`card|paypal|googlepay|applepay|amazon|alipay`).
- `fssg_WC_Gateway_FastSpring_Builder::get_secure_json_payload($method = '')` → same shape. `$method` is a **gateway id** (`fastspring_paypal`, `fastspring_card`, …) — its internal `method_map` only matches those keys.
- `fssg_WC_FastSpring::get_setting($key)` reads `woocommerce_fastspring_settings`; returns `true`/`false` for `yes`/`no`, raw string otherwise.
- `WC_FastSpring_Settings_API::is_active($key)` (`wc_string_to_bool`) reads `woocommerce_fs_advanced_settings`.
- Advanced settings option: `woocommerce_fs_advanced_settings`. Gateway settings option: `woocommerce_fastspring_settings`.
- Order lookup by FS id: `wc_get_orders(['limit'=>1, 'type'=>'shop_order', 'meta_query'=>[OR fs_order_id, _transaction_id]])`.

---

## Task 1: Chargeback Settings fields + getters

**Files:**
- Modify: `includes/admin/class-wc-fastspring-advanced-settings.php`

- [ ] **Step 1: Add the Chargeback Settings section**

Insert after the `refund_cancel` field (which ends at line 119) and before the `display` title (line 120) in `init_form_fields()`:

```php
				'chargebacks'             => array(
					'type'  => 'title',
					'title' => __( 'Chargeback Settings', 'fastspring-split-gateways' ),
				),
				'chargeback_created'      => array(
					'title'       => __( 'Chargeback Created', 'fastspring-split-gateways' ),
					'type'        => 'checkbox',
					'default'     => 'yes',
					'desc_tip'    => true,
					'description' => __( 'If enabled, the plugin listens for the <strong>chargeback.created</strong> webhook and sets the order\'s status to the configured value.', 'fastspring-split-gateways' ),
				),
				'chargeback_created_status' => array(
					'title'             => __( 'Chargeback Created Order Status', 'fastspring-split-gateways' ),
					'type'              => 'select',
					'default'           => 'wc-on-hold',
					'options'           => wc_get_order_statuses(),
					'desc_tip'          => true,
					'description'       => __( 'The status assigned to an order when a chargeback is filed.', 'fastspring-split-gateways' ),
					'custom_attributes' => array(
						'data-show-if' => array( 'chargeback_created' => true ),
					),
				),
				'chargeback_closed'       => array(
					'title'       => __( 'Chargeback Closed', 'fastspring-split-gateways' ),
					'type'        => 'checkbox',
					'default'     => 'yes',
					'desc_tip'    => true,
					'description' => __( 'If enabled, the plugin restores the order to its pre-chargeback status when FastSpring closes the chargeback.', 'fastspring-split-gateways' ),
				),
```

- [ ] **Step 2: Add getters**

Add these two methods after the existing `is_refund_cancel_enabled()` (currently the last method, lines 181-188):

```php
		/**
		 * Whether the plugin should react to chargeback.created webhooks.
		 *
		 * @return bool
		 */
		public function is_chargeback_created_enabled() {
			return $this->is_active( 'chargeback_created' );
		}

		/**
		 * Whether the plugin should restore the pre-chargeback status.
		 *
		 * @return bool
		 */
		public function is_chargeback_closed_enabled() {
			return $this->is_active( 'chargeback_closed' );
		}
```

- [ ] **Step 3: Verify syntax and rendering**

Run: `php -l includes/admin/class-wc-fastspring-advanced-settings.php`
Expected: `No syntax errors detected`

Open `http://micropro.test/wp-admin/admin.php?page=wc-settings&tab=checkout&section=fs_advanced` → a **Chargeback Settings** section must appear between Order Settings and Display Settings, with the status dropdown hidden unless "Chargeback Created" is checked.

- [ ] **Step 4: Verify save round-trip**

In the Advanced Settings page: uncheck "Chargeback Created", save, reload — it stays unchecked. Re-check, save, reload — checked. Then read the option row:

Run: `mysql -uroot micropro -e "SELECT option_value FROM wp_options WHERE option_name='woocommerce_fs_advanced_settings'"` (or phpMyAdmin if your local DB differs)
Expected: keys `chargeback_created`, `chargeback_created_status`, `chargeback_closed` present with your saved values.

- [ ] **Step 5: Commit**

```bash
git add includes/admin/class-wc-fastspring-advanced-settings.php
git commit -m "Add Chargeback Settings section to Advanced Settings"
```

---

## Task 2: Order-flow class — Order Completion Status filter

**Files:**
- Create: `includes/class-wc-fastspring-order-flow.php`

- [ ] **Step 1: Create the class with the completion-status filter**

Create `includes/class-wc-fastspring-order-flow.php`:

```php
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
```

- [ ] **Step 2: Include + instantiate in the main plugin file**

In `woocommerce-gateway-fastspring.php`, in `init()`, after the handler include (line 219 `include_once ... class-wc-gateway-fastspring-handler.php;`), add:

```php
			include_once dirname( __FILE__ ) . '/includes/class-wc-fastspring-order-flow.php';
			include_once dirname( __FILE__ ) . '/includes/class-wc-fastspring-express-checkout.php';

			new WC_FastSpring_Order_Flow();
			new WC_FastSpring_Express_Checkout();
```

(Note: `class-wc-fastspring-express-checkout.php` is created in Task 7. Running the site before Task 7 will emit a PHP warning from this include — acceptable mid-plan; commit after Task 7 wires the file, or run Task 7 immediately after. To keep Task 2 independently commit-able, you may temporarily comment the express include/instantiate lines and uncomment them in Task 7 — do NOT ship a broken include in a final commit.)

- [ ] **Step 3: Verify syntax**

Run: `php -l includes/class-wc-fastspring-order-flow.php` and `php -l woocommerce-gateway-fastspring.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Verify the filter changes completion status**

1. In Advanced Settings set **Order Completion Status = Processing** (`wc-processing`), Save.
2. In admin, create a test order and mark it paid via the API-driven path (or place a real test-mode order through the FastSpring popup so `payment_complete()` runs).
3. Confirm the order's status is **Processing** (not Completed).

Reset the option to `wc-completed` afterward and confirm a completed order still completes as **Completed**.

- [ ] **Step 5: Commit**

```bash
git add includes/class-wc-fastspring-order-flow.php woocommerce-gateway-fastspring.php
git commit -m "Wire Order Completion Status via payment_complete filter"
```

---

## Task 3: Refund On Cancel (FastSpring /returns call)

**Files:**
- Modify: `includes/class-wc-fastspring-order-flow.php` (fill in `order_cancelled`)

- [ ] **Step 1: Implement the refund-on-cancel handler**

Replace the `order_cancelled()` stub in `includes/class-wc-fastspring-order-flow.php` with:

```php
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
```

- [ ] **Step 2: Verify syntax**

Run: `php -l includes/class-wc-fastspring-order-flow.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verify the hook fires**

1. In Advanced Settings enable **Refund On Cancel**, Save.
2. In admin open a test order that has a `fs_order_id` meta value (created through a FastSpring flow).
3. Set its status to **Cancelled**.
4. Confirm: a new order note "FastSpring refund requested for cancelled order." and a `Refund on cancel requested for order N (FS X)` entry in the log file.
5. Cancel the same order again (transition pending → cancelled) — the refund must **not** fire again (idempotency meta). Then disable Refund On Cancel and confirm cancelling adds no refund note.

- [ ] **Step 4: Commit**

```bash
git add includes/class-wc-fastspring-order-flow.php
git commit -m "Wire Refund On Cancel to the FastSpring returns API"
```

---

## Task 4: Webhook envelope fix + get_receipt endpoint alias

**Files:**
- Modify: `includes/class-wc-gateway-fastspring-handler.php`

- [ ] **Step 1: Fix the envelope dispatch in `listen_webhook_request()`**

Replace the body of `listen_webhook_request()` (currently lines 203-219) with:

```php
	public function listen_webhook_request() {
		$body = json_decode( file_get_contents( 'php://input' ) );

		if ( ! $this->is_valid_webhook_request() ) {
			$this->log( 'Invalid webhook request - check secret' );
			return wp_send_json_error();
		}

		// FastSpring delivers an envelope: {"events":[{type,data}, ...]}.
		$events = ( isset( $body->events ) && is_array( $body->events ) ) ? $body->events : array();

		if ( empty( $events ) ) {
			$this->log( 'No events found in webhook request' );
			return wp_send_json_error();
		}

		foreach ( $events as $event ) {
			if ( ! is_object( $event ) ) {
				continue;
			}
			$this->handle_webhook_request( $event );
		}

		return wp_send_json_success();
	}
```

- [ ] **Step 2: Stop `handle_webhook_request()` from dying per-event**

Replace `handle_webhook_request()` (currently lines 256-295) with:

```php
	public function handle_webhook_request( $payload ) {
		try {
			switch ( $payload->type ) {

				case 'order.completed':
				  $this->handle_webhook_request_order_completed( $payload );
				  break;

				case 'return.created':
				  $this->handle_webhook_request_order_refunded( $payload );
				  break;

				case 'chargeback.created':
				  $this->handle_webhook_request_chargeback_created( $payload );
				  break;

				case 'subscription.canceled':
				  $this->handle_webhook_request_subscription_canceled( $payload );
				  break;

				case 'subscription.deactivated':
				  $this->handle_webhook_request_subscription_deactivate( $payload );
				  break;

				case 'subscription.activated':
				  $this->handle_webhook_request_subscription_activate( $payload );
				  break;

				case 'subscription.updated':
				//$this->handle_webhook_request_subscription_canceled( $payload );
				//break;

				default:
				  $this->log( sprintf( 'No webhook handler found for %s', $payload->type ) );
				  break;
				}

			$this->log( json_encode( $payload ) );
			return true;
		} catch ( Exception $e ) {
			$this->log( sprintf( 'Webhook handler error: %s', $e->getMessage() ) );
			return false;
		}
	}
```

Note: the `chargeback.created` case references `handle_webhook_request_chargeback_created()`, implemented in Task 5. Until Task 5 lands, PHP will fatal on that case only when such an event arrives — acceptable mid-plan.

- [ ] **Step 3: Register the get_receipt endpoint alias**

In `init()` (currently line 193), register both names so the frontend JS pattern (`?wc-ajax=wc_fastspring_get_receipt`) and the legacy name both resolve:

```php
		add_action( 'wc_ajax_fssg_wc_fastspring_get_receipt', array( $this, 'ajax_get_receipt' ) );
		add_action( 'wc_ajax_wc_fastspring_get_receipt', array( $this, 'ajax_get_receipt' ) );
```

- [ ] **Step 4: Verify syntax**

Run: `php -l includes/class-wc-gateway-fastspring-handler.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Verify the envelope dispatch end-to-end**

Create `C:\Users\LYNSRO~1\AppData\Local\Temp\opencode\fs-event-order.json`:

```json
{
  "events": [
    {
      "id": "evt_1",
      "type": "order.completed",
      "created": 1751896963000,
      "live": false,
      "processed": false,
      "data": {
        "tags": { "store_order_id": 1234 }
      }
    }
  ]
}
```

Run: `curl -i -X POST "http://micropro.test/?wc-api=wc_gateway_fastspring" -H "Content-Type: application/json" --data-binary @C:\Users\LYNSRO~1\AppData\Local\Temp\opencode\fs-event-order.json`
Expected: HTTP 200 and the log contains `Order tag found for 1234` plus a `No order found with transaction ID 1234`-style exception log (i.e. the event was **dispatched** — previously it silently no-oped).

- [ ] **Step 6: Commit**

```bash
git add includes/class-wc-gateway-fastspring-handler.php
git commit -m "Fix webhook envelope dispatch and register get_receipt AJAX alias"
```

---

## Task 5: Chargeback + return-restore webhook handlers

**Files:**
- Modify: `includes/class-wc-gateway-fastspring-handler.php`

- [ ] **Step 1: Add order lookup by FastSpring order ID**

Add this method after `find_order_by_fastspring_tag()` (after line 246):

```php
	/**
	 * Finds one WC order by FastSpring order reference (fs_order_id meta or
	 * transaction id). Used by events that carry no tags, e.g. chargebacks.
	 *
	 * @throws Exception
	 *
	 * @param string $fs_order_id FastSpring order ID.
	 * @return WC_Order WooCommerce order
	 */
	public function find_order_by_fastspring_id( $fs_order_id ) {
		$fs_order_id = sanitize_text_field( $fs_order_id );

		if ( ! $fs_order_id ) {
			throw new Exception( 'No FastSpring order ID provided' );
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

		if ( empty( $orders ) ) {
			$this->log( sprintf( 'No order found with FS order ID %s', $fs_order_id ) );
			throw new Exception( sprintf( 'Unable to locate order with FS order ID %s', $fs_order_id ) );
		}

		return $orders[0];
	}
```

- [ ] **Step 2: Add the chargeback.created handler**

Add this method after `handle_webhook_request_order_completed()` (after line 313):

```php
	/**
	 * Handles the chargeback.created webhook.
	 *
	 * @param array $payload Webhook data
	 */
	public function handle_webhook_request_chargeback_created( $payload ) {
		$data = isset( $payload->data ) && is_object( $payload->data ) ? $payload->data : $payload;

		$fs_order_id = isset( $data->order ) ? sanitize_text_field( $data->order ) : '';
		$order       = $this->find_order_by_fastspring_id( $fs_order_id );

		$settings = get_option( 'woocommerce_fs_advanced_settings', array() );
		if ( empty( $settings['chargeback_created'] ) || 'yes' !== $settings['chargeback_created'] ) {
			$this->log( sprintf( 'Chargeback created webhook received but handler disabled for order %s', $order->get_id() ) );
			return;
		}

		$order->update_meta_data( '_fs_chargeback_prev_status', $order->get_status() );
		$order->save();

		$status   = isset( $settings['chargeback_created_status'] ) ? $settings['chargeback_created_status'] : 'wc-on-hold';
		$reason   = isset( $data->reasonDescription ) ? sanitize_text_field( $data->reasonDescription ) : '';
		$code     = isset( $data->reasonCode ) ? sanitize_text_field( $data->reasonCode ) : '';
		$amount   = isset( $data->chargebackAmount ) ? wc_format_decimal( $data->chargebackAmount ) : '';
		$currency = isset( $data->currency ) ? sanitize_text_field( $data->currency ) : '';

		$order->update_status(
			$status,
			sprintf( __( 'FastSpring chargeback filed (reason: %1$s, code: %2$s, amount: %3$s %4$s).', 'fastspring-split-gateways' ), $reason, $code, $amount, $currency )
		);

		$this->log( sprintf( 'Order %s set to %s on chargeback', $order->get_id(), $status ) );
	}
```

- [ ] **Step 3: Add chargeback-close restore to the return.created handler**

Replace `handle_webhook_request_order_refunded()` (currently lines 320-325) with:

```php
	/**
	 * Handles the return.created webhook.
	 *
	 * A chargeback-originated return (data.type = CHARGEBACK) restores the
	 * pre-chargeback status when chargeback_closed is enabled. All other
	 * returns mark the order refunded.
	 *
	 * @param array $payload Webhook data
	 */
	public function handle_webhook_request_order_refunded( $payload ) {
		$data = isset( $payload->data ) && is_object( $payload->data ) ? $payload->data : $payload;

		$fs_order_id = '';
		if ( isset( $data->original ) && isset( $data->original->order ) ) {
			$fs_order_id = sanitize_text_field( $data->original->order );
		} elseif ( isset( $data->reference ) ) {
			$fs_order_id = sanitize_text_field( $data->reference );
		}

		$order = $this->find_order_by_fastspring_id( $fs_order_id );

		$type        = isset( $data->type ) ? strtoupper( sanitize_text_field( $data->type ) ) : 'RETURN';
		$prev_status = $order->get_meta( '_fs_chargeback_prev_status' );

		if ( 'CHARGEBACK' === $type && $prev_status ) {
			$settings = get_option( 'woocommerce_fs_advanced_settings', array() );
			$enabled  = isset( $settings['chargeback_closed'] ) ? ( 'yes' === $settings['chargeback_closed'] ) : true;

			if ( $enabled ) {
				$order->update_status( $prev_status, __( 'FastSpring chargeback closed - order status restored.', 'fastspring-split-gateways' ) );
				$order->delete_meta_data( '_fs_chargeback_prev_status' );
				$order->save();
				$this->log( sprintf( 'Order %s restored to %s after chargeback closed', $order->get_id(), $prev_status ) );
				return;
			}
		}

		$this->log( sprintf( 'Marking order ID %s as refunded', $order->get_id() ) );
		$order->update_status( 'refunded' );
	}
```

- [ ] **Step 4: Verify syntax**

Run: `php -l includes/class-wc-gateway-fastspring-handler.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Verify chargeback.created via webhook simulation**

1. Create a test order in WooCommerce and set its `fs_order_id` meta to a fake value, e.g. `FS-TEST-0001` (via the order edit screen → custom fields, or a snippet). Put it in status `completed`.
2. Create `C:\Users\LYNSRO~1\AppData\Local\Temp\opencode\fs-event-chargeback.json`:

```json
{
  "events": [
    {
      "id": "evt_cb_1",
      "type": "chargeback.created",
      "created": 1751896963000,
      "live": false,
      "processed": false,
      "data": {
        "id": "cb_1",
        "reasonCode": "4837",
        "reasonDescription": "FRAUDULENT",
        "fraudType": "true",
        "creationDate": "Fri May 09 17:00:00 UTC 2025",
        "chargebackAmount": 171.68,
        "currency": "USD",
        "order": "FS-TEST-0001",
        "processorCaseId": "dsp_test",
        "status": "NEW",
        "paymentMethod": "CREDIT_CARD",
        "paymentMethodVariant": "Mastercard"
      }
    }
  ]
}
```

3. Run: `curl -i -X POST "http://micropro.test/?wc-api=wc_gateway_fastspring" -H "Content-Type: application/json" --data-binary @C:\Users\LYNSRO~1\AppData\Local\Temp\opencode\fs-event-chargeback.json`
4. Expected: order is now **On Hold** with a "FastSpring chargeback filed…" note, and `_fs_chargeback_prev_status` meta = `completed`.
5. Re-run the same POST — the order note/meta must not double-apply in a way that loses the original status (status already on-hold; `_fs_chargeback_prev_status` overwritten with `on-hold` is acceptable, but confirm no fatal).

- [ ] **Step 6: Verify return.created restore via webhook simulation**

1. With the order still on-hold and `_fs_chargeback_prev_status=completed`, create `fs-event-return-cb.json`:

```json
{
  "events": [
    {
      "id": "evt_ret_1",
      "type": "return.created",
      "created": 1751896963000,
      "live": false,
      "processed": false,
      "data": {
        "return": "ret_1",
        "reference": "RET-TEST-0001",
        "completed": true,
        "type": "CHARGEBACK",
        "totalReturn": 171.68,
        "currency": "USD",
        "original": { "id": "FS-TEST-0001", "order": "FS-TEST-0001", "reference": "FS-TEST-0001" }
      }
    }
  ]
}
```

2. Run: `curl -i -X POST "http://micropro.test/?wc-api=wc_gateway_fastspring" -H "Content-Type: application/json" --data-binary @C:\Users\LYNSRO~1\AppData\Local\Temp\opencode\fs-event-return-cb.json`
3. Expected: order status restored to **Completed**, `_fs_chargeback_prev_status` meta removed, note "FastSpring chargeback closed - order status restored."
4. Send a `type: "RETURN"` variant → order must go to **Refunded** instead (no restore).

- [ ] **Step 7: Commit**

```bash
git add includes/class-wc-gateway-fastspring-handler.php
git commit -m "Handle chargeback.created and chargeback-closed return.created webhooks"
```

---

## Task 6: Receipt address copy (Orders API)

**Files:**
- Modify: `includes/class-wc-gateway-fastspring-handler.php`

- [ ] **Step 1: Add `get_order_details()` and refactor `get_order_status()`**

Add this method after `get_order_status()` (after line 105):

```php
	/**
	 * Fetches FastSpring order details via the Orders API.
	 *
	 * @param string $id FastSpring order ID.
	 * @return object|null Decoded response body, or null on failure.
	 */
	public function get_order_details( $id ) {
		if ( empty( self::get_setting( 'api_username' ) ) || empty( self::get_setting( 'api_password' ) ) ) {
			return null;
		}

		$url  = 'https://api.fastspring.com/orders/' . rawurlencode( sanitize_text_field( $id ) );
		$auth = base64_encode( sanitize_text_field( self::get_setting( 'api_username' ) ) . ':' . sanitize_text_field( self::get_setting( 'api_password' ) ) );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . $auth,
					'User-Agent'    => 'Mozilla/5.0',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( sprintf( 'API order %s details failed: %s', $id, $response->get_error_message() ) );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$this->log( sprintf( 'API order %s details not found (HTTP %s)', $id, $code ) );
			return null;
		}

		return json_decode( wp_remote_retrieve_body( $response ) );
	}
```

Replace `get_order_status()` (lines 67-105) with a version that reuses it (DRY):

```php
	/**
	 * If API credentials provided we can check for order completion on popup close
	 */
	public function get_order_status( $id ) {
		if ( empty( self::get_setting( 'api_username' ) ) || empty( self::get_setting( 'api_password' ) ) ) {
			$this->log( 'No API credentials - skipping API order confirm' );
			return 'pending';
		}

		$data = $this->get_order_details( $id );

		if ( $data && isset( $data->completed ) && true === $data->completed ) {
			$this->log( sprintf( 'API order %s completion checked', $id ) );
			return 'completed';
		}

		$this->log( sprintf( 'API order %s not found', $id ) );
		return 'pending';
	}
```

- [ ] **Step 2: Add `copy_customer_fields()`**

Add this method after `get_order_details()`:

```php
	/**
	 * Copies customer/address fields from a FastSpring order object into a
	 * WooCommerce order, but only for fields the WC order is missing.
	 * All values are sanitized.
	 *
	 * @param WC_Order  $order WooCommerce order.
	 * @param object|null $data FastSpring order details.
	 * @return void
	 */
	public function copy_customer_fields( $order, $data ) {
		if ( ! $order || ! is_object( $data ) ) {
			return;
		}

		$map = array(
			'billing_first_name' => array( 'customer', 'first' ),
			'billing_last_name'  => array( 'customer', 'last' ),
			'billing_email'      => array( 'customer', 'email' ),
			'billing_phone'      => array( 'customer', 'phone' ),
			'billing_city'       => array( 'address', 'city' ),
			'billing_postcode'   => array( 'address', 'postalCode' ),
			'billing_country'    => array( 'address', 'country' ),
			'billing_state'      => array( 'address', 'regionCode' ),
			'billing_address_1'  => array( 'address', 'addressLine1' ),
			'billing_address_2'  => array( 'address', 'addressLine2' ),
		);

		$copied = false;

		foreach ( $map as $meta_key => $path ) {
			if ( '' !== $order->get_meta( '_' . $meta_key ) ) {
				continue;
			}

			$node = $data;
			foreach ( $path as $key ) {
				if ( isset( $node->$key ) ) {
					$node = $node->$key;
				} else {
					$node = null;
					break;
				}
			}

			if ( null !== $node && '' !== $node ) {
				$order->update_meta_data( '_' . $meta_key, sanitize_text_field( (string) $node ) );
				$copied = true;
			}
		}

		if ( $copied ) {
			$order->save();
			$this->log( sprintf( 'Copied customer details from FastSpring for order %s', $order->get_id() ) );
		}
	}
```

- [ ] **Step 3: Call the copy in `ajax_get_receipt()`**

In `ajax_get_receipt()` (line ~144-151), inside the `if ( $status === 'completed' && ... )` branch, after `$order->add_order_note(...)`, add:

```php
				$this->copy_customer_fields( $order, $this->get_order_details( isset( $payload->id ) ? sanitize_text_field( $payload->id ) : '' ) );
```

- [ ] **Step 4: Verify syntax**

Run: `php -l includes/class-wc-gateway-fastspring-handler.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Verify via a completed popup order**

1. Place a test-mode order through the FastSpring popup (product page or checkout) as a logged-out guest.
2. Before this change, the completed order's billing fields may be empty (express / pre-filled popup path).
3. After completing, confirm the order has billing email/name/address populated from FastSpring when the WC order lacked them, and that no data is overwritten when the fields already exist.

- [ ] **Step 6: Commit**

```bash
git add includes/class-wc-gateway-fastspring-handler.php
git commit -m "Copy FastSpring customer details into orders on receipt"
```

---

## Task 7: Express Checkout PHP class

**Files:**
- Create: `includes/class-wc-fastspring-express-checkout.php`

- [ ] **Step 1: Create the class**

Create `includes/class-wc-fastspring-express-checkout.php`:

```php
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
```

- [ ] **Step 2: Ensure the main file include is active**

Confirm the `init()` include + instantiate added in Task 2 Step 2 are present (including `class-wc-fastspring-express-checkout.php`).

- [ ] **Step 3: Verify syntax**

Run: `php -l includes/class-wc-fastspring-express-checkout.php` and `php -l woocommerce-gateway-fastspring.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Verify buttons render per settings**

1. Advanced Settings: enable **Express Checkout**, ensure methods Apple Pay + PayPal are selected, Product location = After Add to Cart, Cart location = Below Cart Total. Save.
2. Load a product page → two `Buy with Apple Pay` / `Buy with PayPal` buttons after Add to Cart; the `#fsc-api` script tag has `data-test="yes"`.
3. Load the cart page (with an item) → same buttons below the cart totals.
4. Change Product location to Before Add to Cart → buttons move above the button.

- [ ] **Step 5: Verify the AJAX endpoint returns a session**

Open the product page, then in the browser console run (adjust nonce by first checking the page source for `wc-fastspring-express` nonce):

```js
fetch(woocommerce_fastspring_express_params.ajax_url.replace('%%endpoint%%', 'fssg_wc_fastspring_express_order'), {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({
    security: <nonce from the localized script>,
    method: 'paypal',
    product_id: <product id>,
    cart: 0,
    qty: 1
  })
}).then(r => r.json()).then(console.log)
```

Expected: `{ result: "success", session: { payload: {...}, key: "" }, order_id: N }` (test mode = plain payload).

- [ ] **Step 6: Commit**

```bash
git add includes/class-wc-fastspring-express-checkout.php woocommerce-gateway-fastspring.php
git commit -m "Add Express Checkout button rendering and AJAX order builder"
```

---

## Task 8: Express Checkout frontend JS

**Files:**
- Create: `assets/js/fs-express-checkout.js`

- [ ] **Step 1: Create the JS**

Create `assets/js/fs-express-checkout.js`:

```js
/* FastSpring Split Gateways - Express Checkout */
(function ($) {
    'use strict';

    function getAjaxURL(endpoint) {
        return woocommerce_fastspring_express_params.ajax_url.toString().replace('%%endpoint%%', endpoint);
    }

    // SBL callback stubs required by the script tag data-* attributes.
    window.fastspringBeforeRequestHandler = function () {};
    window.dataCallbackFunction = function () {};

    window.errorCallback = function (code, string) {
        console.error('FastSpring Error: ', code, string);
    };

    // Popup closed -> ask WooCommerce for the receipt URL (completes the order).
    window.fastspringPopupCloseHandler = function (data) {
        if (data && data.reference) {
            data.security = woocommerce_fastspring_express_params.nonce.receipt;
            $.ajax({
                type: 'POST',
                dataType: 'json',
                data: JSON.stringify(data),
                url: getAjaxURL('wc_fastspring_get_receipt'),
                success: function (res) {
                    if (res.redirect_url) {
                        window.location = res.redirect_url;
                    }
                },
                error: function (xhr) {
                    console.error('Receipt lookup failed', xhr.responseText);
                }
            });
        }
    };

    $(document.body).on('click', '.fssg-express-button', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var method = $btn.data('method');
        var productId = parseInt($btn.data('product-id'), 10) || 0;
        var isCart = $btn.data('cart') === 1;
        var qty = 1;

        if (!isCart) {
            var $qty = $('.quantity input.qty');
            if ($qty.length) {
                qty = Math.max(1, parseInt($qty.val(), 10) || 1);
            }
        }

        $btn.prop('disabled', true).addClass('fssg-express-loading');

        $.ajax({
            type: 'POST',
            url: getAjaxURL('fssg_wc_fastspring_express_order'),
            dataType: 'json',
            data: {
                security: woocommerce_fastspring_express_params.nonce.express,
                method: method,
                product_id: productId,
                cart: isCart ? 1 : 0,
                qty: qty
            },
            success: function (res) {
                $btn.prop('disabled', false).removeClass('fssg-express-loading');
                if (res.result === 'success' && res.session && res.session.payload) {
                    fastspring.builder.push({ reset: true });
                    fastspring.builder.secure(res.session.payload, res.session.key);
                    setTimeout(function () {
                        fastspring.builder.checkout();
                    }, 300);
                } else {
                    console.error('Express checkout failed', res);
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false).removeClass('fssg-express-loading');
                console.error('Express checkout request failed', xhr.responseText);
            }
        });
    });
})(jQuery);
```

- [ ] **Step 2: Verify end-to-end popup flow (test mode)**

1. Ensure Express Checkout enabled (Task 7 settings).
2. Load a product page; confirm no console errors on load and the `#fsc-api` script has `data-test="yes"` and `data-popup-closed="fastspringPopupCloseHandler"`.
3. Click **Buy with PayPal** → FastSpring popup opens pre-filled with the product.
4. Complete checkout with a test payment (test card) → popup closes → browser redirects to the WooCommerce order-received page → order is Completed and has billing details.

- [ ] **Step 3: Commit**

```bash
git add assets/js/fs-express-checkout.js
git commit -m "Add Express Checkout frontend script"
```

---

## Task 9: Final verification + commit

- [ ] **Step 1: Syntax-check every changed PHP file**

Run each:
- `php -l includes/admin/class-wc-fastspring-advanced-settings.php`
- `php -l includes/class-wc-fastspring-order-flow.php`
- `php -l includes/class-wc-gateway-fastspring-handler.php`
- `php -l includes/class-wc-fastspring-express-checkout.php`
- `php -l woocommerce-gateway-fastspring.php`

Expected: `No syntax errors detected` for all five.

- [ ] **Step 2: Full behavior sweep (local only, no VPS)**

1. **Chargebacks:** send `chargeback.created` + `return.created` (CHARGEBACK/RETURN) via curl against the order created in Task 5 — status on-hold, then restore, then refunded for manual returns. Verify `_fs_chargeback_prev_status` lifecycle.
2. **Completion status:** order completes as the configured status (e.g. `wc-processing`).
3. **Refund on cancel:** cancelling a FastSpring-tagged order POSTs `/returns` once (idempotent), with note + log.
4. **Express:** buttons render on product + cart pages per location settings; popup opens with the product; completing the popup completes the order and redirects; the order carries billing details.
5. **Regression:** the normal checkout flow (sub-gateways popup) still launches and completes, and the webhook now dispatches events (check logs after a test order).

- [ ] **Step 3: Review diffs and commit any stragglers**

Run: `git status --short` and `git log --oneline -10`
Expected: all 8 feature commits present, working tree clean except the pre-existing `readme.txt` modification. If the `readme.txt` change was yours, commit it separately; otherwise leave it.

- [ ] **Step 4: Update the design/plan docs if the plan drifted**

If any task deviated from this plan, update this file and the design spec so docs match code. Do not create new docs otherwise.

```bash
git add -A docs/
git commit -m "docs: note plan/spec updates"
```

---

## Implementation drift (recorded during execution)

- **Task 4:** the `get_receipt` AJAX alias is registered as `wc_ajax_wc_fastspring_get_receipt` (not only `wc_ajax_fssg_wc_fastspring_get_receipt`) so the existing popup-close flow resolves. This was already in the plan spec.
- **Task 7 (deviation):** the express checkout script localizes its own global `woocommerce_fastspring_express_params` (and filter hook) instead of reusing `woocommerce_fastspring_params`. Reason: the checkout script localizes `woocommerce_fastspring_params` via `wp_localize_script`, and on product/cart pages where the gateway script is also enqueued it clobbers the express params (missing `nonce.express`). Task 8's JS therefore reads `woocommerce_fastspring_express_params` throughout.
- **Task 7 (small addition):** orders created by express checkout store `_fssg_express_source` meta (`product` or `cart`); `ajax_express_order()` guards the cart branch against an empty cart; `ajax_get_receipt()` only calls `WC()->cart->empty_cart()` when the order was NOT a product-source express purchase (product express never touched the cart). Both order creators snapshot express-specific request values locally so the cart payload builder (which reads `order_awaiting_payment`/`current_order`) cannot pick up a stale pending order.

---

## Self-review notes

- **Spec coverage:** Part 1 → Task 1 + Task 5; Part 2 → Task 2; Part 3 → Task 3; Part 4 → Tasks 7-8; receipt enhancement → Task 6; webhook envelope fix + wiring → Task 4; main-file includes → Tasks 2 + 7. All defaults preserved (`chargeback_created=yes`, `chargeback_created_status=wc-on-hold`, `chargeback_closed=yes`, `order_completion_status=wc-completed`, `refund_cancel=no`, `express_checkout=no`).
- **Security:** all inputs sanitized (`sanitize_text_field`, `absint`, `sanitize_key`, `wp_unslash`), outputs escaped (`esc_html`/`esc_attr`), nonces on `express` + `receipt`, API only via `wp_remote_*` with `is_wp_error()` + code checks and `timeout => 15`, credentials read from options — no cURL, no hardcoded secrets.
- **Type consistency:** `get_secure_express_payload($product_id, $qty, $method, $order_id)` and `get_secure_json_payload($gateway_id)` used exactly as defined; method keys (`applepay|googlepay|paypal|amazon|alipay`) match both the settings options and the builder whitelist; `chargeback_created_status` values come from `wc_get_order_statuses()` (wc- prefixed) and `update_status()` accepts them (WC strips the prefix).
