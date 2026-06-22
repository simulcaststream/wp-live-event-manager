# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Install / update PHP dependencies (JWT library)
composer install

# Regenerate autoloader after vendor changes
composer dump-autoload -o
```

There is no build step for JS/CSS — assets in `assets/` are plain files. There is no test suite.

## Two-package architecture

- **LEM Core** (this repo, GPLv2+) — contracts and orchestration only. Ships **no** providers. Includes the CPT, settings shell, JWT issuance, REST/webhook dispatcher, factories, template manager, magic links, cache, access, device service, settings registry, AJAX helpers, webhook log.
- **LEM Adaptors** (separate GPL repo) — bundles Mux, OME, Stripe, PayPal, and `LEM_Ably_Chat_Provider`. Self-registers on `plugins_loaded`. Ably credentials live on **Services → Chat**, not Settings.

The full extension contract is documented in [EXTENDING.md](EXTENDING.md).

## Architecture

**Plugin entry point**: `live-event-manager.php` — defines constants (`LEM_PLUGIN_DIR`, `LEM_PLUGIN_FILE`, `LEM_VERSION`, `LEM_REST_NAMESPACE`), loads vendor autoload + all includes, then instantiates the single `LiveEventManager` global.

**Main class split into traits** — `LiveEventManager` has no methods of its own; all logic lives in four traits loaded from `includes/plugin/`:

| Trait file | Responsibility |
|---|---|
| `trait-lem-bootstrap-events.php` | Constructor (wires all hooks), CPT registration, AJAX handlers, front-end blocks/shortcode |
| `trait-lem-admin-streaming.php` | Admin menu pages, stream management UI, vendor credentials |
| `trait-lem-jwt-payments.php` | JWT generation, checkout session creation, payment records |
| `trait-lem-rest-webhooks.php` | REST API (`lem/v1`), webhooks, watch-page access, Ably chat tokens |

**Service classes** (loaded by the entry point or on demand):

- `includes/class-lem-cache.php` — `LEM_Cache`: Upstash Redis over HTTP (no phpredis). Used as a singleton (`LEM_Cache::instance()`) or via static helpers (`LEM_Cache::get/set`). All cache operations are no-ops when Upstash is not configured.
- `includes/class-lem-access.php` — `LEM_Access`: Redis keys for playback blobs + DB table `{prefix}lem_entitlement_revocations` for hard revocations. Table is lazily created via `dbDelta`.
- `includes/class-lem-device-service.php` — Device fingerprinting / identification.
- `includes/class-lem-template-manager.php` — `LEM_Template_Manager`: discovers, installs, and resolves template packs. Resolution order: active user pack in `wp-content/lem-templates/{slug}/` → bundled pack in `template-packs/{slug}/` → plugin default in `templates/`. Third-party packs register via `LEM_Template_Manager::register_pack_source()`.
- `includes/class-lem-settings-registry.php` — `LEM_Settings_Registry`: registry for adaptor-contributed sections on the Services admin page (grouped by tab: streaming/payments/chat/general).
- `includes/class-lem-ajax-helpers.php` — `LEM_Ajax_Helpers`: shared nonce/capability checks and JSON response helpers for adaptor AJAX handlers.
- `includes/class-lem-webhook-log.php` — `LEM_Webhook_Log::record()`: writes to `{prefix}lem_webhook_log`. Used by core and adaptors.
- `services/magic-links/class-magic-link-service.php` — One-time magic link generation, validation, and email dispatch.
- `services/streaming/class-streaming-provider-factory.php` — `LEM_Streaming_Provider_Factory` singleton. **Adaptor plugins register providers** via `register_provider($id, $class)` and the `lem_streaming_provider_class_file` filter for lazy loading. Active provider read from `lem_settings['streaming_provider']`.
- `services/payments/class-payment-provider-factory.php` — Same pattern for payments. Filter: `lem_payment_provider_class_file`.

**Templates**: `templates/` holds the default PHP templates for every admin screen and front-end surface (blocks, single event, confirmation page, etc.). These are the fallback when no active template pack overrides a file.

**Template packs**: `template-packs/starter/` and `template-packs/obsidian/` are bundled examples. Each pack has a `template.json` manifest (slug, name, version) and can override any file from the allowed set. User-uploaded packs go to `wp-content/lem-templates/{slug}/`. Third-party plugins can expose packs via `LEM_Template_Manager::register_pack_source()` on `plugins_loaded`.

**WordPress option key**: `lem_settings` — single serialized array for all plugin configuration (Upstash URL/token, provider credentials, active provider, Ably key, etc.).

**REST namespace**: `LEM_REST_NAMESPACE` (`lem/v1`). Read via `apply_filters('lem_rest_namespace', LEM_REST_NAMESPACE)`. Most routes require `manage_options`. The `POST /lem/v1/check-jwt-status` endpoint also accepts a nonce and is designed for edge-layer revocation checks (e.g. a Cloudflare Worker). After core routes are registered, `do_action('lem_rest_register_routes', $namespace)` fires so adaptors can add their own routes. Streaming webhook dispatcher: `POST /lem/v1/webhooks/streaming/{provider}` — delegates to the provider's `handle_webhook()`.

**Logging**: errors are written with `error_log()` prefixed `[LEM]` and appear in `wp-content/debug.log` when `WP_DEBUG_LOG` is enabled.
