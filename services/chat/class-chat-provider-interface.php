<?php
/**
 * Chat Provider Interface
 *
 * Defines the contract for pluggable chat backends. The core plugin does not ship
 * a chat implementation — this interface exists so companion plugins can register
 * providers (e.g. a self-hosted WebSocket server, Ably, Pusher, Stream) without
 * forking the plugin.
 *
 * Registration (on plugins_loaded):
 *   LEM_Chat_Provider_Factory::get_instance()->register_provider('myprovider', 'My_Chat_Provider');
 *
 * All methods should return WP_Error on unrecoverable failure so callers can
 * distinguish "provider not configured" from "provider returned bad data".
 */

if (!defined('ABSPATH')) {
    exit;
}

interface LEM_Chat_Provider_Interface {

    /**
     * Human-readable name shown in admin (e.g. "Ably", "Self-hosted WS").
     */
    public function get_name(): string;

    /**
     * Machine slug used in settings and filters (e.g. "ably").
     */
    public function get_id(): string;

    /**
     * Whether all required credentials/config are present and the provider can be used.
     */
    public function is_configured(): bool;

    /**
     * Issue a short-lived token that allows a viewer to connect to the chat room.
     *
     * @param  string $event_id   LEM event post ID.
     * @param  string $email      Viewer email (used for identity / dedup).
     * @param  string $display_name  Display name for the chat.
     * @param  array  $caps       Optional capability overrides, e.g. ['publish' => false] for read-only.
     * @return array|WP_Error     On success: ['token' => string, 'expires_at' => int, 'channel' => string]
     */
    public function issue_viewer_token(string $event_id, string $email, string $display_name, array $caps = array());

    /**
     * Issue an elevated token for the event host / moderator.
     *
     * @param  string $event_id
     * @param  string $user_id   WordPress user ID of the host.
     * @return array|WP_Error    Same shape as issue_viewer_token().
     */
    public function issue_host_token(string $event_id, string $user_id);

    /**
     * Revoke chat access for a viewer (e.g. when their playback JWT is revoked).
     *
     * @param  string $event_id
     * @param  string $email
     * @return true|WP_Error
     */
    public function revoke_viewer(string $event_id, string $email);

    /**
     * Return the name of the chat room / channel for a given event.
     *
     * @param  string $event_id
     * @return string
     */
    public function get_channel_name(string $event_id): string;

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
