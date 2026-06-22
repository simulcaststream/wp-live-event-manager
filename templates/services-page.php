<?php
/**
 * Services — Streaming · Payments · Chat
 *
 * Each service tab shows:
 *   - The registered providers as sub-tabs (with ✓/✗ configured status)
 *   - An "Activate" button to make a provider the active one for that service
 *   - A credentials form for the selected provider
 *   - An "+ Add Provider" info/upload tab
 *
 * URL params:
 *   service  – streaming | payments | chat          (default: streaming)
 *   provider – active provider slug                 (default: first registered)
 *   subtab   – credentials | [extra provider tabs]  (default: credentials)
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!current_user_can('manage_options')) {
    wp_die(__('Unauthorized', 'live-event-manager'));
}

$settings = get_option('lem_settings', array());

// ── Helpers ───────────────────────────────────────────────────────────────────

function lem_svc_url(string $service, string $provider = '', string $subtab = 'credentials'): string {
    $args = array('post_type' => 'lem_event', 'page' => 'live-event-manager-services', 'service' => $service);
    if ($provider !== '') {
        $args['provider'] = $provider;
    }
    if ($subtab !== 'credentials') {
        $args['subtab'] = $subtab;
    }
    return admin_url(add_query_arg($args, 'edit.php'));
}

/**
 * Map a row from get_settings_fields() to the lem_settings option key.
 * Providers may use either associative arrays (outer key = option name) or a numeric list with ['key' => 'option_name'].
 *
 * @param mixed $outer_key Index/key from foreach over get_settings_fields().
 * @param array $field     Field definition row.
 * @return string|null     Option key, or null if the row cannot be mapped.
 */
function lem_settings_field_option_key($outer_key, array $field): ?string {
    if (array_key_exists('key', $field) && $field['key'] !== '' && $field['key'] !== null) {
        return (string) $field['key'];
    }
    // Associative field maps use the outer array key; numeric lists must include ['key' => ...].
    if (is_int($outer_key)) {
        return null;
    }
    if ($outer_key === '' || $outer_key === null) {
        return null;
    }
    return (string) $outer_key;
}

// ── Active service & provider ──────────────────────────────────────────────────

$active_service = sanitize_key($_GET['service'] ?? 'streaming');
if (!in_array($active_service, array('streaming', 'payments', 'chat'), true)) {
    $active_service = 'streaming';
}

$active_subtab = sanitize_text_field($_GET['subtab'] ?? 'credentials');

// ── Build provider lists per service ──────────────────────────────────────────

$streaming_factory  = LEM_Streaming_Provider_Factory::get_instance();
$payment_factory    = LEM_Payment_Provider_Factory::get_instance();
$chat_factory       = LEM_Chat_Provider_Factory::get_instance();

$streaming_providers = $streaming_factory->get_available_providers();  // ['mux', 'ome', …]
$payment_providers   = $payment_factory->get_available_providers();    // ['stripe', …]
$chat_providers      = $chat_factory->get_available_providers();       // [] or ['ably', …]

// Active provider per service
$active_streaming_provider = $settings['streaming_provider'] ?? ($streaming_providers[0] ?? '');
$active_payment_provider   = $settings['payment_provider']   ?? ($payment_providers[0]  ?? '');
$active_chat_provider      = $settings['chat_provider']      ?? ($chat_providers[0]     ?? '');

// Selected provider tab (what the user is currently *viewing*, not necessarily active)
if ($active_service === 'streaming') {
    $pool            = $streaming_providers;
    $default_viewing = $active_streaming_provider ?: ($pool[0] ?? '_add');
} elseif ($active_service === 'payments') {
    $pool            = $payment_providers;
    $default_viewing = $active_payment_provider ?: ($pool[0] ?? '_add');
} else {
    $pool            = $chat_providers;
    $default_viewing = $chat_providers[0] ?? '_add';
}

$viewing_provider = sanitize_text_field($_GET['provider'] ?? $default_viewing);
if ($viewing_provider !== '_add' && !in_array($viewing_provider, $pool, true)) {
    $viewing_provider = $pool[0] ?? '_add';
}

// ── Save: activate provider ────────────────────────────────────────────────────

if (
    isset($_POST['lem_activate_nonce'], $_POST['lem_activate_service'], $_POST['lem_activate_provider']) &&
    wp_verify_nonce($_POST['lem_activate_nonce'], 'lem_activate_provider')
) {
    $svc = sanitize_key($_POST['lem_activate_service']);
    $pid = sanitize_key($_POST['lem_activate_provider']);

    if ($svc === 'streaming' && in_array($pid, $streaming_providers, true)) {
        $settings['streaming_provider'] = $pid;
        $active_streaming_provider      = $pid;
    } elseif ($svc === 'payments' && in_array($pid, $payment_providers, true)) {
        $settings['payment_provider'] = $pid;
        $active_payment_provider      = $pid;
    } elseif ($svc === 'chat' && in_array($pid, $chat_providers, true)) {
        $settings['chat_provider'] = $pid;
        $active_chat_provider      = $pid;
    }

    update_option('lem_settings', $settings);
    echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html(ucfirst($pid)) . ' set as the active ' . esc_html($svc) . ' provider.</strong></p></div>';
}

// ── Save: provider credentials ────────────────────────────────────────────────

if (
    isset($_POST['lem_vendor_nonce'], $_POST['lem_vendor_service'], $_POST['lem_vendor_provider']) &&
    wp_verify_nonce($_POST['lem_vendor_nonce'], 'lem_save_vendor_' . sanitize_key($_POST['lem_vendor_provider']))
) {
    $svc = sanitize_key($_POST['lem_vendor_service']);
    $pid = sanitize_key($_POST['lem_vendor_provider']);

    // Resolve provider object
    if ($svc === 'streaming') {
        $save_provider = $streaming_factory->get_provider($pid);
    } elseif ($svc === 'payments') {
        $save_provider = $payment_factory->get_provider($pid);
    } elseif ($svc === 'chat') {
        $save_provider = $chat_factory->get_provider($pid);
    } else {
        $save_provider = null;
    }

    if ($save_provider) {
        $fields = $save_provider->get_settings_fields();
        foreach ($fields as $outer_key => $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = lem_settings_field_option_key($outer_key, $field);
            if ($key === null) {
                continue;
            }
            $type = $field['type'] ?? 'text';
            $raw  = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
            if (!is_scalar($raw)) {
                continue;
            }
            $raw = (string) $raw;
            // Leave blank password inputs unchanged so secrets are not wiped on each save.
            if ($type === 'password' && $raw === '' && isset($settings[$key]) && $settings[$key] !== '') {
                continue;
            }
            $settings[$key] = ($type === 'textarea')
                ? sanitize_textarea_field($raw)
                : sanitize_text_field($raw);
        }
        update_option('lem_settings', $settings);
        $settings = get_option('lem_settings', array());

        $validation = $save_provider->validate_settings($settings);
        if ($validation === true) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Credentials saved.</strong></p></div>';
        } elseif (is_wp_error($validation)) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html($validation->get_error_message()) . '</strong></p></div>';
        } elseif (is_array($validation)) {
            // OME/Mux return a list of error strings; Stripe/PayPal return an associative sanitized map.
            $is_indexed_error_list = $validation !== array()
                && array_keys($validation) === range(0, count($validation) - 1);
            if ($is_indexed_error_list) {
                $issues = implode('</li><li>', array_map('esc_html', $validation));
                echo '<div class="notice notice-warning is-dismissible"><p><strong>Saved with warnings:</strong></p><ul><li>' . $issues . '</li></ul></div>';
            } else {
                $settings = array_merge($settings, $validation);
                update_option('lem_settings', $settings);
                echo '<div class="notice notice-success is-dismissible"><p><strong>Credentials saved.</strong></p></div>';
            }
        } else {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Credentials saved.</strong></p></div>';
        }
    }
}

// ── Shared: render a credentials form for any provider ───────────────────────

/**
 * @param object $provider  An LEM_*_Provider_Interface instance.
 * @param string $service   'streaming' | 'payments' | 'chat'
 * @param string $active_pid  The currently active provider slug for this service.
 */
function lem_render_provider_panel($provider, string $service, string $active_pid): void {
    $pid      = $provider->get_id();
    $is_active = ($pid === $active_pid);
    $nonce_action = 'lem_save_vendor_' . $pid;
    $fields   = $provider->get_settings_fields();
    $settings = get_option('lem_settings', array());
    ?>

    <div class="lem-provider-panel">

        <!-- Active status + Activate button -->
        <div class="lem-provider-header">
            <div class="lem-provider-header-left">
                <h2 style="margin:0;"><?php echo esc_html($provider->get_name()); ?></h2>
                <?php if ($provider->is_configured()): ?>
                    <span class="lem-badge lem-badge-ok">&#10003; Configured</span>
                <?php else: ?>
                    <span class="lem-badge lem-badge-err">&#10007; Not configured</span>
                <?php endif; ?>
                <?php if ($is_active): ?>
                    <span class="lem-badge lem-badge-active">&#9654; Active</span>
                <?php endif; ?>
            </div>
            <?php if (!$is_active && $provider->is_configured()): ?>
            <form method="post" action="">
                <?php wp_nonce_field('lem_activate_provider', 'lem_activate_nonce'); ?>
                <input type="hidden" name="lem_activate_service"  value="<?php echo esc_attr($service); ?>">
                <input type="hidden" name="lem_activate_provider" value="<?php echo esc_attr($pid); ?>">
                <input type="hidden" name="service"   value="<?php echo esc_attr($service); ?>">
                <input type="hidden" name="provider"  value="<?php echo esc_attr($pid); ?>">
                <button type="submit" class="button button-primary">&#9654; Activate</button>
            </form>
            <?php elseif (!$is_active): ?>
            <p class="description" style="margin:0;">Configure credentials below, then activate.</p>
            <?php endif; ?>
        </div>

        <!-- Credentials form -->
        <?php if (!empty($fields)): ?>
        <form method="post" action="<?php echo esc_url(lem_svc_url($service, $pid)); ?>" style="margin-top:20px;">
            <?php wp_nonce_field($nonce_action, 'lem_vendor_nonce'); ?>
            <input type="hidden" name="lem_vendor_service"  value="<?php echo esc_attr($service); ?>">
            <input type="hidden" name="lem_vendor_provider" value="<?php echo esc_attr($pid); ?>">

            <?php
            // Group fields by 'section' if provided, preserving the setting key.
            $sections   = array();
            $no_section = array();
            foreach ($fields as $field_key => $field) {
                $section = $field['section'] ?? '';
                if ($section !== '') {
                    $sections[$section][$field_key] = $field;
                } else {
                    $no_section[$field_key] = $field;
                }
            }
            $all_groups = array_merge(
                $no_section ? array('' => $no_section) : array(),
                $sections
            );
            ?>

            <?php foreach ($all_groups as $section_label => $group_fields): ?>
            <?php if ($section_label !== ''): ?>
            <h3><?php echo esc_html($section_label); ?></h3>
            <?php endif; ?>

            <table class="form-table">
                <?php foreach ($group_fields as $key => $field):
                    $input_key   = lem_settings_field_option_key($key, $field);
                    if ($input_key === null) {
                        continue;
                    }
                    $label       = $field['label']        ?? $input_key;
                    $type        = $field['type']         ?? 'text';
                    $description = $field['description']  ?? '';
                    $placeholder = $field['placeholder']  ?? '';
                    $required    = !empty($field['required']);
                    $current_val = $settings[$input_key] ?? '';
                    $input_id    = 'lem_field_' . esc_attr($input_key);
                ?>
                <tr>
                    <th scope="row">
                        <label for="<?php echo $input_id; ?>">
                            <?php echo esc_html($label); ?>
                            <?php if ($required): ?><span style="color:#d63638;">*</span><?php endif; ?>
                        </label>
                    </th>
                    <td>
                        <?php if ($type === 'textarea'): ?>
                        <textarea id="<?php echo $input_id; ?>" name="<?php echo esc_attr($input_key); ?>"
                                  class="regular-text" rows="4"
                                  placeholder="<?php echo esc_attr($placeholder); ?>"><?php echo esc_textarea($current_val); ?></textarea>
                        <?php elseif ($type === 'select' && !empty($field['options'])): ?>
                        <select id="<?php echo $input_id; ?>" name="<?php echo esc_attr($input_key); ?>">
                            <?php foreach ($field['options'] as $opt_val => $opt_label): ?>
                            <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($current_val, $opt_val); ?>>
                                <?php echo esc_html($opt_label); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="<?php echo in_array($type, array('password', 'url', 'email'), true) ? esc_attr($type) : 'text'; ?>"
                               id="<?php echo $input_id; ?>"
                               name="<?php echo esc_attr($input_key); ?>"
                               <?php if ($type !== 'password'): ?>value="<?php echo esc_attr($current_val); ?>"<?php endif; ?>
                               class="regular-text"
                               placeholder="<?php echo esc_attr($placeholder); ?>"
                               <?php echo ($type === 'password' && $current_val !== '') ? 'autocomplete="new-password" ' : ''; ?>>
                        <?php endif; ?>

                        <?php if ($description !== ''): ?>
                        <p class="description"><?php echo wp_kses_post($description); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>

            <?php endforeach; ?>

            <p>
                <button type="submit" class="button button-primary">Save credentials</button>
            </p>
        </form>
        <?php else: ?>
        <p class="description" style="margin-top:16px;">This provider has no configurable credentials.</p>
        <?php endif; ?>

        <!-- Provider-defined extra sub-tabs (streaming only, via get_extra_tabs()) -->
        <?php if (method_exists($provider, 'get_extra_tabs')): ?>
            <?php foreach ($provider->get_extra_tabs() as $tab_slug => $tab): ?>
            <hr class="lem-divider">
            <h3><?php echo esc_html($tab['label'] ?? $tab_slug); ?></h3>
            <?php if (!empty($tab['template']) && file_exists($tab['template'])): ?>
                <?php include $tab['template']; ?>
            <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>

    </div><!-- .lem-provider-panel -->
    <?php
}

// ── "Add Provider" panels per service ─────────────────────────────────────────

function lem_render_add_provider_panel(string $service): void {
    $interface_map = array(
        'streaming' => 'LEM_Streaming_Provider_Interface',
        'payments'  => 'LEM_Payment_Provider_Interface',
        'chat'      => 'LEM_Chat_Provider_Interface',
    );
    $factory_map = array(
        'streaming' => 'LEM_Streaming_Provider_Factory',
        'payments'  => 'LEM_Payment_Provider_Factory',
        'chat'      => 'LEM_Chat_Provider_Factory',
    );
    $dir_map = array(
        'streaming' => 'services/streaming/providers/',
        'payments'  => 'services/payments/providers/',
        'chat'      => 'services/chat/providers/',
    );
    $interface  = $interface_map[$service]  ?? '';
    $factory    = $factory_map[$service]    ?? '';
    $dir        = $dir_map[$service]        ?? '';
    $register_method = ($service === 'streaming') ? '__construct' : 'register_provider';
    ?>
    <div class="lem-add-provider-panel lem-vendor-panel">
        <h2>Add a <?php echo esc_html(ucfirst($service)); ?> Provider</h2>
        <p class="description">
            Any class implementing <code><?php echo esc_html($interface); ?></code> and registered with
            <code><?php echo esc_html($factory); ?>::get_instance()->register_provider()</code>
            appears here automatically.
        </p>

        <div class="lem-add-steps">
            <div class="lem-add-step">
                <span class="lem-step-num">1</span>
                <div>
                    <strong>Create the class file</strong>
                    <p>Implement <code><?php echo esc_html($interface); ?></code> and save it as:<br>
                    <code><?php echo esc_html($dir); ?>class-<em>yourprovider</em>-provider.php</code></p>
                </div>
            </div>
            <div class="lem-add-step">
                <span class="lem-step-num">2</span>
                <div>
                    <strong>Register on <code>plugins_loaded</code></strong>
                    <p>In your own plugin (or a <code>mu-plugin</code>):</p>
                    <pre><?php echo esc_html($factory); ?>::get_instance()->register_provider('yourprovider', 'LEM_YourProvider_Provider');</pre>
                </div>
            </div>
            <div class="lem-add-step">
                <span class="lem-step-num">3</span>
                <div>
                    <strong>Activate it</strong>
                    <p>Your provider tab will appear here. Fill in credentials, then click <strong>Activate</strong>.</p>
                </div>
            </div>
        </div>

        <hr class="lem-divider">

        <h3>Interface reference — <code><?php echo esc_html($interface); ?></code></h3>
        <?php if ($service === 'streaming'): ?>
        <div class="lem-interface-grid">
            <div>
                <h4>Required methods</h4>
                <ul>
                    <li><code>get_id()</code> · <code>get_name()</code> · <code>is_configured()</code></li>
                    <li><code>get_credentials()</code> · <code>get_settings_fields()</code> · <code>validate_settings()</code></li>
                    <li><code>generate_playback_token($email, $event_id, $payment_id, $is_refresh)</code></li>
                    <li><code>supports_token_refresh()</code> · <code>get_extra_tabs()</code></li>
                </ul>
            </div>
            <div>
                <h4>Stream management</h4>
                <ul>
                    <li><code>create_stream($params)</code> · <code>update_stream($id, $params)</code> · <code>delete_stream($id)</code></li>
                    <li><code>get_stream_details($id)</code> · <code>list_streams()</code> · <code>get_stream_status($id)</code></li>
                    <li><code>get_rtmp_info($id)</code> · <code>get_webrtc_publish_url($id)</code> · <code>get_playback_url($id)</code></li>
                    <li><code>get_player_component($id, $token, $opts)</code> · <code>handle_webhook($payload, $sig)</code></li>
                    <li><code>create_simulcast_target($stream_id, $url)</code> · <code>list_simulcast_targets($id)</code> · <code>delete_simulcast_target($stream_id, $target_id)</code></li>
                </ul>
            </div>
        </div>
        <?php elseif ($service === 'payments'): ?>
        <ul>
            <li><code>get_id()</code> · <code>get_name()</code> · <code>is_configured()</code></li>
            <li><code>create_checkout_session(array $args)</code> — returns <code>['checkout_url', 'session_id']</code> or <code>WP_Error</code></li>
            <li><code>verify_webhook()</code> — reads <code>php://input</code>; returns normalised event or <code>WP_Error</code></li>
            <li><code>finalize_checkout($reference_id, $context)</code> — return-path finalize (e.g. capture); or <code>WP_Error( 'not_applicable' )</code></li>
            <li><code>get_payment_status($reference_id)</code> — API poll; returns <code>paid</code>, <code>email</code>, <code>event_id</code>, <code>payment_id</code> (required when paid)</li>
            <li><code>get_settings_fields()</code> · <code>validate_settings(array $settings)</code></li>
        </ul>
        <p class="description">Hook: <code>lem_stripe_session_args</code> — filter checkout args before session creation.</p>
        <p class="description">Actions fired: <code>lem_webhook_event_received</code> · <code>lem_webhook_payment_received</code></p>
        <?php elseif ($service === 'chat'): ?>
        <ul>
            <li><code>get_id()</code> · <code>get_name()</code> · <code>is_configured()</code></li>
            <li><code>issue_viewer_token($event_id, $email, $display_name, $caps)</code></li>
            <li><code>issue_host_token($event_id, $user_id)</code></li>
            <li><code>revoke_viewer($event_id, $email)</code></li>
            <li><code>get_channel_name($event_id)</code></li>
            <li><code>get_settings_fields()</code> · <code>validate_settings(array $settings)</code></li>
        </ul>
        <?php endif; ?>
    </div>
    <?php
}

?>

<div class="wrap lem-services-wrap">
    <h1>Services</h1>
    <?php require __DIR__ . '/admin-subnav.php'; ?>
    <p class="description" style="margin-bottom:16px;">
        Select a service to configure its provider. Only the <strong>active</strong> provider is used at runtime;
        all others are ignored.
    </p>

    <?php /* ── Service tabs ──────────────────────────────────────────────────── */ ?>
    <nav class="nav-tab-wrapper lem-service-top-tabs" style="margin-bottom:0;">
        <?php
        $service_labels = array(
            'streaming' => '&#9654; Streaming',
            'payments'  => '&#128179; Payments',
            'chat'      => '&#128172; Chat',
        );
        foreach ($service_labels as $svc => $label): ?>
        <a href="<?php echo esc_url(lem_svc_url($svc)); ?>"
           class="nav-tab <?php echo $active_service === $svc ? 'nav-tab-active' : ''; ?>"
           style="font-size:14px;">
            <?php echo $label; // safe — only static HTML entities ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="lem-service-panel" style="border-top:0; background:#fff; border:1px solid #c3c4c7; border-top:none; padding:16px 20px 0;">

    <?php /* ═════════════════ STREAMING ═════════════════════════════════════ */ ?>
    <?php if ($active_service === 'streaming'): ?>

        <?php
        $active_pid = $active_streaming_provider;

        // Active provider status banner
        if ($active_pid && $p = $streaming_factory->get_provider($active_pid)):
        ?>
        <div class="notice notice-info inline" style="margin:12px 0;">
            <p>Active streaming provider: <strong><?php echo esc_html($p->get_name()); ?></strong>
            <?php if ($p->is_configured()): ?>
                &nbsp;<span style="color:#46b450;">&#10003; Configured</span>
            <?php else: ?>
                &nbsp;<span style="color:#d63638;">&#10007; Credentials missing — fill in the form below.</span>
            <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

        <nav class="nav-tab-wrapper" style="margin:0 -20px; padding:0 20px; border-bottom:1px solid #c3c4c7;">
            <?php foreach ($streaming_providers as $pid):
                $p          = $streaming_factory->get_provider($pid);
                $pname      = $p ? $p->get_name() : ucfirst($pid);
                $configured = $p ? $p->is_configured() : false;
                $is_viewing = ($viewing_provider === $pid);
                $is_cur_active = ($pid === $active_pid);
            ?>
            <a href="<?php echo esc_url(lem_svc_url('streaming', $pid)); ?>"
               class="nav-tab <?php echo $is_viewing ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($pname); ?>
                <?php if ($is_cur_active): ?><span style="color:#46b450; font-weight:600;" title="Active">&#9654;</span><?php endif; ?>
                <?php if ($configured): ?>
                    <span class="lem-status-dot lem-ok" title="Configured">&#10003;</span>
                <?php else: ?>
                    <span class="lem-status-dot lem-err" title="Not configured">&#10007;</span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
            <a href="<?php echo esc_url(lem_svc_url('streaming', '_add')); ?>"
               class="nav-tab <?php echo $viewing_provider === '_add' ? 'nav-tab-active' : ''; ?>">
                + Add Provider
            </a>
        </nav>

        <div style="padding:20px 0;">
        <?php if ($viewing_provider === '_add'): ?>
            <?php lem_render_add_provider_panel('streaming'); ?>
        <?php else:
            $p = $streaming_factory->get_provider($viewing_provider);
            if ($p): lem_render_provider_panel($p, 'streaming', $active_pid); endif;
        endif; ?>
        </div>

    <?php /* ═════════════════ PAYMENTS ══════════════════════════════════════ */ ?>
    <?php elseif ($active_service === 'payments'): ?>

        <?php
        $active_pid = $active_payment_provider;

        if ($active_pid && $p = $payment_factory->get_provider($active_pid)):
        ?>
        <div class="notice notice-info inline" style="margin:12px 0;">
            <p>Active payment provider: <strong><?php echo esc_html($p->get_name()); ?></strong>
            <?php if ($p->is_configured()): ?>
                &nbsp;<span style="color:#46b450;">&#10003; Configured</span>
            <?php else: ?>
                &nbsp;<span style="color:#d63638;">&#10007; Credentials missing — fill in the form below.</span>
            <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

        <?php if (empty($payment_providers)): ?>
        <div class="notice notice-warning inline" style="margin:12px 0;">
            <p>No payment providers are registered. See the <strong>+ Add Provider</strong> tab.</p>
        </div>
        <?php endif; ?>

        <nav class="nav-tab-wrapper" style="margin:0 -20px; padding:0 20px; border-bottom:1px solid #c3c4c7;">
            <?php foreach ($payment_providers as $pid):
                $p          = $payment_factory->get_provider($pid);
                $pname      = $p ? $p->get_name() : ucfirst($pid);
                $configured = $p ? $p->is_configured() : false;
                $is_viewing = ($viewing_provider === $pid);
                $is_cur_active = ($pid === $active_pid);
            ?>
            <a href="<?php echo esc_url(lem_svc_url('payments', $pid)); ?>"
               class="nav-tab <?php echo $is_viewing ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($pname); ?>
                <?php if ($is_cur_active): ?><span style="color:#46b450; font-weight:600;" title="Active">&#9654;</span><?php endif; ?>
                <?php echo $configured ? '<span class="lem-status-dot lem-ok" title="Configured">&#10003;</span>' : '<span class="lem-status-dot lem-err" title="Not configured">&#10007;</span>'; ?>
            </a>
            <?php endforeach; ?>
            <a href="<?php echo esc_url(lem_svc_url('payments', '_add')); ?>"
               class="nav-tab <?php echo $viewing_provider === '_add' ? 'nav-tab-active' : ''; ?>">
                + Add Provider
            </a>
        </nav>

        <div style="padding:20px 0;">
        <?php if ($viewing_provider === '_add'): ?>
            <?php lem_render_add_provider_panel('payments'); ?>
        <?php else:
            $p = $payment_factory->get_provider($viewing_provider);
            if ($p): lem_render_provider_panel($p, 'payments', $active_pid); endif;
        endif; ?>
        </div>

        <?php /* Webhook URL reminder */ ?>
        <?php if ($viewing_provider !== '_add'):
            $webhook_url = admin_url('admin-ajax.php?action=lem_payment_webhook');
            $webhook_event_hint = array(
                'stripe'  => 'Listen for <code>checkout.session.completed</code>.',
                'paypal'  => 'Listen for <code>PAYMENT.CAPTURE.COMPLETED</code>. Copy the Webhook ID into the field above.',
            );
            $hint = $webhook_event_hint[$viewing_provider] ?? 'Configure this URL in your provider\'s webhook settings.';
        ?>
        <hr class="lem-divider" style="margin:4px 0 16px;">
        <table class="form-table" style="margin-top:0;">
            <tr>
                <th>Webhook URL</th>
                <td>
                    <code><?php echo esc_url($webhook_url); ?></code>
                    <p class="description">Register this URL in your payment provider's dashboard. <?php echo $hint; ?></p>
                </td>
            </tr>
        </table>
        <?php endif; ?>

    <?php /* ═════════════════ CHAT ════════════════════════════════════════ */ ?>
    <?php elseif ($active_service === 'chat'): ?>

        <?php
        $active_pid = $active_chat_provider;

        if ($active_pid && !empty($chat_providers) && $p = $chat_factory->get_provider($active_pid)):
        ?>
        <div class="notice notice-info inline" style="margin:12px 0;">
            <p>Active chat provider: <strong><?php echo esc_html($p->get_name()); ?></strong>
            <?php if ($p->is_configured()): ?>
                &nbsp;<span style="color:#46b450;">&#10003; Configured</span>
            <?php else: ?>
                &nbsp;<span style="color:#d63638;">&#10007; Credentials missing — fill in the form below.</span>
            <?php endif; ?>
            </p>
        </div>
        <?php elseif (empty($chat_providers)): ?>
        <div class="notice notice-warning inline" style="margin:12px 0;">
            <p><strong>No chat providers installed.</strong> Chat is optional — see the <strong>+ Add Provider</strong> tab to learn how to connect one.</p>
        </div>
        <?php endif; ?>

        <nav class="nav-tab-wrapper" style="margin:0 -20px; padding:0 20px; border-bottom:1px solid #c3c4c7;">
            <?php foreach ($chat_providers as $pid):
                $p          = $chat_factory->get_provider($pid);
                $pname      = $p ? $p->get_name() : ucfirst($pid);
                $configured = $p ? $p->is_configured() : false;
                $is_viewing = ($viewing_provider === $pid);
                $is_cur_active = ($pid === $active_pid);
            ?>
            <a href="<?php echo esc_url(lem_svc_url('chat', $pid)); ?>"
               class="nav-tab <?php echo $is_viewing ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($pname); ?>
                <?php if ($is_cur_active): ?><span style="color:#46b450; font-weight:600;" title="Active">&#9654;</span><?php endif; ?>
                <?php echo $configured ? '<span class="lem-status-dot lem-ok" title="Configured">&#10003;</span>' : '<span class="lem-status-dot lem-err" title="Not configured">&#10007;</span>'; ?>
            </a>
            <?php endforeach; ?>
            <a href="<?php echo esc_url(lem_svc_url('chat', '_add')); ?>"
               class="nav-tab <?php echo $viewing_provider === '_add' ? 'nav-tab-active' : ''; ?>">
                <?php echo empty($chat_providers) ? 'Connect a Provider' : '+ Add Provider'; ?>
            </a>
        </nav>

        <div style="padding:20px 0;">
        <?php if ($viewing_provider === '_add' || empty($chat_providers)): ?>
            <?php lem_render_add_provider_panel('chat'); ?>
        <?php else:
            $p = $chat_factory->get_provider($viewing_provider);
            if ($p): lem_render_provider_panel($p, 'chat', $active_pid); endif;
        endif; ?>
        </div>

    <?php endif; ?>

    <?php
    // Adaptor- and gating-plugin sections registered via LEM_Settings_Registry,
    // grouped by tab matching the active service.
    if (class_exists('LEM_Settings_Registry')) {
        LEM_Settings_Registry::render_tab($active_service);
    }
    ?>
    </div><!-- .lem-service-panel -->

</div><!-- .wrap -->

<style>
.lem-services-wrap .lem-service-top-tabs .nav-tab {
    padding: 8px 18px;
}
.lem-services-wrap .lem-service-panel {
    background: #fff;
}
.lem-provider-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    padding: 16px 0 12px;
    border-bottom: 1px solid #f0f0f0;
    margin-bottom: 8px;
}
.lem-provider-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.lem-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}
.lem-badge-ok     { background: #edfaed; color: #1d8a1d; border: 1px solid #b3dfb3; }
.lem-badge-err    { background: #fdf0f0; color: #d63638; border: 1px solid #f5c0c0; }
.lem-badge-active { background: #e8f4fd; color: #0073aa; border: 1px solid #a0d0f0; }
.lem-status-dot   { font-size: 11px; margin-left: 4px; }
.lem-status-dot.lem-ok  { color: #46b450; }
.lem-status-dot.lem-err { color: #d63638; }
.lem-add-provider-panel { padding-top: 8px; }
.lem-add-steps    { display: flex; flex-direction: column; gap: 16px; margin: 20px 0; }
.lem-add-step     { display: flex; align-items: flex-start; gap: 14px; }
.lem-step-num     { flex-shrink: 0; width: 30px; height: 30px; border-radius: 50%; background: #0073aa; color: #fff;
                    display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
.lem-add-step pre { background: #f6f7f7; padding: 10px 14px; border-radius: 4px; overflow-x: auto; font-size: 12px; margin: 6px 0 0; }
.lem-interface-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
@media (max-width: 782px) { .lem-interface-grid { grid-template-columns: 1fr; } }
.lem-divider { border: none; border-top: 1px solid #eee; margin: 20px 0; }
.lem-provider-panel form .form-table th { width: 220px; }
</style>
