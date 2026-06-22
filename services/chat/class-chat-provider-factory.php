<?php
/**
 * Chat Provider Factory
 *
 * Companion plugins register on plugins_loaded (priority 20 or later):
 *
 *   LEM_Chat_Provider_Factory::get_instance()->register_provider('ably', 'LEM_Ably_Chat_Provider');
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
        // No built-in chat provider — adaptors register via register_provider().
    }

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_provider(string $id, string $class_name): void {
        self::$providers[ $id ] = $class_name;
        do_action('lem_chat_provider_registered', $id, $class_name);
    }

    public function get_available_providers(): array {
        return array_keys(self::$providers);
    }

    /**
     * Resolve the file containing a provider class. Adaptors hook
     * 'lem_chat_provider_class_file' and return an absolute path.
     */
    private function resolve_provider_file(string $provider_id): ?string {
        $external = apply_filters('lem_chat_provider_class_file', null, $provider_id);
        if (is_string($external) && $external !== '' && file_exists($external)) {
            return $external;
        }
        $bundled = plugin_dir_path(__FILE__) . 'providers/class-' . strtolower($provider_id) . '-provider.php';
        return file_exists($bundled) ? $bundled : null;
    }

    /**
     * @param  string|null $provider_id
     * @return LEM_Chat_Provider_Interface|null
     */
    public function get_provider(?string $provider_id = null): ?LEM_Chat_Provider_Interface {
        if (empty(self::$providers)) {
            return null;
        }

        if ($provider_id === null) {
            $settings    = get_option('lem_settings', array());
            $provider_id = $settings['chat_provider'] ?? '';
        }

        if ($provider_id === '' || ! isset(self::$providers[ $provider_id ])) {
            $provider_id = array_key_first(self::$providers);
        }

        $class_name = self::$providers[ $provider_id ];

        if (! class_exists($class_name)) {
            $file = $this->resolve_provider_file($provider_id);
            if ($file) {
                require_once $file;
            }
        }

        if (! class_exists($class_name)) {
            error_log("LEM: Chat provider class {$class_name} not found.");
            return null;
        }

        return new $class_name();
    }

    public function get_active_provider(): ?LEM_Chat_Provider_Interface {
        return $this->get_provider();
    }

    public function is_available(): bool {
        $provider = $this->get_active_provider();
        return $provider !== null && $provider->is_configured();
    }
}
