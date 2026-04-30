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
        $this->register_provider('stripe', 'LEM_Stripe_Provider');
        $this->register_provider('paypal', 'LEM_PayPal_Provider');
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
     * Return an instantiated provider by ID, or the active provider if $id is null.
     * Falls back to 'stripe' if the requested provider is missing.
     *
     * @param  string|null $provider_id
     * @return LEM_Payment_Provider_Interface|null
     */
    public function get_provider(?string $provider_id = null): ?LEM_Payment_Provider_Interface {
        if ($provider_id === null) {
            $settings    = get_option('lem_settings', array());
            $provider_id = $settings['payment_provider'] ?? 'stripe';
        }

        if (!isset(self::$providers[$provider_id])) {
            $provider_id = 'stripe';
        }

        $class_name    = self::$providers[$provider_id];
        $provider_file = plugin_dir_path(__FILE__) . 'providers/class-' . strtolower($provider_id) . '-provider.php';

        if (file_exists($provider_file) && !class_exists($class_name)) {
            require_once $provider_file;
        }

        if (!class_exists($class_name)) {
            error_log("LEM: Payment provider class {$class_name} not found.");
            return null;
        }

        return new $class_name();
    }

    public function get_active_provider(): ?LEM_Payment_Provider_Interface {
        return $this->get_provider();
    }
}
