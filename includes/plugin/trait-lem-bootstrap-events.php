<?php
/**
 * Bootstrap, lifecycle hooks, events CPT/editor, frontend routing, viewer sessions, public blocks & shortcodes.
 *
 * @package Live_Event_Manager
 */

trait LEM_Trait_Bootstrap_And_Events {
    public function __construct() {
        // Initialize device identification service
        $this->device_service = new DeviceIdentificationService();
        $this->magic_link_service = new LEM_Magic_Link_Service($this);
        
        // Load streaming provider factory and initialize provider
        $this->load_streaming_provider();
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_menu', array($this, 'remove_add_new_submenu'), 999);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // AJAX handlers (admin-only: public access goes through Stripe checkout or magic links)
        add_action('wp_ajax_lem_generate_jwt', array($this, 'ajax_generate_jwt'));

        // Public: free-event access (validates event is actually free before issuing)
        add_action('wp_ajax_lem_free_event_access', array($this, 'ajax_free_event_access'));
        add_action('wp_ajax_nopriv_lem_free_event_access', array($this, 'ajax_free_event_access'));
        add_action('wp_ajax_lem_revoke_jwt', array($this, 'ajax_revoke_jwt'));
        
        // Stream setup AJAX handlers
        add_action('wp_ajax_lem_get_rtmp_info', array($this, 'ajax_get_rtmp_info'));
        add_action('wp_ajax_lem_create_simulcast_target', array($this, 'ajax_create_simulcast_target'));
        add_action('wp_ajax_lem_delete_simulcast_target', array($this, 'ajax_delete_simulcast_target'));
        add_action('wp_ajax_lem_save_stream_id', array($this, 'ajax_save_stream_id'));
        
        // Provider upload was removed in the three-package architecture.
        // Providers live in adaptor plugins (Free Adaptors / Premium) and register
        // through the factories. Keeping a write-into-plugin-dir upload endpoint
        // is both brittle and a security risk.

        // Template pack management
        add_action('wp_ajax_lem_upload_template',   array($this, 'ajax_upload_template'));
        add_action('wp_ajax_lem_activate_template', array($this, 'ajax_activate_template'));
        add_action('wp_ajax_lem_delete_template',   array($this, 'ajax_delete_template'));
        add_action('admin_post_lem_download_pack',  array($this, 'handle_download_pack'));

        // Stream management AJAX handlers
        add_action('wp_ajax_lem_create_stream', array($this, 'ajax_create_stream'));
        add_action('wp_ajax_lem_delete_stream', array($this, 'ajax_delete_stream'));
        add_action('wp_ajax_lem_update_stream', array($this, 'ajax_update_stream'));
        add_action('wp_ajax_lem_get_stream_details', array($this, 'ajax_get_stream_details'));
        
        // Restrictions AJAX handlers
        add_action('wp_ajax_lem_create_restriction', array($this, 'ajax_create_restriction'));
        add_action('wp_ajax_lem_get_restrictions', array($this, 'ajax_get_restrictions'));
        add_action('wp_ajax_lem_delete_restriction', array($this, 'ajax_delete_restriction'));
        
        // JWT management AJAX handlers
        add_action('wp_ajax_lem_get_jwt_tokens', array($this, 'ajax_get_jwt_tokens'));
        add_action('wp_ajax_lem_revoke_emails_for_event', array($this, 'ajax_revoke_emails_for_event'));

        
        // Stripe session creation
        add_action('wp_ajax_lem_create_stripe_session', array($this, 'ajax_create_stripe_session'));
        add_action('wp_ajax_nopriv_lem_create_stripe_session', array($this, 'ajax_create_stripe_session'));
        
        // Regenerate JWT endpoint
        add_action('wp_ajax_lem_regenerate_jwt', array($this, 'ajax_regenerate_jwt'));
        add_action('wp_ajax_nopriv_lem_regenerate_jwt', array($this, 'ajax_regenerate_jwt'));
        add_action('wp_ajax_lem_check_event_access', array($this, 'ajax_check_event_access'));
        add_action('wp_ajax_nopriv_lem_check_event_access', array($this, 'ajax_check_event_access'));
        
        // Redis connection test (useful for troubleshooting)
        add_action('wp_ajax_lem_test_redis_connection', array($this, 'ajax_test_redis_connection'));

        // Email delivery test
        add_action('wp_ajax_lem_test_email', array($this, 'ajax_test_email'));

        // Capture wp_mail() failures so we can surface them
        add_action('wp_mail_failed', array($this, 'on_wp_mail_failed'));
        
        // Session management AJAX
        add_action('wp_ajax_lem_revoke_session', array($this, 'ajax_revoke_session'));
        add_action('wp_ajax_nopriv_lem_revoke_session', array($this, 'ajax_revoke_session'));
        
        // Email validation for device change
        add_action('wp_ajax_lem_validate_email', array($this, 'ajax_validate_email'));
        add_action('wp_ajax_nopriv_lem_validate_email', array($this, 'ajax_validate_email'));
        
        // Clear all tokens
        add_action('wp_ajax_lem_clear_all_tokens', array($this, 'ajax_clear_all_tokens'));
        
        // Canonical payment webhook — use this single URL in every payment provider's dashboard.
        // The handler auto-detects the provider from request headers and delegates to its
        // verify_webhook() method. Vendor-specific webhook URLs (Mux, PayPal capture, etc.)
        // are owned by adaptor plugins — core ships no vendor-specific AJAX actions.
        add_action('wp_ajax_lem_payment_webhook',        array($this, 'handle_payment_webhook'));
        add_action('wp_ajax_nopriv_lem_payment_webhook', array($this, 'handle_payment_webhook'));
        // Back-compat for Stripe CLI / dashboards still using the old action name.
        add_action('wp_ajax_lem_stripe_webhook',        array($this, 'handle_payment_webhook'));
        add_action('wp_ajax_nopriv_lem_stripe_webhook', array($this, 'handle_payment_webhook'));

        add_action('wp_ajax_lem_reconcile_payment',        array($this, 'ajax_reconcile_payment'));
        add_action('wp_ajax_nopriv_lem_reconcile_payment', array($this, 'ajax_reconcile_payment'));

        // Ably chat token endpoint
        add_action('wp_ajax_lem_ably_token', array($this, 'ajax_ably_token'));
        add_action('wp_ajax_nopriv_lem_ably_token', array($this, 'ajax_ably_token'));
        
        // JWT settings save endpoint
        add_action('wp_ajax_lem_save_jwt_settings', array($this, 'ajax_save_jwt_settings'));
        
        // REST API endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Activation and deactivation hooks
        register_activation_hook(LEM_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(LEM_PLUGIN_FILE, array($this, 'deactivate'));

        // Show admin notice if Upstash is not configured
        add_action('admin_notices', array('LEM_Cache', 'maybe_show_config_notice'));

        // Custom event editor: intercept native WP editor, save event, status endpoint
        add_action('admin_init',            array($this, 'intercept_event_edit'));
        add_action('wp_ajax_lem_save_event',        array($this, 'ajax_save_event'));
        add_action('wp_ajax_lem_get_event_status',  array($this, 'ajax_get_event_status'));

        // Webhook activity log
        add_action('wp_ajax_lem_get_webhook_log',   array($this, 'ajax_get_webhook_log'));
        add_action('wp_ajax_lem_clear_webhook_log', array($this, 'ajax_clear_webhook_log'));

        // Events list: custom columns and edit-link override
        add_filter('manage_lem_event_posts_columns',       array($this, 'event_list_columns'));
        add_action('manage_lem_event_posts_custom_column', array($this, 'event_list_column_content'), 10, 2);
        add_filter('post_row_actions',                     array($this, 'event_list_row_actions'), 10, 2);
    }
    
    public function init() {
        // Ensure database tables exist
        $this->ensure_tables_exist();
        if (class_exists('LEM_Access')) {
            LEM_Access::ensure_revocations_table();
        }
        
        // Register post type and blocks (only if not already registered)
        if (!post_type_exists('lem_event')) {
            $this->register_event_post_type();
        }
        
        $this->add_rewrite_rules();
        
        $this->register_blocks();
        
        // Register shortcodes
        add_shortcode('simulcast_player', array($this, 'render_simulcast_player_shortcode'));
        
        // Register hooks (always register these)
        add_action('add_meta_boxes', array($this, 'add_event_meta_boxes'));
        add_action('save_post', array($this, 'save_event_meta'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_event_edit_assets'));
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_editor_assets'));
        add_filter('single_template', array($this, 'load_event_template'));
        add_filter('archive_template', array($this, 'load_event_archive_template'));
        add_filter('template_include', array($this, 'load_confirmation_template'));
        add_filter('template_include', array($this, 'load_additional_templates'));
        add_action('template_redirect', array($this, 'handle_confirmation_redirect'));
        add_action('template_redirect', array($this, 'handle_events_redirect'));
        add_action('template_redirect', array($this, 'redirect_homepage_to_event'));
        add_action('template_redirect', array($this, 'handle_event_magic_link'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_filter( 'theme_page_templates', function( $templates ) {
            $templates['page-events.php'] = __( 'Events (lem_events)', 'live-event-manager' );
            return $templates;
        });
        add_filter( 'template_include', function( $template ) {
            if ( is_page() ) {
                $page_template = get_page_template_slug( get_queried_object_id() );
                if ( $page_template === 'page-events.php' ) {
                    $template = LEM_Template_Manager::resolve_template_file('page-events.php');
                }
            }
            return $template;
        });
        
        
    }
    
    /**
     * Load streaming provider using factory.
     *
     * The interface and factory are required at the plugin entry-point
     * (live-event-manager.php) so this just resolves the currently-active
     * provider. When no adaptor plugin has registered anything, this is null.
     */
    private function load_streaming_provider() {
        if (!class_exists('LEM_Streaming_Provider_Factory')) {
            $this->streaming_provider = null;
            return;
        }
        $factory = LEM_Streaming_Provider_Factory::get_instance();
        $this->streaming_provider = $factory->get_active_provider($this);
    }
    
    /**
     * Get the active streaming provider
     */
    public function get_streaming_provider() {
        if ($this->streaming_provider === null) {
            $this->load_streaming_provider();
        }
        return $this->streaming_provider;
    }
    
    // Debug logging method
    public function debug_log($message, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // Ensure message is a string and clean
            $message = is_string($message) ? $message : (string) $message;
            $message = $this->sanitize_log_string($message);
            
            $log_message = '[LEM] ' . $message;
            
            if ($data !== null) {
                // Handle different data types more carefully
                $encoded_data = null;
                
                if (is_string($data)) {
                    $encoded_data = $this->sanitize_log_string($data);
                } elseif (is_array($data) || is_object($data)) {
                    // Try JSON encoding first
                    $encoded_data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if ($encoded_data === false) {
                        // If JSON fails, use var_export
                        $encoded_data = var_export($data, true);
                    }
                    $encoded_data = $this->sanitize_log_string($encoded_data);
                } elseif (is_numeric($data) || is_bool($data)) {
                    $encoded_data = (string) $data;
                } else {
                    // For any other type, convert to string safely
                    $encoded_data = $this->sanitize_log_string((string) $data);
                }
                
                if ($encoded_data !== null) {
                    $log_message .= ' - ' . $encoded_data;
                }
            }
            
            // Ensure final log message is a valid string and not too long
            if (is_string($log_message) && strlen($log_message) < 8192) {
                error_log($log_message);
            }
        }
    }
    
    // Sanitize strings for logging to prevent binary data corruption
    private function sanitize_log_string($string) {
        if (!is_string($string)) {
            return (string) $string;
        }
        
        // Remove null bytes and other control characters that could corrupt the log
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $string);
        
        // Ensure it's valid UTF-8
        if (!mb_check_encoding($string, 'UTF-8')) {
            $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        }
        
        // Limit length to prevent huge log entries
        if (strlen($string) > 1000) {
            $string = substr($string, 0, 1000) . '... [truncated]';
        }
        
        return $string;
    }
    
    private function ensure_tables_exist() {
        if (get_option('lem_db_tables_v1') === '1') {
            $this->ensure_webhook_log_table();
            return;
        }
        $this->create_tables();
        $this->ensure_webhook_log_table();
        update_option('lem_db_tables_v1', '1');
    }

    /**
     * Lazily create the webhook activity log table.
     * Stores recent inbound payment webhook attempts so admins can verify
     * Stripe / PayPal are actually reaching the endpoint.
     */
    private function ensure_webhook_log_table() {
        if (get_option('lem_webhook_log_v1') === '1') {
            return;
        }
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'lem_webhook_log';
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            received_at DATETIME NOT NULL,
            provider VARCHAR(32) DEFAULT NULL,
            source_ip VARCHAR(64) DEFAULT NULL,
            has_signature TINYINT(1) DEFAULT 0,
            event_type VARCHAR(64) DEFAULT NULL,
            payment_id VARCHAR(128) DEFAULT NULL,
            event_id VARCHAR(64) DEFAULT NULL,
            email VARCHAR(190) DEFAULT NULL,
            status VARCHAR(32) NOT NULL,
            message TEXT DEFAULT NULL,
            payload_preview TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_received_at (received_at)
        ) {$charset_collate};";
        $wpdb->query($sql);
        update_option('lem_webhook_log_v1', '1');
    }
    
    public function activate() {
        $this->debug_log('Plugin activation started');
        
        // Create database tables
        $this->create_tables();
        
        $this->add_rewrite_rules();
        flush_rewrite_rules();
        
        // Set default options. Core ships only cross-cutting keys; every
        // provider-specific key (Mux, Stripe, PayPal, OME, …) is seeded by its
        // own adaptor plugin activation hook.
        if (!get_option('lem_settings')) {
            update_option('lem_settings', array(
                // Cache (Upstash)
                'upstash_redis_url'   => '',
                'upstash_redis_token' => '',
                // Access
                'jwt_expiration_hours'         => 24,
                'jwt_refresh_duration_minutes' => 15,
                // Debug
                'debug_mode' => 0,
            ));
        }
        
        $this->debug_log('Plugin activation completed');
    }
    
    public function deactivate() {
        // Clear rewrite rules
        flush_rewrite_rules();
        
        // Clear any scheduled cron jobs
        wp_clear_scheduled_hook('lem_cleanup_expired_sessions');
        
        $this->debug_log('Plugin deactivated - rewrite rules cleared');
    }
    
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_jwts = $wpdb->prefix . 'lem_jwt_tokens';
        
        // Create table with basic structure first
        $sql_jwts = "CREATE TABLE IF NOT EXISTS $table_jwts (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            jti varchar(255) NOT NULL,
            hash_jti varchar(255) NOT NULL,
            jwt_token LONGTEXT,
            email varchar(255) NOT NULL,
            event_id varchar(100) NOT NULL,
            payment_id varchar(255),
            ip_address varchar(45),
            expires_at datetime NOT NULL,
            revoked_at datetime NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        $result = $wpdb->query($sql_jwts);
        
        if ($result !== false) {
            $indexes_sql = array(
                "ALTER TABLE $table_jwts ADD UNIQUE KEY jti (jti)",
                "ALTER TABLE $table_jwts ADD KEY hash_jti (hash_jti)",
                "ALTER TABLE $table_jwts ADD KEY email (email)",
                "ALTER TABLE $table_jwts ADD KEY event_id (event_id)",
                "ALTER TABLE $table_jwts ADD KEY revoked_at (revoked_at)",
                "ALTER TABLE $table_jwts ADD KEY payment_id (payment_id)",
                "ALTER TABLE $table_jwts ADD KEY email_event (email(191), event_id)"
            );
            
            foreach ($indexes_sql as $index_sql) {
                $wpdb->query($index_sql);
            }
        }
    }
    
    // Register custom post type for events
    private function register_event_post_type() {
        register_post_type('lem_event', array(
            'labels' => array(
                'name'               => 'Live Events',
                'singular_name'      => 'Live Event',
                'menu_name'          => 'Live Events Manager',
                'add_new'            => 'Add New Event',
                'add_new_item'       => 'Add New Live Event',
                'edit_item'          => 'Edit Live Event',
                'new_item'           => 'New Live Event',
                'view_item'          => 'View Live Event',
                'search_items'       => 'Search Live Events',
                'not_found'          => 'No live events found',
                'not_found_in_trash' => 'No live events found in trash',
            ),
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_nav_menus' => false,
            'show_in_admin_bar' => true,
            'has_archive' => true,
            'supports' => array('title', 'editor', 'custom-fields', 'thumbnail'),
            'menu_icon' => 'dashicons-video-alt3',
            'menu_position' => 25,
            'show_in_rest' => true,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'rewrite' => array('slug' => 'events')
        ));
    }
    
    // Add meta boxes for event fields
    public function add_event_meta_boxes() {
        add_meta_box(
            'lem_event_details',
            'Event Details',
            array($this, 'render_event_meta_box'),
            'lem_event',
            'normal',
            'high'
        );
        
        add_meta_box(
            'lem_homepage_meta',
            'Homepage Settings',
            array($this, 'render_homepage_meta_box'),
            'lem_event',
            'side',
            'high'
        );
    }
    
    // Render event meta box
    public function render_event_meta_box($post) {
        wp_nonce_field('lem_save_event_meta', 'lem_event_meta_nonce');

        // ── Load saved meta ───────────────────────────────────────────────
        $playback_id             = get_post_meta($post->ID, '_lem_playback_id', true);
        $live_stream_id          = get_post_meta($post->ID, '_lem_live_stream_id', true);
        $stream_provider         = get_post_meta($post->ID, '_lem_stream_provider', true) ?: 'mux';
        $playback_restriction_id = get_post_meta($post->ID, '_lem_playback_restriction_id', true);
        $event_date              = get_post_meta($post->ID, '_lem_event_date', true);
        $event_end               = get_post_meta($post->ID, '_lem_event_end',  true);
        $is_free                 = get_post_meta($post->ID, '_lem_is_free', true) ?: 'free';
        $price_id                = get_post_meta($post->ID, '_lem_price_id', true);
        $display_price           = get_post_meta($post->ID, '_lem_display_price', true);
        $excerpt                 = get_post_meta($post->ID, '_lem_excerpt', true);

        // ── Fetch available streams via provider factory ───────────────────
        $available_streams    = array();
        $provider_configured  = false;
        $factory              = LEM_Streaming_Provider_Factory::get_instance();
        $settings             = get_option('lem_settings', array());
        $active_provider_id   = $settings['streaming_provider'] ?? 'mux';
        $meta_box_provider    = $factory->get_provider($active_provider_id, $this);

        if ($meta_box_provider && $meta_box_provider->is_configured()) {
            $provider_configured = true;
            $streams_result      = $meta_box_provider->list_streams(100);
            if (!is_wp_error($streams_result) && is_array($streams_result)) {
                foreach ($streams_result as $stream) {
                    $stream['_provider'] = $active_provider_id;
                    $available_streams[] = $stream;
                }
            }
        }

        // ── Build per-stream data for JS (avoid re-parsing data-* in JS) ──
        $streams_js = array();
        foreach ($available_streams as $stream) {
            $sid          = $stream['id'] ?? '';
            $playback_ids = $stream['playback_ids'] ?? array();
            $pbid         = $playback_ids[0]['id'] ?? '';
            $streams_js[$sid] = array(
                'playback_id' => $pbid,
                'stream_key'  => $stream['stream_key']  ?? '',
                'status'      => $stream['status']      ?? 'unknown',
                'name'        => $stream['passthrough'] ?? $sid,
                'provider'    => $stream['_provider']   ?? $active_provider_id,
            );
        }

        $streams_json = wp_json_encode($streams_js);
        $manage_url   = admin_url('edit.php?post_type=lem_event&page=live-event-manager-stream-management');
        $publisher_url = admin_url('edit.php?post_type=lem_event&page=live-event-manager-publisher');
        ?>

        <style>
        #lem_event_details .form-table th { width: 160px; }

        .lem-stream-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .lem-stream-row select { flex: 1; min-width: 200px; }

        #lem-stream-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: 600;
        }
        #lem-stream-badge.active { background:#edfaed; color:#2a6e2a; border:1px solid #b3e6b3; }
        #lem-stream-badge.idle   { background:#f6f7f7; color:#646970; border:1px solid #dcdcde; }
        #lem-stream-badge.empty  { display:none; }

        #lem-stream-detail {
            margin-top: 6px; font-size: 12px; color: #646970; display: none;
        }
        #lem-stream-detail code { font-size: 11px; }

        .lem-meta-section {
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: .06em; color: #8c8f94;
            padding: 16px 0 4px; border: none;
        }
        #lem-pricing-fields, #lem-display-price-field { transition: none; }
        </style>

        <table class="form-table">

            <!-- ── Stream ────────────────────────────────────────────────── -->
            <tr><td colspan="2" class="lem-meta-section">Stream</td></tr>

            <tr>
                <th><label for="lem_stream_selector">Live Stream</label></th>
                <td>
                    <?php if ( ! $provider_configured ) : ?>
                        <p class="description" style="color:#d63638;">
                            Provider not configured.
                            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=lem_event&page=live-event-manager-stream-vendors' ) ); ?>">Set up credentials →</a>
                        </p>
                    <?php elseif ( empty( $available_streams ) ) : ?>
                        <p class="description">
                            No streams yet. <a href="<?php echo esc_url( $manage_url ); ?>" target="_blank">Create one →</a>
                        </p>
                    <?php else : ?>

                    <div class="lem-stream-row">
                        <select id="lem_stream_selector" name="lem_stream_selector">
                            <option value="">— choose a stream —</option>
                            <?php foreach ( $available_streams as $stream ) :
                                $sid     = $stream['id']          ?? '';
                                $sname   = $stream['passthrough'] ?? $sid;
                                $sstatus = $stream['status']      ?? 'unknown';
                            ?>
                            <option value="<?php echo esc_attr( $sid ); ?>"
                                    <?php selected( $live_stream_id, $sid ); ?>>
                                <?php echo esc_html( $sname ); ?> — <?php echo esc_html( ucfirst( $sstatus ) ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>

                        <span id="lem-stream-badge" class="empty"></span>

                        <a href="<?php echo esc_url( $manage_url ); ?>" target="_blank"
                           class="button button-small">↗ Manage</a>
                    </div>

                    <!-- Confirmation strip shown after selection -->
                    <p id="lem-stream-detail"></p>

                    <?php endif; ?>

                    <!-- Hidden fields — saved values, written by JS on selection -->
                    <input type="hidden" id="lem_live_stream_id"  name="lem_live_stream_id"  value="<?php echo esc_attr( $live_stream_id ); ?>">
                    <input type="hidden" id="lem_playback_id"     name="lem_playback_id"     value="<?php echo esc_attr( $playback_id ); ?>">
                    <input type="hidden" id="lem_stream_provider" name="lem_stream_provider" value="<?php echo esc_attr( $stream_provider ); ?>">
                </td>
            </tr>

            <tr>
                <th><label for="lem_playback_restriction_id">Playback Restriction</label></th>
                <td>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <select id="lem_playback_restriction_id" name="lem_playback_restriction_id"
                                data-current-value="<?php echo esc_attr( $playback_restriction_id ); ?>">
                            <option value="">No restriction</option>
                            <?php if ( ! empty( $playback_restriction_id ) ) : ?>
                            <option value="<?php echo esc_attr( $playback_restriction_id ); ?>" selected>
                                <?php echo esc_html( $playback_restriction_id ); ?> (Loading…)
                            </option>
                            <?php endif; ?>
                        </select>
                        <button type="button" id="lem-refresh-restrictions" class="button button-small">Refresh</button>
                    </div>
                    <p class="description">Optionally limit playback to specific domains.</p>
                </td>
            </tr>

            <!-- ── Event Details ─────────────────────────────────────────── -->
            <tr><td colspan="2" class="lem-meta-section">Event Details</td></tr>

            <tr>
                <th><label for="lem_event_date">Start Date &amp; Time <span style="color:#d63638;">*</span></label></th>
                <td>
                    <input type="datetime-local" id="lem_event_date" name="lem_event_date"
                           value="<?php echo esc_attr( $event_date ); ?>" required>
                    <p class="description">When the live stream begins.</p>
                </td>
            </tr>

            <tr>
                <th><label for="lem_event_end">End Date &amp; Time</label></th>
                <td>
                    <input type="datetime-local" id="lem_event_end" name="lem_event_end"
                           value="<?php echo esc_attr( $event_end ); ?>">
                    <p class="description">
                        When the stream ends. Access tokens expire 2 hours after this time.
                        Leave blank to use the default expiry from Settings.
                    </p>
                </td>
            </tr>

            <tr>
                <th>Event Type</th>
                <td>
                    <label style="margin-right:16px;">
                        <input type="radio" name="lem_is_free" value="free" <?php checked( $is_free, 'free' ); ?>> Free
                    </label>
                    <label>
                        <input type="radio" name="lem_is_free" value="paid" <?php checked( $is_free, 'paid' ); ?>> Paid (Stripe)
                    </label>
                </td>
            </tr>

            <tr id="lem-pricing-fields" style="display:<?php echo $is_free === 'paid' ? 'table-row' : 'none'; ?>;">
                <th><label for="lem_price_id">Stripe Price ID</label></th>
                <td>
                    <input type="text" id="lem_price_id" name="lem_price_id"
                           value="<?php echo esc_attr( $price_id ); ?>"
                           class="regular-text" placeholder="price_xxxxxxxxxxxxxxxx">
                    <p class="description">
                        Create a one-time price in the
                        <a href="https://dashboard.stripe.com/prices" target="_blank">Stripe Dashboard</a>
                        and paste the ID here.
                    </p>
                </td>
            </tr>

            <tr id="lem-amount-field" style="display:<?php echo $is_free === 'paid' ? 'table-row' : 'none'; ?>;">
                <th><label for="lem_amount">Amount (PayPal)</label></th>
                <td>
                    <input type="text" id="lem_amount" name="lem_amount"
                           value="<?php echo esc_attr( get_post_meta( $post->ID, '_lem_amount', true ) ); ?>"
                           class="small-text" placeholder="19.99">
                    <p class="description">Numeric amount used by PayPal (e.g. <code>19.99</code>). Currency is set in Services → PayPal settings.</p>
                </td>
            </tr>

            <tr id="lem-display-price-field" style="display:<?php echo $is_free === 'paid' ? 'table-row' : 'none'; ?>;">
                <th><label for="lem_display_price">Display Price</label></th>
                <td>
                    <input type="text" id="lem_display_price" name="lem_display_price"
                           value="<?php echo esc_attr( $display_price ); ?>"
                           class="regular-text" placeholder="e.g. $19.99">
                    <p class="description">Shown on the event page next to the buy button.</p>
                </td>
            </tr>

            <tr>
                <th><label for="lem_excerpt">Excerpt</label></th>
                <td>
                    <textarea id="lem_excerpt" name="lem_excerpt" rows="3" class="large-text"
                              placeholder="Short description for the events listing (≤150 chars)…"
                    ><?php echo esc_textarea( $excerpt ); ?></textarea>
                </td>
            </tr>

        </table>

        <script>
        jQuery(document).ready(function($) {

            var streams = <?php echo $streams_json; ?>;

            // ── Apply a stream selection ──────────────────────────────────
            function applyStream(streamId) {
                var s = streams[streamId];

                if (!s) {
                    $('#lem_live_stream_id').val('');
                    $('#lem_playback_id').val('');
                    $('#lem_stream_provider').val('<?php echo esc_js( $active_provider_id ); ?>');
                    $('#lem-stream-badge').attr('class', 'empty').text('');
                    $('#lem-stream-detail').hide().text('');
                    return;
                }

                // Write hidden fields
                $('#lem_live_stream_id').val(streamId);
                $('#lem_playback_id').val(s.playback_id);
                $('#lem_stream_provider').val(s.provider);

                // Status badge
                var cls   = s.status === 'active' ? 'active' : 'idle';
                var label = s.status.charAt(0).toUpperCase() + s.status.slice(1);
                $('#lem-stream-badge').attr('class', cls).text('● ' + label);

                // Confirmation strip
                var detail = 'Playback ID: <code>' + s.playback_id + '</code>';
                if (!s.playback_id) detail = '<span style="color:#d63638;">No playback ID on this stream yet.</span>';
                $('#lem-stream-detail').html(detail).show();
            }

            // Wire up change
            $('#lem_stream_selector').on('change', function() {
                applyStream($(this).val());
            });

            // Init badge from already-saved stream
            (function() {
                var saved = $('#lem_live_stream_id').val();
                if (saved && streams[saved]) {
                    var s   = streams[saved];
                    var cls = s.status === 'active' ? 'active' : 'idle';
                    $('#lem-stream-badge').attr('class', cls)
                        .text('● ' + s.status.charAt(0).toUpperCase() + s.status.slice(1));
                    var detail = 'Playback ID: <code>' + s.playback_id + '</code>';
                    if (!s.playback_id) detail = '<span style="color:#d63638;">No playback ID on this stream yet.</span>';
                    $('#lem-stream-detail').html(detail).show();
                }
            })();

            // ── Pricing fields ────────────────────────────────────────────
            function updatePricingFields() {
                var paid = $('input[name="lem_is_free"]:checked').val() === 'paid';
                $('#lem-pricing-fields, #lem-display-price-field').toggle(paid);
            }
            $('input[name="lem_is_free"]').on('change', updatePricingFields);
            updatePricingFields();

            // ── Playback restrictions ─────────────────────────────────────
            function loadRestrictions() {
                var $sel  = $('#lem_playback_restriction_id');
                var $btn  = $('#lem-refresh-restrictions');
                var saved = $sel.data('current-value') || $sel.val();
                $btn.prop('disabled', true).text('Loading…');
                $.post(lem_ajax.ajax_url, { action: 'lem_get_restrictions', nonce: lem_ajax.nonce },
                    function(r) {
                        if (r.success && Array.isArray(r.data)) {
                            $sel.find('option:not(:first)').remove();
                            r.data.forEach(function(item) {
                                var domains = item.referrer && item.referrer.allowed_domains
                                    ? item.referrer.allowed_domains.join(', ') : '—';
                                $sel.append($('<option>').val(item.id).text(item.id + ' (' + domains + ')'));
                            });
                            if (saved) $sel.val(saved);
                        }
                    }
                ).always(function() { $btn.prop('disabled', false).text('Refresh'); });
            }

            if ($('#lem_playback_restriction_id').length) loadRestrictions();
            $('#lem-refresh-restrictions').on('click', loadRestrictions);

        });
        </script>
        <?php
    }
    
    // Save event meta data
    public function save_event_meta($post_id) {
        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Check if this is a revision
        if (wp_is_post_revision($post_id)) {
            return;
        }
        
        // Check if this is the correct post type
        if (get_post_type($post_id) !== 'lem_event') {
            return;
        }
        
        // Check nonce
        if (!isset($_POST['lem_event_meta_nonce']) || !wp_verify_nonce($_POST['lem_event_meta_nonce'], 'lem_save_event_meta')) {
            return;
        }
        
        // Check homepage meta nonce
        if (isset($_POST['lem_homepage_meta_nonce']) && wp_verify_nonce($_POST['lem_homepage_meta_nonce'], 'lem_save_homepage_meta')) {
            $this->save_homepage_setting($post_id);
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Debug: Log POST data for event type
        if (isset($_POST['lem_is_free'])) {
            $this->debug_log('Event type save - POST data received', array(
                'post_id' => $post_id,
                'lem_is_free' => $_POST['lem_is_free'],
                'all_post_fields' => array_keys($_POST)
            ));
        } else {
            $this->debug_log('Event type save - No lem_is_free in POST data', array(
                'post_id' => $post_id,
                'all_post_fields' => array_keys($_POST)
            ));
        }
        
        // Save meta fields
        $fields = array(
            'lem_playback_id' => '_lem_playback_id',
            'lem_live_stream_id' => '_lem_live_stream_id',
            'lem_stream_provider' => '_lem_stream_provider',
            'lem_playback_restriction_id' => '_lem_playback_restriction_id',
            'lem_event_date' => '_lem_event_date',
            'lem_event_end'  => '_lem_event_end',
            'lem_is_free' => '_lem_is_free',
            'lem_price_id' => '_lem_price_id',
            'lem_amount' => '_lem_amount',
            'lem_display_price' => '_lem_display_price',
            'lem_excerpt' => '_lem_excerpt',
            'lem_payment_provider' => '_lem_payment_provider',
        );
        
        foreach ($fields as $field_name => $meta_key) {
            if (isset($_POST[$field_name])) {
                $value = sanitize_text_field($_POST[$field_name]);
                update_post_meta($post_id, $meta_key, $value);
                $this->debug_log("Saved field: {$field_name} = {$value}", array('post_id' => $post_id));
                            } else {
                    // Handle radio buttons that might not be in $_POST if none selected
                    if ($field_name === 'lem_is_free') {
                        // Default to free event if no selection
                        update_post_meta($post_id, $meta_key, 'free');
                        $this->debug_log("Set default for {$field_name} = free", array('post_id' => $post_id));
                    }
                }
        }
        
        // Store event data in Redis for fast edge access
        $event_data = array(
            'event_id' => $post_id, // Use post ID as event ID
            'title' => get_the_title($post_id),
            'description' => get_post_field('post_content', $post_id),
            'playback_id' => get_post_meta($post_id, '_lem_playback_id', true),
            'live_stream_id' => get_post_meta($post_id, '_lem_live_stream_id', true),
            'playback_restriction_id' => get_post_meta($post_id, '_lem_playback_restriction_id', true),
            'event_date' => get_post_meta($post_id, '_lem_event_date', true),
            'event_end'  => get_post_meta($post_id, '_lem_event_end',  true),
            'price_id' => get_post_meta($post_id, '_lem_price_id', true),
            'is_free' => get_post_meta($post_id, '_lem_is_free', true) ?: 'free',
            'post_status' => get_post_status($post_id),
            'updated_at' => current_time('mysql')
        );
        
        $this->store_event_redis($post_id, $event_data);
        
        // Clear in-memory cache for this event
        unset(self::$memory_cache["event:{$post_id}"]);
        unset($this->event_access_cache[$post_id]);
    }
    
    // ── Custom event editor ────────────────────────────────────────────────────

    /**
     * AJAX: create or update an lem_event from the custom editor.
     * Action: lem_save_event (admin-only).
     */
    public function ajax_save_event() {
        check_ajax_referer('lem_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
        $title    = sanitize_text_field($_POST['title']    ?? '');
        $slug     = sanitize_title($_POST['slug']          ?? '');
        $status   = sanitize_text_field($_POST['status']   ?? 'publish');
        if (!in_array($status, array('publish', 'draft'), true)) {
            $status = 'publish';
        }

        $post_data = array(
            'post_title'  => $title,
            'post_status' => $status,
            'post_type'   => 'lem_event',
        );
        if ($slug) {
            $post_data['post_name'] = $slug;
        }

        if ($event_id > 0 && get_post_type($event_id) === 'lem_event') {
            $post_data['ID'] = $event_id;
            $result = wp_update_post($post_data, true);
        } else {
            $result = wp_insert_post($post_data, true);
        }

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $event_id = (int) $result;

        // Save all event meta fields
        $meta_fields = array(
            'lem_playback_id'            => '_lem_playback_id',
            'lem_live_stream_id'         => '_lem_live_stream_id',
            'lem_stream_provider'        => '_lem_stream_provider',
            'lem_playback_restriction_id'=> '_lem_playback_restriction_id',
            'lem_event_date'             => '_lem_event_date',
            'lem_event_end'              => '_lem_event_end',
            'lem_is_free'                => '_lem_is_free',
            'lem_price_id'               => '_lem_price_id',
            'lem_amount'                 => '_lem_amount',
            'lem_display_price'          => '_lem_display_price',
            'lem_excerpt'                => '_lem_excerpt',
            'lem_payment_provider'       => '_lem_payment_provider',
        );

        foreach ($meta_fields as $post_key => $meta_key) {
            if (isset($_POST[$post_key])) {
                update_post_meta($event_id, $meta_key, sanitize_text_field($_POST[$post_key]));
            }
        }

        // Refresh Redis event blob
        $event_data = array(
            'event_id'                => $event_id,
            'title'                   => get_the_title($event_id),
            'playback_id'             => get_post_meta($event_id, '_lem_playback_id', true),
            'live_stream_id'          => get_post_meta($event_id, '_lem_live_stream_id', true),
            'playback_restriction_id' => get_post_meta($event_id, '_lem_playback_restriction_id', true),
            'event_date'              => get_post_meta($event_id, '_lem_event_date', true),
            'event_end'               => get_post_meta($event_id, '_lem_event_end', true),
            'price_id'                => get_post_meta($event_id, '_lem_price_id', true),
            'is_free'                 => get_post_meta($event_id, '_lem_is_free', true) ?: 'free',
            'post_status'             => get_post_status($event_id),
            'updated_at'              => current_time('mysql'),
        );
        $this->store_event_redis($event_id, $event_data);
        unset(self::$memory_cache["event:{$event_id}"]);

        wp_send_json_success(array(
            'event_id'  => $event_id,
            'permalink' => get_permalink($event_id),
            'edit_url'  => admin_url(add_query_arg(
                array('post_type' => 'lem_event', 'page' => 'lem-event-editor', 'event_id' => $event_id),
                'edit.php'
            )),
        ));
    }

    /**
     * AJAX: lightweight status for the event editor status bar.
     * Returns tickets_sold, stream_status. Admin-only.
     */
    public function ajax_get_event_status() {
        check_ajax_referer('lem_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) {
            wp_send_json_error('event_id required');
        }

        global $wpdb;
        $table        = $wpdb->prefix . 'lem_jwt_tokens';
        $tickets_sold = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_id = %s AND payment_id IS NOT NULL AND payment_id != '' AND revoked_at IS NULL",
            (string) $event_id
        ));

        $stream_id = get_post_meta($event_id, '_lem_live_stream_id', true);
        $stream_status = 'idle';
        if ($stream_id) {
            $provider = $this->get_streaming_provider();
            if ($provider) {
                try {
                    $details = $provider->get_stream_details($stream_id);
                    $stream_status = $details['status'] ?? 'idle';
                } catch (\Exception $e) {
                    $stream_status = 'unknown';
                }
            }
        }

        wp_send_json_success(array(
            'tickets_sold'  => $tickets_sold,
            'stream_status' => $stream_status,
        ));
    }

    // ── Webhook activity log AJAX ─────────────────────────────────────────────

    public function ajax_get_webhook_log() {
        check_ajax_referer('lem_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        global $wpdb;
        $table = $wpdb->prefix . 'lem_webhook_log';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$exists) {
            wp_send_json_success(array('rows' => array()));
        }
        $rows = $wpdb->get_results(
            "SELECT id, received_at, provider, source_ip, has_signature, event_type, payment_id, event_id, email, status, message
             FROM {$table}
             ORDER BY received_at DESC, id DESC
             LIMIT 100"
        );
        wp_send_json_success(array('rows' => $rows ?: array()));
    }

    public function ajax_clear_webhook_log() {
        check_ajax_referer('lem_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}lem_webhook_log");
        wp_send_json_success();
    }

    // ── Events list columns & row actions ─────────────────────────────────────

    public function event_list_columns($columns) {
        $new = array();
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['lem_status']  = 'Status';
                $new['lem_tickets'] = 'Tickets';
                $new['lem_date_doors'] = 'Doors Open';
            }
        }
        unset($new['date']); // replaced by doors-open column
        return $new;
    }

    public function event_list_column_content($column, $post_id) {
        if ($column === 'lem_status') {
            $status = get_post_status($post_id);
            if ($status === 'publish') {
                echo '<span style="color:#27ae60">&#9679; ' . esc_html__('Live', 'live-event-manager') . '</span>';
            } else {
                echo '<span style="color:#999">&#9679; ' . esc_html(ucfirst($status ?: 'Draft')) . '</span>';
            }
        }
        if ($column === 'lem_tickets') {
            global $wpdb;
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}lem_jwt_tokens WHERE event_id = %s AND payment_id IS NOT NULL AND payment_id != '' AND revoked_at IS NULL",
                (string) $post_id
            ));
            echo esc_html($count);
        }
        if ($column === 'lem_date_doors') {
            $date = get_post_meta($post_id, '_lem_event_date', true);
            echo $date ? esc_html(date_i18n('M j, Y H:i', strtotime($date))) : '—';
        }
    }

    public function event_list_row_actions($actions, $post) {
        if ($post->post_type !== 'lem_event') {
            return $actions;
        }
        $edit_url = admin_url(add_query_arg(
            array('post_type' => 'lem_event', 'page' => 'lem-event-editor', 'event_id' => $post->ID),
            'edit.php'
        ));
        $actions['edit'] = '<a href="' . esc_url($edit_url) . '">' . __('Edit') . '</a>';
        unset($actions['inline hide-if-no-js']); // remove quick-edit
        return $actions;
    }

    // Render homepage meta box
    public function render_homepage_meta_box($post) {
        wp_nonce_field('lem_save_homepage_meta', 'lem_homepage_meta_nonce');
        
        $settings = get_option('lem_settings', array());
        $current_homepage_event = $settings['homepage_event_id'] ?? 0;
        $is_current_homepage = ($current_homepage_event == $post->ID);
        
        ?>
        <div class="lem-homepage-settings">
            <p>
                <label>
                    <input type="checkbox" name="lem_set_as_homepage" value="1" <?php checked($is_current_homepage); ?>>
                    Set this event as the homepage
                </label>
            </p>
            
            <?php if ($is_current_homepage): ?>
                <div class="lem-homepage-status" style="background: #d4edda; padding: 10px; border-radius: 4px; margin-top: 10px;">
                    <strong>✅ This event is currently set as the homepage</strong>
                    <p style="margin: 5px 0 0 0; font-size: 12px;">
                        Visitors to your site's homepage will be redirected to this event page.
                    </p>
                </div>
            <?php else: ?>
                <div class="lem-homepage-status" style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin-top: 10px;">
                    <strong>ℹ️ This event is not the homepage</strong>
                    <p style="margin: 5px 0 0 0; font-size: 12px;">
                        Check the box above to make this event your homepage.
                    </p>
                </div>
            <?php endif; ?>
            
            <p style="margin-top: 15px; font-size: 12px; color: #666;">
                <strong>Note:</strong> Only published events can be set as the homepage. 
                If no event is set as homepage, WordPress will use your default homepage setting.
            </p>
        </div>
        <?php
    }
    
    // Save homepage setting
    private function save_homepage_setting($post_id) {
        $settings = get_option('lem_settings', array());
        
        // Check if this event should be set as homepage
        if (isset($_POST['lem_set_as_homepage']) && $_POST['lem_set_as_homepage'] == '1') {
            // Only allow published events to be homepage
            if (get_post_status($post_id) === 'publish') {
                $settings['homepage_event_id'] = $post_id;
                update_option('lem_settings', $settings);
                $this->debug_log('Event set as homepage', array('post_id' => $post_id));
            }
        } else {
            // If this event was previously the homepage, clear it
            if (isset($settings['homepage_event_id']) && $settings['homepage_event_id'] == $post_id) {
                $settings['homepage_event_id'] = 0;
                update_option('lem_settings', $settings);
                $this->debug_log('Event removed as homepage', array('post_id' => $post_id));
            }
        }
    }
    
    // Register Gutenberg blocks
    public function register_blocks() {
        // Register smart event ticket block (only for event post type)
        register_block_type('lem/event-ticket', array(
            'render_callback' => array($this, 'render_event_ticket_block'),
            'attributes' => array(
                'allowed_post_types' => array(
                    'type' => 'array',
                    'default' => array('lem_event')
                )
            )
        ));
        
        // Register gated video player block (available everywhere)
        register_block_type('lem/gated-video', array(
            'render_callback' => array($this, 'render_gated_video_block')
        ));
    }
    
    // Load custom template for event display
    public function load_event_template($template) {
        global $post;

        if ($post && $post->post_type === 'lem_event') {
            $event_template = LEM_Template_Manager::resolve_template_file('single-event.php');

            if (file_exists($event_template)) {
                // Make the plugin instance available to the template
                global $live_event_manager;
                $live_event_manager = $this;
                $this->debug_log('Loading single event template', array('post_id' => $post->ID));
                return $event_template;
            }
        }

        return $template;
    }

    // Load archive template for events listing
    public function load_event_archive_template($template) {
        if (is_post_type_archive('lem_event')) {
            $archive_template = LEM_Template_Manager::resolve_template_file('page-events.php');

            if (file_exists($archive_template)) {
                $this->debug_log('Loading events archive template');
                return $archive_template;
            }
        }

        return $template;
    }
    
    // Redirect homepage to designated event
    public function redirect_homepage_to_event() {
        // Only redirect if we're on the homepage and not in admin
        if (is_front_page() && !is_admin()) {
            $settings = get_option('lem_settings', array());
            $homepage_event_id = isset($settings['homepage_event_id']) ? intval($settings['homepage_event_id']) : 0;
            
            // Only redirect if a specific event is explicitly set as homepage
            if ($homepage_event_id > 0) {
                $event = get_post($homepage_event_id);
                if ($event && $event->post_type === 'lem_event' && $event->post_status === 'publish') {
                    $event_url = get_permalink($homepage_event_id);
                    wp_redirect($event_url, 302);
                    exit;
                }
            }
            
            // No fallback - let WordPress handle the homepage normally
            // This allows users to set their own homepage in WordPress Settings
        }
    }
    
    // Load confirmation page template
    public function load_confirmation_template($template) {
        global $wp_query;

        // CRITICAL: Do NOT interfere with single events or archives
        if (is_singular('lem_event') || is_post_type_archive('lem_event')) {
            return $template;  // Let other filters handle it
        }

        $this->debug_log('Confirmation template loading check', array(
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'not set',
            'query_vars' => $wp_query->query_vars,
            'get_params' => $_GET
        ));
        
        // Check if this is a confirmation page request
        if (isset($wp_query->query_vars['lem_confirmation']) && $wp_query->query_vars['lem_confirmation'] == '1') {
            $confirmation_template = LEM_Template_Manager::resolve_template_file('confirmation-page.php');
            
            if (file_exists($confirmation_template)) {
                $this->debug_log('Loading confirmation template via rewrite rule');
                return $confirmation_template;
            }
        }
        
        // Fallback: Check if the current page is /confirmation (for when rewrite rules don't work)
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/confirmation') === 0) {
            $confirmation_template = LEM_Template_Manager::resolve_template_file('confirmation-page.php');
            
            if (file_exists($confirmation_template)) {
                $this->debug_log('Loading confirmation template via fallback method', array('uri' => $_SERVER['REQUEST_URI']));
                return $confirmation_template;
            }
        }
        
        // Additional fallback: Check if this is a 404 and the URL contains confirmation
        if (is_404() && isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/confirmation') === 0) {
            $confirmation_template = LEM_Template_Manager::resolve_template_file('confirmation-page.php');
            
            if (file_exists($confirmation_template)) {
                $this->debug_log('Loading confirmation template via 404 fallback', array('uri' => $_SERVER['REQUEST_URI']));
                return $confirmation_template;
            }
        }
        
        // Fallback for direct GET parameters
        if (isset($_GET['session_id']) || (isset($_GET['lem_success']) && $_GET['lem_success'] == '1')) {
            $confirmation_template = LEM_Template_Manager::resolve_template_file('confirmation-page.php');
            
            if (file_exists($confirmation_template)) {
                $this->debug_log('Loading confirmation template via GET parameters');
                return $confirmation_template;
            }
        }
        
        return $template;
    }
    
    // Add rewrite rules for watch page
    public function add_rewrite_rules() {
        // Use more specific patterns to ensure higher priority
        add_rewrite_rule(
            '^confirmation/?$',
            'index.php?lem_confirmation=1',
            'top'
        );
        
        // Add events page
        add_rewrite_rule(
            '^events/?$',
            'index.php?lem_events_page=1',
            'top'
        );
        
        // Add device swap page
        add_rewrite_rule(
            '^device-swap$',
            'index.php?lem_device_swap=1',
            'top'
        );
        
    }
    
    // Load additional templates for LEM routes
    public function load_additional_templates($template) {
        if (is_singular('lem_event') || is_post_type_archive('lem_event')) {
            return $template;
        }

        // Load events page template
        if (get_query_var('lem_events_page')) {
            $events_template = LEM_Template_Manager::resolve_template_file('page-events.php');

            if (file_exists($events_template)) {
                $this->debug_log('Loading events template via rewrite rule');
                return $events_template;
            }
        }
        
        // Load device swap template
        if (get_query_var('lem_device_swap')) {
            $device_swap_template = LEM_Template_Manager::resolve_template_file('device-swap-form.php');
            
            if (file_exists($device_swap_template)) {
                return $device_swap_template;
            }
        }
        
        // Fallback: Check if the current page is EXACTLY /events (not /events/something)
        // CRITICAL: Must NOT match single events like /events/event-name/
        // CRITICAL: Only run if this is NOT already a single event
        if (isset($_SERVER['REQUEST_URI']) && !is_singular('lem_event')) {
            $uri = trim($_SERVER['REQUEST_URI'], '/');
            // Only match /events or /events/ exactly, not /events/something
            if ($uri === 'events' || preg_match('#^events/?$#', $uri)) {
                $events_template = LEM_Template_Manager::resolve_template_file('page-events.php');

                if (file_exists($events_template)) {
                    $this->debug_log('Loading events template via fallback method', array('uri' => $_SERVER['REQUEST_URI']));
                    return $events_template;
                }
            }
        }
        
        // Fallback: Check if the current page is /device-swap
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/device-swap') === 0) {
            $device_swap_template = LEM_Template_Manager::resolve_template_file('device-swap-form.php');
            
            if (file_exists($device_swap_template)) {
                return $device_swap_template;
            }
        }
        
        return $template;
    }
    
    // Add query vars for watch page
    public function add_query_vars($vars) {
        $vars[] = 'lem_session';
        $vars[] = 'lem_confirmation';
        $vars[] = 'lem_events_page';
        $vars[] = 'lem_device_swap';
        return $vars;
    }

    public function get_event_url($event_id, $args = array()) {
        $permalink = get_permalink($event_id);

        if (!$permalink) {
            $permalink = home_url('/');
        }

        if (!empty($args) && is_array($args)) {
            $permalink = add_query_arg($args, $permalink);
        }

        return $permalink;
    }

    private function clear_session_cookie() {
        if (isset($_COOKIE['lem_session_id'])) {
            setcookie('lem_session_id', '', array(
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
            unset($_COOKIE['lem_session_id']);
        }
    }

    public function handle_event_magic_link() {
        if (!is_singular('lem_event')) {
            return;
        }

        global $post;
        $event_id = $post ? $post->ID : 0;
        if (!$event_id) {
            return;
        }

        $base_url = $this->get_event_url($event_id);
        $redirect_base = remove_query_arg(array('magic', 'lem_error', 'lem_success'), $base_url);

        if (isset($_GET['magic'])) {
            $magic_token = sanitize_text_field(wp_unslash($_GET['magic']));

            if (!empty($magic_token)) {
                $result = $this->validate_magic_token($magic_token);

                if (!headers_sent()) {
                    if (!empty($result['valid'])) {
                        $session_id = $result['session_id'] ?? '';
                        if (!empty($session_id)) {
                            // Note: 'secure' uses is_ssl() which is the standard WordPress
                            // pattern. HTTPS should be enforced in production so that the
                            // cookie is always transmitted over a secure channel.
                            setcookie('lem_session_id', $session_id, array(
                                'expires'  => time() + DAY_IN_SECONDS,
                                'path'     => '/',
                                'secure'   => is_ssl(),
                                'httponly' => true,
                                'samesite' => 'Lax',
                            ));
                            $_COOKIE['lem_session_id'] = $session_id;
                        }

                        $this->event_access_cache[$event_id] = null;
                        wp_safe_redirect($redirect_base);
                        exit;
                    }

                    // ── Magic token invalid / already consumed ────────────────────
                    // Do NOT touch the session cookie here. If someone tries to
                    // reuse an already-consumed link (back button, shared URL, etc.),
                    // the current user's active session must be completely unaffected.
                    // Just show the error message and let them request a new link.
                    $error_message = !empty($result['error']) ? $result['error'] : 'Invalid or expired magic token.';
                    $redirect_with_error = add_query_arg('lem_error', rawurlencode($error_message), $redirect_base);
                    wp_safe_redirect($redirect_with_error);
                    exit;
                }
            }
        }

        // Prime access cache so templates can read without recalculating
        $this->get_event_access_state($event_id);
    }

    /**
     * Random anonymous display name for Ably chat (one per session).
     */
    private function generate_chat_name(): string {
        $adjectives = array('Cosmic', 'Neon', 'Crystal', 'Shadow', 'Golden', 'Velvet', 'Electric', 'Mystic', 'Blazing', 'Silent', 'Lunar', 'Crimson', 'Swift', 'Phantom', 'Radiant', 'Arctic', 'Emerald', 'Thunder', 'Pixel', 'Turbo');
        $nouns      = array('Panda', 'Falcon', 'Phoenix', 'Tiger', 'Wolf', 'Hawk', 'Lynx', 'Viper', 'Raven', 'Fox', 'Otter', 'Jaguar', 'Eagle', 'Comet', 'Nova', 'Spark', 'Storm', 'Blaze', 'Reef', 'Orbit');
        return $adjectives[ array_rand( $adjectives ) ] . $nouns[ array_rand( $nouns ) ] . wp_rand( 10, 99 );
    }

    // Session management — gating only (playback is lem:playback:*, not tied to session rotation).
    public function create_session($event_id, $email) {
        $session_id = $this->generate_session_id();
        $expires_at = time() + (24 * 60 * 60); // 24 hours

        $redis = $this->get_redis_connection();
        if ($redis) {
            $session_data = array(
                'session_id' => $session_id,
                'event_id'   => $event_id,
                'email'      => $email,
                'chat_name'  => $this->generate_chat_name(),
                'created_at' => time(),
                'expires_at' => $expires_at,
                'active'     => true,
            );

            $email_hash   = hash('sha256', strtolower(trim($email)));
            $session_json = wp_json_encode($session_data);
            $ttl          = 24 * 60 * 60;
            $active_key   = "active_sessions:{$event_id}:{$email_hash}";

            $device_settings = get_option('lem_device_settings', array());
            $max_devices     = max(1, (int) ($device_settings['max_devices'] ?? 1));

            $existing_json   = $redis->get($active_key);
            $active_sessions = ($existing_json ? json_decode($existing_json, true) : []) ?: [];

            while (count($active_sessions) >= $max_devices) {
                $oldest_id = array_shift($active_sessions);
                if (!empty($oldest_id)) {
                    $this->redis_delete_session_key($redis, $oldest_id, $event_id, $email);
                    unset(self::$memory_cache["session_val:{$oldest_id}"]);
                }
            }

            $active_sessions[] = $session_id;

            $redis->setex("session:{$session_id}", $ttl, $session_json);
            $redis->setex($active_key, $ttl, wp_json_encode($active_sessions));
            $this->sessions_index_add($redis, $email, $event_id, $session_id, $ttl);

            $this->debug_log('Session created', array(
                'session_id'   => $session_id,
                'event_id'     => $event_id,
                'email'        => $email,
                'active_count' => count($active_sessions),
                'max_devices'  => $max_devices,
            ));
        }

        return $session_id;
    }

    /**
     * Track session id for admin purge (JSON array in Redis).
     */
    private function sessions_index_add($redis, $email, $event_id, $session_id, $ttl) {
        $idx_key = LEM_Access::sessions_index_key($email, $event_id);
        $raw     = $redis->get($idx_key);
        $list    = ($raw ? json_decode($raw, true) : []) ?: [];
        if (!in_array($session_id, $list, true)) {
            $list[] = $session_id;
        }
        $redis->setex($idx_key, $ttl, wp_json_encode(array_values($list)));
    }

    /**
     * Remove one session id from the index (best-effort).
     */
    private function sessions_index_remove($redis, $email, $event_id, $session_id) {
        $idx_key = LEM_Access::sessions_index_key($email, $event_id);
        $raw     = $redis->get($idx_key);
        $list    = ($raw ? json_decode($raw, true) : []) ?: [];
        $list    = array_values(array_filter($list, function ($s) use ($session_id) {
            return $s !== $session_id;
        }));
        if (empty($list)) {
            $redis->del($idx_key);
        } else {
            $redis->setex($idx_key, 24 * 60 * 60, wp_json_encode($list));
        }
    }

    /**
     * Delete session key, active_sessions entry, index, and event_access cache.
     */
    private function redis_delete_session_key($redis, $session_id, $event_id, $email) {
        $email_hash  = hash('sha256', $email);
        $active_key  = "active_sessions:{$event_id}:{$email_hash}";
        $active_json = $redis->get($active_key);
        $list        = ($active_json ? json_decode($active_json, true) : []) ?: [];
        $list        = array_values(array_filter($list, function ($s) use ($session_id) {
            return $s !== $session_id;
        }));
        if (empty($list)) {
            $redis->del($active_key);
        } else {
            $redis->setex($active_key, 24 * 60 * 60, wp_json_encode($list));
        }
        $this->sessions_index_remove($redis, $email, $event_id, $session_id);
        $redis->del("session:{$session_id}");
        $redis->del('event_access:' . (int) $event_id . ':' . $session_id);
    }

    /**
     * Admin / revoke: delete all Redis keys for this email+event (sessions, playback, caches).
     */
    public function purge_redis_access_for_email_event($email, $event_id) {
        $redis = $this->get_redis_connection();
        if (!$redis) {
            return false;
        }
        $event_id   = (int) $event_id;
        $email_hash = hash('sha256', $email);
        $idx_key    = LEM_Access::sessions_index_key($email, $event_id);
        $raw        = $redis->get($idx_key);
        $ids        = ($raw ? json_decode($raw, true) : []) ?: [];
        foreach ($ids as $sid) {
            $redis->del("session:{$sid}");
            $redis->del("event_access:{$event_id}:{$sid}");
        }
        $redis->del($idx_key);
        $redis->del("active_sessions:{$event_id}:{$email_hash}");
        $redis->del(LEM_Access::playback_key($email, $event_id));
        return true;
    }
    
    public function validate_session($session_id) {
        // Check in-memory cache first (5 second TTL per request)
        $memory_key = "session_val:{$session_id}";
        if (isset(self::$memory_cache[$memory_key])) {
            $cached = self::$memory_cache[$memory_key];
            // Check if cache is still fresh (5 seconds)
            if (isset($cached['_cached_at']) && (time() - $cached['_cached_at']) < 5) {
                unset($cached['_cached_at']); // Remove internal field
                return $cached;
            }
        }
        
        $redis = $this->get_redis_connection();
        if (!$redis) {
            $message = 'Upstash Redis is not configured. Session validation requires Upstash — add your credentials in Live Events > Settings > Cache & Access.';
            $result = array('valid' => false, 'error' => $message);
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }
        
        // Use pipeline to batch Redis calls (reduces network round-trips)
        $pipe = null;
        $use_pipeline = method_exists($redis, 'pipeline');
        
        if ($use_pipeline) {
            $pipe = $redis->pipeline();
            $pipe->get("session:{$session_id}");
            $results = $pipe->execute();
            $session_data = $results[0] ?? false;
        } else {
            $session_data = $redis->get("session:{$session_id}");
        }
        
        if (!$session_data) {
            $is_transport = method_exists($redis, 'last_request_failed') && $redis->last_request_failed();
            $error = $is_transport ? 'Redis connection unavailable — please try again' : 'Session not found';
            $result = array('valid' => false, 'error' => $error);
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }
        
        $session = json_decode($session_data, true);
        if (!$session) {
            $result = array('valid' => false, 'error' => 'Invalid session data');
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }
        
        // Check if session is expired
        if (time() > $session['expires_at']) {
            $this->redis_delete_session_key($redis, $session_id, $session['event_id'], $session['email']);
            $result = array('valid' => false, 'error' => 'Session expired');
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }
        
        if (isset($session['active']) && !$session['active']) {
            $result = array('valid' => false, 'error' => 'Session revoked');
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }

        // ── Device-limit enforcement ──────────────────────────────────────────
        // active_sessions holds an ordered JSON array of authorised session IDs
        // for this email+event (oldest first). If this session_id is no longer
        // in the list, it was evicted when a newer device was added beyond the
        // max_devices limit — reject it so the old device gets logged out.
        // An empty array means no limit is tracked yet (e.g. fresh install or
        // Redis flush) — allow the session through for backward compatibility.
        $email_hash_check = hash('sha256', $session['email']);
        $active_key       = "active_sessions:{$session['event_id']}:{$email_hash_check}";
        $active_json      = $redis->get($active_key);
        $active_sessions  = ($active_json ? json_decode($active_json, true) : []) ?: [];

        if (!empty($active_sessions) && !in_array($session_id, $active_sessions, true)) {
            $this->revoke_session($session_id);
            $result = array(
                'valid' => false,
                'error' => 'Your access link has been used on another device. Request a new magic link to continue.',
            );
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }

        // Playback entitlement is validated separately via lem:playback (no per-request JWT coupling).
        $email_hash = hash('sha256', $session['email']);
        $cache_key = 'session_status:' . $session['event_id'] . ':' . $email_hash;
        $redis->setex($cache_key, 5 * 60, json_encode(array(
            'valid' => true,
            'event_id' => $session['event_id'],
            'session_id' => $session_id
        )));

        $event_obj = $this->get_event_by_id($session['event_id']);

        $result = array(
            'valid' => true,
            'session' => $session,
            'event' => $event_obj
        );
        
        // Cache in memory for 5 seconds
        self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
        
        return $result;
    }
    
    public function revoke_session($session_id) {
        $redis = $this->get_redis_connection();
        if (!$redis) {
            return false;
        }

        $session_data = $redis->get("session:{$session_id}");
        if ($session_data) {
            $session = json_decode($session_data, true);
            if ($session) {
                $email_hash      = hash('sha256', $session['email']);
                $active_key      = "active_sessions:{$session['event_id']}:{$email_hash}";
                $active_json     = $redis->get($active_key);
                $active_sessions = ($active_json ? json_decode($active_json, true) : []) ?: [];
                $active_sessions = array_values(array_filter($active_sessions, function ($s) use ($session_id) {
                    return $s !== $session_id;
                }));
                if (empty($active_sessions)) {
                    $redis->del($active_key);
                } else {
                    $redis->setex($active_key, 24 * 60 * 60, wp_json_encode($active_sessions));
                }

                $this->sessions_index_remove($redis, $session['email'], $session['event_id'], $session_id);
                if (!empty($session['jti'])) {
                    $redis->del("jti_session:{$session['jti']}");
                }
                $redis->del("session:{$session_id}");
                $redis->del('event_access:' . (int) $session['event_id'] . ':' . $session_id);

                unset(self::$memory_cache["session_val:{$session_id}"]);

                $this->debug_log('Session revoked', array(
                    'session_id'       => $session_id,
                    'remaining_active' => count($active_sessions),
                ));

                return true;
            }
        }

        return false;
    }
    
    public function revoke_all_sessions_for_event($event_id, $email) {
        $redis = $this->get_redis_connection();
        if (!$redis) {
            return false;
        }

        $email_hash      = hash('sha256', $email);
        $active_key      = "active_sessions:{$event_id}:{$email_hash}";
        $active_json     = $redis->get($active_key);
        $active_sessions = ($active_json ? json_decode($active_json, true) : []) ?: [];

        $revoked = false;
        foreach ($active_sessions as $sid) {
            if ($this->revoke_session($sid)) {
                $revoked = true;
            }
        }

        // Ensure the key is removed even if all sessions were already expired
        $redis->del($active_key);

        return $revoked;
    }
    
    public function generate_session_id() {
        return bin2hex(random_bytes(32));
    }
    
    // Validate JWT by JTI (for session validation) - optimized with caching
    private function validate_jwt_direct_by_jti($jti) {
        // Check in-memory cache first
        $memory_key = "jwt_val:{$jti}";
        if (isset(self::$memory_cache[$memory_key])) {
            $cached = self::$memory_cache[$memory_key];
            if (isset($cached['_cached_at']) && (time() - $cached['_cached_at']) < 5) {
                unset($cached['_cached_at']);
                return $cached;
            }
        }
        
        $redis = $this->get_redis_connection();
        if (!$redis) {
            $message = 'Upstash Redis is not configured. JWT validation requires Upstash — add your credentials in Live Events > Settings > Cache & Access.';
            $result = array('valid' => false, 'error' => $message);
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }
        
        // Use pipeline to batch Redis calls
        $use_pipeline = method_exists($redis, 'pipeline');
        
        if ($use_pipeline) {
            $pipe = $redis->pipeline();
            $pipe->get("jwt:{$jti}");
            $pipe->exists("revoked:{$jti}");
            $results = $pipe->execute();
            $jwt_data = $results[0] ?? false;
            $is_revoked = $results[1] ?? false;
        } else {
        $jwt_data = $redis->get("jwt:{$jti}");
            $is_revoked = $redis->exists("revoked:{$jti}");
        }
        
        if ($is_revoked) {
            $result = array('valid' => false, 'error' => 'JWT has been revoked');
            self::$memory_cache[$memory_key] = array_merge($result, array('_cached_at' => time()));
            return $result;
        }
        
        if (!$jwt_data) {
            global $wpdb;
            $table = $wpdb->prefix . 'lem_jwt_tokens';

            $jwt_record = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE jti = %s AND revoked_at IS NULL",
                $jti
            ));

            if (!$jwt_record) {
                return array('valid' => false, 'error' => 'JWT not found');
            }

            $expires_at = strtotime($jwt_record->expires_at);
            if ($expires_at !== false && $expires_at <= time()) {
                return array('valid' => false, 'error' => 'JWT has expired');
            }
            $jwt_info = array(
                'jti' => $jwt_record->jti,
                'event_id' => $jwt_record->event_id,
                'email' => $jwt_record->email,
                'expires_at' => $expires_at,
                'jwt_token' => $jwt_record->jwt_token
            );

            $ttl = ($expires_at !== false) ? max(60, $expires_at - time()) : 86400;
            $redis->setex("jwt:{$jti}", $ttl, json_encode($jwt_info));
            $redis->setex("jwt_token:{$jti}", $ttl, $jwt_record->jwt_token);
        } else {
            $jwt_info = json_decode($jwt_data, true);
            if (!$jwt_info) {
                return array('valid' => false, 'error' => 'Invalid JWT data');
            }
        }
        
        // Check if JWT is revoked
        if (isset($jwt_info['revoked']) && $jwt_info['revoked']) {
            return array('valid' => false, 'error' => 'JWT has been revoked');
        }
        
        // Check if JWT is expired
        if (time() > $jwt_info['expires_at']) {
            return array('valid' => false, 'error' => 'JWT has expired');
        }
        
        // Get event data
        $event = $this->get_event_by_id($jwt_info['event_id']);
        if (!$event) {
            return array('valid' => false, 'error' => 'Event not found');
        }
        
        return array(
            'valid' => true,
            'event' => $event,
            'jwt_info' => $jwt_info
        );
    }
    
    // Render smart event ticket block
    public function render_event_ticket_block($attributes) {
        global $post;
        
        // Get event data from current post meta
        $playback_id = get_post_meta($post->ID, '_lem_playback_id', true);
        $event_date = get_post_meta($post->ID, '_lem_event_date', true);
        $is_free = get_post_meta($post->ID, '_lem_is_free', true);
        $price_id = get_post_meta($post->ID, '_lem_price_id', true);
        
        // Get block attributes
        $button_text = isset($attributes['buttonText']) ? $attributes['buttonText'] : 'Get Access';
        $email_placeholder = isset($attributes['emailPlaceholder']) ? $attributes['emailPlaceholder'] : 'Enter your email address';
        $show_price = isset($attributes['showPrice']) ? $attributes['showPrice'] : true;
        $theme = isset($attributes['theme']) ? $attributes['theme'] : 'dark';
        $size = isset($attributes['size']) ? $attributes['size'] : 'large';
        $show_event_details = isset($attributes['showEventDetails']) ? $attributes['showEventDetails'] : true;
        
        // Check if we're on an event page
        if ($post->post_type !== 'lem_event') {
            return '<p>This block can only be used on event pages.</p>';
        }
        
        $success_message = '';
        $error_message = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lem_request_new_link'])) {
            $email_input = sanitize_email($_POST['email'] ?? '');
            $nonce_valid = isset($_POST['lem_new_link_nonce']) && wp_verify_nonce($_POST['lem_new_link_nonce'], 'lem_request_new_link');

            if (!$nonce_valid) {
                $error_message = __('Security check failed. Refresh and try again.', 'live-event-manager');
            } elseif (empty($email_input)) {
                $error_message = __('Please enter a valid email address.', 'live-event-manager');
            } else {
                $result = $this->validate_email_and_send_link($email_input, $post->ID);
                if (!empty($result['valid'])) {
                    $success_message = $result['message'] ?? __('Fresh access link sent. Check your inbox!', 'live-event-manager');
                } else {
                    $error_message = $result['error'] ?? __('Unable to send a new link right now. Please try again shortly.', 'live-event-manager');
                }
            }

            // Non-JS fallback: land on the resend tab with feedback.
            if (! empty($success_message) || ! empty($error_message)) {
                $_GET['lem_resend'] = '1';
            }
        }

        // Create event object for template
        $event = (object) array(
            'event_id' => $post->ID, // Use post ID as event ID
            'title' => $post->post_title,
            'event_date' => $event_date,
            'is_free' => $is_free === 'free',
            'price_id' => $price_id,
            'playback_id' => $playback_id,
            'post_status' => get_post_status($post->ID)
        );

        $event_access = $this->get_event_access_state($event->event_id);

        if (!empty($success_message)) {
            $event_access['success_message'] = $success_message;
        }

        if (!empty($error_message) && empty($event_access['error_message'])) {
            $event_access['error_message'] = $error_message;
        }
        
        ob_start();
        include LEM_Template_Manager::resolve_template_file('event-ticket-block.php');
        return ob_get_clean();
    }

    // Render gated video player block
    public function render_gated_video_block($attributes) {
        // Make the plugin instance available to the template
        global $live_event_manager;
        $live_event_manager = $this;
        
        ob_start();
        include LEM_Template_Manager::resolve_template_file('gated-video-block.php');
        return ob_get_clean();
    }
    
    /**
     * Render simulcast player shortcode
     * Usage: [simulcast_player]
     */
    public function render_simulcast_player_shortcode($atts) {
        // Make the plugin instance available to the template
        global $live_event_manager;
        $live_event_manager = $this;
        
        // Get settings
        $settings = get_option('lem_settings', array());
        $live_stream_id = $settings['mux_live_stream_id'] ?? '';
        
        if (empty($live_stream_id)) {
            return '<div class="lem-error">Mux Live Stream ID not configured. Please set it in Live Events → Settings.</div>';
        }
        
        // Get current user data
        $current_user = wp_get_current_user();
        $user_data = array(
            'user_id' => 0,
            'display_name' => '',
            'is_logged_in' => false
        );
        
        if ($current_user && $current_user->ID > 0) {
            $user_data = array(
                'user_id' => $current_user->ID,
                'display_name' => $current_user->display_name,
                'is_logged_in' => true
            );
        }
        
        // Enqueue React app assets (we'll create this)
        wp_enqueue_script('lem-simulcast-player', LEM_PLUGIN_URL . 'assets/simulcast-player.js', array('jquery'), LEM_VERSION, true);
        wp_localize_script('lem-simulcast-player', 'lemSimulcastData', array(
            'live_stream_id' => $live_stream_id,
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lem_nonce'),
            'user' => $user_data,
            'api_url' => get_rest_url(null, 'lem/v1/')
        ));
        
        // Render React app container
        ob_start();
        ?>
        <div id="lem-simulcast-player-app" data-live-stream-id="<?php echo esc_attr($live_stream_id); ?>">
            <div class="lem-loading">Loading player...</div>
        </div>
        <?php
        return ob_get_clean();
    }
}
