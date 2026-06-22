<?php
/**
 * Live Event Manager – Settings Page
 *
 * Tabs: Streaming | Payments | Cache & Access | Device
 */
if (!defined('ABSPATH')) exit;

$settings        = get_option('lem_settings', []);
$device_settings = get_option('lem_device_settings', ['identification_method' => 'session_based']);

// ── Save handler ─────────────────────────────────────────────────────────────
if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
if (isset($_POST['lem_settings_nonce']) && wp_verify_nonce($_POST['lem_settings_nonce'], 'lem_save_settings')) {

    $saved_tab = sanitize_text_field($_POST['tab'] ?? 'streaming');

    if ($saved_tab === 'streaming') {
        $settings['streaming_provider'] = sanitize_text_field($_POST['streaming_provider'] ?? 'mux');
    }

    if ($saved_tab === 'payments') {
        $available_pay = LEM_Payment_Provider_Factory::get_instance()->get_available_providers();
        $submitted_pid = sanitize_text_field($_POST['payment_provider'] ?? '');
        if (in_array($submitted_pid, $available_pay, true)) {
            $settings['payment_provider'] = $submitted_pid;
        }
    }

    if ($saved_tab === 'cache') {
        $settings['upstash_redis_url']            = sanitize_text_field($_POST['upstash_redis_url']   ?? '');
        $settings['upstash_redis_token']          = sanitize_text_field($_POST['upstash_redis_token'] ?? '');
        $settings['jwt_expiration_hours']         = max(1, intval($_POST['jwt_expiration_hours']      ?? 24));
        $settings['jwt_refresh_duration_minutes'] = max(1, intval($_POST['jwt_refresh_duration_minutes'] ?? 15));
        $settings['debug_mode']                   = isset($_POST['debug_mode']) ? 1 : 0;

        if (class_exists('LEM_Cache')) {
            LEM_Cache::reset();
        }
    }

    if ($saved_tab === 'device') {
        $device_settings['identification_method'] = sanitize_text_field($_POST['identification_method'] ?? 'session_based');
        update_option('lem_device_settings', $device_settings);
    }

    update_option('lem_settings', $settings);

    echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved.</strong></p></div>';
}

// ── Active tab ────────────────────────────────────────────────────────────────
$active_tab = sanitize_text_field($_GET['tab'] ?? 'streaming');
$tabs       = array(
    'streaming' => 'Streaming',
    'payments'  => 'Payments',
    'cache'     => 'Cache & Access',
    'device'    => 'Device',
);

if (! isset($tabs[ $active_tab ])) {
    $active_tab = 'streaming';
}

// ── Webhook URLs ──────────────────────────────────────────────────────────────
$stripe_webhook_url = admin_url('admin-ajax.php?action=lem_payment_webhook');
$mux_webhook_url    = admin_url('admin-ajax.php?action=lem_mux_webhook');
$jwt_status_url     = get_rest_url(null, 'lem/v1/check-jwt-status');
?>

<div class="wrap lem-settings-wrap">
    <h1>Live Event Manager – Settings</h1>
    <?php require __DIR__ . '/admin-subnav.php'; ?>

    <?php
    // Show Upstash config warning if on a non-cache tab and not configured.
    if ($active_tab !== 'cache' && class_exists('LEM_Cache') && !LEM_Cache::is_configured()):
    ?>
    <div class="notice notice-warning inline">
        <p>
            <strong>Upstash Redis is not configured.</strong>
            JWT sessions and caching will not work until you add your credentials on the
            <a href="<?php echo esc_url(add_query_arg('tab', 'cache')); ?>">Cache &amp; Access</a> tab.
        </p>
    </div>
    <?php endif; ?>

    <?php /* Tab navigation */ ?>
    <nav class="nav-tab-wrapper">
        <?php foreach ($tabs as $slug => $label):
            $url = add_query_arg(['page' => 'live-event-manager-settings', 'tab' => $slug], admin_url('edit.php?post_type=lem_event'));
        ?>
        <a href="<?php echo esc_url($url); ?>"
           class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
            <?php echo esc_html($label); ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <form method="post" action="">
        <?php wp_nonce_field('lem_save_settings', 'lem_settings_nonce'); ?>
        <input type="hidden" name="tab" value="<?php echo esc_attr($active_tab); ?>">

        <?php /* ═══ STREAMING ══════════════════════════════════════════════ */ ?>
        <?php if ($active_tab === 'streaming'):
            $factory            = LEM_Streaming_Provider_Factory::get_instance();
            $all_providers      = [];
            foreach ($factory->get_available_providers() as $pid) {
                $all_providers[$pid] = $factory->get_provider_name($pid);
            }
            $active_pid      = $settings['streaming_provider'] ?? 'mux';
            $active_provider = $factory->get_provider($active_pid);
            $vendors_url     = admin_url('edit.php?post_type=lem_event&page=live-event-manager-services&service=streaming');
        ?>

        <h2>Streaming Provider</h2>
        <table class="form-table">
            <tr>
                <th><label for="streaming_provider">Active Provider</label></th>
                <td>
                    <select id="streaming_provider" name="streaming_provider">
                        <?php foreach ($all_providers as $pid => $pname): ?>
                        <option value="<?php echo esc_attr($pid); ?>" <?php selected($active_pid, $pid); ?>>
                            <?php echo esc_html($pname); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($active_provider): ?>
                    <span style="margin-left:12px;">
                        <?php if ($active_provider->is_configured()): ?>
                            <span style="color:#46b450;">&#10003; Configured</span>
                        <?php else: ?>
                            <span style="color:#d63638;">&#10007; Credentials missing</span>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                    <p class="description">
                        Credentials and API keys are configured on the
                        <a href="<?php echo esc_url($vendors_url); ?>">Services</a> page.
                    </p>
                </td>
            </tr>
        </table>

        <?php endif; ?>

        <?php /* ═══ PAYMENTS ════════════════════════════════════════════════ */ ?>
        <?php if ($active_tab === 'payments'):
            $pay_factory       = LEM_Payment_Provider_Factory::get_instance();
            $pay_providers     = $pay_factory->get_available_providers();
            $active_pay_pid    = $settings['payment_provider'] ?? ($pay_providers[0] ?? 'stripe');
            $active_pay_prov   = $pay_factory->get_provider($active_pay_pid);
            $services_pay_url  = admin_url('edit.php?post_type=lem_event&page=live-event-manager-services&service=payments');
        ?>

        <h2>Payment Provider</h2>
        <table class="form-table">
            <tr>
                <th><label for="payment_provider">Active Provider</label></th>
                <td>
                    <select id="payment_provider" name="payment_provider">
                        <?php foreach ($pay_providers as $pid):
                            $prov = $pay_factory->get_provider($pid);
                            $name = $prov ? $prov->get_name() : $pid;
                        ?>
                        <option value="<?php echo esc_attr($pid); ?>" <?php selected($active_pay_pid, $pid); ?>>
                            <?php echo esc_html($name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($active_pay_prov): ?>
                    <span style="margin-left:12px;">
                        <?php if ($active_pay_prov->is_configured()): ?>
                            <span style="color:#46b450;">&#10003; Configured</span>
                        <?php else: ?>
                            <span style="color:#d63638;">&#10007; Credentials missing</span>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                    <p class="description">
                        Credentials and API keys are configured on the
                        <a href="<?php echo esc_url($services_pay_url); ?>">Services</a> page.
                    </p>
                </td>
            </tr>
        </table>

        <?php endif; ?>

        <?php /* ═══ CACHE & ACCESS ══════════════════════════════════════════ */ ?>
        <?php if ($active_tab === 'cache'): ?>
        <h2>Upstash Redis</h2>
        <p>
            Create a free database at <a href="https://upstash.com" target="_blank">upstash.com</a>
            then paste the REST URL and token below.
            Upstash works over HTTPS so no server extension is needed.
        </p>

        <?php if (class_exists('LEM_Cache') && LEM_Cache::is_configured()): ?>
        <div class="notice notice-success inline" style="margin:0 0 16px;">
            <p>Upstash is configured and ready.</p>
        </div>
        <?php else: ?>
        <div class="notice notice-warning inline" style="margin:0 0 16px;">
            <p><strong>Not configured.</strong> JWT sessions will not work until you add your Upstash credentials.</p>
        </div>
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th><label for="upstash_redis_url">REST URL</label></th>
                <td>
                    <input type="text" id="upstash_redis_url" name="upstash_redis_url"
                           value="<?php echo esc_attr($settings['upstash_redis_url'] ?? ''); ?>"
                           class="regular-text" placeholder="https://xxxx.upstash.io">
                    <p class="description">Found on your Upstash database page under <em>REST API</em>.</p>
                </td>
            </tr>
            <tr>
                <th><label for="upstash_redis_token">REST Token</label></th>
                <td>
                    <input type="password" id="upstash_redis_token" name="upstash_redis_token"
                           value="<?php echo esc_attr($settings['upstash_redis_token'] ?? ''); ?>"
                           class="regular-text">
                    <p class="description">The read-write token from your Upstash database page.</p>
                </td>
            </tr>
            <tr>
                <th>Test Connection</th>
                <td>
                    <button type="button" id="lem-test-upstash" class="button button-secondary">Test Upstash Connection</button>
                    <span id="lem-upstash-test-result" style="margin-left:12px;"></span>
                </td>
            </tr>
        </table>

        <h2>Email Delivery</h2>
        <table class="form-table">
            <tr>
                <th>Last send error</th>
                <td>
                    <?php
                    $last_mail_error = get_transient('lem_last_mail_error');
                    if ($last_mail_error): ?>
                        <div class="notice notice-error inline" style="margin:0;padding:6px 12px;">
                            <p style="margin:0;"><?php echo esc_html($last_mail_error); ?></p>
                        </div>
                        <p class="description" style="margin-top:6px;">
                            This is the most recent <code>wp_mail()</code> failure captured by the plugin.
                            Fix the error, then send a test email below to clear it.
                        </p>
                    <?php else: ?>
                        <span style="color:#2cb191;">&#10003; No recent errors</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Test email</th>
                <td>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <input type="email" id="lem-test-email-to" class="regular-text"
                               placeholder="<?php echo esc_attr(wp_get_current_user()->user_email); ?>"
                               value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
                        <button type="button" id="lem-test-email-btn" class="button button-secondary">Send test email</button>
                    </div>
                    <span id="lem-test-email-result" style="display:block;margin-top:8px;"></span>
                    <p class="description">
                        Sends a plain test message via <code>wp_mail()</code>.
                        If Easy WP SMTP (or another mailer) is active, this tests its configuration.
                        Make sure you have configured the SMTP credentials inside <strong>WP Admin → Easy WP SMTP → Settings</strong> — installing the plugin alone is not enough.
                    </p>
                </td>
            </tr>
        </table>

        <h2>JWT Settings</h2>
        <table class="form-table">
            <tr>
                <th><label for="jwt_expiration_hours">Token Lifetime (hours)</label></th>
                <td>
                    <input type="number" id="jwt_expiration_hours" name="jwt_expiration_hours"
                           value="<?php echo esc_attr($settings['jwt_expiration_hours'] ?? 24); ?>"
                           class="small-text" min="1" max="720">
                    <p class="description">How long a viewer's access token is valid after initial sign-in. Default: 24 hours.</p>
                </td>
            </tr>
            <tr>
                <th><label for="jwt_refresh_duration_minutes">Refresh Token Lifetime (minutes)</label></th>
                <td>
                    <input type="number" id="jwt_refresh_duration_minutes" name="jwt_refresh_duration_minutes"
                           value="<?php echo esc_attr($settings['jwt_refresh_duration_minutes'] ?? 15); ?>"
                           class="small-text" min="1" max="60">
                    <p class="description">Short-lived JWT issued on refresh. Default: 15 minutes.</p>
                </td>
            </tr>
        </table>

        <h2>Debug</h2>
        <table class="form-table">
            <tr>
                <th><label for="debug_mode">Debug Logging</label></th>
                <td>
                    <label>
                        <input type="checkbox" id="debug_mode" name="debug_mode" value="1"
                               <?php checked($settings['debug_mode'] ?? 0, 1); ?>>
                        Write detailed logs to <code>wp-content/debug.log</code>
                    </label>
                </td>
            </tr>
        </table>

        <h2>Endpoint Reference</h2>
        <table class="form-table">
            <tr>
                <th>JWT Status (REST)</th>
                <td><code><?php echo esc_url($jwt_status_url); ?></code> — POST</td>
            </tr>
            <tr>
                <th>Stripe Webhook</th>
                <td><code><?php echo esc_url($stripe_webhook_url); ?></code></td>
            </tr>
            <tr>
                <th>Mux Webhook</th>
                <td><code><?php echo esc_url($mux_webhook_url); ?></code></td>
            </tr>
            <tr>
                <th>System Diagnostics</th>
                <td>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=lem_event&page=live-event-manager-debug')); ?>" class="button button-secondary">
                        Open Debug page &rarr;
                    </a>
                    <p class="description">Redis connection test, system info, and troubleshooting tools.</p>
                </td>
            </tr>
        </table>

        <?php endif; ?>

        <?php /* ═══ DEVICE ═══════════════════════════════════════════════════ */ ?>
        <?php if ($active_tab === 'device'): ?>
        <h2>Device Identification</h2>
        <p>Controls how the plugin ties a viewer to their access session. <strong>Session-based</strong> is recommended for most sites.</p>
        <table class="form-table">
            <?php
            $methods = [
                'session_based'  => ['Session-based',  'One session cookie per browser. Simplest and most reliable.'],
                'ip_address'     => ['IP Address',     'Ties access to the viewer\'s IP. Breaks for mobile users switching networks.'],
                'fingerprint'    => ['Fingerprint',    'Browser fingerprint via JS. More persistent, but requires JS.'],
                'custom_token'   => ['Custom Token',   'A custom token you provide. Useful for integrations.'],
                'hybrid'         => ['Hybrid',         'Session primary, IP as fallback.'],
            ];
            $current_method = $device_settings['identification_method'] ?? 'session_based';
            foreach ($methods as $value => [$label, $desc]):
            ?>
            <tr>
                <th><?php echo esc_html($label); ?></th>
                <td>
                    <label>
                        <input type="radio" name="identification_method"
                               value="<?php echo esc_attr($value); ?>"
                               <?php checked($current_method, $value); ?>>
                        <?php echo esc_html($desc); ?>
                    </label>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>

        <p class="submit">
            <input type="submit" class="button button-primary" value="Save Settings">
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // HTML escape helper
    function escHtml(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }

    // Stripe sub-tabs
    $('.lem-tab-button').on('click', function() {
        var tab = $(this).data('tab');
        $('.lem-tab-button').removeClass('active');
        $(this).addClass('active');
        $('.lem-tab-content').removeClass('active');
        $('#' + tab).addClass('active');
    });

    // Auto-switch Stripe sub-tab when mode changes
    $('#stripe_mode').on('change', function() {
        var mode = $(this).val();
        $('.lem-tab-button[data-tab="stripe-' + mode + '"]').trigger('click');
    });

    // Test Upstash connection
    $('#lem-test-upstash').on('click', function() {
        var $btn    = $(this);
        var $result = $('#lem-upstash-test-result');
        $btn.prop('disabled', true).text('Testing…');
        $result.html('');

        $.post(lem_ajax.ajax_url, {
            action:               'lem_test_redis_connection',
            nonce:                lem_ajax.nonce,
            upstash_redis_url:   $('#upstash_redis_url').val(),
            upstash_redis_token: $('#upstash_redis_token').val()
        }, function(response) {
            if (response.success) {
                $result.html('<span style="color:#46b450;">&#10003; ' + escHtml(response.data.message) + '</span>');
            } else {
                $result.html('<span style="color:#dc3232;">&#10007; ' + escHtml(response.data || 'Connection failed') + '</span>');
            }
        }).fail(function() {
            $result.html('<span style="color:#dc3232;">&#10007; Network error</span>');
        }).always(function() {
            $btn.prop('disabled', false).text('Test Upstash Connection');
        });
    });

    // Test email
    $('#lem-test-email-btn').on('click', function() {
        var $btn    = $(this);
        var $result = $('#lem-test-email-result');
        var to      = $('#lem-test-email-to').val().trim();
        $btn.prop('disabled', true).text('Sending…');
        $result.html('');

        $.post(lem_ajax.ajax_url, {
            action: 'lem_test_email',
            nonce:  lem_ajax.nonce,
            to:     to
        }, function(response) {
            if (response.success) {
                $result.html('<span style="color:#46b450;">&#10003; ' + escHtml(response.data.message) + '</span>');
            } else {
                $result.html('<span style="color:#dc3232;">&#10007; ' + escHtml(response.data || 'Send failed') + '</span>');
            }
        }).fail(function() {
            $result.html('<span style="color:#dc3232;">&#10007; Network error</span>');
        }).always(function() {
            $btn.prop('disabled', false).text('Send test email');
        });
    });

    // Copy to clipboard helper
    $('.lem-copy-btn').on('click', function() {
        var target = $(this).data('target');
        var text   = $(target).first().text();
        navigator.clipboard.writeText(text).then(function() {
            // brief visual feedback handled by CSS
        });
    });
});
</script>

<style>
.lem-settings-wrap .nav-tab-wrapper { margin-bottom: 24px; }
.lem-tab-nav { margin: 12px 0 0; }
.lem-tab-button {
    padding: 8px 18px;
    margin-right: 4px;
    border: 1px solid #c3c4c7;
    background: #f0f0f1;
    cursor: pointer;
    border-radius: 3px 3px 0 0;
}
.lem-tab-button.active { background: #0073aa; color: #fff; border-color: #0073aa; }
.lem-tab-content { display: none; border-top: 1px solid #c3c4c7; padding-top: 8px; }
.lem-tab-content.active { display: block; }
.lem-copy-field { display: inline-block; padding: 6px 10px; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 3px; }
</style>
