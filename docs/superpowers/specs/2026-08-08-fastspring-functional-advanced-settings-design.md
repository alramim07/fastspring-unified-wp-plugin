# Design: Functional Advanced Settings for FastSpring Split Gateways

Date: 2026-08-08
Status: Approved (user: "execute the plan")

## Purpose

Make the FastSpring plugin's **Advanced Settings** page (`fs_advanced`,
option `woocommerce_fs_advanced_settings`) fully functional, and port the
advanced settings from the Stripe gateway plugin that FastSpring can genuinely
support. Stripe-only options (installments, statement descriptors, fee display,
capture/authorization, BNPL messaging, Stripe customer objects, reviews,
Stripe email receipts, locale) are **dropped** — FastSpring either controls them
in its merchant portal or does not expose the feature.

Work is **local-only** (no VPS deployment) per the user's instruction.

## Scope

1. **New "Chargeback Settings" section** — ported from Stripe's "Dispute
   Settings". FastSpring equivalents exist (`chargeback.created` and
   `return.created` webhooks).
2. **Wire Order Completion Status** — existing option is saved but never read.
3. **Wire Refund On Cancel** — existing option is saved but never read; add a
   real `POST /returns` call to the FastSpring API.
4. **Implement Express Checkout** — existing options are saved but never read;
   build real "Buy Now" buttons on product/cart pages.

## Defaults (no behavior change out of the box)

- `chargeback_created = yes`
- `chargeback_created_status = wc-on-hold`
- `chargeback_closed = yes`
- `order_completion_status` stays `wc-completed` (unchanged default)
- `refund_cancel` stays `no`
- `express_checkout` stays `no`

## Part 1 — Chargeback Settings

New form fields in `includes/admin/class-wc-fastspring-advanced-settings.php`
under a new **Chargeback Settings** section (after Order Settings):

- `chargeback_created` (checkbox, default `yes`) — listen for
  `chargeback.created` and set the order on-hold + add a note.
- `chargeback_created_status` (select of `wc_get_order_statuses()`, default
  `wc-on-hold`, `data-show-if` on `chargeback_created`) — the status applied
  when a chargeback is filed.
- `chargeback_closed` (checkbox, default `yes`) — restore the pre-chargeback
  status when a chargeback-originated `return.created` fires.

### Webhook handler (`includes/class-wc-gateway-fastspring-handler.php`)

- Add `case 'chargeback.created'` -> new
  `handle_webhook_request_chargeback_created($payload)`:
  - find order by tag; if `chargeback_created` enabled:
    - store `_fs_chargeback_prev_status` meta (the current order status),
    - `update_status()` to configured `chargeback_created_status`,
    - add an order note with chargeback reason/amount from payload.
  - if disabled: log and skip.
- Modify `handle_webhook_request_order_refunded()` (`return.created`):
  - if `chargeback_closed` enabled and the order has `_fs_chargeback_prev_status`
    meta, restore that status, delete the meta, add note.
  - otherwise keep existing behavior (set `refunded`).

## Part 2 — Order Completion Status

Add filter `woocommerce_payment_complete_order_status` returning the configured
`order_completion_status` normalized:

- configured `wc-completed` -> `completed` (pass through default).
- any other status -> return that status slug.

Single hook covers both the webhook `order.completed` path and the popup-close
`ajax_get_receipt` path because both call `$order->payment_complete()`.

## Part 3 — Refund On Cancel

Add `woocommerce_order_status_cancelled` hook in a new
`includes/class-wc-fastspring-order-flow.php`:

- if `refund_cancel` disabled -> return.
- order must be a FastSpring order (`_fs_order_id` meta or transaction id).
- idempotency: skip if `_fs_refund_requested` meta exists.
- `wp_remote_post('https://api.fastspring.com/returns', ...)` with Basic auth
  (existing `api_username`/`api_password`), `timeout => 15`,
  `Content-Type: application/json`, body:
  `{ "orderId": <fs_id>, "reason": "Order cancelled", "amount": <order total>,
     "currency": <order currency> }`.
- On success: order note + set `_fs_refund_requested`.
- On `is_wp_error()` or non-2xx: log + order note, do not set meta (retry on
  next transition).
- FastSpring then fires `return.created`, which flips the order to `refunded`.

## Part 4 — Express Checkout

New `includes/class-wc-fastspring-express-checkout.php`:

- Render buttons only when `express_checkout` enabled and gateway available.
  - Product page: `woocommerce_after_add_to_cart_button` /
    `woocommerce_before_add_to_cart_button` per `express_product_location`.
  - Cart page: `woocommerce_before_cart_totals` /
    `woocommerce_after_cart_totals` per `express_cart_location`.
  - One button per enabled `express_methods`
    (applepay, googlepay, paypal, amazon, alipay) with
    `data-method`, `data-product-id`, `data-cart` attributes.
- Enqueue `assets/js/fs-express-checkout.js` (deps: jquery, fastspring) on
  product/cart pages when enabled, localized with an express nonce.
- AJAX `wc_ajax_fssg_wc_fastspring_express_order` (nonce `wc-fastspring-express`):
  - product page: create pending order (product + qty, payment method
    `fastspring`, calculate totals), payload via
    `fssg_WC_Gateway_FastSpring_Builder::get_secure_express_payload($product_id,
    $qty, $method, $order_id)`.
  - cart page: create pending order from `WC()->cart` items, payload via
    `get_secure_json_payload()`.
  - set `WC()->session->set('current_order', $order_id)` (reused by the
    existing `ajax_get_receipt` flow).
  - return `{ result, session, order_id }`.

New `assets/js/fs-express-checkout.js`:

- on button click: POST to the express AJAX; on success
  `fastspring.builder.secure(payload, key)` then `fastspring.builder.checkout()`.
- popup-close handler posts to the existing `get_receipt` endpoint
  (`wc_fastspring_get_receipt`) and redirects via `redirect_url`.

### Receipt enhancement (`ajax_get_receipt`)

- Copy billing/shipping + address from the completion payload into the order
  when present and the order lacks them (needed for shippable products bought
  via express where the checkout form was never filled). All values sanitized.

## File changes

New:
- `includes/class-wc-fastspring-order-flow.php`
- `includes/class-wc-fastspring-express-checkout.php`
- `assets/js/fs-express-checkout.js`

Edited:
- `includes/admin/class-wc-fastspring-advanced-settings.php` (Chargebacks section)
- `includes/class-wc-gateway-fastspring-handler.php` (chargeback handler,
  return-restore, receipt address copy)
- `woocommerce-gateway-fastspring.php` (include new class files)

## Security

- All inputs sanitized (`sanitize_text_field`, `absint`, `sanitize_key`, ...).
- All output escaped (`esc_html`, `esc_attr`, `esc_url`).
- Nonces on all state-changing AJAX (`wc-fastspring-express`).
- API calls via `wp_remote_post`/`wp_remote_get` with `is_wp_error()` +
  response-code checks, `timeout => 15`. Credentials from options, never
  hardcoded. No raw cURL.

## Testing (local only)

- `php -l` on all changed files.
- Advanced Settings page renders the Chargebacks section; save round-trips new
  keys.
- Webhook simulation via WP-CLI: `chargeback.created` sets on-hold + note;
  `return.created` (chargeback-originated) restores prior status.
- Order completing as configured status (e.g. processing) when option changed.
- Cancel a test order -> `/returns` called (verify log + order note).
- Express buttons render per settings on product/cart pages; clicking opens the
  popup; completion completes the order and redirects.
