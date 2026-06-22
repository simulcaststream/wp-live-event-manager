<?php
/**
 * Payment Provider Interface
 *
 * All payment providers must implement this interface. Core uses these methods for:
 *   - Checkout creation
 *   - Webhook verification (verify_webhook)
 *   - API reconciliation when webhooks are delayed (finalize_checkout + get_payment_status)
 *
 * Extension point for companion plugins:
 *   LEM_Payment_Provider_Factory::register_provider('myprovider', 'My_LEM_Provider');
 */

if (!defined('ABSPATH')) {
    exit;
}

interface LEM_Payment_Provider_Interface {

    /**
     * Human-readable name shown in admin (e.g. "Stripe").
     */
    public function get_name(): string;

    /**
     * Machine slug used in settings and filters (e.g. "stripe").
     */
    public function get_id(): string;

    /**
     * Whether all required credentials are present and the provider is ready to use.
     */
    public function is_configured(): bool;

    /**
     * Create a checkout / payment session.
     *
     * $args shape (all optional keys are provider-defined):
     *   price_id      — provider-side price / product identifier
     *   event_id      — LEM event post ID (stored in metadata)
     *   event_title   — human-readable event title (stored in metadata)
     *   email         — pre-fill customer email on checkout
     *   success_url   — redirect after successful payment
     *   cancel_url    — redirect on cancel
     *
     * Returns an array on success:
     *   checkout_url  — URL to redirect the buyer to
     *   session_id    — provider reference id (store for reconciliation; Stripe cs_…, PayPal order id)
     *
     * Returns WP_Error on failure.
     *
     * @param  array $args
     * @return array|WP_Error
     */
    public function create_checkout_session(array $args);

    /**
     * Verify and parse an incoming webhook request.
     *
     * Reads the raw request body and any required headers internally.
     * Returns a normalised event object on success or WP_Error on failure.
     *
     * Normalised event shape:
     *   type          — string  use 'checkout.completed' when access should be granted
     *   payment_id    — string  id stored on JWT rows (session id, capture id, etc.)
     *   event_id      — string  LEM event ID from metadata
     *   email         — string  buyer email
     *   raw           — mixed   full provider event object for advanced hooks
     *
     * @return array|WP_Error
     */
    public function verify_webhook();

    /**
     * Finalize checkout after the buyer returns (provider-specific).
     *
     * Examples: PayPal captures an approved order; Stripe has nothing to do here.
     * Core calls this before get_payment_status() during API reconciliation.
     *
     * Return WP_Error with code `not_applicable` when this provider only needs get_payment_status().
     *
     * On success, return the same normalized shape as get_payment_status() (see below).
     *
     * @param string               $reference_id Checkout reference from create_checkout_session() session_id.
     * @param array<string, mixed> $context      Optional hints, e.g. ['event_id' => '123'].
     * @return array|WP_Error
     */
    public function finalize_checkout(string $reference_id, array $context = array());

    /**
     * Retrieve payment status from the provider API (webhook fallback / reconciliation).
     *
     * $reference_id is the value returned as session_id from create_checkout_session()
     * (Stripe Checkout session id, PayPal order id, etc.).
     *
     * Required normalized return shape:
     *   paid         — bool    true only when funds are captured and access may be granted
     *   email        — string  buyer email (required when paid is true)
     *   event_id     — string  LEM event post id from metadata (required when paid is true)
     *   payment_id   — string  id stored on JWT rows; defaults to $reference_id if omitted
     *   order_id     — string  optional parent order id (PayPal); omit if not applicable
     *
     * Returns WP_Error on failure.
     *
     * @param  string $reference_id
     * @return array|WP_Error
     */
    public function get_payment_status(string $reference_id);

    /**
     * Return admin settings fields this provider needs.
     *
     * Each entry: [ 'key' => string, 'label' => string, 'type' => string, 'description' => string ]
     *
     * @return array[]
     */
    public function get_settings_fields(): array;

    /**
     * Validate and sanitize submitted settings values for this provider.
     *
     * @param  array $settings Raw POST values.
     * @return array           Sanitized values or indexed list of error strings.
     */
    public function validate_settings(array $settings): array;
}
