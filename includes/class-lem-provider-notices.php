<?php
/**
 * Admin notices related to provider registration.
 *
 * Core (LEM) ships no providers of its own. When neither a streaming nor a
 * payment provider is registered by an adaptor plugin, the plugin is useless —
 * this class renders a prominent admin notice pointing the site owner to the
 * Free Adaptors / Premium plugins or to EXTENDING.md.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_Provider_Notices {

    public static function register(): void {
        add_action('admin_notices', array(__CLASS__, 'maybe_render'));
    }

    public static function maybe_render(): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $streaming = array();
        $payments  = array();

        if (class_exists('LEM_Streaming_Provider_Factory')) {
            $streaming = LEM_Streaming_Provider_Factory::get_instance()->get_available_providers();
        }
        if (class_exists('LEM_Payment_Provider_Factory')) {
            $payments = LEM_Payment_Provider_Factory::get_instance()->get_available_providers();
        }

        if (!empty($streaming) && !empty($payments)) {
            return;
        }

        $missing = array();
        if (empty($streaming)) {
            $missing[] = 'streaming';
        }
        if (empty($payments)) {
            $missing[] = 'payment';
        }

        $missing_label = implode(' and ', $missing);
        $doc_link      = esc_url('https://github.com/simulcast-stream/wp-live-event-manager/blob/main/EXTENDING.md');
        ?>
        <div class="notice notice-error">
            <p>
                <strong>Live Event Manager:</strong>
                No <?php echo esc_html($missing_label); ?> provider is registered.
                The core plugin ships only contracts — install the
                <em>LEM Free Adaptors</em> plugin (OME + PayPal) for the free path,
                or <em>LEM Premium</em> (Mux + Stripe) for the commercial path.
                See <a href="<?php echo $doc_link; ?>" target="_blank" rel="noopener">EXTENDING.md</a>
                if you're building your own adaptor.
            </p>
        </div>
        <?php
    }
}
