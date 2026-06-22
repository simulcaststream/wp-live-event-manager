<?php
/**
 * Payment Provider Factory
 *
 * Mirrors the pattern of LEM_Streaming_Provider_Factory.
 * Companion plugins register additional providers on plugins_loaded:
 *
 *   LEM_Payment_Provider_Factory::get_instance()->register_provider('paypal', 'My_PayPal_Provider');
 *
 * The active provider is controlled by lem_settings['payment_provider'] (default: 'stripe').
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_Payment_Provider_Factory {

    private static array $providers = array();
    private static ?self $instance  = null;

    private function __construct() {
        // No built-in providers. Adaptor plugins register concrete providers
        // (Stripe, PayPal, etc.) via register_provider() on plugins_loaded and
        // supply their class file through the 'lem_payment_provider_class_file'
        // filter. See EXTENDING.md.
    }

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_provider(string $id, string $class_name): void {
        self::$providers[$id] = $class_name;
        do_action('lem_payment_provider_registered', $id, $class_name);
    }

    public function get_available_providers(): array {
        return array_keys(self::$providers);
    }

    /**
     * Resolve the file containing a provider class. Companion plugins hook
     * 'lem_payment_provider_class_file' and return an absolute path.
     */
    private function resolve_provider_file(string $provider_id): ?string {
        $external = apply_filters('lem_payment_provider_class_file', null, $provider_id);
        if (is_string($external) && $external !== '' && file_exists($external)) {
            return $external;
        }
        $bundled = plugin_dir_path(__FILE__) . 'providers/class-' . strtolower($provider_id) . '-provider.php';
        return file_exists($bundled) ? $bundled : null;
    }

    /**
     * Return an instantiated provider by ID, or the active provider if $id is null.
     *
     * @param  string|null $provider_id
     * @return LEM_Payment_Provider_Interface|null
     */
    public function get_provider(?string $provider_id = null): ?LEM_Payment_Provider_Interface {
        if ($provider_id === null) {
            $settings    = get_option('lem_settings', array());
            $provider_id = $settings['payment_provider'] ?? '';
        }

        if (!isset(self::$providers[$provider_id])) {
            $registered = array_keys(self::$providers);
            if (empty($registered)) {
                return null;
            }
            $provider_id = $registered[0];
        }

        $class_name = self::$providers[$provider_id];

        if (!class_exists($class_name)) {
            $file = $this->resolve_provider_file($provider_id);
            if ($file !== null) {
                require_once $file;
            }
        }

        if (!class_exists($class_name)) {
            error_log("LEM: Payment provider class {$class_name} not found.");
            return null;
        }

        $instance = new $class_name();
        do_action('lem_payment_provider_activated', $provider_id, $instance);
        return $instance;
    }

    public function get_active_provider(): ?LEM_Payment_Provider_Interface {
        return $this->get_provider();
    }
}
