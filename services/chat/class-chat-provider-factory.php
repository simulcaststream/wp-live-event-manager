<?php
/**
 * Chat Provider Factory
 *
 * Mirrors the pattern of LEM_Streaming_Provider_Factory and LEM_Payment_Provider_Factory.
 * No built-in provider is registered — the chat slot is empty until a companion plugin
 * calls register_provider(). get_active_provider() returns null when nothing is registered.
 *
 * Companion plugins register on plugins_loaded (priority 20 or later):
 *
 *   LEM_Chat_Provider_Factory::get_instance()->register_provider('ably', 'My_Ably_Provider');
 *
 * The active provider is controlled by lem_settings['chat_provider'] (default: first registered).
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_Chat_Provider_Factory {

    private static array $providers = array();
    private static ?self $instance  = null;

    private function __construct() {
        // No built-in chat provider — companion plugins register their own.
    }

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_provider(string $id, string $class_name): void {
        self::$providers[$id] = $class_name;
    }

    public function get_available_providers(): array {
        return array_keys(self::$providers);
    }

    /**
     * Return an instantiated provider by ID, or the configured/first provider if null.
     * Returns null when no providers are registered.
     *
     * @param  string|null $provider_id
     * @return LEM_Chat_Provider_Interface|null
     */
    public function get_provider(?string $provider_id = null): ?LEM_Chat_Provider_Interface {
        if (empty(self::$providers)) {
            return null;
        }

        if ($provider_id === null) {
            $settings    = get_option('lem_settings', array());
            $provider_id = $settings['chat_provider'] ?? array_key_first(self::$providers);
        }

        if (!isset(self::$providers[$provider_id])) {
            $provider_id = array_key_first(self::$providers);
        }

        $class_name    = self::$providers[$provider_id];
        $provider_file = plugin_dir_path(__FILE__) . 'providers/class-' . strtolower($provider_id) . '-provider.php';

        if (file_exists($provider_file) && !class_exists($class_name)) {
            require_once $provider_file;
        }

        if (!class_exists($class_name)) {
            error_log("LEM: Chat provider class {$class_name} not found.");
            return null;
        }

        return new $class_name();
    }

    public function get_active_provider(): ?LEM_Chat_Provider_Interface {
        return $this->get_provider();
    }

    /**
     * Whether any chat provider is registered and configured.
     */
    public function is_available(): bool {
        $provider = $this->get_active_provider();
        return $provider !== null && $provider->is_configured();
    }
}
