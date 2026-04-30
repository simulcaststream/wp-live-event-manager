<?php
/**
 * Payment Provider Interface
 *
 * All payment providers must implement this interface. The contract is intentionally
 * minimal: create a checkout session, verify an incoming webhook, and fetch a payment
 * status. Everything else (refunds, subscriptions, …) lives in provider-specific code
 * or future extensions to this interface.
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
     *   session_id    — provider-side session identifier (stored for dedup)
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
     *   type          — string  provider-specific event type, e.g. 'checkout.completed'
     *   payment_id    — string  provider-side session / payment identifier
     *   event_id      — string  LEM event ID from metadata
     *   email         — string  buyer email
     *   raw           — mixed   full provider event object for advanced hooks
     *
     * @return array|WP_Error
     */
    public function verify_webhook();

    /**
     * Retrieve the status of a payment session.
     *
     * Returns an array:
     *   paid          — bool
     *   email         — string|null
     *   event_id      — string|null
     *
     * Returns WP_Error on failure.
     *
     * @param  string $session_id
     * @return array|WP_Error
     */
    public function get_payment_status(string $session_id);

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
     * @return array           Sanitized values.
     */
    public function validate_settings(array $settings): array;
}
