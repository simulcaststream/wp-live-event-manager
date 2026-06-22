<?php
if (!defined('ABSPATH')) exit;

$settings        = get_option('lem_settings', array());

// ── Active providers ─────────────────────────────────────────────────────────
$stream_factory  = LEM_Streaming_Provider_Factory::get_instance();
$active_spid     = $settings['streaming_provider'] ?? 'mux';
$active_sprov    = $stream_factory->get_provider($active_spid);

$pay_factory     = LEM_Payment_Provider_Factory::get_instance();
$active_ppid     = $settings['payment_provider'] ?? 'stripe';
$active_pprov    = $pay_factory->get_provider($active_ppid);

// ── URLs ─────────────────────────────────────────────────────────────────────
$payment_webhook_url = admin_url('admin-ajax.php?action=lem_payment_webhook');
$paypal_capture_url  = admin_url('admin-ajax.php?action=lem_paypal_capture');
$settings_cache_url  = admin_url('edit.php?post_type=lem_event&page=live-event-manager-settings&tab=cache');
$services_pay_url    = admin_url('edit.php?post_type=lem_event&page=live-event-manager-services&service=payments');
?>
<div class="wrap">
    <h1>System Diagnostics</h1>
    <?php require __DIR__ . '/admin-subnav.php'; ?>

    <div class="lem-admin-container">

        <!-- ── System Information ─────────────────────────────────────────── -->
        <div class="lem-section">
            <h2>System Information</h2>
            <div class="lem-card">
                <table class="form-table">
                    <tr>
                        <th>WordPress Version</th>
                        <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                    </tr>
                    <tr>
                        <th>PHP Version</th>
                        <td><?php echo esc_html(phpversion()); ?></td>
                    </tr>
                    <tr>
                        <th>Plugin Version</th>
                        <td><?php echo esc_html(LEM_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th>Debug Mode</th>
                        <td><?php echo WP_DEBUG ? '<span style="color:#46b450;">Enabled</span>' : 'Disabled'; ?></td>
                    </tr>
                    <tr>
                        <th>Database Prefix</th>
                        <td><?php global $wpdb; echo esc_html($wpdb->prefix); ?></td>
                    </tr>
                    <tr>
                        <th>JWT Table</th>
                        <td><?php global $wpdb; echo esc_html($wpdb->prefix . 'lem_jwt_tokens'); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- ── Provider Status ────────────────────────────────────────────── -->
        <div class="lem-section">
            <h2>Provider Status</h2>
            <div class="lem-card">
                <table class="form-table">
                    <tr>
                        <th>Streaming Provider</th>
                        <td>
                            <strong><?php echo esc_html($active_sprov ? $active_sprov->get_name() : $active_spid); ?></strong>
                            &nbsp;
                            <?php if ($active_sprov && $active_sprov->is_configured()): ?>
                                <span style="color:#46b450;">&#10003; Configured</span>
                            <?php else: ?>
                                <span style="color:#d63638;">&#10007; Not configured</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Payment Provider</th>
                        <td>
                            <strong><?php echo esc_html($active_pprov ? $active_pprov->get_name() : $active_ppid); ?></strong>
                            &nbsp;
                            <?php if ($active_pprov && $active_pprov->is_configured()): ?>
                                <span style="color:#46b450;">&#10003; Configured</span>
                            <?php else: ?>
                                <span style="color:#d63638;">&#10007; Not configured
                                    — <a href="<?php echo esc_url($services_pay_url); ?>">Add credentials</a>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Upstash Cache</th>
                        <td>
                            <?php if (class_exists('LEM_Cache') && LEM_Cache::is_configured()): ?>
                                <span style="color:#46b450;">&#10003; Configured</span>
                            <?php else: ?>
                                <span style="color:#d63638;">&#10007; Not configured
                                    — <a href="<?php echo esc_url($settings_cache_url); ?>">Add credentials</a>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- ── Webhook URLs ───────────────────────────────────────────────── -->
        <div class="lem-section">
            <h2>Webhook URLs</h2>
            <div class="lem-card">
                <table class="form-table">
                    <tr>
                        <th>Payment Webhook</th>
                        <td>
                            <code><?php echo esc_url($payment_webhook_url); ?></code>
                            <p class="description">
                                Register this single URL in every payment provider's dashboard.
                                The plugin routes to the active provider automatically.<br>
                                Stripe: subscribe to <code>checkout.session.completed</code> &nbsp;|&nbsp;
                                PayPal: subscribe to <code>PAYMENT.CAPTURE.COMPLETED</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>PayPal Capture URL</th>
                        <td>
                            <code><?php echo esc_url($paypal_capture_url); ?></code>
                            <p class="description">Set automatically by the plugin when creating a PayPal order. Not needed in PayPal's dashboard.</p>
                        </td>
                    </tr>
                </table>

                <div class="lem-code-block" style="margin-top:16px;">
                    <h5>Testing payment webhooks locally (Stripe CLI):</h5>
                    <code>stripe listen --forward-to "<?php echo esc_attr($payment_webhook_url); ?>" --skip-verify</code>
                    <p><em>The <code>--skip-verify</code> flag is required for local SSL certificates.</em></p>
                </div>
            </div>
        </div>

        <!-- ── Webhook Activity Log ───────────────────────────────────────── -->
        <div class="lem-section">
            <h2>Recent Webhook Activity</h2>
            <div class="lem-card">
                <p style="margin-top:0;">Live record of every inbound payment webhook. Use this to verify Stripe / PayPal are reaching the endpoint, and to see why a webhook didn't issue a JWT.</p>

                <div style="display:flex;gap:8px;margin-bottom:12px;align-items:center;">
                    <button id="lem-refresh-webhook-log" class="button">Refresh</button>
                    <label style="margin-left:8px;font-size:13px;"><input type="checkbox" id="lem-webhook-log-auto" checked> Auto-refresh (5s)</label>
                    <button id="lem-clear-webhook-log" class="button" style="margin-left:auto;color:#a00;">Clear log</button>
                </div>

                <div id="lem-webhook-log-wrap">
                    <p id="lem-webhook-log-empty">Loading…</p>
                    <table id="lem-webhook-log-table" class="wp-list-table widefat fixed striped" style="display:none;">
                        <thead>
                            <tr>
                                <th style="width:140px;">Time</th>
                                <th style="width:80px;">Provider</th>
                                <th style="width:120px;">Status</th>
                                <th style="width:160px;">Event Type</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody id="lem-webhook-log-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Upstash Cache Test ─────────────────────────────────────────── -->
        <div class="lem-section">
            <h2>Upstash Cache Test</h2>
            <div class="lem-card">
                <p>
                    This plugin uses <strong>Upstash Redis</strong> over HTTPS — no local Redis server or PHP extension required.
                    Credentials are set on the <a href="<?php echo esc_url($settings_cache_url); ?>">Settings → Cache &amp; Access</a> tab.
                </p>
                <button id="lem-test-redis" class="button button-primary">Test Upstash Connection</button>
                <div id="lem-redis-result" class="lem-result"></div>

                <div class="lem-instructions" style="margin-top:24px;">
                    <h3>Upstash Troubleshooting</h3>

                    <div class="lem-collapsible-section">
                        <div class="lem-collapsible-header" data-target="upstash-setup">
                            <h4>Getting Upstash credentials</h4>
                            <span class="lem-toggle-icon">&#9654;</span>
                        </div>
                        <div class="lem-collapsible-content collapsed" id="upstash-setup">
                            <div class="lem-code-block">
                                <ol>
                                    <li>Create a free account at <a href="https://upstash.com" target="_blank">upstash.com</a></li>
                                    <li>Click <strong>Create Database</strong> → choose a region close to your server</li>
                                    <li>Open the database → go to the <strong>REST API</strong> tab</li>
                                    <li>Copy the <strong>UPSTASH_REDIS_REST_URL</strong> and <strong>UPSTASH_REDIS_REST_TOKEN</strong></li>
                                    <li>Paste both into <a href="<?php echo esc_url($settings_cache_url); ?>">Settings → Cache &amp; Access</a></li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <div class="lem-collapsible-section">
                        <div class="lem-collapsible-header" data-target="upstash-errors">
                            <h4>Common errors</h4>
                            <span class="lem-toggle-icon">&#9654;</span>
                        </div>
                        <div class="lem-collapsible-content collapsed" id="upstash-errors">
                            <div class="lem-troubleshooting">
                                <div class="lem-issue">
                                    <strong>REST URL and token are required</strong>
                                    <p>No credentials saved yet. Go to <a href="<?php echo esc_url($settings_cache_url); ?>">Settings → Cache &amp; Access</a> and enter them.</p>
                                </div>
                                <div class="lem-issue">
                                    <strong>Upstash SET failed / 401 Unauthorized</strong>
                                    <p>The token is wrong or has been revoked. Regenerate it in the Upstash dashboard under REST API.</p>
                                </div>
                                <div class="lem-issue">
                                    <strong>GET returned unexpected value</strong>
                                    <p>Data roundtrip mismatch — usually a transient network issue. Try the test again. If it persists, check that the database region is accessible from your server.</p>
                                </div>
                                <div class="lem-issue">
                                    <strong>cURL error / timeout</strong>
                                    <p>Your server cannot reach Upstash. Check outbound HTTPS (port 443) is allowed in your firewall or hosting control panel.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Clear All Tokens ───────────────────────────────────────────── -->
        <div class="lem-section">
            <h2>Clear All Tokens &amp; Sessions</h2>
            <div class="lem-card">
                <div class="lem-warning">
                    <h3>&#9888; Warning: This action cannot be undone!</h3>
                    <p>This will permanently delete:</p>
                    <ul>
                        <li>All JWT tokens from the database</li>
                        <li>All active sessions from Upstash</li>
                        <li>All magic tokens from Upstash</li>
                        <li>All cached event data from Upstash</li>
                    </ul>
                    <p><strong>All users will lose access and need to request new magic links.</strong></p>
                </div>
                <button id="lem-clear-tokens" class="button button-danger">Clear All Tokens &amp; Sessions</button>
                <div id="lem-clear-result" class="lem-result"></div>
            </div>
        </div>

    </div>
</div>

<style>
.lem-result { margin-top:15px; padding:15px; border-radius:8px; display:none; }
.lem-result.success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; display:block; }
.lem-result.error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; display:block; }
.lem-result.info    { background:#d1ecf1; color:#0c5460; border:1px solid #bee5eb; display:block; }

.lem-instructions { margin-top:20px; padding:20px; background:#f8f9fa; border-radius:8px; border:1px solid #e9ecef; }
.lem-instructions h3 { margin-top:0; color:#333; border-bottom:2px solid #007cba; padding-bottom:10px; }

.lem-collapsible-section { margin-bottom:12px; border:1px solid #e9ecef; border-radius:6px; overflow:hidden; }
.lem-collapsible-header  { background:#f8f9fa; padding:12px 16px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e9ecef; }
.lem-collapsible-header:hover { background:#e9ecef; }
.lem-collapsible-header h4 { margin:0; color:#007cba; font-size:1em; }
.lem-toggle-icon { font-size:.8em; color:#666; }

.lem-collapsible-content { overflow:hidden; transition:max-height .3s ease, opacity .3s ease; background:#fff; max-height:800px; opacity:1; }
.lem-collapsible-content.collapsed { max-height:0; opacity:0; }
.lem-collapsible-content > * { padding:16px 20px; }
.lem-collapsible-content ol { margin:0; padding-left:20px; }
.lem-collapsible-content li { margin:6px 0; color:#555; }

.lem-code-block { background:#f1f3f4; padding:15px; border-radius:6px; margin:10px 0; border-left:4px solid #007cba; }
.lem-code-block code { background:#e8eaed; padding:4px 8px; border-radius:4px; font-family:'Courier New',monospace; color:#d73a49; display:inline-block; margin:5px 0; word-break:break-all; }
.lem-code-block p { margin:5px 0; color:#666; }
.lem-code-block ol { margin:0; padding-left:20px; }
.lem-code-block li { margin:6px 0; color:#555; }

.lem-troubleshooting { margin:12px 0; }
.lem-issue { background:#fff3cd; border:1px solid #ffeaa7; padding:12px; border-radius:6px; margin:8px 0; }
.lem-issue strong { color:#856404; display:block; margin-bottom:4px; }
.lem-issue p { margin:4px 0; color:#856404; }

.lem-warning { background:#fff3cd; border:1px solid #ffeaa7; padding:15px; border-radius:6px; margin-bottom:20px; }
.lem-warning h3 { color:#856404; margin-top:0; margin-bottom:8px; }
.lem-warning p, .lem-warning li { color:#856404; margin:6px 0; }
.lem-warning ul { margin:8px 0; padding-left:20px; }

.button-danger { background:#dc3545 !important; border-color:#dc3545 !important; color:#fff !important; }
.button-danger:hover { background:#c82333 !important; border-color:#bd2130 !important; }

.lem-stats { display:flex; gap:20px; margin:12px 0; }
.lem-stat { background:#f8f9fa; padding:10px 15px; border-radius:6px; border:1px solid #e9ecef; }
.lem-stat .number { font-size:1.5em; font-weight:bold; color:#007cba; }
.lem-stat .label  { font-size:.9em; color:#666; }
</style>

<script>
jQuery(document).ready(function($) {
    function escHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s)));
        return d.innerHTML;
    }

    // Collapsible sections
    $('.lem-collapsible-header').on('click', function() {
        var $content = $('#' + $(this).data('target'));
        var $icon    = $(this).find('.lem-toggle-icon');
        var collapsed = $content.hasClass('collapsed');
        $content.toggleClass('collapsed', !collapsed);
        $icon.html(collapsed ? '&#9660;' : '&#9654;');
    });

    // Test Upstash connection
    $('#lem-test-redis').on('click', function() {
        var $btn    = $(this).prop('disabled', true).text('Testing…');
        var $result = $('#lem-redis-result').removeClass('success error info').hide();

        $.post(lem_ajax.ajax_url, { action: 'lem_test_redis_connection', nonce: lem_ajax.nonce }, function(r) {
            if (r.success) {
                $result.addClass('success').html(
                    '<h4>&#10003; ' + escHtml(r.data.message) + '</h4>' +
                    '<p>Endpoint: <code>' + escHtml(r.data.url) + '</code></p>'
                ).show();
            } else {
                $result.addClass('error').html('<h4>&#10007; Test Failed</h4><p>' + escHtml(r.data) + '</p>').show();
            }
        }).fail(function() {
            $result.addClass('error').html('<h4>&#10007; Network Error</h4><p>Could not reach the server.</p>').show();
        }).always(function() {
            $btn.prop('disabled', false).text('Test Upstash Connection');
        });
    });

    // Clear all tokens
    $('#lem-clear-tokens').on('click', function() {
        if (!confirm('WARNING: This will permanently delete ALL tokens and sessions.\n\nAll users will lose access and need to request new magic links.\n\nThis action cannot be undone. Continue?')) {
            return;
        }
        var $btn    = $(this).prop('disabled', true).text('Clearing…');
        var $result = $('#lem-clear-result').removeClass('success error info').hide();

        $.post(lem_ajax.ajax_url, { action: 'lem_clear_all_tokens', nonce: lem_ajax.nonce }, function(r) {
            if (r.success) {
                var html = '<h4>&#10003; All Tokens Cleared</h4><p>' + escHtml(r.data.message) + '</p>';
                html += '<div class="lem-stats">';
                html += '<div class="lem-stat"><span class="number">' + escHtml(r.data.results.mysql_deleted)    + '</span><div class="label">DB Records Deleted</div></div>';
                html += '<div class="lem-stat"><span class="number">' + escHtml(r.data.results.redis_keys_deleted) + '</span><div class="label">Cache Keys Deleted</div></div>';
                html += '</div>';
                if (r.data.results.redis_error) {
                    html += '<p style="color:#856404;"><strong>Cache warning:</strong> ' + escHtml(r.data.results.redis_error) + '</p>';
                }
                $result.addClass('success').html(html).show();
            } else {
                $result.addClass('error').html('<h4>&#10007; Clear Failed</h4><p>' + escHtml(r.data) + '</p>').show();
            }
        }).fail(function() {
            $result.addClass('error').html('<h4>&#10007; Network Error</h4><p>Failed to clear tokens.</p>').show();
        }).always(function() {
            $btn.prop('disabled', false).text('Clear All Tokens & Sessions');
        });
    });

    // ── Webhook activity log ──────────────────────────────────────────────
    var webhookLogTimer = null;

    var statusColors = {
        received:            '#3498db',
        processed:           '#27ae60',
        duplicate:           '#8e44ad',
        already_has_access:  '#8e44ad',
        skipped:             '#888',
        verification_failed: '#c0392b',
        missing_metadata:    '#e67e22',
        jwt_failed:          '#c0392b',
        failed:              '#c0392b'
    };

    function statusBadge(status) {
        var color = statusColors[status] || '#666';
        return '<span style="background:' + color + ';color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">' + escHtml(status.replace(/_/g, ' ')) + '</span>';
    }

    function renderWebhookLog(rows) {
        var $empty = $('#lem-webhook-log-empty');
        var $table = $('#lem-webhook-log-table');
        var $tbody = $('#lem-webhook-log-tbody');

        if (!rows || rows.length === 0) {
            $empty.text('No webhook activity recorded yet. Trigger a test webhook from your provider dashboard or via Stripe CLI to populate this log.').show();
            $table.hide();
            return;
        }

        var html = '';
        rows.forEach(function(r) {
            var details = '';
            if (r.message)    details += escHtml(r.message);
            if (r.payment_id) details += '<br><small style="color:#666;">payment_id: <code>' + escHtml(r.payment_id) + '</code></small>';
            if (r.event_id)   details += ' &nbsp;<small style="color:#666;">event_id: <code>' + escHtml(r.event_id) + '</code></small>';
            if (r.email)      details += ' &nbsp;<small style="color:#666;">email: ' + escHtml(r.email) + '</small>';
            if (r.source_ip)  details += '<br><small style="color:#999;">from ' + escHtml(r.source_ip) + (parseInt(r.has_signature) ? ' • signed' : ' • unsigned') + '</small>';

            html += '<tr>';
            html += '<td><small>' + escHtml(r.received_at) + '</small></td>';
            html += '<td>' + escHtml(r.provider || '—') + '</td>';
            html += '<td>' + statusBadge(r.status) + '</td>';
            html += '<td><code style="font-size:11px;">' + escHtml(r.event_type || '—') + '</code></td>';
            html += '<td>' + details + '</td>';
            html += '</tr>';
        });
        $tbody.html(html);
        $empty.hide();
        $table.show();
    }

    function loadWebhookLog() {
        $.post(lem_ajax.ajax_url, {
            action: 'lem_get_webhook_log',
            nonce:  lem_ajax.nonce
        }, function(r) {
            if (r.success) renderWebhookLog(r.data.rows);
        });
    }

    function startWebhookAutoRefresh() {
        stopWebhookAutoRefresh();
        webhookLogTimer = setInterval(loadWebhookLog, 5000);
    }
    function stopWebhookAutoRefresh() {
        if (webhookLogTimer) { clearInterval(webhookLogTimer); webhookLogTimer = null; }
    }

    $('#lem-refresh-webhook-log').on('click', loadWebhookLog);

    $('#lem-webhook-log-auto').on('change', function() {
        if (this.checked) startWebhookAutoRefresh();
        else stopWebhookAutoRefresh();
    });

    $('#lem-clear-webhook-log').on('click', function() {
        if (!confirm('Clear all recorded webhook activity?')) return;
        $.post(lem_ajax.ajax_url, {
            action: 'lem_clear_webhook_log',
            nonce:  lem_ajax.nonce
        }, function(r) {
            if (r.success) loadWebhookLog();
        });
    });

    if ($('#lem-webhook-log-wrap').length) {
        loadWebhookLog();
        if ($('#lem-webhook-log-auto').is(':checked')) startWebhookAutoRefresh();
    }
});
</script>
