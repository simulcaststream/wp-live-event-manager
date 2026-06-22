<?php
/**
 * Streaming Provider Interface
 * All streaming providers must implement this interface
 */

if (!defined('ABSPATH')) {
    exit;
}

interface LEM_Streaming_Provider_Interface {
    
    /**
     * Get provider display name
     */
    public function get_name();
    
    /**
     * Get provider ID/slug
     */
    public function get_id();
    
    /**
     * Check if provider is configured
     */
    public function is_configured();
    
    /**
     * Get credentials for API calls
     */
    public function get_credentials();
    
    /**
     * Generate playback token/JWT for viewer
     */
    public function generate_playback_token($email, $event_id, $payment_id = null, $is_refresh = false);
    
    /**
     * Get RTMP stream information (if supported)
     */
    public function get_rtmp_info($stream_id = null);
    
    /**
     * Create a new live stream
     */
    public function create_stream($params);
    
    /**
     * Update an existing stream
     */
    public function update_stream($stream_id, $params);
    
    /**
     * Delete a stream
     */
    public function delete_stream($stream_id);
    
    /**
     * Get stream details
     */
    public function get_stream_details($stream_id);
    
    /**
     * List all streams
     */
    public function list_streams($limit = 100);
    
    /**
     * Get stream status (active/idle/error)
     */
    public function get_stream_status($stream_id = null);
    
    /**
     * Create simulcast target (if supported)
     */
    public function create_simulcast_target($stream_id, $url);
    
    /**
     * List simulcast targets
     */
    public function list_simulcast_targets($stream_id = null);
    
    /**
     * Delete simulcast target
     */
    public function delete_simulcast_target($stream_id, $target_id);
    
    /**
     * Get WebRTC publish URL (if supported)
     */
    public function get_webrtc_publish_url($stream_id = null);
    
    /**
     * Get WebRTC playback URL
     */
    public function get_webrtc_playback_url($stream_id = null);
    
    /**
     * Get playback URL for player component
     */
    public function get_playback_url($stream_id = null);
    
    /**
     * Get player component HTML/attributes
     */
    public function get_player_component($playback_id, $token = null, $options = array());
    
    /**
     * Handle webhook events
     */
    public function handle_webhook($payload, $signature = null);
    
    /**
     * Normalize a raw provider stream object to a canonical shape.
     *
     * Every implementation must return an array with at least these keys:
     *   id          – provider stream ID
     *   name        – human-readable display name
     *   status      – 'active' | 'idle' | 'error' | 'unknown'
     *   stream_key  – RTMP stream key (empty string if not applicable)
     *   playback_id – primary playback identifier (empty string if none)
     *   created_at  – ISO-8601 / strtotime()-parseable string, or empty
     *
     * @param  array $raw  Raw stream object as returned by the provider API.
     * @return array       Normalized stream.
     */
    public function normalize_stream(array $raw): array;

    /**
     * Return field definitions for the "Create Stream" admin form.
     *
     * Each entry: [ 'key', 'label', 'type', 'placeholder'?, 'required'?,
     *               'options'? (for select), 'default'? ]
     * Return an empty array if the provider creates streams automatically
     * (e.g. OME where the encoder pushes RTMP directly).
     *
     * @return array
     */
    public function get_create_stream_fields(): array;

    /**
     * Return field definitions for the "Edit Stream" admin form.
     * Return an empty array if streams cannot be edited via the provider API.
     *
     * @return array
     */
    public function get_edit_stream_fields(): array;

    /**
     * Whether this provider supports adding simulcast targets (forwarding the
     * live stream to secondary RTMP destinations such as YouTube or Twitch).
     *
     * @return bool
     */
    public function supports_simulcast(): bool;

    /**
     * Get settings fields for admin
     */
    public function get_settings_fields();

    /**
     * Validate settings
     */
    public function validate_settings($settings);

    /**
     * Whether this provider requires periodic client-side token refresh.
     *
     * Return true  when tokens are short-lived and must be rotated while the
     *              stream is playing (e.g. OME Signed Policy URLs).
     * Return false when the token covers the full event duration and mid-stream
     *              refresh is either unsupported or unsafe (e.g. Mux RS256 JWT).
     *
     * The template exposes this as window.lemTokenRefreshEnabled so JS never
     * needs to branch on a vendor name.
     *
     * @return bool
     */
    public function supports_token_refresh();

    /**
     * Return additional admin sub-tabs to render inside this provider's vendor page.
     *
     * Each entry: 'slug' => [ 'label' => 'Tab Label', 'template' => '/abs/path/to/template.php' ]
     * Return an empty array if the provider has no extra tabs.
     *
     * @return array
     */
    public function get_extra_tabs();
}
