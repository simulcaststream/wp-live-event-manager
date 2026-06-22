# Extending Live Event Manager

This document is the public contract for adaptor plugins. It describes how
third-party code registers streaming/payment/chat providers, owns its REST
routes and webhooks, and contributes admin sections.

The plugin family is two packages:

- **LEM Core** (this repo, GPLv2+) — contracts and orchestration. Ships no providers.
- **LEM Adaptors** (separate GPL repo) — official providers: Mux, OME, Stripe, PayPal, Ably chat.

Any third-party plugin can register additional providers on the same factories.

---

## Plugin header

Adaptor plugins must declare the core plugin as a hard dependency:

```
Plugin Name: My LEM Adaptor
Requires Plugins: live-event-manager
```

(The `Requires Plugins` header needs WordPress 6.5+. Older WP can use
`add_action('admin_notices', ...)` to warn if the global `LiveEventManager`
class is missing.)

## Hook timing

Register on `plugins_loaded` priority **20** so the core plugin (priority 10) has
already declared its constants and loaded the factories.

```php
add_action('plugins_loaded', 'my_adaptor_bootstrap', 20);
function my_adaptor_bootstrap() {
    if (!class_exists('LEM_Streaming_Provider_Factory')) {
        return; // core not active
    }
    // …register providers, routes, sections…
}
```

## Constants

| Constant              | Meaning                                                              |
|-----------------------|----------------------------------------------------------------------|
| `LEM_REST_NAMESPACE`  | Default REST namespace (currently `lem/v1`). Read via the filter below. |
| `LEM_PLUGIN_DIR`      | Absolute path to the core plugin directory.                          |
| `LEM_PLUGIN_FILE`     | Absolute path to the core plugin entry file.                         |
| `LEM_VERSION`         | Core plugin version string.                                          |

---

## Streaming providers

### Registering

```php
$factory = LEM_Streaming_Provider_Factory::get_instance();
$factory->register_provider('mux', 'LEM_Mux_Provider');
```

Implement `LEM_Streaming_Provider_Interface` (see
`services/streaming/class-streaming-provider-interface.php`).

### Lazy-loading the class file

Don't `require` your provider class at bootstrap. Hook the resolver instead:

```php
add_filter('lem_streaming_provider_class_file', function ($path, $id) {
    if ($id === 'mux') {
        return __DIR__ . '/providers/class-mux-provider.php';
    }
    return $path;
}, 10, 2);
```

The factory loads the file the first time `get_provider('mux')` is called.

### Lifecycle actions

| Action                                     | Args                              | Fires when                          |
|--------------------------------------------|-----------------------------------|-------------------------------------|
| `lem_streaming_provider_registered`        | `$id`, `$class_name`              | A provider is registered.           |
| `lem_streaming_provider_activated`         | `$id`, `$instance`                | A provider is instantiated.         |
| `lem_streaming_webhook_received`           | `$provider_id`, `$normalized`     | Streaming webhook handled OK.       |

---

## Payment providers

Same shape as streaming — different factory:

```php
$factory = LEM_Payment_Provider_Factory::get_instance();
$factory->register_provider('paypal', 'LEM_PayPal_Provider');

add_filter('lem_payment_provider_class_file', function ($path, $id) {
    if ($id === 'paypal') {
        return __DIR__ . '/providers/class-paypal-provider.php';
    }
    return $path;
}, 10, 2);
```

Implement `LEM_Payment_Provider_Interface` (see
`services/payments/class-payment-provider-interface.php`).

### Required methods for every payment adaptor

| Method | Purpose |
|--------|---------|
| `create_checkout_session()` | Start checkout; return `checkout_url` + `session_id` (reference id). |
| `verify_webhook()` | Verify incoming webhook; return normalized `checkout.completed` when paid. |
| `finalize_checkout($reference_id, $context)` | Optional return-path step (e.g. PayPal capture). Return `WP_Error( 'not_applicable' )` if not needed. |
| `get_payment_status($reference_id)` | Poll provider API; return normalized status (see below). |

When `paid` is true, `get_payment_status()` / `finalize_checkout()` must return:

```php
[
    'paid'        => true,
    'email'       => 'buyer@example.com',
    'event_id'    => '123',           // LEM event post ID
    'payment_id'  => 'cs_… or capture id', // stored on JWT row
    'order_id'    => '…',             // optional (PayPal order id)
]
```

Core calls `LEM_Payment_Status::resolve_for_reconciliation()` (finalize, then status) on the confirmation page, PayPal return, and `lem_reconcile_payment` AJAX when webhooks are delayed. **Do not** grant JWT access only in webhooks — implement the status methods so reconciliation works.

Lifecycle actions: `lem_payment_provider_registered`, `lem_payment_provider_activated`, `lem_webhook_payment_received`.

---

## REST routes

### Default core namespace

```php
$ns = apply_filters('lem_rest_namespace', LEM_REST_NAMESPACE);
```

### Registering custom routes

After core routes are registered, the action `lem_rest_register_routes` fires
with the namespace as its arg. Hook it to add your own routes:

```php
add_action('lem_rest_register_routes', function ($ns) {
    register_rest_route($ns, '/my-thing', [
        'methods'             => 'GET',
        'callback'            => 'my_callback',
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);
});
```

### Streaming webhooks

Core exposes a generic dispatcher:

```
POST /lem/v1/webhooks/streaming/{provider}
```

The dispatcher resolves the provider by ID and calls
`$provider->handle_webhook($payload, $signature)`. Your provider's
`handle_webhook()` is responsible for vendor-specific signature verification —
read additional headers from `$_SERVER` if needed.

After successful handling, `do_action('lem_streaming_webhook_received', $provider_id, $result)` fires.

### Payment webhooks

The existing route `POST /lem/v1/webhooks/payment` already auto-detects the
provider from request headers (`Stripe-Signature`, `PayPal-Transmission-Sig`)
and delegates to the active provider's `verify_webhook()`. New payment
providers do not need their own route.

---

## Admin sections (`LEM_Settings_Registry`)

Add a section to a Services-page tab:

```php
LEM_Settings_Registry::register_section('paypal_credentials', [
    'tab'        => 'payments',         // streaming | payments | chat | license | general
    'title'      => 'PayPal',
    'render_cb'  => 'my_render_paypal_form',
    'save_cb'    => 'my_save_paypal_form',  // optional
    'capability' => 'manage_options',       // default
    'priority'   => 10,                     // optional — render order within tab
]);
```

`render_cb` receives the section args. Output the section body (no `<section>`
wrapper — the registry adds it).

---

## AJAX helpers (`LEM_Ajax_Helpers`)

```php
add_action('wp_ajax_my_action', 'my_handler');
function my_handler() {
    LEM_Ajax_Helpers::verify_admin_request('my_nonce_action');
    // …work…
    LEM_Ajax_Helpers::json_success(['ok' => true]);
}
```

For public endpoints use `verify_public_request()` (nonce only, no capability check).

---

## Webhook logging (`LEM_Webhook_Log`)

```php
LEM_Webhook_Log::record('processed', [
    'provider'    => 'mux',
    'event_type'  => 'video.asset.ready',
    'event_id'    => $event_id,
    'message'     => 'Asset ready and stored',
]);
```

Statuses used by core: `received`, `processed`, `skipped`, `failed`,
`verification_failed`, `missing_metadata`, `duplicate`, `already_has_access`,
`jwt_failed`. Adaptors may use any string; core only matches a few in the admin
log filter.

---

## Template packs

Manifest contract: `docs/template-pack.schema.json` (`lem_format`, `requires_lem`,
`preview_url`, reserved `update_url` / `update_id` for future marketplace plugins).

Use `LEM_Template_Manager::register_pack_source()` on `plugins_loaded` to expose packs
bundled with your plugin. Optional: hook `lem_before_template_pack_install` or `lem_template_pack_install_metadata`
if you operate a separate template storefront. Core does not enforce licenses.

Reserved manifest keys (ignored by core): `marketplace`, `product_id`, `license_required`.
Update checks: filter `lem_template_pack_update_response` (core does not call remote URLs).

---

## Reference: existing core hooks

These were already in core before this extension API consolidation; new
adaptors can rely on them.

**JWT / access:**
- `lem_jwt_access_denied` (filter) — pre-issuance denial.
- `lem_playback_token_generated` (filter) — modify token after issuance.
- `lem_access_granted` (action) — after access check succeeds.

**Webhooks / payments:**
- `lem_webhook_event_received` (action) — any payment webhook event.
- `lem_webhook_payment_received` (action) — successful payment + JWT issued.
- `lem_event_access_state` (filter) — modify event access state.
- `lem_stripe_session_args` (filter) — Stripe-specific.
- `lem_paypal_order_args` (filter) — PayPal-specific.

**Templates:**
- `lem_resolve_template_file`, `lem_installed_template_packs`
- `lem_before_template_pack_install`, `lem_template_pack_install_metadata`
- `lem_template_pack_update_response` (reserved; marketplace plugins only)
- `lem_template_pack_source_error`, `lem_template_pack_activated`, `lem_template_pack_deleted`, `lem_template_pack_installed`
