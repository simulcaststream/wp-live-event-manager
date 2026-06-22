<?php
/**
 * Magic Link Service
 *
 * Encapsulates magic-link emails, token generation/validation, and resend flows.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_Magic_Link_Service {
    /** @var LiveEventManager */
    private $plugin;

    public function __construct($plugin) {
        $this->plugin = $plugin;
    }

    public function send_magic_link_email($email, $jwt, $event_id, $session_id = null) {
        $event = $this->plugin->get_event_by_id($event_id);
        if (!$event) {
            return array('sent' => false, 'error' => 'Event not found.');
        }

        $magic_token = $this->generate_magic_token($email, $event_id, $session_id);
        if ($magic_token === false) {
            return array(
                'sent'  => false,
                'error' => 'Magic links require Upstash Redis. Configure it under Live Events → Settings → Cache & Access.',
            );
        }

        $magic_link = $this->plugin->get_event_url($event_id, array('magic' => $magic_token));

        $unique_code = $this->extract_unique_code($jwt, $session_id);
        $resend_url = $this->plugin->get_event_url($event_id);

        $subject = 'Your Stream Access Link - ' . $event->title;
        $message = "Hello,\n\n";
        $message .= "Here's your access link for the stream: " . $event->title . "\n\n";
        $message .= "Click the link below to access the stream:\n";
        $message .= $magic_link . "\n\n";
        $message .= "⚠️  IMPORTANT: This link is ONE-TIME USE ONLY.\n";
        $message .= "• Do not share this link with others\n";
        $message .= "• If you access from a different device, you'll get a new link\n";
        $message .= "• Previous sessions will be automatically revoked\n\n";
        $message .= "This link will expire in 24 hours.\n\n";
        if (!empty($unique_code)) {
            $message .= "Your access code: " . $unique_code . "\n";
            $message .= "Keep this handy—combine it with your email on the resend page if you ever need another link.\n\n";
        }
        $message .= "Need a new link later? Visit: " . $resend_url . "\n\n";
        $message .= "Best regards,\nLive Event Team";

        // Use the WordPress admin email as the From address.
        // SendLayer (and most transactional providers) require the From domain
        // to be verified. WordPress's default (wordpress@domain) is often
        // different from the verified sender — the admin email is more reliable.
        $from_email = get_option('admin_email');
        $from_name  = get_bloginfo('name') ?: 'Live Events';
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_email . '>',
            'Reply-To: ' . $from_email,
        );

        // Clear any stale error before sending so we capture a fresh one
        delete_transient('lem_last_mail_error');

        $sent = wp_mail($email, $subject, $message, $headers);

        if (!$sent) {
            $error = get_transient('lem_last_mail_error') ?: 'wp_mail() returned false — check your SMTP configuration in the plugin settings.';
            $this->plugin->debug_log('Magic link email failed', array(
                'to'    => $email,
                'error' => $error,
            ));
            return array('sent' => false, 'error' => $error);
        }

        return array(
            'sent'        => true,
            'magic_link'  => $magic_link,
            'magic_token' => $magic_token,
        );
    }

    private function extract_unique_code($jwt, $session_id) {
        if (!empty($jwt)) {
            $parts = explode('.', $jwt);
            if (count($parts) === 3) {
                $segment = $parts[1];
                $b64     = strtr($segment, '-_', '+/');
                $pad     = (4 - (strlen($b64) % 4)) % 4;
                $payload = json_decode(base64_decode($b64 . str_repeat('=', $pad), true), true);
                if (!empty($payload['jti'])) {
                    return substr($payload['jti'], 0, 8);
                }
            }
        }

        if (!empty($session_id)) {
            return substr($session_id, 0, 8);
        }

        return '';
    }

    public function generate_magic_token($email, $event_id, $session_id = null) {
        $redis = $this->plugin->get_redis_connection();
        if (! $redis) {
            $this->plugin->debug_log('generate_magic_token: Redis unavailable — token not stored');
            return false;
        }

        $token   = bin2hex(random_bytes(32));
        $expires = time() + (24 * 60 * 60);

        $magic_data = array(
            'token'      => $token,
            'email'      => $email,
            'event_id'   => (string) $event_id,
            'session_id' => $session_id,
            'created_at' => time(),
            'expires_at' => $expires,
            'consumed'   => false,
        );

        $redis->setex('magic_token:' . $token, 24 * 60 * 60, json_encode($magic_data));

        return $token;
    }

    public function validate_magic_token($token) {
        $this->plugin->debug_log('Magic token validation started', array('token' => substr($token, 0, 20) . '...'));

        $redis = $this->plugin->get_redis_connection();
        if (!$redis) {
            $settings = get_option('lem_settings', array());
            $redis_enabled = !empty($settings['use_redis']);
            $this->plugin->debug_log('Magic token validation failed - Redis unavailable', array('redis_enabled' => $redis_enabled));

            $message = $redis_enabled
                ? 'Redis connection failed. Check Live Events > Settings and ensure Redis is running.'
                : 'Magic links require Redis. Open the watch link from your email or enable Redis in Live Events settings.';

            return array('valid' => false, 'error' => $message);
        }

        $key = 'magic_token:' . $token;
        $magic_data_json = $redis->get($key);
        if (!$magic_data_json) {
            $this->plugin->debug_log('Magic token validation failed - Token not found in Redis', array('key' => $key));
            return array('valid' => false, 'error' => 'Invalid or expired magic token');
        }

        $magic_data = json_decode($magic_data_json, true);
        $this->plugin->debug_log('Magic token data retrieved', array(
            'email' => $magic_data['email'] ?? 'unknown',
            'event_id' => $magic_data['event_id'] ?? 'unknown',
            'consumed' => $magic_data['consumed'] ?? 'unknown',
            'expires_at' => $magic_data['expires_at'] ?? 'unknown',
            'current_time' => time()
        ));

        if ($magic_data['consumed']) {
            $this->plugin->debug_log('Magic token validation failed - Token already consumed');
            return array('valid' => false, 'error' => 'Magic token already used');
        }

        if (time() > $magic_data['expires_at']) {
            return array('valid' => false, 'error' => 'Magic token expired');
        }

        // Atomic consume: single SET NX EX so the lock is created with its TTL in one
        // round-trip. The old SETNX + SETEX pattern had a window where the key could
        // exist without a TTL if the process crashed between the two calls, making the
        // token permanently unusable without manual intervention.
        $lock_key   = 'magic_lock:' . $token;
        $lock_taken = $redis->set_nx_ex($lock_key, '1', 300);
        if (!$lock_taken) {
            return array('valid' => false, 'error' => 'Magic token already used');
        }

        if (class_exists('LEM_Access') && LEM_Access::is_email_revoked_for_event($magic_data['email'], $magic_data['event_id'])) {
            return array('valid' => false, 'error' => 'Access for this email has been revoked for this event.');
        }

        $jti = $this->resolve_jti_for_magic_token($magic_data, $redis);
        if (!$jti) {
            $this->plugin->debug_log('No valid JTI found for magic token');
            return array('valid' => false, 'error' => 'No valid JWT found for this access');
        }

        $session_id = $this->plugin->create_session($magic_data['event_id'], $magic_data['email']);

        $magic_data['consumed'] = true;
        $magic_data['consumed_at'] = time();
        $magic_data['session_id'] = $session_id;
        $redis->setex($key, 24 * 60 * 60, json_encode($magic_data));

        $this->plugin->ensure_playback_blob($magic_data['email'], $magic_data['event_id']);

        return array(
            'valid' => true,
            'email' => $magic_data['email'],
            'event_id' => $magic_data['event_id'],
            'session_id' => $session_id,
            'device_change' => false
        );
    }

    private function resolve_jti_for_magic_token($magic_data, $redis) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'lem_jwt_tokens';
        $now        = date('Y-m-d H:i:s', time());

        // Try the JTI stored in the original session first — but VERIFY it is still
        // active in MySQL. If the token was revoked after the magic link was sent
        // (e.g. a second webhook attempt called invalidate_existing_tokens before
        // failing), fall through to the general MySQL lookup.
        if (!empty($magic_data['session_id'])) {
            $original_session_data = $redis->get('session:' . $magic_data['session_id']);
            if ($original_session_data) {
                $original_session = json_decode($original_session_data, true);
                if ($original_session && isset($original_session['jti'])) {
                    $candidate_jti = $original_session['jti'];
                    $still_active  = $wpdb->get_var($wpdb->prepare(
                        "SELECT jti FROM {$table_name}
                         WHERE jti = %s AND revoked_at IS NULL AND expires_at > %s
                         LIMIT 1",
                        $candidate_jti,
                        $now
                    ));
                    if ($still_active) {
                        $this->plugin->debug_log('Using original JTI from session (verified active)', array('jti' => $candidate_jti));
                        return $candidate_jti;
                    }
                    $this->plugin->debug_log('Session JTI is revoked or expired — falling through to DB lookup', array('jti' => $candidate_jti));
                }
            }
        }

        $jwt_record = $wpdb->get_row($wpdb->prepare(
            "SELECT jti FROM {$table_name}
             WHERE email    = %s
               AND event_id = %s
               AND revoked_at IS NULL
               AND expires_at > %s
             ORDER BY created_at DESC LIMIT 1",
            $magic_data['email'],
            $magic_data['event_id'],
            $now
        ));

        if ($jwt_record) {
            $this->plugin->debug_log('Using JTI from database', array('jti' => $jwt_record->jti));
            return $jwt_record->jti;
        }

        return null;
    }

    public function send_new_device_magic_link($email, $event_id, $session_id) {
        $event = $this->plugin->get_event_by_id($event_id);
        if (!$event) {
            return false;
        }

        $magic_token = $this->generate_magic_token($email, $event_id, $session_id);
        $magic_link = $this->plugin->get_event_url($event_id, array('magic' => $magic_token));

        $subject = 'New Access Link - New Device Detected - ' . $event->title;
        $message = "Hello,\n\n";
        $message .= "We detected you're accessing from a new device for: " . $event->title . "\n\n";
        $message .= "Your previous session has been revoked for security.\n";
        $message .= "Here's your new access link:\n";
        $message .= $magic_link . "\n\n";
        $message .= "⚠️  IMPORTANT: This link is ONE-TIME USE ONLY.\n";
        $message .= "• Do not share this link with others\n";
        $message .= "• Previous sessions have been automatically revoked\n\n";
        $message .= "This link will expire in 24 hours.\n\n";
        $message .= "Best regards,\nLive Event Team";

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        return wp_mail($email, $subject, $message, $headers);
    }

    public function validate_email_and_send_link($email, $event_id) {
        $email    = $this->normalize_resend_email($email);
        $event_id = $this->normalize_event_id($event_id);

        if ($event_id === '0' || ! is_email($email)) {
            return array('valid' => false, 'error' => 'A valid email and event are required.');
        }

        if (class_exists('LEM_Access') && LEM_Access::is_email_revoked_for_event($email, $event_id)) {
            return array('valid' => false, 'error' => 'Access for this email has been revoked for this event.');
        }

        if (! $this->has_entitlement_for_resend($email, $event_id)) {
            return array(
                'valid' => false,
                'error' => 'No ticket found for this email and event. Use the same email you used at checkout or when joining.',
            );
        }

        $jwt_token = $this->resolve_jwt_for_resend($email, $event_id);
        if ($jwt_token === '') {
            $detail = '';
            if (property_exists($this->plugin, 'last_jwt_error') && ! empty($this->plugin->last_jwt_error)) {
                $detail = ' ' . $this->plugin->last_jwt_error;
            }
            return array(
                'valid' => false,
                'error' => 'Could not issue a new playback token.' . $detail,
            );
        }

        $new_session_id = $this->plugin->create_session($event_id, $email);
        $result         = $this->send_magic_link_email($email, $jwt_token, $event_id, $new_session_id);

        $mail_ok = is_array($result) && ! empty($result['sent']);
        if ($mail_ok) {
            return array('valid' => true, 'message' => 'New access link sent to your email');
        }
        $err = (is_array($result) && ! empty($result['error'])) ? $result['error'] : 'Failed to send access link';
        return array('valid' => false, 'error' => $err);
    }

    /**
     * True when the viewer may request a magic link (active JWT, expired ticket, golden email list, or Redis playback).
     */
    public function has_entitlement_for_resend($email, $event_id) {
        if ($this->has_valid_ticket($email, $event_id)) {
            return true;
        }

        if ($this->is_email_on_event_allowlist($email, $event_id)) {
            return true;
        }

        if ($this->get_latest_token_row($email, $event_id, false) !== null) {
            return true;
        }

        if ($this->has_playback_blob($email, $event_id)) {
            return true;
        }

        return false;
    }

    /**
     * Active (non-expired) JWT for watch/resend, or freshly generated when entitlement exists but JWT lapsed.
     */
    private function resolve_jwt_for_resend($email, $event_id) {
        $active = $this->get_latest_token_row($email, $event_id, true);
        if ($active && ! empty($active->jwt_token)) {
            return $active->jwt_token;
        }

        $latest     = $this->get_latest_token_row($email, $event_id, false);
        $payment_id = ($latest && ! empty($latest->payment_id)) ? $latest->payment_id : null;

        $jwt_result = $this->plugin->generate_jwt($email, $event_id, $payment_id, true);
        if ($jwt_result && is_array($jwt_result) && ! empty($jwt_result['jwt'])) {
            return $jwt_result['jwt'];
        }

        return '';
    }

    private function normalize_resend_email($email) {
        return strtolower(sanitize_email($email));
    }

    private function normalize_event_id($event_id) {
        return (string) max(0, intval($event_id));
    }

    private function is_email_on_event_allowlist($email, $event_id) {
        $event_emails = get_post_meta((int) $event_id, '_lem_event_emails', true);
        if (! is_array($event_emails)) {
            return false;
        }
        return in_array($this->normalize_resend_email($email), $event_emails, true);
    }

    private function has_playback_blob($email, $event_id) {
        if (! class_exists('LEM_Access')) {
            return false;
        }
        $redis = $this->plugin->get_redis_connection();
        if (! $redis) {
            return false;
        }
        return (bool) $redis->get(LEM_Access::playback_key($email, $event_id));
    }

    /**
     * @param bool $active_only When true, only rows with expires_at in the future.
     * @return object|null
     */
    private function get_latest_token_row($email, $event_id, $active_only) {
        global $wpdb;
        $table_name   = $wpdb->prefix . 'lem_jwt_tokens';
        $email        = $this->normalize_resend_email($email);
        $event_id_int = (int) $this->normalize_event_id($event_id);

        if ($event_id_int <= 0 || ! is_email($email)) {
            return null;
        }

        $expires_sql = $active_only ? ' AND expires_at > %s' : '';
        $prepare_args  = array($email, $event_id_int);
        if ($active_only) {
            $prepare_args[] = date('Y-m-d H:i:s', time());
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name}
             WHERE LOWER(email) = %s
               AND CAST(event_id AS UNSIGNED) = %d
               AND revoked_at IS NULL
               {$expires_sql}
             ORDER BY created_at DESC
             LIMIT 1",
            ...$prepare_args
        ));
    }

    public function has_valid_ticket($email, $event_id) {
        $email        = $this->normalize_resend_email($email);
        $event_id_int = (int) $this->normalize_event_id($event_id);

        if ($event_id_int <= 0 || ! is_email($email)) {
            return false;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'lem_jwt_tokens';
        $now        = date('Y-m-d H:i:s', time());

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name}
             WHERE LOWER(email) = %s
               AND CAST(event_id AS UNSIGNED) = %d
               AND payment_id IS NOT NULL AND payment_id != ''
               AND revoked_at IS NULL
               AND expires_at > %s",
            $email,
            $event_id_int,
            $now
        ));

        if ($result) {
            $this->plugin->debug_log('Valid paid ticket found', array(
                'email'      => $email,
                'event_id'   => $event_id_int,
                'payment_id' => $result->payment_id,
                'jti'        => $result->jti,
            ));
            $this->cache_session_status((string) $event_id_int, $email, true);
            return true;
        }

        $free_result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name}
             WHERE LOWER(email) = %s
               AND CAST(event_id AS UNSIGNED) = %d
               AND revoked_at IS NULL
               AND expires_at > %s",
            $email,
            $event_id_int,
            $now
        ));

        if ($free_result) {
            $this->plugin->debug_log('Valid free ticket found', array(
                'email'      => $email,
                'event_id'   => $event_id_int,
                'payment_id' => $free_result->payment_id,
                'jti'        => $free_result->jti,
            ));
            $this->cache_session_status((string) $event_id_int, $email, true);
            return true;
        }

        $this->plugin->debug_log('No valid ticket found', array('email' => $email, 'event_id' => $event_id_int));
        $this->cache_session_status((string) $event_id_int, $email, false);
        return false;
    }

    public function cache_session_status($event_id, $email, $valid) {
        $redis = $this->plugin->get_redis_connection();
        if (!$redis) {
            return;
        }

        $email_hash = hash('sha256', strtolower(trim($email)));
        $key = 'session_status:' . $event_id . ':' . $email_hash;
        $redis->setex($key, 5 * 60, json_encode(array(
            'valid' => (bool) $valid,
            'event_id' => $event_id
        )));
    }

    public function get_active_session_for_email_event($email, $event_id) {
        $redis = $this->plugin->get_redis_connection();
        if (!$redis) {
            return [];
        }

        $email_hash = hash('sha256', $email);
        $json       = $redis->get("active_sessions:{$event_id}:{$email_hash}");
        return $json ? (json_decode($json, true) ?: []) : [];
    }

    public function get_jwt_for_session($session_id) {
        $redis = $this->plugin->get_redis_connection();
        if (!$redis) {
            return null;
        }

        $session_data_json = $redis->get('session:' . $session_id);
        if (!$session_data_json) {
            return null;
        }

        $session_data = json_decode($session_data_json, true);
        if (!$session_data || empty($session_data['active'])) {
            return null;
        }

        $jwt_token = $redis->get('jwt_token:' . $session_data['jti']);
        if ($jwt_token) {
            $this->plugin->debug_log('JWT retrieved from Redis cache', array(
                'session_id' => $session_id,
                'jti' => $session_data['jti']
            ));
            return $jwt_token;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lem_jwt_tokens';
        $token_record = $wpdb->get_row($wpdb->prepare(
            "SELECT jwt_token FROM $table WHERE jti = %s AND revoked_at IS NULL",
            $session_data['jti']
        ));

        if ($token_record) {
            $redis->setex('jwt_token:' . $session_data['jti'], 24 * 60 * 60, $token_record->jwt_token);
            $this->plugin->debug_log('JWT retrieved from database and cached in Redis', array(
                'session_id' => $session_id,
                'jti' => $session_data['jti']
            ));
            return $token_record->jwt_token;
        }

        return null;
    }
}
