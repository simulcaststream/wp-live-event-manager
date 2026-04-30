<?php
/**
 * JWT / playback issuance, Stripe checkout AJAX, entitlement & Redis token storage, revocation.
 *
 * @package Live_Event_Manager
 */

trait LEM_Trait_Jwt_And_Payments {

    /**
     * Issue or dedupe playback credentials. Provider-agnostic: delegates to whichever
     * streaming provider is active without branching on vendor name.
     *
     * Providers that return a fully-normalised token (containing a 'vendor' key) are
     * trusted directly. Providers that return a raw token shape (e.g. OME Signed Policy)
     * are normalised here and their playback blob is stored before returning.
     *
     * @return array{jwt?:string, jti?:string, expires_at?:int, llhls_url?:string, policy?:string, signature?:string, vendor?:string}|false
     */
    public function generate_jwt($email, $event_id, $payment_id = null, $is_refresh = false) {
        if (class_exists('LEM_Access') && LEM_Access::is_email_revoked_for_event($email, $event_id)) {
            return apply_filters('lem_jwt_access_denied', false, $email, $event_id);
        }

        $provider = $this->get_streaming_provider();
        if (!$provider || !$provider->is_configured()) {
            return false;
        }

        // Centralise revocation here so no individual provider needs to handle it.
        if ($is_refresh) {
            $this->invalidate_existing_tokens($email, $event_id);
        }

        // Always pass false for $is_refresh: tokens are already invalidated above, so
        // the provider's dedup check (if any) will find nothing and issue fresh.
        $tok = $provider->generate_playback_token($email, $event_id, $payment_id, false);

        $tok = apply_filters('lem_playback_token_generated', $tok, $email, $event_id, $provider->get_id());

        if (is_wp_error($tok) || !is_array($tok)) {
            return false;
        }

        // Providers that return a fully-normalised token (e.g. Mux via issue_mux_playback_token)
        // include a 'vendor' key and have already stored their blob — return as-is.
        if (isset($tok['vendor'])) {
            return $tok;
        }

        // Raw token (e.g. OME Signed Policy) — persist blob and return normalised shape.
        $exp_ts = strtotime($tok['expires_at'] ?? '') ?: (time() + 3600);
        $vid    = $provider->get_id();
        $blob   = array(
            'vendor'    => $vid,
            'jwt'       => $tok['jwt'] ?? '',
            'llhls_url' => $tok['llhls_url'] ?? '',
            'policy'    => $tok['policy'] ?? '',
            'signature' => $tok['signature'] ?? '',
            'jti'       => $tok['jti'] ?? $vid,
        );
        $this->store_playback_blob($email, $event_id, $blob, $exp_ts);

        return array(
            'jwt'        => $tok['jwt'] ?? '',
            'jti'        => $tok['jti'] ?? $vid,
            'expires_at' => $exp_ts,
            'llhls_url'  => $tok['llhls_url'] ?? '',
            'policy'     => $tok['policy'] ?? '',
            'signature'  => $tok['signature'] ?? '',
            'vendor'     => $vid,
            'session_id' => null,
        );
    }

    /**
     * Mux RS256 playback token + DB row + lem:playback (no session creation).
     */
    public function issue_mux_playback_token($email, $event_id, $payment_id, $is_refresh) {
        $settings    = get_option('lem_settings', array());
        $key_id      = $settings['mux_key_id'] ?? '';
        $private_key = $settings['mux_private_key'] ?? '';

        if (empty($key_id) || empty($private_key) || !class_exists('\Firebase\JWT\JWT')) {
            return false;
        }

        $event = $this->get_event_by_id($event_id);
        if (!$event) {
            return false;
        }

        if ($is_refresh) {
            $this->invalidate_existing_tokens($email, $event_id);
        } else {
            global $wpdb;
            $table    = $wpdb->prefix . 'lem_jwt_tokens';
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table
                 WHERE email      = %s
                   AND event_id   = %s
                   AND revoked_at IS NULL
                   AND expires_at > %s
                 ORDER BY created_at DESC
                 LIMIT 1",
                $email,
                $event_id,
                current_time('mysql')
            ));

            if ($existing) {
                $exp_ts = strtotime($existing->expires_at);
                $this->store_playback_blob($email, $event_id, array(
                    'vendor'  => 'mux',
                    'mux_jwt' => $existing->jwt_token,
                    'jti'     => $existing->jti,
                ), $exp_ts ? $exp_ts : time() + DAY_IN_SECONDS);

                $this->debug_log('Returning existing valid token (dedup)', array(
                    'email'    => $this->redact_email($email),
                    'event_id' => $event_id,
                    'jti'      => $existing->jti,
                ));
                return array(
                    'jwt'        => $existing->jwt_token,
                    'jti'        => $existing->jti,
                    'session_id' => null,
                    'expires_at' => strtotime($existing->expires_at),
                    'vendor'     => 'mux',
                );
            }
        }

        $event_end_str = $event->event_end ?? '';
        if (!empty($event_end_str)) {
            $end_ts = strtotime($event_end_str);
            if ($end_ts && $end_ts > time()) {
                $exp = $end_ts + (2 * 3600);
            } else {
                $jwt_expiration_hours = $settings['jwt_expiration_hours'] ?? 24;
                $exp                  = time() + ($jwt_expiration_hours * 3600);
            }
        } else {
            $jwt_expiration_hours = $settings['jwt_expiration_hours'] ?? 24;
            $exp                  = time() + ($jwt_expiration_hours * 3600);
        }

        $random_jti = uniqid('jwt_', true);
        $placeholder = $this->generate_session_id();
        $device_identifier = $this->device_service->getDeviceIdentifier(array(
            'session_id' => $placeholder,
        ));

        $device_settings   = get_option('lem_device_settings', array('identification_method' => 'session_based'));
        $identifier_type   = $device_settings['identification_method'] ?? 'session_based';
        $identifier_value  = $placeholder;
        $ip                = $device_identifier['metadata']['ip'] ?? '0.0.0.0';
        $hash_jti          = hash('sha256', $email . '|' . $ip . '|' . $event->playback_id);
        $exp_int           = (int) $exp;

        $payload = array(
            'sub' => $event->playback_id,
            'aud' => 'v',
            'exp' => $exp_int,
            'kid' => $key_id,
            'custom' => array(
                'jti'                => $random_jti,
                'identifier_type'    => $identifier_type,
                'identifier_value'   => $identifier_value,
                'event_id'           => $event_id,
                'ip'                 => $ip,
            ),
        );

        if (!empty($event->playback_restriction_id)) {
            $payload['playback_restriction_id'] = $event->playback_restriction_id;
        }

        try {
            $jwt = \Firebase\JWT\JWT::encode($payload, base64_decode($private_key), 'RS256');

            $this->store_jwt($random_jti, $hash_jti, $jwt, $email, $event_id, $payment_id, $ip, gmdate('Y-m-d H:i:s', $exp));

            $jwt_redis_data = array(
                'jti'                     => $random_jti,
                'jwt_token'               => $jwt,
                'playback_id'             => $event->playback_id,
                'playback_restriction_id' => $event->playback_restriction_id,
                'email'                   => $email,
                'event_id'                => $event_id,
                'device_identifier'       => $device_identifier,
                'identifier_type'         => $identifier_type,
                'identifier_value'        => $identifier_value,
                'expires_at'              => gmdate('Y-m-d H:i:s', $exp),
                'created_at'              => gmdate('Y-m-d H:i:s'),
                'revoked'                 => false,
            );
            $this->store_jwt_redis_by_jti($random_jti, $jwt_redis_data, $exp);
            $this->store_jwt_redis($hash_jti, $payload);
            $this->store_jti_mapping($random_jti, $hash_jti);

            $this->store_playback_blob($email, $event_id, array(
                'vendor'  => 'mux',
                'mux_jwt' => $jwt,
                'jti'     => $random_jti,
            ), $exp_int);

            return array(
                'jwt'        => $jwt,
                'jti'        => $random_jti,
                'expires_at' => $exp_int,
                'vendor'     => 'mux',
                'session_id' => null,
            );
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Ensure lem:playback exists (watch path avoids DB; magic link may only have DB entitlement).
     */
    public function ensure_playback_blob($email, $event_id) {
        $redis = $this->get_redis_connection();
        if (!$redis) {
            return;
        }
        $key = LEM_Access::playback_key($email, $event_id);
        if ($redis->get($key)) {
            return;
        }
        if (class_exists('LEM_Access') && LEM_Access::is_email_revoked_for_event($email, $event_id)) {
            return;
        }
        $this->generate_jwt($email, $event_id, null, false);
    }
    

    
    // AJAX generate JWT (admin-only — viewers get access via Stripe webhook or magic links)
    public function ajax_generate_jwt() {
        check_ajax_referer('lem_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $email = sanitize_email($_POST['email'] ?? '');
        $event_id = sanitize_text_field($_POST['event_id'] ?? '');
        $payment_id = sanitize_text_field($_POST['payment_id'] ?? '');
        
        if (empty($email) || empty($event_id)) {
            wp_send_json_error('Email and Event ID are required');
        }
        
        // Validate email format
        if (!is_email($email)) {
            wp_send_json_error('Please enter a valid email address');
        }

        if (class_exists('LEM_Access') && LEM_Access::is_email_revoked_for_event($email, $event_id)) {
            wp_send_json_error('Access for this email has been revoked for this event.');
        }
        
        // Store email as valid access for this event (golden data)
        $this->store_event_email($event_id, $email);
        
        $result = $this->generate_jwt($email, $event_id, $payment_id);

        if ($result && is_array($result)) {
            do_action('lem_access_granted', $email, $event_id, $payment_id ?: null, 'admin', $result);

            $session_id = $this->create_session($event_id, $email);
            $result['session_id'] = $session_id;

            if (!empty($result['jti'])) {
                $r = $this->get_redis_connection();
                if ($r) {
                    $r->setex("jti_session:{$result['jti']}", 24 * 60 * 60, $session_id);
                }
            }

            // Send magic link email with session ID
            $mail_result = $this->magic_link_service->send_magic_link_email($email, $result['jwt'], $event_id, $session_id);
            $mail_sent   = is_array($mail_result) ? ($mail_result['sent'] ?? false) : (bool) $mail_result;
            $mail_error  = (!$mail_sent && is_array($mail_result)) ? ($mail_result['error'] ?? '') : '';

            if (!headers_sent() && !empty($session_id)) {
                setcookie('lem_session_id', $session_id, array(
                    'expires'  => time() + DAY_IN_SECONDS,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ));
                $_COOKIE['lem_session_id'] = $session_id;
            }

            $message = $mail_sent
                ? 'Access granted! Your magic link is on its way.'
                : 'Access granted! However, the confirmation email could not be sent — ' . ($mail_error ?: 'check your SMTP settings in Live Events → Settings.');

            $watch_url = $this->get_event_url($event_id);
            if ($mail_sent && is_array($mail_result) && !empty($mail_result['magic_link'])) {
                $watch_url = $mail_result['magic_link'];
            } elseif (!$mail_sent) {
                // Email failed: still give a working gate URL (same shape as the email link).
                $magic_token = $this->generate_magic_token($email, $event_id, $session_id);
                if ($magic_token) {
                    $watch_url = $this->get_event_url($event_id, array('magic' => $magic_token));
                }
            }

            wp_send_json_success(array(
                'jwt'        => $result['jwt'],
                'session_id' => $session_id,
                'jti'        => $result['jti'],
                'watch_url'  => $watch_url,
                'mail_sent'  => $mail_sent,
                'mail_error' => $mail_error,
                'message'    => $message,
            ));
        } else {
            wp_send_json_error('Failed to generate access. Please try again.');
        }
    }
    
    /**
     * Public AJAX: grant access for FREE events only.
     * Validates the event exists and is marked free before issuing a JWT + magic link.
     */
    public function ajax_free_event_access() {
        check_ajax_referer('lem_nonce', 'nonce');

        if (!$this->check_rate_limit('free_access', 5)) {
            wp_send_json_error(['message' => 'Too many requests. Please wait a moment.']);
        }

        $email    = sanitize_email($_POST['email'] ?? '');
        $event_id = sanitize_text_field($_POST['event_id'] ?? '');

        if (empty($email) || empty($event_id) || !is_email($email)) {
            wp_send_json_error('A valid email and event are required.');
        }

        $post = get_post($event_id);
        if (!$post || $post->post_type !== 'lem_event' || $post->post_status !== 'publish') {
            wp_send_json_error('Event not found.');
        }

        $is_free = get_post_meta($event_id, '_lem_is_free', true);
        if ($is_free !== 'free') {
            wp_send_json_error('This event requires a ticket purchase.');
        }

        if (class_exists('LEM_Access') && LEM_Access::is_email_revoked_for_event($email, $event_id)) {
            wp_send_json_error('Access for this email has been revoked for this event.');
        }

        $this->store_event_email($event_id, $email);

        $result = $this->generate_jwt($email, $event_id, null);

        if (!$result || !is_array($result)) {
            wp_send_json_error('Failed to generate access. Please try again.');
        }

        do_action('lem_access_granted', $email, $event_id, null, 'free', $result);

        $session_id = $this->create_session($event_id, $email);

        if (!empty($result['jti'])) {
            $r = $this->get_redis_connection();
            if ($r) {
                $r->setex("jti_session:{$result['jti']}", 24 * 60 * 60, $session_id);
            }
        }

        $mail_result = $this->magic_link_service->send_magic_link_email($email, $result['jwt'], $event_id, $session_id);
        $mail_sent   = is_array($mail_result) ? ($mail_result['sent'] ?? false) : (bool) $mail_result;
        $mail_error  = (!$mail_sent && is_array($mail_result)) ? ($mail_result['error'] ?? '') : '';

        if (!headers_sent() && !empty($session_id)) {
            setcookie('lem_session_id', $session_id, array(
                'expires'  => time() + DAY_IN_SECONDS,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        }

        $watch_url = $this->get_event_url($event_id);
        if ($mail_sent && is_array($mail_result) && !empty($mail_result['magic_link'])) {
            $watch_url = $mail_result['magic_link'];
        } elseif (!$mail_sent) {
            $magic_token = $this->generate_magic_token($email, $event_id, $session_id);
            if ($magic_token) {
                $watch_url = $this->get_event_url($event_id, array('magic' => $magic_token));
            }
        }

        $message = $mail_sent
            ? 'Access granted! Your magic link is on its way.'
            : 'Access granted! However, the confirmation email could not be sent — ' . ($mail_error ?: 'check SMTP settings.');

        wp_send_json_success(array(
            'watch_url'  => $watch_url,
            'mail_sent'  => $mail_sent,
            'message'    => $message,
        ));
    }

    /**
     * Check payment session status immediately (before webhook arrives).
     * Delegates to the active payment provider.
     */
    public function check_stripe_session_immediate($session_id) {
        if (empty($session_id)) {
            return false;
        }

        $provider = LEM_Payment_Provider_Factory::get_instance()->get_active_provider();
        if (!$provider || !$provider->is_configured()) {
            $this->debug_log('Payment provider not configured for immediate check');
            return false;
        }

        $status = $provider->get_payment_status($session_id);
        if (is_wp_error($status)) {
            $this->debug_log('Payment status check failed: ' . $status->get_error_message());
            return false;
        }

        if (!empty($status['paid'])) {
            $event_id = $status['event_id'] ?? null;
            $email    = $status['email'] ?? null;

            if ($event_id && $email) {
                $this->debug_log('Payment confirmed immediately', array(
                    'session_id' => $session_id,
                    'event_id'   => $event_id,
                    'email'      => $this->redact_email($email),
                ));

                // Check for an existing JWT to avoid duplicate issuance.
                global $wpdb;
                $table          = $wpdb->prefix . 'lem_jwt_tokens';
                $existing_token = $wpdb->get_row($wpdb->prepare(
                    "SELECT jti, jwt_token, created_at FROM $table WHERE payment_id = %s AND revoked_at IS NULL ORDER BY created_at DESC LIMIT 1",
                    $session_id
                ));

                if ($existing_token) {
                    return array(
                        'jwt'        => $existing_token->jwt_token,
                        'jti'        => $existing_token->jti,
                        'email'      => $email,
                        'event_id'   => $event_id,
                        'from_cache' => true,
                    );
                }

                // No JWT yet — webhook will generate it.
                return false;
            }
        }

        return false;
    }
    
    /**
     * Store email as valid access for an event (golden data)
     */
    private function store_event_email($event_id, $email) {
        $event_emails = get_post_meta($event_id, '_lem_event_emails', true);
        
        if (!is_array($event_emails)) {
            $event_emails = array();
        }
        
        // Add email if not already present
        $email_lower = strtolower(trim($email));
        if (!in_array($email_lower, $event_emails)) {
            $event_emails[] = $email_lower;
            update_post_meta($event_id, '_lem_event_emails', $event_emails);
            
            $this->debug_log('Stored email for event', array(
                'event_id' => $event_id,
                'email' => $this->redact_email($email_lower),
                'total_emails' => count($event_emails)
            ));
        }
        
        return true;
    }
    
    /**
     * Get all emails with access to an event
     */
    public function get_event_emails($event_id) {
        $event_emails = get_post_meta($event_id, '_lem_event_emails', true);
        return is_array($event_emails) ? $event_emails : array();
    }
    
    // AJAX revoke JWT
    public function ajax_revoke_jwt() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $jti = sanitize_text_field($_POST['jti']);
        
        if (empty($jti)) {
            wp_send_json_error('JTI is required');
        }
        
        $result = $this->revoke_jwt($jti);
        
        if ($result) {
            wp_send_json_success('JWT revoked successfully');
        } else {
            wp_send_json_error('Failed to revoke JWT');
        }
    }

    /**
     * Admin AJAX: distinct viewer emails from lem_jwt_tokens for an event (revoke page dropdown).
     */
    public function ajax_revoke_emails_for_event() {
        check_ajax_referer('lem_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        if ($event_id <= 0) {
            wp_send_json_error('Invalid event');
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'lem_jwt_tokens';
        $emails = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT email FROM {$table} WHERE event_id = %s AND email != '' ORDER BY email ASC",
                (string) $event_id
            )
        );

        $out = array();
        foreach ((array) $emails as $e) {
            $e = sanitize_email($e);
            if ($e !== '') {
                $out[] = $e;
            }
        }
        $out = array_values(array_unique($out));

        wp_send_json_success(array('emails' => $out));
    }

    
    // Restrictions AJAX handlers
    public function ajax_create_restriction() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $name = sanitize_text_field($_POST['name']);
        $description = sanitize_textarea_field($_POST['description']);
        $allowed_domains = array_map('trim', explode(',', sanitize_text_field($_POST['allowed_domains'])));
        $allow_no_referrer = intval($_POST['allow_no_referrer']);
        $allow_no_user_agent = intval($_POST['allow_no_user_agent']);
        $allow_high_risk_user_agent = intval($_POST['allow_high_risk_user_agent']);
        
        if (empty($name) || empty($allowed_domains[0])) {
            wp_send_json_error('Name and allowed domains are required');
        }
        
        $result = $this->create_playback_restriction(
            $name,
            $description,
            $allowed_domains,
            $allow_no_referrer,
            $allow_no_user_agent,
            $allow_high_risk_user_agent
        );
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['error']);
        }
    }
    
    public function ajax_get_restrictions() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $result = $this->get_playback_restrictions();
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['error']);
        }
    }
    
    public function ajax_delete_restriction() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $restriction_id = sanitize_text_field($_POST['restriction_id']);
        
        if (empty($restriction_id)) {
            wp_send_json_error('Restriction ID is required');
        }
        
        $result = $this->delete_playback_restriction($restriction_id);
        
        if ($result['success']) {
            wp_send_json_success();
        } else {
            wp_send_json_error($result['error']);
        }
    }
    
    public function ajax_get_jwt_tokens() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        
        $this->debug_log('JWT Manager: Checking table ' . $table);
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s", DB_NAME, $table));
        $this->debug_log('JWT Manager: Table exists: ' . ($table_exists ? 'yes' : 'no'));
        
        if (!$table_exists) {
            $this->debug_log('JWT Manager: Table does not exist, returning empty array');
            wp_send_json_success(array());
        }
        
        $limit  = max(1, min(500, intval($_POST['limit'] ?? 200)));
        $offset = max(0, intval($_POST['offset'] ?? 0));
        $tokens = $wpdb->get_results($wpdb->prepare(
            "SELECT jti, email, event_id, ip_address, created_at, expires_at
             FROM $table
             WHERE revoked_at IS NULL
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
        
        $redis = $this->get_redis_connection();
        
        foreach ($tokens as &$token) {
            $redis_info = array(
                'available' => (bool) $redis,
                'session_id' => null,
                'session' => null,
                'session_raw' => null,
                'jwt_cache' => null,
                'jwt_cache_raw' => null,
                'jwt_token_cache' => null,
                'status_cache' => null,
                'status_cache_key' => null,
            );
            
            if ($redis) {
                $jti = $token->jti;
                
                // Session data
                $session_id = $redis->get("jti_session:{$jti}");
                if ($session_id) {
                    $redis_info['session_id'] = $session_id;
                    $session_raw = $redis->get("session:{$session_id}");
                    if ($session_raw) {
                        $redis_info['session_raw'] = $session_raw;
                        $redis_info['session'] = json_decode($session_raw, true) ?: $session_raw;
                    }
                }
                
                // JWT cache
                $jwt_cache_raw = $redis->get("jwt:{$jti}");
                if ($jwt_cache_raw) {
                    $redis_info['jwt_cache_raw'] = $jwt_cache_raw;
                    $decoded = json_decode($jwt_cache_raw, true);
                    $redis_info['jwt_cache'] = $decoded ?: $jwt_cache_raw;
                }
                
                $jwt_token_cache = $redis->get("jwt_token:{$jti}");
                if ($jwt_token_cache) {
                    $redis_info['jwt_token_cache'] = $jwt_token_cache;
                }
                
                if (!empty($token->email)) {
                    $email_hash = hash('sha256', $token->email);
                    $status_key = 'session_status:' . $token->event_id . ':' . $email_hash;
                    $status_raw = $redis->get($status_key);
                    if ($status_raw) {
                        $redis_info['status_cache_key'] = $status_key;
                        $decoded_status = json_decode($status_raw, true);
                        $redis_info['status_cache'] = $decoded_status ?: $status_raw;
                    }
                }
            }
            
            $token->redis = $redis_info;
        }
        unset($token);
        
        $this->debug_log('JWT Manager: Found ' . count($tokens) . ' tokens');
        $this->debug_log('JWT Manager: Tokens count', count($tokens));
        
        wp_send_json_success($tokens);
    }
    

    
    public function ajax_create_stripe_session() {
        check_ajax_referer('lem_nonce', 'nonce');

        if (!$this->check_rate_limit('stripe_session', 5)) {
            wp_send_json_error(array('message' => 'Too many requests. Please wait a moment.'));
        }

        $event_id = sanitize_text_field($_POST['event_id'] ?? '');
        $price_id = sanitize_text_field($_POST['price_id'] ?? '');
        $email    = sanitize_email($_POST['email'] ?? '');

        if (empty($event_id) || empty($price_id)) {
            wp_send_json_error('Event ID and Price ID are required');
        }

        if (!empty($email) && $this->magic_link_service->has_valid_ticket($email, $event_id)) {
            wp_send_json_error(array(
                'message' => __('You already have access to this event. Check your inbox for your link or use “Resend” on the event page.', 'live-event-manager'),
            ));
        }

        $event = $this->get_event_by_id($event_id);
        if (!$event) {
            wp_send_json_error('Event not found');
        }

        $provider = LEM_Payment_Provider_Factory::get_instance()->get_active_provider();
        if (!$provider || !$provider->is_configured()) {
            wp_send_json_error('Payment provider not configured. Please check your settings.');
        }

        $result = $provider->create_checkout_session(array(
            'price_id'    => $price_id,
            'event_id'    => $event_id,
            'event_title' => $event->title,
            'email'       => $email,
            'cancel_url'  => get_permalink($event_id) ?: home_url('/'),
        ));

        if (is_wp_error($result)) {
            wp_send_json_error($provider->get_name() . ' error: ' . $result->get_error_message());
        }

        wp_send_json_success($result);
    }
    

    
    // Regenerate JWT using unique code
    public function ajax_regenerate_jwt() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        $email = sanitize_email($_POST['email']);
        $code = sanitize_text_field($_POST['code']);
        
        if (empty($email) || empty($code)) {
            wp_send_json_error('Email and code are required');
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        
        // Find the token by email and code (first 8 characters of JTI)
        $tokens = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE email = %s AND jti LIKE %s ORDER BY created_at DESC LIMIT 1",
            $email,
            $code . '%'
        ));
        
        if (empty($tokens)) {
            wp_send_json_error('Invalid email or code combination');
        }
        
        $token = $tokens[0];
        $event_id = $token->event_id;

        // Invalidate all existing tokens before issuing a fresh one
        $this->invalidate_existing_tokens($email, $event_id);

        // Generate new playback token (invalidates prior rows first)
        $jwt_result = $this->generate_jwt($email, $event_id, $token->payment_id, true);
        
        if ($jwt_result && is_array($jwt_result)) {
            $new_sid = $this->create_session($event_id, $email);
            $this->magic_link_service->send_magic_link_email($email, $jwt_result['jwt'], $event_id, $new_sid);
            
            wp_send_json_success('New access link generated and sent to your email');
        } else {
            wp_send_json_error('Failed to generate new access link');
        }
    }
    
    public function ajax_check_event_access() {
        check_ajax_referer('lem_nonce', 'nonce');

        $event_id = intval($_POST['event_id'] ?? 0);
        if (empty($event_id)) {
            wp_send_json_error('Event ID required');
        }

        $state = $this->get_event_access_state($event_id);

        if (!empty($state['can_watch'])) {
            wp_send_json_success(array(
                'can_watch' => true,
                'watch_url' => $this->get_event_url($event_id),
                'session_id' => $state['session_id'],
                'jwt_token' => $state['jwt_token']
            ));
        }

        wp_send_json_success(array(
            'can_watch' => false,
            'error' => $state['error_message']
        ));
    }

    // Test Redis connection
    public function ajax_test_redis_connection() {
        check_ajax_referer('lem_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $settings = get_option('lem_settings', array());

        // Accept credentials from the POST body (settings page test button) or fall back to saved settings.
        $url   = sanitize_text_field(wp_unslash($_POST['upstash_redis_url']   ?? $settings['upstash_redis_url']   ?? ''));
        $token = sanitize_text_field(wp_unslash($_POST['upstash_redis_token'] ?? $settings['upstash_redis_token'] ?? ''));

        if (empty($url) || empty($token)) {
            wp_send_json_error('Upstash REST URL and token are required. Add them on the Cache & Access tab.');
        }

        $override = array('upstash_redis_url' => $url, 'upstash_redis_token' => $token);
        $cache    = $this->get_redis_connection($override);

        if (!$cache) {
            wp_send_json_error('Could not build Upstash client — check that the URL and token are correct.');
        }

        // Test SET → GET → DEL round-trip.
        $test_key   = 'lem_test_' . time();
        $test_value = 'ok_' . wp_generate_password(8, false);

        if (!$cache->set($test_key, $test_value, 30)) {
            wp_send_json_error('Upstash SET failed. Check your token has write permissions.');
        }

        $fetched = $cache->get($test_key);
        if ($fetched !== $test_value) {
            wp_send_json_error('Upstash GET returned unexpected value. Expected: ' . $test_value . ', got: ' . $fetched);
        }

        $cache->del($test_key);

        wp_send_json_success(array(
            'message' => 'Upstash connection successful! SET / GET / DEL all passed.',
            'url'     => preg_replace('/^(https?:\/\/[^.]+).*/', '$1…', $url),
        ));
    }
    
    /**
     * Store the last wp_mail() failure so it can be surfaced in the admin.
     */
    public function on_wp_mail_failed( $wp_error ) {
        if ( is_wp_error( $wp_error ) ) {
            set_transient( 'lem_last_mail_error', $wp_error->get_error_message(), HOUR_IN_SECONDS );
        }
    }

    /**
     * AJAX: send a test email to the currently logged-in admin.
     */
    public function ajax_test_email() {
        check_ajax_referer( 'lem_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorised.' );
        }

        $to = sanitize_email( $_POST['to'] ?? '' );
        if ( empty( $to ) ) {
            $to = wp_get_current_user()->user_email;
        }
        if ( ! is_email( $to ) ) {
            wp_send_json_error( 'Invalid email address.' );
        }

        // Clear any stale error before the test
        delete_transient( 'lem_last_mail_error' );

        $from_email = get_option( 'admin_email' );
        $from_name  = get_bloginfo( 'name' ) ?: 'Live Events';
        $headers    = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
        );

        $subject = '[Live Event Manager] Test email';
        $message = "This is a test email from Live Event Manager.\n\n"
                 . "If you received this, wp_mail() is working correctly with your current mailer.\n\n"
                 . "From address used: " . $from_email . "\n"
                 . "Sent: " . current_time( 'Y-m-d H:i:s' );

        $sent = wp_mail( $to, $subject, $message, $headers );

        if ( $sent ) {
            wp_send_json_success( array(
                'message' => "Test email sent to {$to}. Check your inbox (and spam folder).",
            ) );
        } else {
            $error = get_transient( 'lem_last_mail_error' ) ?: 'wp_mail() returned false but no error was captured. Check your SMTP plugin configuration.';
            wp_send_json_error( $error );
        }
    }

    public function ajax_revoke_session() {
        check_ajax_referer('lem_nonce', 'nonce');
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (empty($session_id)) {
            wp_send_json_error('Session ID is required');
        }

        // Only the session owner (matching cookie) or an admin may revoke.
        $cookie_session = $_COOKIE['lem_session_id'] ?? '';
        if ($session_id !== $cookie_session && !current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $result = $this->revoke_session($session_id);
        
        if ($result) {
            wp_send_json_success('Session revoked successfully');
        } else {
            wp_send_json_error('Failed to revoke session');
        }
    }
    
    // AJAX validate email and send new link
    public function ajax_validate_email() {
        check_ajax_referer('lem_nonce', 'nonce');

        if (!$this->check_rate_limit('validate_email', 5)) {
            wp_send_json_error(['message' => 'Too many requests. Please wait a moment.']);
        }

        $email = sanitize_email($_POST['email']);
        $event_id = intval($_POST['event_id']);
        
        if (empty($email) || empty($event_id)) {
            wp_send_json_error('Email and Event ID are required');
        }
        
        $result = $this->validate_email_and_send_link($email, $event_id);
        
        if ($result['valid']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['error']);
        }
    }
    

    
    // Get all events
    private function get_all_events() {
        $args = array(
            'post_type' => 'lem_event',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => 'meta_value',
            'meta_key' => '_lem_event_date',
            'order' => 'DESC'
        );
        
        $posts = get_posts($args);
        $events = array();
        
        foreach ($posts as $post) {
            $event = new stdClass();
            $event->event_id = $post->ID; // Use post ID as event ID
            $event->title = $post->post_title;
            $event->description = $post->post_content;
            $event->playback_id = get_post_meta($post->ID, '_lem_playback_id', true);
            $event->playback_restriction_id = get_post_meta($post->ID, '_lem_playback_restriction_id', true);
            $event->event_date = get_post_meta($post->ID, '_lem_event_date', true);
            $event->price_id = get_post_meta($post->ID, '_lem_price_id', true);
            $event->is_free = get_post_meta($post->ID, '_lem_is_free', true) ?: 'free';
            $event->post_status = $post->post_status;
            $event->created_at = $post->post_date;
            $event->updated_at = $post->post_modified;
            $events[] = $event;
        }
        
        return $events;
    }
    
    // Get event by ID with multi-layer caching
    public function get_event_by_id($event_id) {
        $event_id = intval($event_id);
        
        if ($event_id <= 0) {
            return null;
        }
        
        $cache_key = "event:{$event_id}";
        
        // Level 1: In-memory cache (current request only)
        if (isset(self::$memory_cache[$cache_key])) {
            return self::$memory_cache[$cache_key];
        }
        
        // Level 2: Redis cache (shared across requests)
        $redis = $this->get_redis_connection();
        if ($redis) {
            $cached = $redis->get($cache_key);
            if ($cached !== false) {
                $data = json_decode($cached, true);
                if ($data) {
                    $event = (object) $data;
                    // Store in memory cache too
                    self::$memory_cache[$cache_key] = $event;
                    return $event;
                }
            }
        }
        
        // Level 3: WordPress database (cache miss)
        $post = get_post($event_id);
        
        if (!$post || $post->post_type !== 'lem_event') {
            return null;
        }
        
        $event = new stdClass();
        $event->event_id = $post->ID;
        $event->title = $post->post_title;
        $event->description = $post->post_content;
        $event->playback_id = get_post_meta($post->ID, '_lem_playback_id', true);
        $event->live_stream_id = get_post_meta($post->ID, '_lem_live_stream_id', true);
        $event->playback_restriction_id = get_post_meta($post->ID, '_lem_playback_restriction_id', true);
        $event->event_date = get_post_meta($post->ID, '_lem_event_date', true);
        $event->event_end  = get_post_meta($post->ID, '_lem_event_end',  true);
        $event->price_id = get_post_meta($post->ID, '_lem_price_id', true);
        $event->is_free = get_post_meta($post->ID, '_lem_is_free', true) ?: 'free';
        $event->post_status = $post->post_status;
        $event->created_at = $post->post_date;
        $event->updated_at = $post->post_modified;
        $event->slug = $post->post_name;
        
        // Cache in Redis for 2 hours (events don't change frequently during viewing)
        if ($redis) {
            $redis->setex($cache_key, 7200, json_encode($event));
        }
        
        // Store in memory cache
        self::$memory_cache[$cache_key] = $event;
        
        return $event;
    }
    
    /**
     * Get email by JTI (privacy-focused approach)
     * Only used when absolutely necessary (e.g., confirmation page)
     */
    public function get_email_by_jti($jti) {
        global $wpdb;
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        $token = $wpdb->get_row($wpdb->prepare(
            "SELECT email FROM $table WHERE jti = %s",
            $jti
        ));
        
        return $token ? $token->email : null;
    }
    
    // Store JWT in database
    /**
     * Revoke and remove every active token for a given email + event.
     * Marks rows as revoked in MySQL and deletes the Redis keys so they
     * can never be used again. Called before issuing a replacement token.
     *
     * @param string $email
     * @param string $event_id
     * @return int Number of tokens invalidated
     */
    private function invalidate_existing_tokens($email, $event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'lem_jwt_tokens';

        $tokens = $wpdb->get_results($wpdb->prepare(
            "SELECT jti, hash_jti FROM $table
             WHERE email     = %s
               AND event_id  = %s
               AND revoked_at IS NULL
               AND expires_at > %s",
            $email,
            $event_id,
            current_time('mysql')
        ));

        if (empty($tokens)) {
            if ($redis = $this->get_redis_connection()) {
                $redis->del(LEM_Access::playback_key($email, $event_id));
            }
            return 0;
        }

        $redis = $this->get_redis_connection();
        if ($redis) {
            $redis->del(LEM_Access::playback_key($email, $event_id));
        }

        foreach ($tokens as $token) {
            // Revoke in DB
            $wpdb->update(
                $table,
                array('revoked_at' => current_time('mysql')),
                array('jti'        => $token->jti),
                array('%s'),
                array('%s')
            );

            // Remove from Redis
            if ($redis) {
                try {
                    $redis->del('jwt:'         . $token->hash_jti);
                    $redis->del('jti:'         . $token->jti);
                    $redis->del('jti_session:' . $token->jti);
                } catch (Exception $e) {
                    $this->debug_log('Redis delete failed during token invalidation', array(
                        'jti'   => $token->jti,
                        'error' => $e->getMessage(),
                    ));
                }
            }
        }

        $this->debug_log('Invalidated existing tokens', array(
            'email'    => $email,
            'event_id' => $event_id,
            'count'    => count($tokens),
        ));

        return count($tokens);
    }

    private function store_jwt($jti, $hash_jti, $jwt_token, $email, $event_id, $payment_id, $ip, $exp) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        
        return $wpdb->insert($table, array(
            'jti' => $jti,
            'hash_jti' => $hash_jti,
            'jwt_token' => $jwt_token,
            'email' => $email,
            'event_id' => $event_id,
            'payment_id' => $payment_id,
            'ip_address' => null, // Remove IP address storage
            'expires_at' => $exp
        ));
    }
    
    /**
     * Returns the Upstash cache instance, or false if not configured.
     * All existing $redis = $this->get_redis_connection() call sites continue to work
     * because LEM_Cache implements the same interface (get/set/setex/del/exists/keys/ping/pipeline).
     *
     * @param array $override_settings Optional credential overrides (used by connection test).
     * @return LEM_Cache|false
     */
    public function get_redis_connection($override_settings = array()) {
        $upstash_override = array();
        if (!empty($override_settings['upstash_redis_url'])) {
            $upstash_override['url']   = $override_settings['upstash_redis_url'];
            $upstash_override['token'] = $override_settings['upstash_redis_token'] ?? '';
        }
        return LEM_Cache::instance($upstash_override);
    }
    
    // Store JWT in Redis (legacy method using hash_jti)
    private function store_jwt_redis($hash_jti, $jwt_data) {
        $redis = $this->get_redis_connection();
        if (!$redis) return false;
        
        try {
            $key = 'jwt:' . $hash_jti;
            $expiry = $jwt_data['exp'] - time();
            if ($expiry > 0) {
                $encoded_data = json_encode($jwt_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($encoded_data === false) {
                    return false;
                }
                $redis->setex($key, $expiry, $encoded_data);
                return true;
            }
        } catch (Exception $e) {
            $this->debug_log('Redis operation failed: ' . $e->getMessage());
        }
        return false;
    }

    // Store JWT in Redis by JTI for Cloudflare Worker performance
    private function store_jwt_redis_by_jti($jti, $jwt_data, $exp_timestamp) {
        $redis = $this->get_redis_connection();
        if (!$redis) return false;
        
        try {
            $key = 'jwt:' . $jti;
            $expiry = $exp_timestamp - time();
            if ($expiry > 0) {
                $encoded_data = json_encode($jwt_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($encoded_data === false) {
                    return false;
                }
                $redis->setex($key, $expiry, $encoded_data);
                
                // Also cache the actual JWT token for fast retrieval
                if (isset($jwt_data['jwt_token'])) {
                    $redis->setex("jwt_token:{$jti}", $expiry, $jwt_data['jwt_token']);
                }
                
                return true;
            }
        } catch (Exception $e) {
            $this->debug_log('Redis operation failed: ' . $e->getMessage());
        }
        return false;
    }

    // Store JTI mapping in Redis
    private function store_jti_mapping($random_jti, $hash_jti) {
        $redis = $this->get_redis_connection();
        if (!$redis) return false;
        
        try {
            $key = 'jti_mapping:' . $random_jti;
            $redis->setex($key, 3600, $hash_jti);
            return true;
        } catch (Exception $e) {
            $this->debug_log('Redis operation failed: ' . $e->getMessage());
        }
        return false;
    }

    // Store event in Redis (optimized with longer TTL)
    private function store_event_redis($event_id, $event_data) {
        $redis = $this->get_redis_connection();
        if (!$redis) return false;
        
        try {
            $key = 'event:' . $event_id;
            $encoded_data = json_encode($event_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded_data === false) {
                return false;
            }
            // Cache for 2 hours (events don't change frequently during viewing)
            $redis->setex($key, 7200, $encoded_data);
            return true;
        } catch (Exception $e) {
            $this->debug_log('Failed to store event in Redis', array('error' => $e->getMessage()));
        }
        return false;
    }
    
    // Revoke JWT
    public function revoke_jwt($jti) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        
        // Get the token record to get all related data
        $token = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE jti = %s", $jti));
        
        if (!$token) {
            // Try as hash JTI directly
            $token = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE hash_jti = %s", $jti));
            if (!$token) {
                $this->debug_log('JWT not found for revocation', array('jti' => $jti));
                return false;
            }
            $hash_jti = $jti;
        } else {
            $hash_jti = $token->hash_jti;
        }
        
        $event_id = $token->event_id ?? '';
        $email = $token->email ?? '';
        
        // Mark as revoked in database
        $wpdb->update($table, array('revoked_at' => current_time('mysql')), array('hash_jti' => $hash_jti));
        
        $this->debug_log('Revoking JWT', array(
            'jti' => $jti,
            'hash_jti' => $hash_jti,
            'event_id' => $event_id,
            'email' => $this->redact_email($email)
        ));
        
        // Store in Redis for fast lookup (both hash_jti and jti for compatibility)
        $redis = $this->get_redis_connection();
        if ($redis) {
            // Mark as revoked in Redis
            $redis->setex("revoked:{$hash_jti}", 86400, "revoked"); // 24 hours
            $redis->setex("revoked:{$jti}", 86400, "revoked"); // 24 hours
            
            // Clear all cached JWT data
            $redis->del("jwt_token:{$jti}");
            $redis->del("jwt_token:{$hash_jti}");
            
            // Update JWT data in Redis to mark as revoked
            $jwt_data = $redis->get("jwt:{$jti}");
            if ($jwt_data) {
                $jwt_info = json_decode($jwt_data, true);
                if ($jwt_info) {
                    $jwt_info['revoked'] = true;
                    $jwt_info['revoked_at'] = gmdate('Y-m-d H:i:s');
                    $redis->setex("jwt:{$jti}", 86400, json_encode($jwt_info)); // 24 hours
                }
            }
            
            // Clear event access cache for this event and email
            if (!empty($event_id)) {
                // Clear all event access caches for this event (pattern matching)
                $email_hash = hash('sha256', strtolower(trim($email)));
                
                // Clear session-based access cache
                $session_id = $redis->get("jti_session:{$jti}");
                if ($session_id) {
                    $cache_key = "event_access:{$event_id}:{$session_id}";
                    $redis->del($cache_key);
                    $this->debug_log('Cleared event access cache', array('cache_key' => $cache_key));
                }
                
                // Clear session status cache
                $status_key = "session_status:{$event_id}:{$email_hash}";
                $redis->del($status_key);
                
                // Clear active sessions list
                $redis->del("active_sessions:{$event_id}:{$email_hash}");
                
                // Clear JTI to session mapping
                $redis->del("jti_session:{$jti}");
                $redis->del("jti_session:{$hash_jti}");
                
                // Clear session-related memory cache if we have session ID
                if ($session_id) {
                    unset(self::$memory_cache["jwt_session:{$session_id}"]);
                    unset(self::$memory_cache["session_val:{$session_id}"]);
                }
                
                // Clear all event access caches for this event using SCAN (non-blocking, unlike KEYS)
                $pattern = "event_access:{$event_id}:*";
                try {
                    $cursor = null;
                    $total_deleted = 0;
                    do {
                        $result = $redis->scan($cursor, $pattern, 100);
                        if ($result && is_array($result) && count($result) > 0) {
                            $redis->del($result);
                            $total_deleted += count($result);
                        }
                    } while ($cursor > 0);
                    if ($total_deleted > 0) {
                        $this->debug_log('Cleared all event access caches for event', array(
                            'event_id' => $event_id,
                            'keys_cleared' => $total_deleted
                        ));
                    }
                } catch (Exception $e) {
                    $this->debug_log('Error clearing event access cache pattern', array(
                        'pattern' => $pattern,
                        'error' => $e->getMessage()
                    ));
                }
            }
        }
        
        // Clear in-memory cache (always, even if Redis is not available)
        unset(self::$memory_cache["jwt:{$jti}"]);
        unset(self::$memory_cache["jwt_token:{$jti}"]);
        unset(self::$memory_cache["jwt_val:{$jti}"]);
        
        if (!empty($event_id)) {
            unset($this->event_access_cache[$event_id]);
        }
        
        return true;
    }
}
