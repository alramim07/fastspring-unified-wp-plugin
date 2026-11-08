# FastSpring Unified Payment Gateway for WooCommerce

[![WordPress](https://img.shields.io/badge/WordPress-%3E%3D6.0-21759B?style=for-the-badge&logo=wordpress&logoColor=white)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-%3E%3D3.0-96588A?style=for-the-badge&logo=woocommerce&logoColor=white)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.0-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net)
[![Version](https://img.shields.io/badge/Version-2.2.5-brightgreen?style=for-the-badge)]()
[![License](https://img.shields.io/badge/License-GPL--2.0%2B-blue?style=for-the-badge)]()

An enterprise-grade **FastSpring Payment Gateway & Subscription Management** integration for **WooCommerce**. Engineered to split FastSpring's monolithic hosted checkout into distinct, unbundled payment methods directly within the native WooCommerce checkout experience—while delivering full automated webhook reconciliation, account creation via FastSpring API, and WooCommerce Cart & Checkout Blocks compatibility.

---

## 🏗️ Architecture & Payment Flow

```mermaid
sequenceDiagram
    autonumber
    actor Customer as 🛒 Customer
    participant WC as 🛍️ WooCommerce (Classic / Blocks)
    participant Plugin as ⚙️ FastSpring Unified Plugin
    participant FS_API as 🌐 FastSpring SBL / Accounts API
    participant Webhook as ⚡ FastSpring Webhook Listener

    Customer->>WC: Selects Gateway (Credit Card, PayPal, Google Pay, Amazon Pay, Wire)
    WC->>Plugin: Process Checkout & Validate Order
    Plugin->>FS_API: Create Customer & Session Payload (SBL / Storefront)
    FS_API-->>Customer: Render Isolated Popup Checkout
    Customer->>FS_API: Authorizes Payment
    FS_API->>Webhook: Asynchronous HMAC-Signed Webhook (order.completed)
    Webhook->>Plugin: Verify Signature & Order Payload
    Plugin->>WC: Update Order Status -> Processing / Completed & Store License Data
    WC-->>Customer: Display Thank You / Fulfillment Page
```

---

## 🌟 Key Technical Capabilities

### 1. 🔀 Split Gateway Architecture
Unlike standard FastSpring integrations that force shoppers into a generic hosted page, this plugin decomposes FastSpring into isolated WooCommerce gateway instances:
- **Credit Card / Debit Card**
- **PayPal Express**
- **Google Pay**
- **Amazon Pay**
- **Wire Transfer / ACH**

Each gateway maintains independent admin controls, title overrides, custom SVG payment badges, and display ordering.

### 2. 🧱 Modern WooCommerce Blocks Compatibility
- Fully integrated with WooCommerce Cart & Checkout Blocks via `@woocommerce/blocks-registry` and PHP block integration classes.
- Zero checkout friction across both modern Gutenberg-based checkouts and legacy shortcode templates.

### 3. 🔄 Complete Subscription Lifecycle Management
Full compatibility with **WooCommerce Subscriptions**:
- **Automated Lifecycle Sync:** Real-time state synchronization for subscription activation, cancellation, suspension, and reactivation.
- **Dynamic Adjustments:** Seamless webhook processing for recurring subscription price adjustments and billing cycle alterations.

### 4. 👤 Automated Account Provisioning via FastSpring API
- Integrates with FastSpring Accounts API (`class-wc-fastspring-customer-creation.php`) to automatically provision or link FastSpring customer records when users register on WooCommerce.

### 5. 🛡️ Enterprise Security & Webhook Reconciliation
- **HMAC RSA-2048 Verification:** Webhook payloads are verified against the configured FastSpring private key before updating order state.
- **Idempotent Order Handling:** Defense-in-depth protection against duplicate webhook deliveries.
- **Audit Logging:** System debug logging via `WC_Logger` with sensitive token masking.

---

## 📂 Directory Layout
