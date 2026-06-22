<?php
/**
 * Streaming Provider Factory
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_Streaming_Provider_Factory {
    
    private static $providers = array();
    private static $instance = null;
    
    private function __construct() {
        // No built-in providers. Adaptor plugins register concrete providers
        // (OME, Mux, etc.) via register_provider() on plugins_loaded and
        // supply their class file through the 'lem_streaming_provider_class_file'
        // filter. See EXTENDING.md.
    }
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function register_provider($id, $class_name) {
        self::$providers[$id] = $class_name;
        do_action('lem_streaming_provider_registered', $id, $class_name);
    }

    public function get_available_providers() {
        return array_keys(self::$providers);
    }

    /**
     * Resolve the file containing a provider class.
     *
     * Companion plugins should hook 'lem_streaming_provider_class_file' and return
     * the absolute path to their provider class file. Falls back to the legacy
     * bundled location for backwards compatibility while providers still ship in core.
     *
     * @param string $provider_id
     * @return string|null
     */
    private function resolve_provider_file($provider_id) {
        $external = apply_filters('lem_streaming_provider_class_file', null, $provider_id);
        if (is_string($external) && $external !== '' && file_exists($external)) {
            return $external;
        }
        $bundled = plugin_dir_path(__FILE__) . 'providers/class-' . strtolower($provider_id) . '-provider.php';
        return file_exists($bundled) ? $bundled : null;
    }

    public function get_provider($provider_id = null, $plugin = null) {
        $settings    = get_option('lem_settings', array());
        $provider_id = $provider_id ?: (!empty($settings['streaming_provider']) ? $settings['streaming_provider'] : 'ome');

        if (!isset(self::$providers[$provider_id])) {
            // Fall back to any registered provider — prefer 'ome' if present.
            if (isset(self::$providers['ome'])) {
                $provider_id = 'ome';
            } else {
                $registered = array_keys(self::$providers);
                if (empty($registered)) {
                    return null;
                }
                $provider_id = $registered[0];
            }
        }

        $class_name = self::$providers[$provider_id];

        if (!class_exists($class_name)) {
            $file = $this->resolve_provider_file($provider_id);
            if ($file !== null) {
                require_once $file;
            }
        }

        if (!class_exists($class_name)) {
            error_log("LEM: Provider class {$class_name} not found.");
            return null;
        }

        $instance = new $class_name($plugin);
        do_action('lem_streaming_provider_activated', $provider_id, $instance);
        return $instance;
    }

    public function get_active_provider($plugin) {
        return $this->get_provider(null, $plugin);
    }

    public function get_provider_name($provider_id) {
        if (!isset(self::$providers[$provider_id])) {
            return 'Unknown';
        }

        $class_name = self::$providers[$provider_id];

        if (!class_exists($class_name)) {
            $file = $this->resolve_provider_file($provider_id);
            if ($file !== null) {
                require_once $file;
            }
        }

        if (class_exists($class_name)) {
            $temp_instance = new $class_name(null);
            return $temp_instance->get_name();
        }

        return ucfirst($provider_id);
    }
}
