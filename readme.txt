=== FastSpring Split Gateways ===
Contributors: lynsromero, ticltd
Donate link: https://tic.com.bd/
Tags: fastspring, woocommerce, payment gateway, checkout, split payments
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 8.0
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

This plugin splits bundled FastSpring methods (like PayPal and Credit Cards) into separate WooCommerce gateways for better user experience and control.

== Description ==

FastSpring Split Gateways enhances the default WooCommerce FastSpring integration by breaking out individual payment methods (such as PayPal, Credit Cards, Amazon Pay, Google Pay, and Wire Transfers) into separate, distinct gateways on your checkout page.

This allows store owners to individually enable, disable, and rename specific FastSpring payment methods, offering customers a streamlined and straightforward checkout experience without compromising on FastSpring's powerful global payment infrastructure.

== Installation ==

1. Upload the `fastspring-split-gateways` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to **WooCommerce > Settings > Payments**.
4. You will now see individual settings for FastSpring: PayPal, Credit Card, Amazon Pay, and more. Manage them as regular WooCommerce gateways.

== Frequently Asked Questions ==

= Does this plugin require the official FastSpring plugin? =
Yes, this plugin relies on the core FastSpring WooCommerce integration to handle secure payload generation and webhook processing. It effectively acts as a visual enhancement layer on top of it.

= Can I use this alongside other WooCommerce gateways? =
Absolutely! The split gateways will display seamlessly alongside any other active WooCommerce payment methods you are using.

= Will this break FastSpring webhooks? =
No. The plugin intelligently ensures that payments are mapped correctly back to the core FastSpring method before orders are finalized, keeping webhooks intact.

== Screenshots ==

1. placeholder-1.png - FastSpring Split Gateways Settings Page
2. placeholder-2.png - Checkout View with Individual FastSpring Methods

== Changelog ==

= 1.3.0 =
* Added AliPay as a full payment gateway (separate checkout method, admin section, icon).
* Added the AliPay preset to the gateway icon picker and bundled its icon asset.
* Admin Payments overview now shows real brand icons for every gateway instead of the generic placeholder.

= 1.2.0 =
* Official FastSpring logo in the admin header.
* Preset icon picker with custom image upload for each gateway.
* Icons are now stored as slugs and resolved locally.

= 1.1.0 =
* Branded API and Advanced settings screens.
* Individual branding for all split gateway sections.

= 1.0.0 =
* Initial release of FastSpring Split Gateways.
