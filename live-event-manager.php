<?php
/**
 * Plugin Name: Live Event Manager
 * Plugin URI: https://simulcast.stream
 * Description: Manage stream events, ticketing, and JWT generation for secure paywall system
 * Version: 1.2.0
 * Author: Simulcast
 * License: GPL v2 or later
 * Text Domain: live-event-manager
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LEM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LEM_PLUGIN_URL', plugin_dir_url(__FILE__));
// Must reference this file (not trait files) for activation hooks and paths used from traits.
define('LEM_PLUGIN_FILE', __FILE__);
define('LEM_VERSION', '1.2.0');
// REST namespace. Adaptors should read via apply_filters('lem_rest_namespace', LEM_REST_NAMESPACE).
define('LEM_REST_NAMESPACE', 'lem/v1');

// Load Firebase JWT library if available
if (file_exists(LEM_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once LEM_PLUGIN_DIR . 'vendor/autoload.php';
}

require_once LEM_PLUGIN_DIR . 'includes/class-lem-cache.php';
require_once LEM_PLUGIN_DIR . 'includes/class-lem-access.php';
require_once LEM_PLUGIN_DIR . 'includes/class-lem-device-service.php';
require_once LEM_PLUGIN_DIR . 'includes/class-lem-template-manager.php';
require_once LEM_PLUGIN_DIR . 'includes/class-lem-settings-registry.php';
require_once LEM_PLUGIN_DIR . 'includes/class-lem-ajax-helpers.php';
require_once LEM_PLUGIN_DIR . 'includes/class-lem-webhook-log.php';
require_once LEM_PLUGIN_DIR . 'includes/class-lem-provider-notices.php';
require_once LEM_PLUGIN_DIR . 'services/magic-links/class-magic-link-service.php';
require_once LEM_PLUGIN_DIR . 'services/streaming/class-streaming-provider-interface.php';
require_once LEM_PLUGIN_DIR . 'services/streaming/class-streaming-provider-factory.php';
require_once LEM_PLUGIN_DIR . 'includes/class-lem-payment-status.php';
require_once LEM_PLUGIN_DIR . 'services/payments/class-payment-provider-interface.php';
require_once LEM_PLUGIN_DIR . 'services/payments/class-payment-provider-factory.php';
require_once LEM_PLUGIN_DIR . 'services/chat/class-chat-provider-interface.php';
require_once LEM_PLUGIN_DIR . 'services/chat/class-chat-provider-factory.php';

// Main class body split into domain traits (see includes/plugin/trait-lem-*.php)
require_once LEM_PLUGIN_DIR . 'includes/plugin/trait-lem-bootstrap-events.php';
require_once LEM_PLUGIN_DIR . 'includes/plugin/trait-lem-admin-streaming.php';
require_once LEM_PLUGIN_DIR . 'includes/plugin/trait-lem-jwt-payments.php';
require_once LEM_PLUGIN_DIR . 'includes/plugin/trait-lem-rest-webhooks.php';

/**
 * Main Plugin Class
 */
class LiveEventManager {

    private $device_service;
    private $magic_link_service;
    private $event_access_cache = array();
    private $streaming_provider = null;
    public  $last_jwt_error = null;

    // In-memory cache for current request — now delegated to LEM_Cache static helpers.
    // Kept as an alias so existing self::$memory_cache references still compile.
    private static $memory_cache = array();

    use LEM_Trait_Bootstrap_And_Events;
    use LEM_Trait_Admin_And_Streaming;
    use LEM_Trait_Jwt_And_Payments;
    use LEM_Trait_Rest_And_Webhooks;
}

// Initialize the plugin
global $live_event_manager;
$live_event_manager = new LiveEventManager();

// Admin-only check: warn when no provider adaptors are registered.
LEM_Provider_Notices::register();
