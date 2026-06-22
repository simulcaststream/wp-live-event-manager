<?php
/**
 * REST API, Stripe/Mux webhooks, watch-page access & chat token, template redirects.
 *
 * @package Live_Event_Manager
 */

trait LEM_Trait_Rest_And_Webhooks {

    /**
     * Decode a URL-safe base64 JWT segment, restoring padding stripped by the spec.
     * base64url uses '-' and '_' instead of '+' and '/', and omits '=' padding.
     *
     * @param string $segment The raw base64url-encoded segment (header, payload, or signature).
     * @return string|false Decoded bytes, or false on failure.
     */
    private function jwt_base64_decode(string $segment) {
        // Translate URL-safe characters back to standard base64 alphabet.
        $b64 = strtr($segment, '-_', '+/');
        // Re-add padding so strlen is a multiple of 4.
        $pad = (4 - (strlen($b64) % 4)) % 4;
        return base64_decode($b64 . str_repeat('=', $pad), true);
    }
    /**
     * Resolve a streaming provider for an admin REST request.
     *
     * By default, core delegates to the globally active streaming provider.
     * For admin tooling (Streams page), we allow overriding via `provider` request
     * param so admins can manage streams across vendors without changing the
     * global active provider.
     *
     * @param WP_REST_Request $request
     * @return object|WP_Error Provider instance or error.
     */
    private function streaming_provider_from_request_or_active($request) {
        $requested = sanitize_key($request->get_param('provider') ?? '');
        if ($requested === '') {
            return $this->active_streaming_provider_or_error();
        }

        // Only admins can override provider selection.
        if (!current_user_can('manage_options')) {
            return $this->active_streaming_provider_or_error();
        }

        $factory  = LEM_Streaming_Provider_Factory::get_instance();
        $provider = $factory->get_provider($requested, $this);
        if (!$provider) {
            return new WP_Error('unknown_provider', 'Streaming provider not registered', array('status' => 404));
        }
        if (!$provider->is_configured()) {
            return new WP_Error('provider_not_configured', 'Streaming provider not configured', array('status' => 400));
        }
        return $provider;
    }
    
    // Register REST routes
    public function register_rest_routes() {
        $ns = apply_filters('lem_rest_namespace', LEM_REST_NAMESPACE);

        register_rest_route($ns, '/check-jwt-status', array(
            'methods' => 'POST',
            'callback' => array($this, 'check_jwt_status'),
            'permission_callback' => function($request) {
                if (is_user_logged_in()) {
                    return true;
                }
                $nonce = $request->get_header('X-WP-Nonce') ?? $request->get_param('lem_nonce');
                return !empty($nonce) && wp_verify_nonce($nonce, 'wp_rest');
            }
        ));

        register_rest_route($ns, '/jwt-settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_jwt_settings'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

        register_rest_route($ns, '/live-streams', array(
            'methods' => 'GET',
            'callback' => array($this, 'list_live_streams'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

        register_rest_route($ns, '/live-streams', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_live_stream'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

        register_rest_route($ns, '/live-streams/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_live_stream'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

        register_rest_route($ns, '/live-streams/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_live_stream'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

        register_rest_route($ns, '/stream-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_stream_status'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

        register_rest_route($ns, '/rtmp-info', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_rtmp_info'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

        register_rest_route($ns, '/simulcast-targets', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_simulcast_targets'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

        register_rest_route($ns, '/simulcast-targets', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_simulcast_target'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

        register_rest_route($ns, '/simulcast-targets/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_simulcast_target'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

        register_rest_route($ns, '/webhooks/streaming/(?P<provider>[a-z0-9_-]+)', array(
            'methods'  => 'POST',
            'callback' => array($this, 'handle_streaming_webhook'),
            'permission_callback' => '__return_true',
        ));

        do_action('lem_rest_register_routes', $ns);
    }
    
    // Check admin permission for REST API (must be public — WordPress calls this from outside the class).
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }
    
    // REST endpoint for JWT revocation check (Cloudflare Worker - edge-optimized)
    public function check_jwt_status($request) {
        $this->debug_log('JWT revocation check request received', array(
            'method' => $request->get_method()
        ));
        
        // Get JWT token (only parameter needed for revocation check)
        $jwt_token = $request->get_param('jwt') ?: $request->get_param('token');
        
        // Log the request for security monitoring
        $this->debug_log('JWT revocation check parameters', array(
            'has_jwt' => !empty($jwt_token),
            'jwt_length' => strlen($jwt_token ?? '')
        ));
        
        // JWT token is required
        if (empty($jwt_token)) {
            $this->debug_log('JWT revocation check failed - missing JWT token');
            return new WP_Error('missing_jwt', 'JWT token is required', array('status' => 400));
        }
        
        // Check JWT revocation status only
        return $this->validate_jwt_for_worker($jwt_token);
    }
    
    // JWT revocation check for Cloudflare Worker (edge-optimized)
    private function validate_jwt_for_worker($jwt_token, $detected_ip = null, $user_agent = null, $referer = null) {
        $this->debug_log('JWT revocation check for Cloudflare Worker', array(
            'jwt_length' => strlen($jwt_token)
        ));
        
        try {
            // Decode JWT to extract JTI (signature verification done by Worker)
            $parts = explode('.', $jwt_token);
            if (count($parts) !== 3) {
                throw new \Exception('Invalid JWT format');
            }
            $payload = json_decode($this->jwt_base64_decode($parts[1]), true);
            if (!$payload) {
                throw new \Exception('Invalid JWT payload');
            }

            $jti = $payload['jti'] ?? null;
            if (!$jti) {
                throw new \Exception('JTI not found in JWT payload');
            }
            
            $this->debug_log('JWT payload extracted for revocation check', array(
                'jti' => $jti,
                'playback_id' => $payload['sub'] ?? 'none'
            ));
            
            // Redis-only JWT validation for Cloudflare Worker performance
            $redis = $this->get_redis_connection();
            
            if (!$redis) {
                $this->debug_log('Redis not available for JWT validation', array('jti' => $jti));
                return new WP_Error('redis_unavailable', 'Redis not available for JWT validation', array('status' => 503));
            }
            
            // Use pipeline to batch Redis calls (reduces network round-trips)
            $use_pipeline = method_exists($redis, 'pipeline');
            
            if ($use_pipeline) {
                $pipe = $redis->pipeline();
                $pipe->exists("revoked:{$jti}");
                $pipe->get("jwt:{$jti}");
                $results = $pipe->execute();
                $revoked_in_redis = $results[0] ?? false;
                $jwt_data = $results[1] ?? false;
            } else {
            $revoked_in_redis = $redis->exists("revoked:{$jti}");
                $jwt_data = $redis->get("jwt:{$jti}");
            }
            
            if ($revoked_in_redis) {
                $this->debug_log('JWT revoked in Redis', array('jti' => $jti));
                return array(
                    'revoked' => true,
                    'status' => 'revoked',
                    'message' => 'JWT token has been revoked',
                    'jti' => $jti,
                    'playback_id' => $payload['sub'] ?? null,
                    'device_identifier' => null,
                    'identifier_type' => null,
                    'identifier_value' => null,
                    'session_id' => null,
                    'ip_address' => null,
                    'fingerprint' => null,
                    'check_method' => 'redis'
                );
            }
            
            // Check if JWT exists and get its data from Redis
            if (!$jwt_data) {
                $this->debug_log('JWT not found in Redis', array('jti' => $jti));
                return array(
                    'revoked' => false,
                    'status' => 'not_found',
                    'message' => 'JWT token not found in Redis',
                    'jti' => $jti,
                    'playback_id' => $payload['sub'] ?? null,
                    'device_identifier' => null,
                    'identifier_type' => null,
                    'identifier_value' => null,
                    'session_id' => null,
                    'ip_address' => null,
                    'fingerprint' => null,
                    'check_method' => 'redis'
                );
            }
            
            $jwt_info = json_decode($jwt_data, true);
            
            // Check if JWT is expired using Redis data
            if (isset($jwt_info['expires_at']) && strtotime($jwt_info['expires_at']) < time()) {
                $this->debug_log('JWT expired in Redis', array('jti' => $jti, 'expires_at' => $jwt_info['expires_at']));
                return array(
                    'revoked' => false,
                    'status' => 'expired',
                    'message' => 'JWT token has expired',
                    'jti' => $jti,
                    'playback_id' => $payload['sub'] ?? null,
                    'device_identifier' => $jwt_info['device_identifier'] ?? null,
                    'identifier_type' => $jwt_info['identifier_type'] ?? null,
                    'identifier_value' => $jwt_info['identifier_value'] ?? null,
                    'session_id' => $jwt_info['session_id'] ?? null,
                    'ip_address' => $jwt_info['ip_address'] ?? null,
                    'fingerprint' => $jwt_info['fingerprint'] ?? null,
                    'check_method' => 'redis'
                );
            }
            
            // JWT is active and not revoked
            $this->debug_log('JWT validation passed (Redis-only)', array(
                'jti' => $jti,
                'playback_id' => $payload['sub'] ?? null
            ));
            
            return array(
                'revoked' => false,
                'status' => 'active',
                'jti' => $jti,
                'playback_id' => $payload['sub'] ?? null,
                'playback_restriction_id' => $payload['playback_restriction_id'] ?? null,
                'device_identifier' => $jwt_info['device_identifier'] ?? null,
                'identifier_type' => $jwt_info['identifier_type'] ?? 'session_based',
                'identifier_value' => $jwt_info['identifier_value'] ?? null,
                'session_id' => $jwt_info['session_id'] ?? null,
                'ip_address' => $jwt_info['ip_address'] ?? null, // Legacy field
                'fingerprint' => $jwt_info['fingerprint'] ?? null, // Legacy field
                'redis_available' => true,
                'check_method' => 'redis'
            );
            
        } catch (\Exception $e) {
            $this->debug_log('JWT revocation check error', array('error' => $e->getMessage()));
            return new WP_Error('jwt_check_error', 'JWT revocation check failed: ' . $e->getMessage(), array('status' => 400));
        }
    }
    
    // REST endpoint for JWT settings
    public function get_jwt_settings($request) {
        $settings = get_option('lem_settings', array());
        
        return array(
            'jwt_expiration_hours' => intval($settings['jwt_expiration_hours'] ?? 24),
            'jwt_refresh_duration_minutes' => intval($settings['jwt_refresh_duration_minutes'] ?? 15),
            'refresh_interval_minutes' => intval($settings['jwt_refresh_duration_minutes'] ?? 15) - 1
        );
    }
    
    /**
     * Get the active streaming provider, or a 503 WP_Error if none is configured.
     */
    private function active_streaming_provider_or_error() {
        $provider = $this->get_streaming_provider();
        if (!$provider) {
            return new WP_Error('no_streaming_provider', 'No streaming provider is registered. Install a provider plugin (e.g. LEM Free Adaptors).', array('status' => 503));
        }
        return $provider;
    }

    /**
     * Stream status — delegates to the active streaming provider.
     */
    public function get_stream_status($request) {
        $provider = $this->streaming_provider_from_request_or_active($request);
        if (is_wp_error($provider)) {
            return $provider;
        }
        return $provider->get_stream_status($request->get_param('stream_id'));
    }
    
    /**
     * RTMP info — delegates to the active streaming provider.
     */
    public function get_rtmp_info($request) {
        $provider = $this->streaming_provider_from_request_or_active($request);
        if (is_wp_error($provider)) {
            return $provider;
        }
        return $provider->get_rtmp_info($request->get_param('stream_id'));
    }
    
    /**
     * List streams — delegates to the active provider's list_streams().
     * Returns the same `{ data: [...], cached_at: ts }` shape the provider returns
     * (for Mux) or wraps the provider's array result for callers that expect it.
     */
    public function list_live_streams($request, $bypass_cache = false) {
        $provider = $this->streaming_provider_from_request_or_active($request);
        if (is_wp_error($provider)) {
            return $provider;
        }
        $limit  = (int) ($request->get_param('limit') ?: 100);
        $result = $provider->list_streams($limit);

        if (is_wp_error($result)) {
            return $result;
        }

        if (is_array($result) && isset($result['data'])) {
            return $result;
        }

        return array(
            'data'      => is_array($result) ? $result : array(),
            'cached_at' => time(),
        );
    }
    
    /**
     * Create a live stream — delegates to the active streaming provider.
     */
    public function create_live_stream($request) {
        $provider = $this->streaming_provider_from_request_or_active($request);
        if (is_wp_error($provider)) {
            return $provider;
        }
        $params = $request->get_params();
        return $provider->create_stream($params);
    }
    
    /**
     * Delete a live stream — delegates to the active streaming provider.
     */
    public function delete_live_stream($request) {
        $provider = $this->streaming_provider_from_request_or_active($request);
        if (is_wp_error($provider)) {
            return $provider;
        }
        $url_params = $request->get_url_params();
        $stream_id  = $url_params['id'] ?? $request->get_param('id');
        if (empty($stream_id)) {
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }
        return $provider->delete_stream($stream_id);
    }
    
    /**
     * Update a live stream — delegates to the active streaming provider.
     */
    public function update_live_stream($request) {
        $provider = $this->streaming_provider_from_request_or_active($request);
        if (is_wp_error($provider)) {
            return $provider;
        }
        $url_params = $request->get_url_params();
        $stream_id  = $url_params['id'] ?? $request->get_param('id');
        if (empty($stream_id)) {
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }
        $params = $request->get_params();
        unset($params['id']);
        return $provider->update_stream($stream_id, $params);
    }
    
    /**
     * List simulcast targets — delegates to the active streaming provider.
     */
    public function get_simulcast_targets($request) {
        $provider = $this->streaming_provider_from_request_or_active($request);
        if (is_wp_error($provider)) {
            return $provider;
        }
        $stream_id = $request->get_param('stream_id');
        $result    = $provider->list_simulcast_targets($stream_id ?: null);
        if (is_wp_error($result)) {
            return $result;
        }
        return is_array($result) ? $result : array();
    }

    /**
     * Create simulcast target — delegates to the active streaming provider.
     */
    public function create_simulcast_target($request) {
        $provider = $this->streaming_provider_from_request_or_active($request);
        if (is_wp_error($provider)) {
            return $provider;
        }
        $stream_id = $request->get_param('stream_id');
        $url       = $request->get_param('url');
        if (empty($stream_id) || empty($url)) {
            return new WP_Error('missing_params', 'Stream ID and URL are required', array('status' => 400));
        }
        return $provider->create_simulcast_target($stream_id, $url);
    }

    /**
     * Delete simulcast target — delegates to the active streaming provider.
     */
    public function delete_simulcast_target($request) {
        $provider = $this->streaming_provider_from_request_or_active($request);
        if (is_wp_error($provider)) {
            return $provider;
        }
        $stream_id = $request->get_param('stream_id');
        $target_id = $request->get_param('id');
        if (empty($stream_id) || empty($target_id)) {
            return new WP_Error('missing_params', 'Stream ID and target ID are required', array('status' => 400));
        }
        return $provider->delete_simulcast_target($stream_id, $target_id);
    }
    
    // Direct JWT validation method (legacy)
    private function validate_jwt_direct($jwt_token, $ip = null, $playback_id = null) {
        $this->debug_log('Validating JWT directly', array('jwt_length' => strlen($jwt_token)));
        
        try {
            // Check if JWT library is available
            if (!class_exists('\Firebase\JWT\JWT')) {
                $this->debug_log('JWT library not available');
                return new WP_Error('jwt_library_missing', 'JWT validation library not available', array('status' => 500));
            }
            
            // Decode JWT without verification first to get payload
            $parts = explode('.', $jwt_token);
            if (count($parts) !== 3) {
                throw new \Exception('Invalid JWT format');
            }
            $payload = json_decode($this->jwt_base64_decode($parts[1]), true);
            if (!$payload) {
                throw new \Exception('Invalid JWT payload');
            }
            
            $this->debug_log('JWT payload extracted', array(
                'jti' => $payload['jti'] ?? 'none',
                'exp' => $payload['exp'] ?? 'none',
                'sub' => $payload['sub'] ?? 'none'
            ));
            
            // Check if JWT is expired
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                $this->debug_log('JWT expired', array('exp' => $payload['exp'], 'current_time' => time()));
                return array(
                    'valid' => false,
                    'status' => 'expired',
                    'message' => 'JWT token has expired'
                );
            }
            
            // Check if JWT is revoked in Redis
            $redis = $this->get_redis_connection();
            if ($redis && isset($payload['jti'])) {
                if ($redis->exists("revoked:{$payload['jti']}")) {
                    $this->debug_log('JWT revoked in Redis', array('jti' => $payload['jti']));
                    return array(
                        'valid' => false,
                        'status' => 'revoked',
                        'message' => 'JWT token has been revoked'
                    );
                }
            }
            
            // Check if JWT exists in database
            global $wpdb;
            $table = $wpdb->prefix . 'lem_jwt_tokens';
            $token_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE jti = %s AND revoked_at IS NULL",
                $payload['jti'] ?? ''
            ));
            
            if (!$token_record) {
                $this->debug_log('JWT not found in database', array('jti' => $payload['jti'] ?? 'none'));
                return array(
                    'valid' => false,
                    'status' => 'not_found',
                    'message' => 'JWT token not found in database'
                );
            }
            
            // Check if JWT is expired in database
            if (strtotime($token_record->expires_at) < time()) {
                $this->debug_log('JWT expired in database', array(
                    'expires_at' => $token_record->expires_at,
                    'current_time' => gmdate('Y-m-d H:i:s')
                ));
                return array(
                    'valid' => false,
                    'status' => 'expired',
                    'message' => 'JWT token has expired'
                );
            }
            
            // Optional: Validate IP if provided (email removed for privacy)
            if ($ip && $token_record->ip_address !== $ip) {
                $this->debug_log('JWT IP mismatch', array(
                    'provided_ip' => $ip,
                    'token_ip' => $token_record->ip_address
                ));
                return array(
                    'valid' => false,
                    'status' => 'invalid',
                    'message' => 'IP address does not match JWT token'
                );
            }
            
            // JWT is valid
            $this->debug_log('JWT validation successful', array(
                'jti' => $payload['jti'],
                'email' => $token_record->email,
                'event_id' => $token_record->event_id
            ));
            
            return array(
                'valid' => true,
                'status' => 'active',
                'jti' => $payload['jti'],
                'email' => $token_record->email,
                'event_id' => $token_record->event_id,
                'playback_id' => $payload['sub'] ?? null,
                'playback_restriction_id' => $payload['playback_restriction_id'] ?? null,
                'expires_at' => $token_record->expires_at
            );
            
        } catch (\Exception $e) {
            $this->debug_log('JWT validation error', array('error' => $e->getMessage()));
            return new WP_Error('jwt_validation_error', 'JWT validation failed: ' . $e->getMessage(), array('status' => 400));
        }
    }
    
    // Hash-based JWT validation method (fallback)
    private function validate_jwt_by_hash($email, $ip, $playback_id) {
        $this->debug_log('Validating JWT by hash', array('email' => $this->redact_email($email), 'ip' => $ip, 'playback_id' => $playback_id));
        
        // Recreate hash JTI for Redis lookup
        $hash_jti = hash('sha256', $email . '|' . $ip . '|' . $playback_id);
        
        $redis = $this->get_redis_connection();
        if ($redis && $redis->exists("revoked:{$hash_jti}")) {
            $this->debug_log('JWT revoked by hash', array('hash_jti' => $hash_jti));
            return array(
                'valid' => false,
                'status' => 'revoked',
                'message' => 'JWT token has been revoked'
            );
        }
        
        // Check database for active token
        global $wpdb;
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        $token_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE hash_jti = %s AND revoked_at IS NULL AND expires_at > NOW()",
            $hash_jti
        ));
        
        if (!$token_record) {
            $this->debug_log('JWT not found by hash', array('hash_jti' => $hash_jti));
            return array(
                'valid' => false,
                'status' => 'not_found',
                'message' => 'No active JWT token found for these parameters'
            );
        }
        
        $this->debug_log('JWT validation successful by hash', array(
            'hash_jti' => $hash_jti,
            'email' => $token_record->email,
            'event_id' => $token_record->event_id
        ));
        
        return array(
            'valid' => true,
            'status' => 'active',
            'jti' => $token_record->jti,
            'email' => $token_record->email,
            'event_id' => $token_record->event_id,
            'playback_id' => $playback_id,
            'expires_at' => $token_record->expires_at
        );
    }
    
    // Test endpoint for JWT verification (development only)

    
    /**
     * Log a webhook event to {prefix}lem_webhook_log so admins can verify
     * Stripe / PayPal are reaching the endpoint without tailing debug.log.
     * Keeps only the most recent 200 rows.
     */
    private function log_webhook($status, $data = array()) {
        LEM_Webhook_Log::record($status, $data);
    }

    /**
     * Streaming/asset webhook dispatcher.
     *
     * Route: POST /lem/v1/webhooks/streaming/{provider}
     *
     * Looks up the provider by ID and delegates verification + handling to its
     * handle_webhook() method. Providers read vendor-specific signature headers
     * directly from $_SERVER if they need to.
     *
     * @param WP_REST_Request $request
     */
    public function handle_streaming_webhook($request) {
        $provider_id = $request->get_param('provider');
        $factory     = LEM_Streaming_Provider_Factory::get_instance();
        $provider    = $factory->get_provider($provider_id, $this);

        if (!$provider || $provider->get_id() !== $provider_id) {
            $this->log_webhook('failed', array(
                'provider' => $provider_id,
                'message'  => 'Streaming provider not registered',
            ));
            return new WP_REST_Response(array('error' => 'unknown provider'), 404);
        }

        $payload   = $request->get_body();
        $signature = $request->get_header('mux-signature') ?: null;

        $result = $provider->handle_webhook($payload, $signature);

        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            $http = in_array($code, array('invalid_payload', 'invalid_signature', 'missing_signature'), true) ? 400 : 500;
            $this->log_webhook('verification_failed', array(
                'provider' => $provider_id,
                'message'  => '[' . $code . '] ' . $result->get_error_message(),
            ));
            return new WP_REST_Response(array('error' => $result->get_error_message()), $http);
        }

        $this->log_webhook('processed', array(
            'provider'   => $provider_id,
            'event_type' => is_array($result) ? ($result['type'] ?? null) : null,
            'message'    => 'Streaming webhook handled by provider',
        ));

        do_action('lem_streaming_webhook_received', $provider_id, $result);

        return new WP_REST_Response(array('ok' => true), 200);
    }

    // Single payment webhook entry-point — routes to whichever provider is active.
    public function handle_payment_webhook() {
        $this->debug_log('Payment webhook received');

        // Auto-detect provider from request headers so a single webhook URL works for
        // both Stripe and PayPal regardless of which one is configured as the global default.
        $factory = LEM_Payment_Provider_Factory::get_instance();
        $detected_via_header = false;
        if (isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            $provider = $factory->get_provider('stripe');
            $detected_via_header = true;
        } elseif (isset($_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'])) {
            $provider = $factory->get_provider('paypal');
            $detected_via_header = true;
        } else {
            $provider = $factory->get_active_provider();
        }

        $provider_id = $provider ? $provider->get_id() : null;

        // Log entry: every webhook hit, even invalid ones
        $this->log_webhook('received', array(
            'provider'      => $provider_id,
            'has_signature' => $detected_via_header,
            'message'       => $detected_via_header
                ? 'Provider detected from request header'
                : 'No provider header detected — using active default',
        ));

        if (!$provider) {
            $this->log_webhook('failed', array('message' => 'Payment provider not available'));
            status_header(500);
            wp_die('Payment provider not available', 'Webhook Error', array('response' => 500));
        }

        $parsed = $provider->verify_webhook();

        if (is_wp_error($parsed)) {
            $code = $parsed->get_error_code();
            $http = in_array($code, array('invalid_payload', 'invalid_signature', 'missing_signature'), true) ? 400 : 500;
            $this->debug_log('Webhook verification failed: ' . $parsed->get_error_message());
            $this->log_webhook('verification_failed', array(
                'provider'      => $provider_id,
                'has_signature' => $detected_via_header,
                'message'       => '[' . $code . '] ' . $parsed->get_error_message(),
            ));
            status_header($http);
            wp_die($parsed->get_error_message(), 'Webhook Error', array('response' => $http));
        }

        $this->debug_log('Webhook event type: ' . ($parsed['type'] ?? 'unknown'));

        // Allow companion plugins to handle non-checkout events.
        do_action('lem_webhook_event_received', $parsed, $provider->get_id());

        if (($parsed['type'] ?? '') !== 'checkout.completed') {
            $this->log_webhook('skipped', array(
                'provider'   => $provider_id,
                'event_type' => $parsed['type'] ?? 'unknown',
                'message'    => 'Non-checkout event — acknowledged but not processed',
            ));
            status_header(200);
            wp_die('OK', '', array('response' => 200));
        }

        $payment_id = $parsed['payment_id'] ?? null;
        $event_id   = $parsed['event_id']   ?? null;
        $email      = $parsed['email']       ?? null;

        if (!$event_id || !$email) {
            $this->debug_log('Webhook: missing event_id or email', array(
                'payment_id' => $payment_id,
                'event_id'   => $event_id,
                'has_email'  => !empty($email),
            ));
            $this->log_webhook('missing_metadata', array(
                'provider'   => $provider_id,
                'event_type' => $parsed['type'] ?? null,
                'payment_id' => $payment_id,
                'event_id'   => $event_id,
                'email'      => $email,
                'message'    => 'Webhook verified but missing event_id or email in metadata. Check checkout session metadata.',
            ));
            status_header(200);
            wp_die('OK', '', array('response' => 200));
        }

        $this->debug_log('Processing payment for event: ' . $event_id . ', email: ' . $this->redact_email($email));

        $fulfill = $this->fulfill_paid_checkout($payment_id, $event_id, $email, $provider_id, 'webhook');

        if (is_wp_error($fulfill)) {
            $this->log_webhook('jwt_failed', array(
                'provider'   => $provider_id,
                'event_type' => $parsed['type'],
                'payment_id' => $payment_id,
                'event_id'   => $event_id,
                'email'      => $email,
                'message'    => 'JWT generation failed: ' . $fulfill->get_error_message(),
            ));
            // Return 500 so Stripe automatically retries the webhook delivery.
            // On success the handler returns 200 below, which stops retries.
            status_header(500);
            wp_die('JWT generation failed', 'Webhook Error', array('response' => 500));
        } elseif (is_array($fulfill)) {
            switch ($fulfill['status']) {
                case 'duplicate':
                    $this->log_webhook('duplicate', array(
                        'provider'   => $provider_id,
                        'event_type' => $parsed['type'],
                        'payment_id' => $payment_id,
                        'event_id'   => $event_id,
                        'email'      => $email,
                        'message'    => 'Duplicate payment — JWT already issued (jti: ' . ($fulfill['jti'] ?? '?') . ')',
                    ));
                    break;
                case 'already_has_access':
                    $this->log_webhook('already_has_access', array(
                        'provider'   => $provider_id,
                        'event_type' => $parsed['type'],
                        'payment_id' => $payment_id,
                        'event_id'   => $event_id,
                        'email'      => $email,
                        'message'    => 'Email already has a valid ticket for this event',
                    ));
                    break;
                case 'granted':
                    $this->log_webhook('processed', array(
                        'provider'   => $provider_id,
                        'event_type' => $parsed['type'],
                        'payment_id' => $payment_id,
                        'event_id'   => $event_id,
                        'email'      => $email,
                        'message'    => 'JWT issued (jti: ' . ($fulfill['jti'] ?? '?') . ') and magic link emailed',
                    ));
                    break;
            }
        }

        status_header(200);
        wp_die('OK', '', array('response' => 200));
    }

    // Get client IP address (handles proxies and load balancers)
    private function get_client_ip() {
        // Only trust proxy headers if explicitly configured (prevents IP spoofing)
        $trust_proxy = defined('LEM_TRUST_PROXY_HEADERS') && LEM_TRUST_PROXY_HEADERS;

        if ($trust_proxy) {
            $ip_keys = array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR');
        } else {
            $ip_keys = array('REMOTE_ADDR');
        }

        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    // First try to find a public IP
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        // If no public IP found, try to get any valid IP (including private ones)
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        // Final fallback
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Simple transient-based rate limiting for public AJAX endpoints.
     */
    private function check_rate_limit($action, $limit_seconds = 3) {
        $ip = $this->get_client_ip();
        $key = 'lem_rl_' . md5($action . $ip);
        if (get_transient($key)) {
            return false; // rate limited
        }
        set_transient($key, 1, $limit_seconds);
        return true; // allowed
    }

    /**
     * Redact an email address for safe logging.
     */
    public function redact_email($email) {
        if (empty($email) || !is_string($email)) return '[empty]';
        $parts = explode('@', $email);
        if (count($parts) !== 2) return '[invalid]';
        return substr($parts[0], 0, 3) . '***@' . $parts[1];
    }

    // Send magic link email
    private function send_magic_link_email($email, $jwt, $event_id, $session_id = null) {
        return $this->magic_link_service->send_magic_link_email($email, $jwt, $event_id, $session_id);
    }
    
    // Generate one-time magic token
    public function generate_magic_token($email, $event_id, $session_id = null) {
        return $this->magic_link_service->generate_magic_token($email, $event_id, $session_id);
    }
    
    public function validate_magic_token($token) {
        return $this->magic_link_service->validate_magic_token($token);
    }
    
    // Create session for magic token
    private function create_session_for_magic_token($email, $event_id) {
        return $this->create_session($event_id, $email);
    }
    
    // Send new magic link for device change
    private function send_new_device_magic_link($email, $event_id, $session_id) {
        return $this->magic_link_service->send_new_device_magic_link($email, $event_id, $session_id);
    }
    
    public function validate_email_and_send_link($email, $event_id) {
        return $this->magic_link_service->validate_email_and_send_link($email, $event_id);
    }
    
    // Check if there's a valid ticket for email/event
    private function check_valid_ticket($email, $event_id) {
        return $this->magic_link_service->has_valid_ticket($email, $event_id);
    }

    private function cache_session_status($event_id, $email, $valid) {
        $this->magic_link_service->cache_session_status($event_id, $email, $valid);
    }
    

    
    // Get active session for email and event
    private function get_active_session_for_email_event($email, $event_id) {
        return $this->magic_link_service->get_active_session_for_email_event($email, $event_id);
    }
    
    public function get_event_access_state($event_id) {
        $event_id = intval($event_id);

        if ($event_id <= 0) {
            return array(
                'event' => null,
                'can_watch' => false,
                'session_id' => '',
                'jwt_token' => '',
                'email' => '',
                'chat_name' => '',
                'error_message' => __('Missing event context.', 'live-event-manager'),
                'success_message' => ''
            );
        }

        // Level 1: In-memory cache (current request only)
        if (isset($this->event_access_cache[$event_id])) {
            return $this->event_access_cache[$event_id];
        }

        // Level 2: Redis cache per session (shared across requests)
        $session_id = $_COOKIE['lem_session_id'] ?? '';
        $redis = $this->get_redis_connection();
        
        if ($redis && !empty($session_id)) {
            $cache_key = "event_access:{$event_id}:{$session_id}";
            $cached = $redis->get($cache_key);
            
            if ($cached !== false) {
                $state = json_decode($cached, true);
                if ($state) {
                    // Re-validate JWT if cached state says user can watch
                    // This ensures revoked JWTs don't allow access
                    if ($state['can_watch'] && !empty($state['jwt_token'])) {
                        // Extract JTI from JWT to check revocation
                        $jwt_parts = explode('.', $state['jwt_token']);
                        if (count($jwt_parts) === 3) {
                            $payload = json_decode($this->jwt_base64_decode($jwt_parts[1]), true);
                            $jti = $payload['custom']['jti'] ?? '';
                            
                            if (!empty($jti)) {
                                // Check if JWT is revoked
                                $is_revoked = $redis->exists("revoked:{$jti}");
                                if ($is_revoked) {
                                    // JWT was revoked, clear cache and recalculate
                                    $redis->del($cache_key);
                                    unset($this->event_access_cache[$event_id]);
                                    $this->debug_log('Cached access state invalidated due to revoked JWT', array(
                                        'jti' => $jti,
                                        'event_id' => $event_id,
                                        'session_id' => $session_id
                                    ));
                                    // Fall through to recalculate below
                                } else {
                                    // JWT is still valid, use cached state
                                    $this->event_access_cache[$event_id] = $state;
                                    return $state;
                                }
                            } else {
                                // Can't extract JTI, use cached state (shouldn't happen)
                                $this->event_access_cache[$event_id] = $state;
                                return $state;
                            }
                        } else {
                            // Invalid JWT format, use cached state
                            $this->event_access_cache[$event_id] = $state;
                            return $state;
                        }
                    } else {
                        // Cached state says no access, use it
                        $this->event_access_cache[$event_id] = $state;
                        return $state;
                    }
                }
            }
        }

        // Level 3: Calculate (cache miss)
        $state = array(
            'event' => $this->get_event_by_id($event_id), // This is now cached
            'can_watch' => false,
            'session_id' => '',
            'jwt_token' => '',
            'email' => '',
            'chat_name' => '',
            'error_message' => '',
            'success_message' => ''
        );

        if (isset($_GET['lem_error'])) {
            $state['error_message'] = sanitize_text_field(wp_unslash($_GET['lem_error']));
        }

        if (isset($_GET['lem_success']) && $_GET['lem_success'] === '1') {
            $state['success_message'] = __('Access confirmed. Enjoy the stream!', 'live-event-manager');
        }

        if (!empty($session_id)) {
            $session_validation = $this->validate_session($session_id); // Now optimized with caching

            if (!empty($session_validation['valid'])) {
                $session = $session_validation['session'];
                $event_matches = isset($session['event_id']) && (string) $session['event_id'] === (string) $event_id;

                if ($event_matches) {
                    $jwt_token = $this->get_jwt_for_session($session_id);

                    if (!empty($jwt_token)) {
                        $state['can_watch'] = true;
                        $state['session_id'] = $session_id;
                        $state['jwt_token'] = $jwt_token;
                        $state['event'] = $session_validation['event'] ?: $state['event'];
                        $state['email'] = $session['email'] ?? '';
                        $state['chat_name'] = $session['chat_name'] ?? '';
                    } else {
                        $state['error_message'] = __('Unable to fetch stream access token. Request a new link to continue.', 'live-event-manager');
                    }
                } else {
                    $state['error_message'] = __('Your active session is linked to a different event. Request a new link for this stream.', 'live-event-manager');
                }
            } else {
                $error = $session_validation['error'] ?? '';
                $state['error_message'] = $error ?: __('Session expired. Request a new link to continue.', 'live-event-manager');

                // Only destroy the cookie for definitive/terminal session failures.
                // Transient errors (Redis unavailable, connection timeout) should
                // NOT wipe the cookie — the user still has valid access and will
                // recover on the next page load once Redis is back.
                $transient_error = (
                    stripos($error, 'Redis') !== false ||
                    stripos($error, 'connection') !== false ||
                    stripos($error, 'unavailable') !== false
                );

                if (!$transient_error) {
                    $this->clear_session_cookie();
                }
            }
        }

        // Cache result in Redis (5 minute TTL - session-based)
        if ($redis && !empty($session_id)) {
            $cache_key = "event_access:{$event_id}:{$session_id}";
            $ttl = $state['can_watch'] ? 300 : 30; // 5 min for valid, 30 sec for errors
            $redis->setex($cache_key, $ttl, json_encode($state));
        }

        // Allow companion plugins to augment or override the access state.
        $state = (array) apply_filters('lem_event_access_state', $state, $event_id);

        // Store in memory cache
        $this->event_access_cache[$event_id] = $state;

        return $state;
    }

    /**
     * Primary playback string for the player (Mux JWT or OME WebRTC URL) from lem:playback.
     */
    public function get_jwt_for_session($session_id) {
        $memory_key = "jwt_session:{$session_id}";
        if (isset(self::$memory_cache[$memory_key])) {
            return self::$memory_cache[$memory_key];
        }

        $redis = $this->get_redis_connection();
        if (!$redis) {
            return null;
        }

        $session_data_json = $redis->get("session:{$session_id}");
        if (!$session_data_json) {
            return null;
        }

        $session_data = json_decode($session_data_json, true);
        if (!$session_data) {
            return null;
        }
        if (isset($session_data['active']) && !$session_data['active']) {
            return null;
        }

        $email    = $session_data['email'] ?? '';
        $event_id = $session_data['event_id'] ?? 0;
        if ($email === '' || !$event_id) {
            return null;
        }

        if (class_exists('LEM_Access') && LEM_Access::is_email_revoked_for_event($email, $event_id)) {
            return null;
        }

        $key = LEM_Access::playback_key($email, $event_id);
        $raw = $redis->get($key);
        if (!$raw) {
            $this->ensure_playback_blob($email, $event_id);
            $raw = $redis->get($key);
        }
        if (!$raw) {
            return null;
        }

        $blob = json_decode($raw, true);
        if (!is_array($blob)) {
            return null;
        }

        $token = '';
        if (($blob['vendor'] ?? '') === 'mux') {
            $token = $blob['mux_jwt'] ?? '';
        } elseif (($blob['vendor'] ?? '') === 'ome') {
            $token = $blob['jwt'] ?? '';
        } else {
            $token = $blob['mux_jwt'] ?? $blob['jwt'] ?? '';
        }

        if ($token !== '') {
            self::$memory_cache[$memory_key] = $token;
        }

        return $token !== '' ? $token : null;
    }

    /**
     * Full playback blob for OME templates (policy, llhls, etc.).
     */
    public function get_playback_blob_for_session($session_id) {
        $redis = $this->get_redis_connection();
        if (!$redis) {
            return null;
        }
        $session_data_json = $redis->get("session:{$session_id}");
        if (!$session_data_json) {
            return null;
        }
        $session_data = json_decode($session_data_json, true);
        if (!$session_data || empty($session_data['email']) || empty($session_data['event_id'])) {
            return null;
        }
        $raw = $redis->get(LEM_Access::playback_key($session_data['email'], $session_data['event_id']));
        if (!$raw) {
            $this->ensure_playback_blob($session_data['email'], $session_data['event_id']);
            $raw = $redis->get(LEM_Access::playback_key($session_data['email'], $session_data['event_id']));
        }
        $blob = $raw ? json_decode($raw, true) : null;
        return is_array($blob) ? $blob : null;
    }

    /**
     * Clear in-memory cache (useful for testing or when data changes)
     */
    public static function clear_memory_cache() {
        self::$memory_cache = array();
    }
    
    /**
     * Get memory cache stats (for debugging)
     */
    public static function get_memory_cache_stats() {
        return array(
            'keys' => count(self::$memory_cache),
            'size' => strlen(serialize(self::$memory_cache))
        );
    }

    
    // Handle confirmation page redirects
    public function handle_confirmation_redirect() {
        // Check if this is a confirmation page request
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/confirmation') === 0) {
            $this->debug_log('Confirmation redirect handler triggered', array('uri' => $_SERVER['REQUEST_URI']));
            
            // Load the confirmation template directly
            $confirmation_template = LEM_Template_Manager::resolve_template_file('confirmation-page.php');
            if (file_exists($confirmation_template)) {
                // Set status to 200 to prevent 404
                status_header(200);
                
                // Include the confirmation template
                include $confirmation_template;
                exit;
            }
        }
    }
    
    // Handle events page redirects
    public function handle_events_redirect() {
        if (is_admin()) {
            return;
        }

        // Never override the single event template
        if (is_singular('lem_event')) {
            return;
        }

        $should_render_events = false;

        // Respect rewrite/query detection first
        if (is_post_type_archive('lem_event') || get_query_var('lem_events_page')) {
            $should_render_events = true;
        } else {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $request_path = $request_uri ? parse_url($request_uri, PHP_URL_PATH) : '';

            if ($request_path !== null && $request_path !== '') {
                $home_path = parse_url(home_url('/'), PHP_URL_PATH) ?: '';

                if (!empty($home_path) && strpos($request_path, $home_path) === 0) {
                    $request_path = substr($request_path, strlen($home_path));
                }

                $trimmed_path = trim($request_path, '/');

                if ($trimmed_path === 'events') {
                    $should_render_events = true;
                }
            }
        }

        if (!$should_render_events) {
            return;
        }

        $this->debug_log('Events redirect handler triggered', array('uri' => $_SERVER['REQUEST_URI'] ?? '')); 

        $events_template = LEM_Template_Manager::resolve_template_file('page-events.php');
        if (file_exists($events_template)) {
            status_header(200);
            include $events_template;
            exit;
        }
    }
    
    /**
     * Issue a short-lived Ably TokenRequest for an authenticated viewer.
     *
     * Validates the lem_session_id cookie + event access before signing.
     * The token is scoped to a single channel so viewers cannot subscribe
     * to other events' chat rooms.
     */
    public function ajax_ably_token() {
        check_ajax_referer('lem_nonce', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) {
            wp_send_json_error('Missing event_id', 400);
            return;
        }

        // Require a valid watch session.
        $session_id = $_COOKIE['lem_session_id'] ?? '';
        if (empty($session_id)) {
            wp_send_json_error('No active session', 403);
            return;
        }

        $access = $this->get_event_access_state($event_id);
        if (empty($access['can_watch'])) {
            wp_send_json_error('Access denied', 403);
            return;
        }

        $chat = LEM_Chat_Provider_Factory::get_instance()->get_active_provider();
        if ($chat === null || ! $chat->is_configured()) {
            wp_send_json_error('Chat not configured', 503);
            return;
        }

        $token = $chat->issue_viewer_token(
            (string) $event_id,
            (string) ( $access['email'] ?? '' ),
            (string) ( $access['chat_name'] ?? 'Viewer' ),
            array( 'client_id' => (string) ( $access['session_id'] ?? $session_id ) )
        );

        if (is_wp_error($token)) {
            wp_send_json_error($token->get_error_message(), 503);
            return;
        }

        unset($token['channel'], $token['expires_at']);
        wp_send_json_success($token);
    }

    // AJAX handler for saving JWT settings
    public function ajax_save_jwt_settings() {
        try {
            $this->debug_log('JWT settings save request started');
            
            // Verify nonce for security
            check_ajax_referer('lem_nonce', 'nonce');
            
            // Check permissions
            if (!current_user_can('manage_options')) {
                $this->debug_log('JWT settings save failed - insufficient permissions');
                wp_send_json_error('Insufficient permissions');
                return;
            }
            
            // Get current settings
            $settings = get_option('lem_settings', array());
            
            // Validate and sanitize input
            $jwt_expiration_hours = intval($_POST['jwt_expiration_hours'] ?? 24);
            $jwt_refresh_duration_minutes = intval($_POST['jwt_refresh_duration_minutes'] ?? 15);
            
            // Validate ranges
            if ($jwt_expiration_hours < 1 || $jwt_expiration_hours > 168) {
                wp_send_json_error('Initial JWT expiration must be between 1 and 168 hours');
                return;
            }
            
            if ($jwt_refresh_duration_minutes < 5 || $jwt_refresh_duration_minutes > 60) {
                wp_send_json_error('JWT refresh duration must be between 5 and 60 minutes');
                return;
            }
            
            // Update settings
            $settings['jwt_expiration_hours'] = $jwt_expiration_hours;
            $settings['jwt_refresh_duration_minutes'] = $jwt_refresh_duration_minutes;
            
            // Save settings
            $result = update_option('lem_settings', $settings);
            
            if ($result) {
                $this->debug_log('JWT settings saved successfully', array(
                    'jwt_expiration_hours' => $jwt_expiration_hours,
                    'jwt_refresh_duration_minutes' => $jwt_refresh_duration_minutes
                ));
                
                wp_send_json_success('JWT settings saved successfully');
            } else {
                $this->debug_log('JWT settings save failed - database error');
                wp_send_json_error('Failed to save settings to database');
            }
            
        } catch (Exception $e) {
            $this->debug_log('JWT settings save exception', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error('JWT settings save failed: ' . $e->getMessage());
        }
    }
}
