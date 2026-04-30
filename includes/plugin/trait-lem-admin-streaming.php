<?php
/**
 * Admin menus & screens, asset enqueues, stream/template/vendor AJAX, Mux playback restrictions.
 *
 * @package Live_Event_Manager
 */

trait LEM_Trait_Admin_And_Streaming {
    // Admin menu
    public function add_admin_menu() {

        // ── Visible: Live Event Manager · Tickets · Streams · Settings ──────────

        add_submenu_page(
            'edit.php?post_type=lem_event',
            'Tickets',
            'Tickets',
            'manage_options',
            'live-event-manager-payments',
            array($this, 'render_payments_page')
        );

        add_submenu_page(
            'edit.php?post_type=lem_event',
            'Streams',
            'Streams',
            'manage_options',
            'live-event-manager-stream-management',
            array($this, 'render_stream_management_page')
        );

        add_submenu_page(
            'edit.php?post_type=lem_event',
            'Settings',
            'Settings',
            'manage_options',
            'live-event-manager-settings',
            array($this, 'render_settings_page')
        );

        // ── Hidden — registered for URL / bookmark compatibility ──────────────
        // Accessible via links within Settings, Services, and other admin pages.

        $hidden = array(
            array('live-event-manager-jwt',             'Access',       'render_jwt_page'),
            array('live-event-manager-revoke-access',   'Revoke',       'render_revoke_access_page'),
            array('live-event-manager-templates',       'Templates',    'render_templates_page'),
            array('live-event-manager-services',        'Services',     'render_services_page'),
            array('live-event-manager-stripe',          'Stripe',       'render_stripe_redirect'),
            array('live-event-manager-engine',          'Engine',       'render_engine_redirect'),
            array('live-event-manager-user-guide',      'Help',         'render_user_guide_page'),
            array('live-event-manager-debug',           'Debug',        'render_debug_page'),
            array('live-event-manager-device-settings', 'Devices',      'render_devices_redirect'),
            array('live-event-manager-stream-vendors',  'Vendors',      'render_stream_vendors_redirect'),
            array('live-event-manager-stream-setup',    'Stream Setup', 'render_stream_setup_page'),
        );

        foreach ($hidden as list($slug, $title, $cb)) {
            add_submenu_page('edit.php?post_type=lem_event', $title, $title, 'manage_options', $slug, array($this, $cb));
            remove_submenu_page('edit.php?post_type=lem_event', $slug);
        }
    }
    
    // Remove the automatic "Add New" submenu to avoid duplication
    public function remove_add_new_submenu() {
        remove_submenu_page('edit.php?post_type=lem_event', 'post-new.php?post_type=lem_event');
    }
    
    // Enqueue admin assets
    public function enqueue_admin_assets($hook) {
        // Load on plugin pages and event edit pages
        $should_load = false;
        
        // Plugin pages (submenu slugs contain "live-event-manager")
        if (strpos($hook, 'live-event-manager') !== false) {
            $should_load = true;
        }

        // CPT submenu screens use hooks like lem_event_page_* (slug may not include that substring).
        if (strpos($hook, 'lem_event_page_') !== false) {
            $should_load = true;
        }
        
        // Event edit pages (post.php and post-new.php for lem_event post type)
        if (in_array($hook, array('post.php', 'post-new.php'))) {
            global $post_type;
            if ($post_type === 'lem_event') {
                $should_load = true;
            }
        }
        
        if (!$should_load) {
            return;
        }
        
        wp_enqueue_script('lem-admin', LEM_PLUGIN_URL . 'assets/admin.js', array('jquery'), LEM_VERSION, true);
        wp_enqueue_style('lem-admin', LEM_PLUGIN_URL . 'assets/admin.css', array(), LEM_VERSION);
        
        wp_localize_script('lem-admin', 'lem_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lem_nonce'),
            'stream_setup_nonce' => wp_create_nonce('lem_stream_setup_nonce')
        ));
    }
    
    // Ensure lem_ajax is available on event edit pages
    public function enqueue_event_edit_assets() {
        global $post_type;
        
        // Only on lem_event post type pages
        if ($post_type !== 'lem_event') {
            return;
        }
        
        // Add lem_ajax object to the page
        wp_add_inline_script('jquery', '
            if (typeof lem_ajax === "undefined") {
                window.lem_ajax = {
                    ajax_url: "' . admin_url('admin-ajax.php') . '",
                    nonce: "' . wp_create_nonce('lem_nonce') . '"
                };
            }
        ', 'before');
    }
    
    // Enqueue block editor assets
    public function enqueue_block_editor_assets() {
        wp_enqueue_script(
            'lem-blocks',
            LEM_PLUGIN_URL . 'assets/blocks.js',
            array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n'),
            LEM_VERSION,
            true
        );
        
        wp_enqueue_script(
            'lem-gated-video-block',
            LEM_PLUGIN_URL . 'assets/gated-video-block.js',
            array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n'),
            LEM_VERSION,
            true
        );
        
        wp_enqueue_style(
            'lem-blocks-editor',
            LEM_PLUGIN_URL . 'assets/blocks-editor.css',
            array('wp-edit-blocks'),
            LEM_VERSION
        );
        
        wp_localize_script('lem-blocks', 'lem_blocks', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lem_blocks_nonce')
        ));
        
        // Add filter to restrict event ticket block to event post type only
        add_filter('allowed_block_types_all', array($this, 'restrict_event_ticket_block'), 10, 2);
        add_filter('block_categories_all', array($this, 'restrict_event_ticket_block_category'), 10, 2);
    }
    
    // Restrict event ticket block to event post type only
    public function restrict_event_ticket_block($allowed_block_types, $block_editor_context) {
        // Get current post type
        $post_type = get_post_type();
        
        // If we're not editing an event post type, remove the event ticket block
        if ($post_type !== 'lem_event') {
            if (is_array($allowed_block_types)) {
                $allowed_block_types = array_filter($allowed_block_types, function($block_type) {
                    return $block_type !== 'lem/event-ticket';
                });
            }
        }
        
        return $allowed_block_types;
    }
    
    // Restrict event ticket block category to event post type only
    public function restrict_event_ticket_block_category($block_categories, $block_editor_context) {
        // Get current post type
        $post_type = get_post_type();
        
        // If we're not editing an event post type, hide the Live Event Manager category
        if ($post_type !== 'lem_event') {
            $block_categories = array_filter($block_categories, function($category) {
                // Handle both object and array formats
                if (is_object($category)) {
                    return $category->slug !== 'live-event-manager';
                } elseif (is_array($category)) {
                    return $category['slug'] !== 'live-event-manager';
                }
                return true; // Keep if we can't determine the format
            });
        }
        
        return $block_categories;
    }
    
    // Enqueue public assets
    public function enqueue_public_assets() {
        // Only load on event pages or pages with our blocks
        global $post;
        $should_load = false;
        
        // Check if we're on an event page
        if (is_singular('lem_event')) {
            $should_load = true;
        }

        // Check if we're on the events archive
        if (is_post_type_archive('lem_event')) {
            $should_load = true;
        }

        // Check if we're on the confirmation page
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/confirmation') === 0) {
            $should_load = true;
        }
        
        // Check if the page contains our blocks
        if ($post && $post->post_content && (has_shortcode($post->post_content, 'lem_ticket_sales') ||
            strpos($post->post_content, 'lem/ticket-sales') !== false ||
            strpos($post->post_content, 'lem/payment-page') !== false)) {
            $should_load = true;
        }

        // Check if the page uses the events template
        if (is_page() && $post) {
            $page_template = get_page_template_slug($post->ID);
            if ($page_template === 'page-events.php') {
                $should_load = true;
            }
        }

        if (!$should_load) {
            return;
        }

        // Enqueue global styles for all LEM pages
        wp_enqueue_style('lem-global-styles', LEM_PLUGIN_URL . 'assets/lem-global-styles.css', array(), LEM_VERSION);
        
        wp_enqueue_script('lem-public', LEM_PLUGIN_URL . 'assets/public.js', array('jquery'), LEM_VERSION, true);
        wp_enqueue_style('lem-public', LEM_PLUGIN_URL . 'assets/public.css', array(), LEM_VERSION);
        
        // Enqueue Mux player script for video functionality
        wp_enqueue_script(
            'mux-player',
            'https://cdn.jsdelivr.net/npm/@mux/mux-player',
            array(),
            null,
            true
        );
        
        // Get current user data for React app
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
        
        wp_localize_script('lem-public', 'lem_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lem_nonce'),
            'user' => $user_data
        ));

        // Ably realtime chat — only on single event pages and only if configured.
        $settings     = get_option('lem_settings', []);
        $ably_api_key = trim($settings['ably_api_key'] ?? '');
        if (!empty($ably_api_key) && is_singular('lem_event')) {
            wp_enqueue_script(
                'ably-realtime',
                'https://cdn.ably.com/lib/ably.min-2.js',
                [],
                null,
                true
            );
            wp_add_inline_script(
                'lem-public',
                'window.lemAblyEnabled   = true;' .
                'window.lemAblyAuthUrl   = ' . wp_json_encode(admin_url('admin-ajax.php')) . ';' .
                'window.lemAblyViewerName = window.lemViewerName || "Viewer";',
                'after'
            );
        }

        // Enqueue custom template pack assets (CSS after lem-public, JS in footer).
        $style_url  = LEM_Template_Manager::get_active_asset_url('assets/style.css');
        $script_url = LEM_Template_Manager::get_active_asset_url('assets/script.js');
        if (!empty($style_url)) {
            wp_enqueue_style(
                'lem-custom-template',
                $style_url,
                array('lem-public'),
                LEM_Template_Manager::get_active_slug()
            );
        }
        if (!empty($script_url)) {
            wp_enqueue_script(
                'lem-custom-template',
                $script_url,
                array('lem-public', 'jquery'),
                LEM_Template_Manager::get_active_slug(),
                true
            );
        }
    }
    

    
    /**
     * Admin: revoke viewer access for an event (DB + Redis).
     */
    public function render_revoke_access_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = '';
        if (isset($_POST['lem_revoke_submit']) && isset($_POST['lem_revoke_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lem_revoke_nonce'])), 'lem_revoke_access')) {
            $email    = sanitize_email(wp_unslash($_POST['revoke_email'] ?? ''));
            $event_id = intval($_POST['revoke_event_id'] ?? 0);
            if ($email && $event_id && class_exists('LEM_Access')) {
                LEM_Access::record_revocation($email, $event_id, get_current_user_id());
                $this->purge_redis_access_for_email_event($email, $event_id);
                global $wpdb;
                $table = $wpdb->prefix . 'lem_jwt_tokens';
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE $table SET revoked_at = %s WHERE email = %s AND event_id = %s AND revoked_at IS NULL",
                        current_time('mysql'),
                        $email,
                        (string) $event_id
                    )
                );
                $notice = 'success';
            } else {
                $notice = 'invalid';
            }
        }

        include LEM_PLUGIN_DIR . 'templates/revoke-access-page.php';
    }

    /**
     * Admin: paid tickets / Stripe sessions (from lem_jwt_tokens).
     */
    public function render_payments_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lem_jwt_tokens';

        $filter_event = isset($_GET['lem_event']) ? intval($_GET['lem_event']) : 0;

        $where = 't.payment_id IS NOT NULL AND t.payment_id != \'\'';
        $args  = array();
        if ($filter_event > 0) {
            $where .= ' AND t.event_id = %d';
            $args[] = $filter_event;
        }

        $sql = "SELECT t.id, t.email, t.event_id, t.payment_id, t.created_at, t.expires_at, t.revoked_at, t.jti,
                p.post_title AS event_title
            FROM {$table} t
            LEFT JOIN {$wpdb->posts} p ON p.ID = CAST(t.event_id AS UNSIGNED) AND p.post_type = 'lem_event'
            WHERE {$where}
            ORDER BY t.created_at DESC
            LIMIT 500";

        if (!empty($args)) {
            $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
        } else {
            $rows = $wpdb->get_results($sql);
        }

        $events_for_filter = get_posts(array(
            'post_type'      => 'lem_event',
            'posts_per_page' => 200,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));

        $settings    = get_option('lem_settings', array());
        $stripe_mode = $settings['stripe_mode'] ?? 'test';
        $stripe_base = ($stripe_mode === 'live')
            ? 'https://dashboard.stripe.com/checkout/sessions/'
            : 'https://dashboard.stripe.com/test/checkout/sessions/';

        include LEM_PLUGIN_DIR . 'templates/payments-page.php';
    }

    // Render settings page
    public function render_settings_page() {
        $settings = get_option('lem_settings', array());
        include LEM_PLUGIN_DIR . 'templates/settings-page.php';
    }
    
    // Render restrictions page
    public function render_restrictions_page() {
        $settings = get_option('lem_settings', array());
        include LEM_PLUGIN_DIR . 'templates/restrictions-page.php';
    }
    
    // Render JWT manager page
    public function render_jwt_page() {
        $settings = get_option('lem_settings', array());
        include LEM_PLUGIN_DIR . 'templates/jwt-page.php';
    }
    
    public function render_device_settings_page() {
        include LEM_PLUGIN_DIR . 'templates/device-settings-page.php';
    }
    
    public function render_stream_management_page() {
        include LEM_PLUGIN_DIR . 'templates/stream-management-page.php';
    }
    
    public function render_stream_setup_page() {
        // Stream Setup has been merged into Live Streams — redirect transparently.
        $stream_id = sanitize_text_field($_GET['stream_id'] ?? '');
        $args = array('post_type' => 'lem_event', 'page' => 'live-event-manager-stream-management');
        if ($stream_id) {
            $args['stream_id'] = $stream_id;
        }
        wp_redirect(admin_url(add_query_arg($args, 'edit.php')));
        exit;
    }
    
    public function render_debug_page() {
        include LEM_PLUGIN_DIR . 'templates/debug-page.php';
    }

    public function render_templates_page() {
        include LEM_PLUGIN_DIR . 'templates/templates-page.php';
    }
    
    public function render_publisher_page() {
        include LEM_PLUGIN_DIR . 'templates/publisher-page.php';
    }
    
    public function render_services_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'live-event-manager'));
        }
        include LEM_PLUGIN_DIR . 'templates/services-page.php';
    }

    public function render_stream_vendors_page() {
        include LEM_PLUGIN_DIR . 'templates/stream-vendors-page.php';
    }

    public function render_stripe_redirect() {
        wp_redirect(admin_url(add_query_arg(
            array('post_type' => 'lem_event', 'page' => 'live-event-manager-services', 'service' => 'payments'),
            'edit.php'
        )));
        exit;
    }

    public function render_engine_redirect() {
        wp_redirect(admin_url(add_query_arg(
            array('post_type' => 'lem_event', 'page' => 'live-event-manager-services', 'service' => 'streaming'),
            'edit.php'
        )));
        exit;
    }

    public function render_stream_vendors_redirect() {
        $url = admin_url(add_query_arg(
            array('post_type' => 'lem_event', 'page' => 'live-event-manager-services', 'service' => 'streaming'),
            'edit.php'
        ));
        wp_redirect($url);
        exit;
    }

    public function render_devices_redirect() {
        $url = admin_url(add_query_arg(
            array('post_type' => 'lem_event', 'page' => 'live-event-manager-settings', 'tab' => 'device'),
            'edit.php'
        ));
        wp_redirect($url);
        exit;
    }
    
    // Stream setup AJAX handlers
    public function ajax_get_rtmp_info() {
        check_ajax_referer('lem_stream_setup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $stream_id = sanitize_text_field($_POST['stream_id'] ?? '');
        if (empty($stream_id)) {
            $settings  = get_option('lem_settings', array());
            $stream_id = $settings['lem_live_stream_id'] ?? $settings['mux_live_stream_id'] ?? '';
        }

        if (empty($stream_id)) {
            wp_send_json_error('Stream ID is required');
        }

        $request = new WP_REST_Request('GET', '/lem/v1/rtmp-info');
        $request->set_param('stream_id', $stream_id);
        $result = $this->get_rtmp_info($request);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }
    
    public function ajax_create_simulcast_target() {
        check_ajax_referer('lem_stream_setup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $stream_id = sanitize_text_field($_POST['stream_id'] ?? '');
        $url = sanitize_text_field($_POST['url'] ?? '');
        $stream_key = sanitize_text_field($_POST['stream_key'] ?? '');
        
        if (empty($stream_id)) {
            $settings  = get_option('lem_settings', array());
            $stream_id = $settings['lem_live_stream_id'] ?? $settings['mux_live_stream_id'] ?? '';
        }

        if (empty($stream_id) || empty($url)) {
            wp_send_json_error('Stream ID and URL are required');
        }
        
        $request = new WP_REST_Request('POST', '/lem/v1/simulcast-targets');
        $request->set_param('stream_id', $stream_id);
        $request->set_param('url', $url);
        if ($stream_key) {
            $request->set_param('stream_key', $stream_key);
        }
        
        $result = $this->create_simulcast_target($request);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }
    
    public function ajax_delete_simulcast_target() {
        check_ajax_referer('lem_stream_setup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $stream_id = sanitize_text_field($_POST['stream_id'] ?? '');
        $target_id = sanitize_text_field($_POST['target_id'] ?? '');
        
        if (empty($stream_id)) {
            $settings  = get_option('lem_settings', array());
            $stream_id = $settings['lem_live_stream_id'] ?? $settings['mux_live_stream_id'] ?? '';
        }

        if (empty($stream_id) || empty($target_id)) {
            wp_send_json_error('Stream ID and Target ID are required');
        }
        
        $request = new WP_REST_Request('DELETE', '/lem/v1/simulcast-targets/' . $target_id);
        $request->set_param('stream_id', $stream_id);
        
        $result = $this->delete_simulcast_target($request);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success($result);
        }
    }
    
    // ── Provider upload ───────────────────────────────────────────────────────

    // ── Template pack AJAX handlers ────────────────────────────────────────────

    /**
     * AJAX: Upload and install a template pack from a ZIP file.
     */
    public function ajax_upload_template() {
        check_ajax_referer('lem_templates_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }

        $upload_error = $_FILES['template_zip']['error'] ?? -1;
        if (empty($_FILES['template_zip']) || $upload_error !== UPLOAD_ERR_OK) {
            $messages = array(
                UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload_max_filesize limit.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form MAX_FILE_SIZE limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on the server.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            );
            $msg = $messages[$upload_error] ?? 'Upload failed (error code: ' . intval($upload_error) . ').';
            wp_send_json_error($msg);
        }

        $result = LEM_Template_Manager::install_from_zip($_FILES['template_zip']['tmp_name']);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message'  => sprintf('Template "%s" installed successfully.', esc_html($result['name'])),
            'template' => $result,
        ));
    }

    /**
     * AJAX: Activate an installed template pack by slug.
     */
    public function ajax_activate_template() {
        check_ajax_referer('lem_templates_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }

        $slug   = sanitize_key($_POST['slug'] ?? '');
        $result = LEM_Template_Manager::activate_template($slug);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array(
            'message' => 'Template activated.',
            'slug'    => $slug,
        ));
    }

    /**
     * Admin POST: Stream a bundled template pack as a downloadable ZIP.
     *
     * URL: admin-post.php?action=lem_download_pack&pack={slug}&nonce={nonce}
     * Source packs live in plugin/template-packs/{slug}/.
     */
    public function handle_download_pack() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }
        if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), 'lem_download_pack')) {
            wp_die('Invalid or expired link. Please reload the Templates page and try again.');
        }

        $slug     = sanitize_key($_GET['pack'] ?? '');
        $pack_dir = LEM_PLUGIN_DIR . 'template-packs/' . $slug . '/';

        if (empty($slug) || !is_dir($pack_dir)) {
            wp_die('Template pack not found.');
        }

        if (!class_exists('ZipArchive')) {
            wp_die('ZipArchive PHP extension is required to download template packs.');
        }

        // Build ZIP in a temp file
        $tmp = wp_tempnam('lem-pack');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            wp_die('Could not create ZIP file.');
        }

        // Walk all files in the pack directory and add them under {slug}/
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pack_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $real  = $file->getRealPath();
            $rel   = $slug . '/' . ltrim(str_replace(realpath($pack_dir), '', $real), DIRECTORY_SEPARATOR);
            $rel   = str_replace('\\', '/', $rel); // normalise on Windows
            $zip->addFile($real, $rel);
        }
        $zip->close();

        // Stream to browser
        $filename = $slug . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    /**
     * AJAX: Delete an installed template pack by slug.
     */
    public function ajax_delete_template() {
        check_ajax_referer('lem_templates_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }

        $slug   = sanitize_key($_POST['slug'] ?? '');
        $result = LEM_Template_Manager::delete_template($slug);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(array('message' => 'Template deleted.'));
    }

    // ── Streaming provider upload ───────────────────────────────────────────────

    /**
     * AJAX: Upload a new streaming provider class file.
     *
     * Validates the PHP file without executing it, moves it into
     * services/streaming/providers/, and auto-registers it in the factory.
     */
    public function ajax_upload_provider() {
        check_ajax_referer('lem_upload_provider_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
            return;
        }

        if (empty($_FILES['provider_file']) || $_FILES['provider_file']['error'] !== UPLOAD_ERR_OK) {
            $code = $_FILES['provider_file']['error'] ?? -1;
            wp_send_json_error('Upload failed (error code ' . $code . '). Please try again.');
            return;
        }

        $file = $_FILES['provider_file'];

        // ── Size limit: 512 KB ────────────────────────────────────────────
        if ($file['size'] > 524288) {
            wp_send_json_error('File too large. Maximum size is 512 KB.');
            return;
        }

        // ── Filename must match class-{slug}-provider.php ─────────────────
        $filename = sanitize_file_name(basename($file['name']));
        if (!preg_match('/^class-([a-z0-9]+(?:-[a-z0-9]+)*)-provider\.php$/i', $filename, $slug_match)) {
            wp_send_json_error('Filename must follow the pattern: <code>class-{slug}-provider.php</code> (lowercase, hyphens allowed).');
            return;
        }

        // Derive the provider ID from the filename slug (strip hyphens for the ID)
        $provider_id = strtolower(str_replace('-', '', $slug_match[1]));

        // ── Read content ──────────────────────────────────────────────────
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            wp_send_json_error('Could not read the uploaded file.');
            return;
        }

        // ── Must be valid PHP ─────────────────────────────────────────────
        if (strpos(ltrim($content), '<?php') !== 0) {
            wp_send_json_error('File must be a valid PHP file starting with &lt;?php.');
            return;
        }

        // ── Must implement the interface ──────────────────────────────────
        if (strpos($content, 'implements LEM_Streaming_Provider_Interface') === false) {
            wp_send_json_error('Provider class must implement <code>LEM_Streaming_Provider_Interface</code>.');
            return;
        }

        // ── Extract class name ────────────────────────────────────────────
        if (!preg_match('/class\s+(LEM_\w+_Provider)\s+implements\s+LEM_Streaming_Provider_Interface/i', $content, $class_match)) {
            wp_send_json_error('Could not find a valid class declaration. Expected: <code>class LEM_FooBar_Provider implements LEM_Streaming_Provider_Interface</code>.');
            return;
        }
        $class_name = $class_match[1];

        // ── Strict class name validation (prevent code injection) ─────────
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class_name)) {
            wp_send_json_error('Invalid class name. Only letters, numbers, and underscores are allowed.');
            return;
        }

        // ── Guard against overwriting built-ins ───────────────────────────
        $protected = ['class-mux-provider.php', 'class-streaming-provider-interface.php', 'class-streaming-provider-factory.php'];
        if (in_array(strtolower($filename), $protected, true)) {
            wp_send_json_error('Cannot overwrite a built-in plugin file.');
            return;
        }

        // ── Destination directory ─────────────────────────────────────────
        $dest_dir = LEM_PLUGIN_DIR . 'services/streaming/providers/';
        if (!is_dir($dest_dir)) {
            wp_send_json_error('Provider directory does not exist: ' . esc_html($dest_dir));
            return;
        }
        if (!is_writable($dest_dir)) {
            wp_send_json_error('Provider directory is not writable. Check filesystem permissions.');
            return;
        }

        // ── Move file ─────────────────────────────────────────────────────
        $dest_path   = $dest_dir . $filename;
        $is_replace  = file_exists($dest_path);

        if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
            wp_send_json_error('Failed to move file into the plugin directory.');
            return;
        }

        // ── Auto-register in factory ──────────────────────────────────────
        $registered      = $this->register_provider_in_factory($provider_id, $class_name);
        $register_snippet = "\$this->register_provider('{$provider_id}', '{$class_name}');";

        wp_send_json_success(array(
            'provider_id'      => $provider_id,
            'class_name'       => $class_name,
            'filename'         => $filename,
            'is_replace'       => $is_replace,
            'registered'       => $registered,
            'register_snippet' => $register_snippet,
        ));
    }

    /**
     * Inject a register_provider() call into the factory file.
     *
     * Uses the "Additional providers" comment as an anchor so the edit is
     * deterministic and safe even if the file has changed.
     */
    private function register_provider_in_factory(string $provider_id, string $class_name): bool {
        // ── Validate inputs to prevent code injection into factory file ───
        if (!current_user_can('manage_options')) {
            return false;
        }
        if (!preg_match('/^[a-z0-9]+$/', $provider_id)) {
            return false;
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class_name)) {
            return false;
        }

        $factory_file = LEM_PLUGIN_DIR . 'services/streaming/class-streaming-provider-factory.php';

        if (!file_exists($factory_file) || !is_writable($factory_file)) {
            return false;
        }

        $content = file_get_contents($factory_file);
        if ($content === false) {
            return false;
        }

        // Already registered — nothing to do.
        if (strpos($content, "register_provider('{$provider_id}'") !== false) {
            return true;
        }

        $new_line = "        \$this->register_provider('{$provider_id}', '{$class_name}');";
        $anchor   = '        // Additional providers can be registered here in the future';

        if (strpos($content, $anchor) === false) {
            return false; // anchor missing — don't guess
        }

        $updated = str_replace($anchor, $new_line . "\n" . $anchor, $content);

        return file_put_contents($factory_file, $updated) !== false;
    }

    // ── Stream management AJAX handlers ──────────────────────────────────────
    public function ajax_create_stream() {
        check_ajax_referer('lem_stream_management_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $provider = sanitize_text_field($_POST['provider'] ?? 'mux');
        $passthrough = sanitize_text_field($_POST['passthrough'] ?? '');
        
        // Handle playback_policies for live stream - can be JSON string or array
        $playback_policies = array('public'); // Default
        if (isset($_POST['playback_policies'])) {
            if (is_string($_POST['playback_policies'])) {
                $decoded = json_decode(stripslashes($_POST['playback_policies']), true);
                if (is_array($decoded) && !empty($decoded)) {
                    $playback_policies = array_map('sanitize_text_field', $decoded);
                } elseif (is_string($decoded)) {
                    $playback_policies = array(sanitize_text_field($decoded));
                }
            } elseif (is_array($_POST['playback_policies'])) {
                $playback_policies = array_map('sanitize_text_field', $_POST['playback_policies']);
            }
        }
        
        // Handle asset_playback_policies for recorded assets - can be JSON string or array
        $asset_playback_policies = array('public'); // Default
        if (isset($_POST['asset_playback_policies'])) {
            if (is_string($_POST['asset_playback_policies'])) {
                $decoded = json_decode(stripslashes($_POST['asset_playback_policies']), true);
                if (is_array($decoded) && !empty($decoded)) {
                    $asset_playback_policies = array_map('sanitize_text_field', $decoded);
                } elseif (is_string($decoded)) {
                    $asset_playback_policies = array(sanitize_text_field($decoded));
                }
            } elseif (is_array($_POST['asset_playback_policies'])) {
                $asset_playback_policies = array_map('sanitize_text_field', $_POST['asset_playback_policies']);
            }
        }
        
        // Handle boolean values - can be 'true', 'false', '1', '0', or actual boolean
        $reduced_latency = isset($_POST['reduced_latency']) && (
            $_POST['reduced_latency'] === 'true' || 
            $_POST['reduced_latency'] === '1' || 
            $_POST['reduced_latency'] === true
        );
        $test_mode = isset($_POST['test_mode']) && (
            $_POST['test_mode'] === 'true' || 
            $_POST['test_mode'] === '1' || 
            $_POST['test_mode'] === true
        );
        
        // Get provider via factory
        $provider_factory = LEM_Streaming_Provider_Factory::get_instance();
        $streaming_provider = $provider_factory->get_provider($provider, $this);
        
        if (!$streaming_provider) {
            wp_send_json_error('Invalid provider or provider not available');
        }
        
        if (!$streaming_provider->is_configured()) {
            wp_send_json_error('Provider credentials not configured. Please configure in Stream Vendors settings.');
        }
        
        // Delegate to provider's create_stream method
        $params = array(
            'passthrough' => $passthrough,
            'playback_policies' => $playback_policies,
            'asset_playback_policies' => $asset_playback_policies,
            'reduced_latency' => $reduced_latency,
            'test_mode' => $test_mode
        );
        
        $result = $streaming_provider->create_stream($params);
        
        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            $error_data = $result->get_error_data();
            
            // Include additional error details if available
            if (is_array($error_data) && isset($error_data['status'])) {
                $error_message .= ' (HTTP ' . $error_data['status'] . ')';
            }
            
            $this->debug_log('Stream creation failed', array(
                'provider' => $provider,
                'error' => $error_message,
                'error_code' => $result->get_error_code(),
                'error_data' => $error_data
            ));
            
            wp_send_json_error($error_message);
        }
        
        // Clear the provider's stream list cache
        LEM_Cache::del($provider . ':live_streams');

        if (is_array($result)) {
            $result['_provider'] = $provider;
        }

        wp_send_json_success($result);
    }

    public function ajax_delete_stream() {
        check_ajax_referer('lem_stream_management_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $stream_id = sanitize_text_field($_POST['stream_id'] ?? '');
        if (empty($stream_id)) {
            wp_send_json_error('Stream ID is required');
        }

        $settings    = get_option('lem_settings', array());
        $provider_id = $settings['streaming_provider'] ?? 'mux';
        $provider    = LEM_Streaming_Provider_Factory::get_instance()->get_provider($provider_id, $this);

        if (!$provider || !$provider->is_configured()) {
            wp_send_json_error('Streaming provider not configured.');
        }

        $result = $provider->delete_stream($stream_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        LEM_Cache::del($provider_id . ':live_streams');

        wp_send_json_success(array('message' => 'Stream deleted.'));
    }

    public function ajax_update_stream() {
        check_ajax_referer('lem_stream_management_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $stream_id = sanitize_text_field($_POST['stream_id'] ?? '');
        if (empty($stream_id)) {
            wp_send_json_error('Stream ID is required.');
        }

        $settings    = get_option('lem_settings', array());
        $provider_id = $settings['streaming_provider'] ?? 'mux';
        $provider    = LEM_Streaming_Provider_Factory::get_instance()->get_provider($provider_id, $this);

        if (!$provider || !$provider->is_configured()) {
            wp_send_json_error('Streaming provider not configured.');
        }

        // Forward all non-reserved POST fields to the provider as params.
        $reserved = array('action', 'nonce', 'stream_id');
        $params   = array();
        foreach ($_POST as $key => $value) {
            if (!in_array($key, $reserved, true)) {
                $params[sanitize_key($key)] = sanitize_text_field($value);
            }
        }

        $result = $provider->update_stream($stream_id, $params);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        LEM_Cache::del($provider_id . ':live_streams');

        wp_send_json_success($result ?: array('message' => 'Stream updated.'));
    }

    public function ajax_get_stream_details() {
        check_ajax_referer('lem_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $stream_id = sanitize_text_field($_GET['stream_id'] ?? '');
        if (empty($stream_id)) {
            wp_send_json_error('Stream ID is required');
        }

        $settings    = get_option('lem_settings', array());
        $provider_id = $settings['streaming_provider'] ?? 'mux';
        $provider    = LEM_Streaming_Provider_Factory::get_instance()->get_provider($provider_id, $this);

        if (!$provider || !$provider->is_configured()) {
            wp_send_json_error('Streaming provider not configured.');
        }

        $result = $provider->get_stream_details($stream_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }
    
    public function ajax_save_stream_id() {
        check_ajax_referer('lem_stream_setup_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $stream_id = sanitize_text_field($_POST['stream_id'] ?? '');
        if (empty($stream_id)) {
            wp_send_json_error('Stream ID is required');
        }
        
        $settings         = get_option('lem_settings', array());
        // 'lem_saved_stream_ids' supersedes the old Mux-prefixed key.
        $saved_stream_ids = $settings['lem_saved_stream_ids'] ?? $settings['mux_saved_stream_ids'] ?? array();

        if (!is_array($saved_stream_ids)) {
            $saved_stream_ids = array();
        }

        if (!in_array($stream_id, $saved_stream_ids, true)) {
            $saved_stream_ids[]              = $stream_id;
            $settings['lem_saved_stream_ids'] = array_unique($saved_stream_ids);
            update_option('lem_settings', $settings);
            wp_send_json_success(array('message' => 'Stream ID saved.'));
        } else {
            wp_send_json_success(array('message' => 'Stream ID already saved.'));
        }
    }
    
    public function ajax_clear_all_tokens() {
        try {
            $this->debug_log('Clear all tokens AJAX request started');
            
            check_ajax_referer('lem_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                $this->debug_log('Clear all tokens failed - insufficient permissions');
                wp_send_json_error('Insufficient permissions');
            }
        
        $results = array();
        
        // Clear MySQL JWT tokens
        global $wpdb;
        $table_name = $wpdb->prefix . 'lem_jwt_tokens';
        $mysql_deleted = $wpdb->query("DELETE FROM $table_name");
        $results['mysql_deleted'] = $mysql_deleted;
        
        // Clear Redis data
        try {
            $redis = $this->get_redis_connection();
            if ($redis) {
                $redis_keys_deleted = 0;
                
                // NOTE: KEYS command is O(N) and blocks Redis. This is acceptable here
                // because this is an admin-only bulk operation behind manage_options capability.
                // For large Redis instances, consider using SCAN instead.
                $patterns = array(
                    'jwt:*',
                    'jwt_token:*',
                    'revoked:*',
                    'session:*',
                    'jti_session:*',
                    'active_sessions:*',
                    'magic_token:*',
                    'event:*',
                    'event_access:*',
                    'session_status:*'
                );

                foreach ($patterns as $pattern) {
                    try {
                        $keys = $redis->keys($pattern);
                        if ($keys && is_array($keys)) {
                            $deleted = $redis->del($keys);
                            $redis_keys_deleted += $deleted;
                            $this->debug_log("Deleted Redis keys for pattern: $pattern", array('count' => $deleted));
                        }
                    } catch (Exception $e) {
                        $this->debug_log("Error deleting Redis keys for pattern: $pattern", array('error' => $e->getMessage()));
                    }
                }

                $results['redis_warning'] = 'Used KEYS command for bulk clear (admin-only operation).';
                
                $results['redis_keys_deleted'] = $redis_keys_deleted;
            } else {
                $results['redis_keys_deleted'] = 0;
                $results['redis_error'] = 'Redis connection failed';
            }
        } catch (Exception $e) {
            $this->debug_log('Redis clear operation failed', array('error' => $e->getMessage()));
            $results['redis_keys_deleted'] = 0;
            $results['redis_error'] = 'Redis operation failed: ' . $e->getMessage();
        }
        
        $this->debug_log('All tokens cleared by admin', $results);
        
        $response_data = array(
            'message' => 'All tokens and sessions cleared successfully',
            'results' => $results
        );
        
        $this->debug_log('Sending AJAX response', $response_data);
        wp_send_json_success($response_data);
        
        } catch (Exception $e) {
            $this->debug_log('Clear all tokens AJAX failed with exception', array(
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ));
            wp_send_json_error('Clear operation failed: ' . $e->getMessage());
        }
    }
    
    public function render_user_guide_page() {
        include LEM_PLUGIN_DIR . 'templates/user-guide-page.php';
    }
    
    // Mux API methods for playback restrictions
    private function get_mux_api_credentials() {
        $settings = get_option('lem_settings', array());
        $token_id = $settings['mux_token_id'] ?? '';
        $token_secret = $settings['mux_token_secret'] ?? '';
        
        $this->debug_log('Mux API credentials check', array(
            'credentials_configured' => !empty($token_id) && !empty($token_secret)
        ));
        
        if (empty($token_id) || empty($token_secret)) {
            return false;
        }
        
        return array(
            'token_id' => $token_id,
            'token_secret' => $token_secret
        );
    }
    
    public function create_playback_restriction($name, $description, $allowed_domains, $allow_no_referrer = true, $allow_no_user_agent = true, $allow_high_risk_user_agent = true) {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            $this->debug_log('Mux API credentials not configured');
            return array('success' => false, 'error' => 'Mux API credentials not configured. Please configure them in Event Manager > Settings.');
        }
        
        // Validate and clean domains
        $cleaned_domains = array();
        foreach ($allowed_domains as $domain) {
            $domain = trim($domain);
            if (!empty($domain)) {
                // Remove protocol if present
                $domain = preg_replace('/^https?:\/\//', '', $domain);
                // Remove trailing slash
                $domain = rtrim($domain, '/');
                $cleaned_domains[] = $domain;
            }
        }
        
        if (empty($cleaned_domains)) {
            return array('success' => false, 'error' => 'At least one valid domain is required');
        }
        
        $this->debug_log('Cleaned domains', array(
            'original' => $allowed_domains,
            'cleaned' => $cleaned_domains
        ));
        
        // Test API credentials with a simple GET request first
        $test_response = wp_remote_get('https://api.mux.com/video/v1/playback-restrictions', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($test_response)) {
            $this->debug_log('Mux API test failed', array(
                'error' => $test_response->get_error_message()
            ));
            return array('success' => false, 'error' => 'Failed to connect to Mux API: ' . $test_response->get_error_message());
        }
        
        $test_code = wp_remote_retrieve_response_code($test_response);
        $test_body = wp_remote_retrieve_body($test_response);
        $this->debug_log('Mux API test response', array(
            'response_code' => $test_code,
            'body_length' => strlen($test_body),
            'body_preview' => substr($test_body, 0, 200) . (strlen($test_body) > 200 ? '...' : '')
        ));
        
        if ($test_code === 401) {
            return array('success' => false, 'error' => 'Invalid Mux API credentials. Please check your Token ID and Token Secret.');
        }
        
        $payload = array(
            'referrer' => array(
                'allowed_domains' => $cleaned_domains,
                'allow_no_referrer' => (bool) $allow_no_referrer
            ),
            'user_agent' => array(
                'allow_no_user_agent' => (bool) $allow_no_user_agent,
                'allow_high_risk_user_agent' => (bool) $allow_high_risk_user_agent
            )
        );
        
        $url = 'https://api.mux.com/video/v1/playback-restrictions';
                        $this->debug_log('Creating playback restriction', array(
                    'url' => $url,
                    'payload' => $payload,
                    'has_credentials' => !empty($credentials['token_id'])
                ));
        
                        $request_body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        if ($request_body === false) {
                            return array('success' => false, 'error' => 'Failed to encode request payload');
                        }
                $this->debug_log('Mux API request details', array(
                    'request_body' => $request_body,
                    'content_type' => 'application/json',
                    'timeout' => 30
                ));
                
                $response = wp_remote_post($url, array(
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
                    ),
                    'body' => $request_body,
                    'timeout' => 30
                ));
        
        if (is_wp_error($response)) {
            $this->debug_log('WP Error creating restriction', $response->get_error_message());
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $response_code = wp_remote_retrieve_response_code($response);
        
        $this->debug_log('Mux API response', array(
            'response_code' => $response_code,
            'body_length' => strlen($body),
            'body_preview' => substr($body, 0, 200) . (strlen($body) > 200 ? '...' : ''),
            'data' => $data
        ));
        
                        if ($response_code === 201) {
                    // Store the restriction locally for reference
                    $restrictions = get_option('lem_playback_restrictions', array());
                    $restrictions[$data['data']['id']] = array(
                        'name' => $name,
                        'description' => $description,
                        'mux_id' => $data['data']['id'],
                        'created_at' => current_time('mysql')
                    );
                    update_option('lem_playback_restrictions', $restrictions);
                    
                    return array('success' => true, 'data' => $data['data']);
                } else {
                    $error_message = 'Unknown error';
                    if (isset($data['error'])) {
                        if (isset($data['error']['message'])) {
                            $error_message = $data['error']['message'];
                        } elseif (isset($data['error']['messages']) && is_array($data['error']['messages'])) {
                            $error_message = implode(', ', $data['error']['messages']);
                        } elseif (isset($data['error']['type'])) {
                            $error_message = $data['error']['type'];
                        }
                    }
                    
                    $this->debug_log('Mux API error details', array(
                        'response_code' => $response_code,
                        'error_data' => $data['error'] ?? 'No error data',
                        'parsed_error' => $error_message
                    ));
                    
                    return array('success' => false, 'error' => $error_message);
                }
    }
    
    public function get_playback_restrictions() {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            $this->debug_log('Mux API credentials not configured');
            return array('success' => false, 'error' => 'Mux API credentials not configured. Please configure them in Event Manager > Settings.');
        }
        
        $url = 'https://api.mux.com/video/v1/playback-restrictions';
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (wp_remote_retrieve_response_code($response) === 200) {
            return array('success' => true, 'data' => $data['data']);
        } else {
            return array('success' => false, 'error' => $data['error']['message'] ?? 'Unknown error');
        }
    }
    
    public function delete_playback_restriction($restriction_id) {
        $credentials = $this->get_mux_api_credentials();
        if (!$credentials) {
            $this->debug_log('Mux API credentials not configured');
            return array('success' => false, 'error' => 'Mux API credentials not configured. Please configure them in Event Manager > Settings.');
        }
        
        $url = 'https://api.mux.com/video/v1/playback-restrictions/' . $restriction_id;
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($credentials['token_id'] . ':' . $credentials['token_secret'])
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        if (wp_remote_retrieve_response_code($response) === 204) {
            // Remove from local storage
            $restrictions = get_option('lem_playback_restrictions', array());
            unset($restrictions[$restriction_id]);
            update_option('lem_playback_restrictions', $restrictions);
            
            return array('success' => true);
        } else {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            return array('success' => false, 'error' => $data['error']['message'] ?? 'Unknown error');
        }
    }
    
    /**
     * Canonical playback blob in Redis: lem:playback:{email_hash}:{event_id}
     *
     * @param int $expires_ts_unix Expiry as Unix timestamp (TTL derived from now).
     */
    private function store_playback_blob($email, $event_id, array $blob, $expires_ts_unix) {
        $redis = $this->get_redis_connection();
        if (!$redis) {
            return false;
        }
        $ttl = max(60, $expires_ts_unix - time());
        $key = LEM_Access::playback_key($email, $event_id);
        $blob['expires_at_ts'] = $expires_ts_unix;
        return $redis->setex($key, $ttl, wp_json_encode($blob));
    }
}
