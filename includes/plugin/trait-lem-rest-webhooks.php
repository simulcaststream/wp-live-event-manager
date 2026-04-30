<?php
/**
 * REST API, Stripe/Mux webhooks, watch-page access & chat token, template redirects.
 *
 * @package Live_Event_Manager
 */

trait LEM_Trait_Rest_And_Webhooks {
    
    // Register REST routes
    public function register_rest_routes() {
        register_rest_route('lem/v1', '/check-jwt-status', array(
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
        
        register_rest_route('lem/v1', '/jwt-settings', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_jwt_settings'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/live-streams', array(
            'methods' => 'GET',
            'callback' => array($this, 'list_live_streams'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/live-streams', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_live_stream'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/live-streams/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_live_stream'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/live-streams/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_live_stream'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/stream-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_stream_status'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/rtmp-info', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_rtmp_info'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/simulcast-targets', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_simulcast_targets'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/simulcast-targets', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_simulcast_target'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));
        
        register_rest_route('lem/v1', '/simulcast-targets/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_simulcast_target'),
            'permission_callback' => array($this, 'check_admin_permission')
        ));

    }
    
    // Check admin permission for REST API
    private function check_admin_permission() {
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
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
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
     * Get Mux stream status (active/idle) for dual-state player
     */
    public function get_stream_status($request) {
        $settings = get_option('lem_settings', array());
        $live_stream_id = $request->get_param('stream_id') ?: ($settings['mux_live_stream_id'] ?? '');
        
        if (empty($live_stream_id)) {
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }
        
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        // Call Mux API to get live stream status
        $url = "https://api.mux.com/video/v1/live-streams/{$live_stream_id}";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            )
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || isset($data['error'])) {
            return new WP_Error('mux_api_error', 'Failed to fetch stream status', array('status' => 500));
        }
        
        $status = $data['data']['status'] ?? 'idle';
        $is_active = $status === 'active';
        
        // Get most recent asset if stream is idle
        $recent_asset = null;
        if (!$is_active) {
            $recent_asset = $this->get_most_recent_asset($live_stream_id, $credentials);
        }
        
        return array(
            'stream_id' => $live_stream_id,
            'status' => $status,
            'is_active' => $is_active,
            'recent_asset' => $recent_asset
        );
    }
    
    /**
     * Get RTMP stream key and ingest URL (cached for 1 hour)
     */
    public function get_rtmp_info($request) {
        $settings = get_option('lem_settings', array());
        $live_stream_id = $request->get_param('stream_id') ?: ($settings['mux_live_stream_id'] ?? '');
        
        if (empty($live_stream_id)) {
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }
        
        // Cache RTMP info (rarely changes)
        $redis = $this->get_redis_connection();
        $cache_key = "mux:rtmp_info:{$live_stream_id}";
        
        if ($redis) {
            $cached = $redis->get($cache_key);
            if ($cached !== false) {
                return json_decode($cached, true);
            }
        }
        
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        // Call Mux API to get live stream info
        $url = "https://api.mux.com/video/v1/live-streams/{$live_stream_id}";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 3
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || isset($data['error'])) {
            return new WP_Error('mux_api_error', 'Failed to fetch RTMP info', array('status' => 500));
        }
        
        $stream_data = $data['data'] ?? array();
        
        $result = array(
            'stream_key' => $stream_data['stream_key'] ?? '',
            'ingest_url' => $stream_data['reconnect_window'] ? 
                ($stream_data['reconnect_window']['ingest_url'] ?? '') : 
                ($stream_data['ingest_url'] ?? ''),
            'playback_id' => $stream_data['playback_ids'][0]['id'] ?? ''
        );
        
        // Cache for 1 hour (RTMP credentials rarely change)
        if ($redis) {
            $redis->setex($cache_key, 3600, json_encode($result));
        }
        
        return $result;
    }
    
    /**
     * List all live streams from Mux
     */
    public function list_live_streams($request, $bypass_cache = false) {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }

        $redis     = $this->get_redis_connection();
        $cache_key = 'mux:live_streams_list';

        // Check cache unless caller wants fresh data
        if (!$bypass_cache && $redis) {
            $cached = $redis->get($cache_key);
            if ($cached !== false) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
                // Corrupt entry — clear it and fall through to API
                $redis->del($cache_key);
            }
        }

        // Call Mux API
        $url      = 'https://api.mux.com/video/v1/live-streams?limit=100';
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret']),
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body      = wp_remote_retrieve_body($response);
        $data      = json_decode($body, true);

        if ($http_code !== 200 || !is_array($data)) {
            $msg = isset($data['error']['messages'][0]) ? $data['error']['messages'][0] : "HTTP {$http_code}: {$body}";
            return new WP_Error('mux_api_error', $msg, array('status' => $http_code));
        }

        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Mux API error', array('status' => 500));
        }

        $result = array(
            'data'      => $data['data'] ?? array(),
            'cached_at' => time(),
        );

        // Cache for 60 seconds — short enough to stay fresh, long enough to avoid hammering the API
        if ($redis) {
            $redis->setex($cache_key, 60, json_encode($result));
        }

        return $result;
    }
    
    /**
     * Create a new live stream via Mux API
     */
    public function create_live_stream($request) {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        $passthrough = $request->get_param('passthrough') ?: '';
        $reduced_latency = $request->get_param('reduced_latency');
        $test_mode = $request->get_param('test_mode');
        
        // Get playback_policies for live stream from request, default to 'public' if not provided
        $playback_policies = $request->get_param('playback_policies');
        if (empty($playback_policies) || !is_array($playback_policies)) {
            $playback_policies = array('public');
        }
        
        // Ensure playback_policies is an array
        if (is_string($playback_policies)) {
            $playback_policies = array($playback_policies);
        }
        
        // Validate playback policies (only allow 'public', 'signed', 'drm')
        $valid_policies = array('public', 'signed', 'drm');
        $playback_policies = array_filter($playback_policies, function($policy) use ($valid_policies) {
            return in_array($policy, $valid_policies);
        });
        
        // If no valid policies, default to 'public'
        if (empty($playback_policies)) {
            $playback_policies = array('public');
        }
        
        // Get asset_playback_policies for recorded assets from request, default to 'public' if not provided
        $asset_playback_policies = $request->get_param('asset_playback_policies');
        if (empty($asset_playback_policies) || !is_array($asset_playback_policies)) {
            // If not provided, use the same as playback_policies
            $asset_playback_policies = $playback_policies;
        }
        
        // Ensure asset_playback_policies is an array
        if (is_string($asset_playback_policies)) {
            $asset_playback_policies = array($asset_playback_policies);
        }
        
        // Validate asset playback policies (only allow 'public', 'signed', 'drm')
        $asset_playback_policies = array_filter($asset_playback_policies, function($policy) use ($valid_policies) {
            return in_array($policy, $valid_policies);
        });
        
        // If no valid policies, default to 'public'
        if (empty($asset_playback_policies)) {
            $asset_playback_policies = array('public');
        }
        
        // Convert to boolean if needed
        if ($reduced_latency === '1' || $reduced_latency === 'true' || $reduced_latency === true) {
            $reduced_latency = true;
        } else {
            $reduced_latency = false;
        }
        
        if ($test_mode === '1' || $test_mode === 'true' || $test_mode === true) {
            $test_mode = true;
        } else {
            $test_mode = false;
        }
        
        $payload = array();
        
        // Ensure arrays are properly indexed (not associative)
        $playback_policies = array_values($playback_policies);
        $asset_playback_policies = array_values($asset_playback_policies);
        
        // Set playback_policies for the live stream
        $payload['playback_policies'] = $playback_policies;
        
        // Set playback_policies for recorded assets (separate from live stream)
        $payload['new_asset_settings'] = array(
            'playback_policies' => $asset_playback_policies
        );
        
        if (!empty($passthrough)) {
            $payload['passthrough'] = $passthrough;
        }
        if ($reduced_latency) {
            $payload['reduced_latency'] = true;
        }
        if ($test_mode) {
            $payload['test'] = true;
        }
        
        // Log the payload for debugging
        $this->debug_log('Creating Mux stream with payload', array(
            'payload' => $payload,
            'json' => json_encode($payload, JSON_PRETTY_PRINT)
        ));
        
        // Call Mux API to create live stream
        $url = 'https://api.mux.com/video/v1/live-streams';
        $json_body = json_encode($payload);
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret']),
                'Content-Type' => 'application/json'
            ),
            'body' => $json_body,
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to create live stream', array('status' => 500));
        }
        
        // Clear cache
        $redis = $this->get_redis_connection();
        if ($redis) {
            $redis->del('mux:live_streams_list');
        }
        
        // Return the stream data - Mux API wraps it in 'data' key
        $stream_data = $data['data'] ?? $data;
        
        // Ensure we return the full stream object with ID and playback_ids
        if (is_array($stream_data)) {
            if (!isset($stream_data['id']) && isset($data['data']['id'])) {
                $stream_data['id'] = $data['data']['id'];
            }
            // Ensure playback_ids are included
            if (!isset($stream_data['playback_ids']) && isset($data['data']['playback_ids'])) {
                $stream_data['playback_ids'] = $data['data']['playback_ids'];
            }
        }
        
        return $stream_data;
    }
    
    /**
     * Delete a live stream via Mux API
     */
    public function delete_live_stream($request) {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        // Get stream ID from URL parameters (REST route pattern: /live-streams/(?P<id>...)
        $url_params = $request->get_url_params();
        $stream_id = $url_params['id'] ?? '';
        
        // Fallback: try regular param
        if (empty($stream_id)) {
            $stream_id = $request->get_param('id');
        }
        
        if (empty($stream_id)) {
            $this->debug_log('Delete stream: Missing stream ID', array(
                'url_params' => $url_params,
                'all_params' => $request->get_params()
            ));
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }
        
        // Call Mux API to delete live stream
        $url = "https://api.mux.com/video/v1/live-streams/{$stream_id}";
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        // Clear cache
        $redis = $this->get_redis_connection();
        if ($redis) {
            $redis->del('mux:live_streams_list');
        }
        
        if ($code === 204 || $code === 200) {
            return array('success' => true, 'message' => 'Stream deleted successfully');
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to delete live stream', array('status' => 500));
        }
        
        return array('success' => true);
    }
    
    /**
     * Update a live stream via Mux API
     */
    public function update_live_stream($request) {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        // Get stream ID from URL parameters (REST route pattern: /live-streams/(?P<id>...)
        $url_params = $request->get_url_params();
        $stream_id = $url_params['id'] ?? '';
        
        // Fallback: try regular param
        if (empty($stream_id)) {
            $stream_id = $request->get_param('id');
        }
        
        if (empty($stream_id)) {
            $this->debug_log('Update stream: Missing stream ID', array(
                'url_params' => $url_params,
                'all_params' => $request->get_params()
            ));
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }
        
        $passthrough = $request->get_param('passthrough');
        $reduced_latency = $request->get_param('reduced_latency');
        
        // Convert to boolean if needed
        if ($reduced_latency === '1' || $reduced_latency === 'true' || $reduced_latency === true) {
            $reduced_latency = true;
        } else {
            $reduced_latency = false;
        }
        
        $payload = array();
        if ($passthrough !== null && $passthrough !== '') {
            $payload['passthrough'] = $passthrough;
        }
        if ($reduced_latency !== null) {
            $payload['reduced_latency'] = $reduced_latency;
        }
        
        if (empty($payload)) {
            return new WP_Error('missing_params', 'No update parameters provided', array('status' => 400));
        }
        
        // Call Mux API to update live stream
        $url = "https://api.mux.com/video/v1/live-streams/{$stream_id}";
        $response = wp_remote_request($url, array(
            'method' => 'PUT',
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret']),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($payload),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to update live stream', array('status' => 500));
        }
        
        // Clear cache
        $redis = $this->get_redis_connection();
        if ($redis) {
            $redis->del('mux:live_streams_list');
        }
        
        return $data['data'] ?? $data;
    }
    
    /**
     * Get Simulcast targets
     */
    public function get_simulcast_targets($request) {
        $settings = get_option('lem_settings', array());
        $live_stream_id = $request->get_param('stream_id') ?: ($settings['mux_live_stream_id'] ?? '');
        
        if (empty($live_stream_id)) {
            return new WP_Error('missing_stream_id', 'Stream ID is required', array('status' => 400));
        }
        
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        // Call Mux API to get simulcast targets
        $url = "https://api.mux.com/video/v1/live-streams/{$live_stream_id}/simulcast-targets";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            )
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Mux API returns data directly as array, not wrapped
        // Check if it's already an array or wrapped in 'data'
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }
        
        // If it's already an array, return it
        if (is_array($data)) {
            return $data;
        }
        
        return array();
    }
    
    /**
     * Create Simulcast target
     */
    public function create_simulcast_target($request) {
        $settings = get_option('lem_settings', array());
        $live_stream_id = $request->get_param('stream_id') ?: ($settings['mux_live_stream_id'] ?? '');
        $url = $request->get_param('url');
        $stream_key = $request->get_param('stream_key');
        
        if (empty($live_stream_id) || empty($url)) {
            return new WP_Error('missing_params', 'Stream ID and URL are required', array('status' => 400));
        }
        
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        $payload = array('url' => $url);
        if (!empty($stream_key)) {
            $payload['stream_key'] = $stream_key;
        }
        
        // Call Mux API to create simulcast target
        $api_url = "https://api.mux.com/video/v1/live-streams/{$live_stream_id}/simulcast-targets";
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret']),
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($payload)
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('mux_api_error', $data['error']['message'] ?? 'Failed to create simulcast target', array('status' => 500));
        }
        
        return $data['data'] ?? array();
    }
    
    /**
     * Delete Simulcast target
     */
    public function delete_simulcast_target($request) {
        $settings = get_option('lem_settings', array());
        $live_stream_id = $request->get_param('stream_id') ?: ($settings['mux_live_stream_id'] ?? '');
        $target_id = $request->get_param('id');
        
        if (empty($live_stream_id) || empty($target_id)) {
            return new WP_Error('missing_params', 'Stream ID and target ID are required', array('status' => 400));
        }
        
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            return new WP_Error('mux_not_configured', 'Mux API credentials not configured', array('status' => 500));
        }
        
        // Call Mux API to delete simulcast target
        $url = "https://api.mux.com/video/v1/live-streams/{$live_stream_id}/simulcast-targets/{$target_id}";
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            )
        ));
        
        if (is_wp_error($response)) {
            return new WP_Error('mux_api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return array('success' => true);
        }
        
        return new WP_Error('mux_api_error', 'Failed to delete simulcast target', array('status' => 500));
    }
    
    /**
     * Get most recent asset for a live stream
     */
    private function get_most_recent_asset($live_stream_id, $credentials) {
        // Get assets for this stream
        $url = "https://api.mux.com/video/v1/live-streams/{$live_stream_id}";
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            )
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['data']['recent_asset_ids']) || empty($data['data']['recent_asset_ids'])) {
            return null;
        }
        
        // Get the most recent asset
        $asset_id = $data['data']['recent_asset_ids'][0];
        $asset_url = "https://api.mux.com/video/v1/assets/{$asset_id}";
        $asset_response = wp_remote_get($asset_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            )
        ));
        
        if (is_wp_error($asset_response)) {
            return null;
        }
        
        $asset_body = wp_remote_retrieve_body($asset_response);
        $asset_data = json_decode($asset_body, true);
        
        if (!$asset_data || !isset($asset_data['data'])) {
            return null;
        }
        
        $asset = $asset_data['data'];
        $playback_id = $asset['playback_ids'][0]['id'] ?? '';
        
        return array(
            'asset_id' => $asset_id,
            'playback_id' => $playback_id,
            'status' => $asset['status'] ?? '',
            'duration' => $asset['duration'] ?? 0
        );
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
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
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

    
    // Single payment webhook entry-point — routes to whichever provider is active.
    public function handle_payment_webhook() {
        $this->debug_log('Payment webhook received');

        $provider = LEM_Payment_Provider_Factory::get_instance()->get_active_provider();
        if (!$provider) {
            status_header(500);
            wp_die('Payment provider not available', 'Webhook Error', array('response' => 500));
        }

        $parsed = $provider->verify_webhook();

        if (is_wp_error($parsed)) {
            $code = $parsed->get_error_code();
            $http = in_array($code, array('invalid_payload', 'invalid_signature', 'missing_signature'), true) ? 400 : 500;
            $this->debug_log('Webhook verification failed: ' . $parsed->get_error_message());
            status_header($http);
            wp_die($parsed->get_error_message(), 'Webhook Error', array('response' => $http));
        }

        $this->debug_log('Webhook event type: ' . ($parsed['type'] ?? 'unknown'));

        // Allow companion plugins to handle non-checkout events.
        do_action('lem_webhook_event_received', $parsed, $provider->get_id());

        if (($parsed['type'] ?? '') !== 'checkout.completed') {
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
            status_header(200);
            wp_die('OK', '', array('response' => 200));
        }

        $this->debug_log('Processing payment for event: ' . $event_id . ', email: ' . $this->redact_email($email));

        global $wpdb;
        $table = $wpdb->prefix . 'lem_jwt_tokens';

        $existing_token = $wpdb->get_row($wpdb->prepare(
            "SELECT jti, jwt_token, created_at FROM $table WHERE payment_id = %s AND revoked_at IS NULL ORDER BY created_at DESC LIMIT 1",
            $payment_id
        ));

        if ($existing_token) {
            $this->debug_log('Webhook duplicate detected — skipping JWT re-issue.', array(
                'payment_id'   => $payment_id,
                'event_id'     => $event_id,
                'existing_jti' => $existing_token->jti,
            ));
            $redis = $this->get_redis_connection();
            if ($redis && !$redis->get("jti_session:{$existing_token->jti}")) {
                $this->magic_link_service->send_magic_link_email($email, $existing_token->jwt_token, $event_id, null);
            }
        } elseif ($this->magic_link_service->has_valid_ticket($email, $event_id)) {
            $this->debug_log('Webhook: email already has valid access — skipping.', array(
                'email'      => $this->redact_email($email),
                'event_id'   => $event_id,
                'payment_id' => $payment_id,
            ));
        } else {
            $this->store_event_email($event_id, $email);

            $jwt_result = $this->generate_jwt($email, $event_id, $payment_id);

            // Allow companion plugins to react after access is granted.
            do_action('lem_webhook_payment_received', $email, $event_id, $payment_id, $jwt_result, $provider->get_id());

            if ($jwt_result && isset($jwt_result['jwt'])) {
                $sid             = $this->create_session($event_id, $email);
                $jti_for_session = $jwt_result['jti'] ?? '';
                if (!empty($jti_for_session)) {
                    $r = $this->get_redis_connection();
                    if ($r) {
                        $r->setex("jti_session:{$jti_for_session}", 24 * 60 * 60, $sid);
                    }
                }
                $this->magic_link_service->send_magic_link_email($email, $jwt_result['jwt'], $event_id, $sid);
                $this->debug_log('JWT issued and magic link sent.', array(
                    'payment_id' => $payment_id,
                    'event_id'   => $event_id,
                    'jti'        => $jwt_result['jti'] ?? 'unknown',
                ));
            } else {
                $this->debug_log('Failed to generate JWT for payment.', array(
                    'payment_id' => $payment_id,
                    'event_id'   => $event_id,
                ));
            }
        }

        status_header(200);
        wp_die('OK', '', array('response' => 200));
    }

    /**
     * Capture a PayPal order after buyer approval and redirect to the event page.
     * PayPal redirects the buyer here with ?token=ORDER_ID&event_id=POST_ID.
     * After a successful capture, PayPal fires PAYMENT.CAPTURE.COMPLETED → handle_paypal_webhook().
     */
    public function handle_paypal_capture() {
        $order_id = sanitize_text_field($_GET['token']    ?? '');
        $event_id = sanitize_text_field($_GET['event_id'] ?? '');

        if (empty($order_id)) {
            wp_die('Invalid PayPal return URL — missing order token.', 'Payment Error', array('response' => 400));
        }

        $provider = LEM_Payment_Provider_Factory::get_instance()->get_provider('paypal');
        if (!$provider || !$provider->is_configured()) {
            wp_die('PayPal is not configured.', 'Payment Error', array('response' => 500));
        }

        /** @var LEM_PayPal_Provider $provider */
        $result = $provider->capture_order($order_id);

        if (is_wp_error($result)) {
            $this->debug_log('PayPal capture failed: ' . $result->get_error_message());
            $event_url = $event_id ? get_permalink(intval($event_id)) : home_url('/');
            wp_redirect(add_query_arg('lem_payment_error', '1', $event_url));
            exit;
        }

        $this->debug_log('PayPal order captured.', array('order_id' => $order_id, 'event_id' => $event_id));

        $event_url = $event_id ? get_permalink(intval($event_id)) : home_url('/');
        wp_redirect(add_query_arg('lem_payment', 'processing', $event_url));
        exit;
    }

    /**
     * Handle Mux webhook events
     * Specifically handles video.asset.ready to create "Past Stream" posts
     */
    public function handle_mux_webhook() {
        $this->debug_log('Mux webhook received');
        
        $payload = @file_get_contents('php://input');
        $signature = $_SERVER['HTTP_MUX_SIGNATURE'] ?? '';
        
        // Verify webhook signature if configured
        $settings = get_option('lem_settings', array());
        $webhook_secret = $settings['mux_webhook_secret'] ?? '';
        
        if (empty($webhook_secret)) {
            $this->debug_log('Mux webhook secret not configured — rejecting request');
            status_header(403);
            wp_die('Webhook secret not configured', 'Webhook Error', array('response' => 403));
        }

        $expected_signature = hash_hmac('sha256', $payload, $webhook_secret);
        if (!hash_equals($expected_signature, $signature)) {
            $this->debug_log('Invalid Mux webhook signature');
            status_header(401);
            wp_die('Invalid signature', 'Webhook Error', array('response' => 401));
        }

        $event = json_decode($payload, true);

        if (!$event) {
            $this->debug_log('Invalid Mux webhook payload');
            status_header(400);
            wp_die('Invalid payload', 'Webhook Error', array('response' => 400));
        }
        
        $event_type = $event['type'] ?? '';
        $this->debug_log('Mux webhook event: ' . $event_type);
        
        // Handle video.asset.ready event
        if ($event_type === 'video.asset.ready') {
            $asset_data = $event['data'] ?? array();
            $asset_id = $asset_data['id'] ?? '';
            $playback_id = $asset_data['playback_ids'][0]['id'] ?? '';
            $status = $asset_data['status'] ?? '';
            
            if ($asset_id && $playback_id && $status === 'ready') {
                // Find the event that matches this playback_id
                $events = get_posts(array(
                    'post_type' => 'lem_event',
                    'meta_key' => '_lem_playback_id',
                    'meta_value' => $playback_id,
                    'posts_per_page' => 1
                ));
                
                if (!empty($events)) {
                    $event_post = $events[0];
                    $event_id = $event_post->ID;
                    
                    // Create a "Past Stream" post
                    $past_stream_title = 'Past Stream: ' . $event_post->post_title;
                    $past_stream_id = wp_insert_post(array(
                        'post_title' => $past_stream_title,
                        'post_content' => 'This is an automatically created past stream recording.',
                        'post_status' => 'publish',
                        'post_type' => 'lem_event',
                        'post_parent' => $event_id,
                        'meta_input' => array(
                            '_lem_playback_id' => $playback_id,
                            '_lem_asset_id' => $asset_id,
                            '_lem_is_past_stream' => '1',
                            '_lem_original_event_id' => $event_id,
                            '_lem_status' => 'past'
                        )
                    ));
                    
                    if ($past_stream_id && !is_wp_error($past_stream_id)) {
                        $this->debug_log('Past stream post created', array(
                            'past_stream_id' => $past_stream_id,
                            'original_event_id' => $event_id,
                            'asset_id' => $asset_id,
                            'playback_id' => $playback_id
                        ));
                    } else {
                        $this->debug_log('Failed to create past stream post', array(
                            'error' => is_wp_error($past_stream_id) ? $past_stream_id->get_error_message() : 'Unknown error'
                        ));
                    }
                } else {
                    $this->debug_log('No event found for playback_id', array('playback_id' => $playback_id));
                }
            }
        }

        status_header(200);
        wp_die('Webhook processed', '', array('response' => 200));
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
    private function redact_email($email) {
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
                            $payload = json_decode(base64_decode(strtr($jwt_parts[1], '-_', '+/')), true);
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

        $settings = get_option('lem_settings', []);
        $ably_key = trim($settings['ably_api_key'] ?? '');
        if (empty($ably_key) || strpos($ably_key, ':') === false) {
            wp_send_json_error('Ably not configured', 503);
            return;
        }

        $colon_pos  = strrpos($ably_key, ':');
        $key_name   = substr($ably_key, 0, $colon_pos);
        $key_secret = substr($ably_key, $colon_pos + 1);

        // Use the session ID as the clientId so each device has a stable identity.
        $client_id   = $access['session_id'] ?? $session_id;
        $channel     = 'lem:chat:' . $event_id;
        $capability  = wp_json_encode([$channel => ['subscribe', 'publish', 'presence', 'history']]);
        $ttl         = 3600 * 1000; // 1 hour in ms
        $timestamp   = (int) round(microtime(true) * 1000);
        $nonce       = bin2hex(random_bytes(8));

        // Ably HMAC-SHA256 signing string (each field on its own line, trailing newline).
        $sign_string = implode("\n", [
            $key_name,
            $ttl,
            $capability,
            $client_id,
            $timestamp,
            $nonce,
            '', // trailing newline required by spec
        ]);

        $mac = base64_encode(hash_hmac('sha256', $sign_string, $key_secret, true));

        wp_send_json_success([
            'keyName'    => $key_name,
            'clientId'   => $client_id,
            'nonce'      => $nonce,
            'timestamp'  => $timestamp,
            'capability' => $capability,
            'ttl'        => $ttl,
            'mac'        => $mac,
        ]);
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
