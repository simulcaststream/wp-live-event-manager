<?php
/**
 * Custom per-event admin editor.
 * Replaces the native WP post editor for lem_event CPT.
 */
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) wp_die(__('Unauthorized'));

$event_id  = isset($_GET['event_id']) && $_GET['event_id'] !== 'new' ? intval($_GET['event_id']) : 0;
$is_new    = ($event_id === 0);
$post      = $event_id ? get_post($event_id) : null;

if ($event_id && (!$post || $post->post_type !== 'lem_event')) {
    wp_die(__('Event not found.'));
}

$active_tab = sanitize_key($_GET['tab'] ?? 'configure');
if (!in_array($active_tab, array('configure', 'tickets', 'stream', 'access'), true)) {
    $active_tab = 'configure';
}

// Meta values (empty strings for new events)
$title     = $post ? $post->post_title : '';
$slug      = $post ? $post->post_name  : '';
$status    = $post ? $post->post_status : 'draft';

$playback_id             = $event_id ? get_post_meta($event_id, '_lem_playback_id', true)             : '';
$live_stream_id          = $event_id ? get_post_meta($event_id, '_lem_live_stream_id', true)          : '';
$stream_provider         = $event_id ? (get_post_meta($event_id, '_lem_stream_provider', true) ?: 'mux') : 'mux';
$playback_restriction_id = $event_id ? get_post_meta($event_id, '_lem_playback_restriction_id', true)  : '';
$event_date              = $event_id ? get_post_meta($event_id, '_lem_event_date', true)              : '';
$event_end               = $event_id ? get_post_meta($event_id, '_lem_event_end', true)               : '';
$is_free                 = $event_id ? (get_post_meta($event_id, '_lem_is_free', true) ?: 'free')      : 'free';
$price_id                = $event_id ? get_post_meta($event_id, '_lem_price_id', true)                : '';
$amount                  = $event_id ? get_post_meta($event_id, '_lem_amount', true)                  : '';
$display_price           = $event_id ? get_post_meta($event_id, '_lem_display_price', true)           : '';
$excerpt                 = $event_id ? get_post_meta($event_id, '_lem_excerpt', true)                 : '';
$payment_provider        = $event_id ? (get_post_meta($event_id, '_lem_payment_provider', true) ?: '') : '';

// Available payment providers
$pay_providers = LEM_Payment_Provider_Factory::get_instance()->get_available_providers();

// Shortcode reference
$hex_id    = $event_id ? sprintf('0x%x', $event_id) : '0x0';
$shortcode = $event_id ? '[simulcast_event id="' . $hex_id . '" layout="player+chat"]' : '';

// Available streams for the stream picker (all configured providers)
$available_streams = array(); // each item: ['provider' => 'mux', 'stream' => <raw>]
try {
    $lem_instance = new LiveEventManager();
    $stream_factory = LEM_Streaming_Provider_Factory::get_instance();
    foreach ($stream_factory->get_available_providers() as $pid) {
        $p = $stream_factory->get_provider($pid, $lem_instance);
        if (!$p || !$p->is_configured()) {
            continue;
        }
        $streams = $p->list_streams() ?: array();
        if (!is_array($streams)) {
            continue;
        }
        foreach ($streams as $s) {
            $available_streams[] = array('provider' => $pid, 'stream' => $s);
        }
    }
} catch (\Exception $e) {
    // provider not ready
}

// Tickets sold count
$tickets_sold = 0;
if ($event_id) {
    global $wpdb;
    $tickets_sold = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}lem_jwt_tokens WHERE event_id = %s AND payment_id IS NOT NULL AND payment_id != '' AND revoked_at IS NULL",
        (string) $event_id
    ));
}

$editor_url = function($tab = 'configure') use ($event_id) {
    return admin_url(add_query_arg(
        array('post_type' => 'lem_event', 'page' => 'lem-event-editor', 'event_id' => $event_id ?: 'new', 'tab' => $tab),
        'edit.php'
    ));
};

$list_url = admin_url('edit.php?post_type=lem_event');
?>

<div class="wrap" id="lem-event-editor-page" data-event-id="<?php echo esc_attr($event_id ?: 'new'); ?>">

    <h1 class="wp-heading-inline" id="lem-editor-title-display">
        <?php echo $is_new ? 'New Event' : esc_html($title); ?>
    </h1>
    <?php if ($event_id): ?>
        <code style="margin-left:10px;font-size:0.75em;color:#646970;"><?php echo esc_html(strtoupper($hex_id)); ?></code>
    <?php endif; ?>

    <span id="lem-save-status" style="margin-left:12px;font-size:13px;"></span>
    <button type="button" class="button button-primary" id="lem-save-btn" style="float:right;margin-top:6px;">
        <?php echo $is_new ? 'Create Event' : 'Save Changes'; ?>
    </button>

    <hr class="wp-header-end">

    <!-- Tabs -->
    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url($editor_url('configure')); ?>"
           class="nav-tab <?php echo $active_tab === 'configure' ? 'nav-tab-active' : ''; ?>"
           data-tab="configure">Configure</a>
        <?php if (!$is_new): ?>
        <a href="<?php echo esc_url($editor_url('tickets')); ?>"
           class="nav-tab <?php echo $active_tab === 'tickets' ? 'nav-tab-active' : ''; ?>"
           data-tab="tickets">Tickets<?php if ($tickets_sold > 0): ?> <span class="count">(<?php echo esc_html($tickets_sold); ?>)</span><?php endif; ?></a>
        <a href="<?php echo esc_url($editor_url('stream')); ?>"
           class="nav-tab <?php echo $active_tab === 'stream' ? 'nav-tab-active' : ''; ?>"
           data-tab="stream">Stream</a>
        <a href="<?php echo esc_url($editor_url('access')); ?>"
           class="nav-tab <?php echo $active_tab === 'access' ? 'nav-tab-active' : ''; ?>"
           data-tab="access">Access log</a>
        <?php endif; ?>
    </nav>

    <!-- ── Configure tab ─────────────────────────────────────────────── -->
    <div class="tab-content" id="lem-tab-configure">

        <table class="form-table" role="presentation">

            <!-- Basic -->
            <tr>
                <th scope="row"><label for="lem-field-title">Title</label></th>
                <td><input type="text" id="lem-field-title" name="title" class="regular-text"
                           value="<?php echo esc_attr($title); ?>" placeholder="Event title"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lem-field-slug">Slug</label></th>
                <td><input type="text" id="lem-field-slug" name="slug" class="regular-text"
                           value="<?php echo esc_attr($slug); ?>" placeholder="event-slug"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lem-field-status">Status</label></th>
                <td>
                    <select id="lem-field-status" name="status">
                        <option value="publish" <?php selected($status, 'publish'); ?>>Published</option>
                        <option value="draft"   <?php selected($status, 'draft'); ?>>Draft</option>
                    </select>
                </td>
            </tr>

            <!-- Schedule -->
            <tr><td colspan="2"><h2 class="title" style="padding:0;margin:12px 0 0;">Schedule</h2></td></tr>
            <tr>
                <th scope="row"><label for="lem-field-event-date">Doors open</label></th>
                <td><input type="datetime-local" id="lem-field-event-date" name="lem_event_date"
                           value="<?php echo esc_attr($event_date ? date('Y-m-d\TH:i', strtotime($event_date)) : ''); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lem-field-event-end">Doors close</label></th>
                <td><input type="datetime-local" id="lem-field-event-end" name="lem_event_end"
                           value="<?php echo esc_attr($event_end ? date('Y-m-d\TH:i', strtotime($event_end)) : ''); ?>"></td>
            </tr>

            <!-- Streaming -->
            <tr><td colspan="2"><h2 class="title" style="padding:0;margin:12px 0 0;">Streaming</h2></td></tr>
            <tr>
                <th scope="row"><label for="lem-field-stream-provider">Engine</label></th>
                <td>
                    <select id="lem-field-stream-provider" name="lem_stream_provider">
                        <option value="mux"    <?php selected($stream_provider, 'mux'); ?>>Mux</option>
                        <option value="ome"    <?php selected($stream_provider, 'ome'); ?>>OvenMediaEngine</option>
                        <option value="custom" <?php selected(!in_array($stream_provider, array('mux','ome'), true) && $stream_provider); ?>>Custom</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lem-field-stream-id">Stream</label></th>
                <td>
                    <select id="lem-field-stream-id" name="lem_live_stream_id">
                        <option value="">— none —</option>
                        <?php foreach ($available_streams as $row):
                            $pid    = $row['provider'] ?? '';
                            $stream = $row['stream'] ?? null;
                            $sid    = is_array($stream) ? ($stream['id'] ?? '') : '';
                            $slabel = is_array($stream) ? ($stream['name'] ?? $stream['title'] ?? $sid) : (string)$stream;
                        ?>
                            <option value="<?php echo esc_attr($sid); ?>"
                                    data-provider="<?php echo esc_attr($pid); ?>"
                                    data-stream-id="<?php echo esc_attr($sid); ?>"
                                    <?php selected($live_stream_id, $sid); ?>>
                                <?php echo esc_html(($pid ? strtoupper($pid) . ' — ' : '') . ($slabel ?: $sid)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lem-field-playback-id">Playback ID</label></th>
                <td><input type="text" id="lem-field-playback-id" name="lem_playback_id"
                           class="regular-text" value="<?php echo esc_attr($playback_id); ?>" placeholder="Optional override"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lem-field-restriction-id">Restriction ID</label></th>
                <td><input type="text" id="lem-field-restriction-id" name="lem_playback_restriction_id"
                           class="regular-text" value="<?php echo esc_attr($playback_restriction_id); ?>" placeholder="Mux playback restriction ID"></td>
            </tr>

            <!-- Access & Pricing -->
            <tr><td colspan="2"><h2 class="title" style="padding:0;margin:12px 0 0;">Access &amp; Pricing</h2></td></tr>
            <tr>
                <th scope="row"><label for="lem-field-is-free">Access</label></th>
                <td>
                    <select id="lem-field-is-free" name="lem_is_free">
                        <option value="free"   <?php selected($is_free, 'free'); ?>>Open (free)</option>
                        <option value="jwt"    <?php selected($is_free, 'jwt'); ?>>JWT (paid)</option>
                        <option value="jwt_ip" <?php selected($is_free, 'jwt_ip'); ?>>JWT + IP restriction</option>
                    </select>
                </td>
            </tr>
            <tbody id="lem-paid-fields" <?php echo $is_free === 'free' ? 'style="display:none"' : ''; ?>>
            <tr>
                <th scope="row"><label for="lem-field-amount">Price</label></th>
                <td>
                    <input type="text" id="lem-field-amount" name="lem_amount"
                           value="<?php echo esc_attr($amount); ?>" placeholder="49.00" style="width:100px"> USD
                    <p class="description">Numeric amount charged at checkout.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lem-field-display-price">Display label</label></th>
                <td><input type="text" id="lem-field-display-price" name="lem_display_price"
                           class="regular-text" value="<?php echo esc_attr($display_price); ?>" placeholder="e.g. $49"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lem-field-price-id">Stripe price ID</label></th>
                <td><input type="text" id="lem-field-price-id" name="lem_price_id"
                           class="regular-text" value="<?php echo esc_attr($price_id); ?>" placeholder="price_xxxxxxx"></td>
            </tr>
            </tbody>
            <tr>
                <th scope="row"><label for="lem-field-payment-provider">Payment provider</label></th>
                <td>
                    <select id="lem-field-payment-provider" name="lem_payment_provider">
                        <option value="" <?php selected($payment_provider, ''); ?>>Global default</option>
                        <?php foreach ($pay_providers as $pid): ?>
                        <option value="<?php echo esc_attr($pid); ?>" <?php selected($payment_provider, $pid); ?>><?php echo esc_html(ucfirst($pid)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <!-- Description -->
            <tr>
                <th scope="row"><label for="lem-field-excerpt">Description</label></th>
                <td><textarea id="lem-field-excerpt" name="lem_excerpt" rows="3" class="large-text"><?php echo esc_textarea($excerpt); ?></textarea></td>
            </tr>

        </table>

        <?php if ($event_id): ?>
        <!-- Status bar -->
        <div class="notice notice-info inline" id="lem-status-bar" style="padding:8px 14px;margin-top:8px;display:flex;align-items:center;gap:8px;">
            <span id="lem-stream-dot" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#72aee6;flex-shrink:0;"></span>
            <span id="lem-stream-label">checking…</span>
            <span style="color:#646970;">&middot; <strong id="lem-tickets-count"><?php echo esc_html($tickets_sold); ?></strong> tickets sold</span>
        </div>
        <?php endif; ?>

        <?php if ($event_id && $shortcode): ?>
        <!-- Shortcode -->
        <p style="margin-top:16px;">
            <label style="display:block;margin-bottom:4px;font-weight:600;">Shortcode</label>
            <input type="text" id="lem-shortcode-text" class="large-text code" readonly
                   value="<?php echo esc_attr($shortcode); ?>" style="max-width:600px;">
            <button type="button" class="button" id="lem-copy-shortcode" style="margin-left:6px;">Copy</button>
        </p>
        <?php endif; ?>

    </div><!-- /configure tab -->

    <!-- ── Tickets tab ────────────────────────────────────────────────── -->
    <?php if (!$is_new): ?>
    <div class="tab-content" id="lem-tab-tickets">
        <h2>Ticket Sales</h2>
        <?php
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.email, t.payment_id, t.created_at, t.expires_at, t.revoked_at, t.jti
             FROM {$wpdb->prefix}lem_jwt_tokens t
             WHERE t.payment_id IS NOT NULL AND t.payment_id != '' AND t.event_id = %s
             ORDER BY t.created_at DESC LIMIT 500",
            (string) $event_id
        ));
        $settings    = get_option('lem_settings', array());
        $stripe_mode = $settings['stripe_mode'] ?? 'test';
        $stripe_base = $stripe_mode === 'live'
            ? 'https://dashboard.stripe.com/checkout/sessions/'
            : 'https://dashboard.stripe.com/test/checkout/sessions/';
        if (empty($rows)): ?>
            <p>No tickets sold yet.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>Email</th><th>Payment ID</th><th>Purchased</th><th>Expires</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $revoked = !empty($row->revoked_at);
                    $expired = !$revoked && strtotime($row->expires_at) < time();
                ?>
                    <tr>
                        <td><?php echo esc_html($row->email); ?></td>
                        <td><?php if ($row->payment_id): ?><a href="<?php echo esc_url($stripe_base . $row->payment_id); ?>" target="_blank" rel="noopener"><?php echo esc_html(substr($row->payment_id, 0, 22) . '…'); ?></a><?php else: echo '—'; endif; ?></td>
                        <td><?php echo esc_html(date_i18n('M j, Y H:i', strtotime($row->created_at))); ?></td>
                        <td><?php echo esc_html(date_i18n('M j, Y H:i', strtotime($row->expires_at))); ?></td>
                        <td><?php
                            if ($revoked)      echo '<span style="color:#d63638">Revoked</span>';
                            elseif ($expired)  echo '<span style="color:#646970">Expired</span>';
                            else               echo '<span style="color:#00a32a">Active</span>';
                        ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ── Stream tab ──────────────────────────────────────────────────── -->
    <div class="tab-content" id="lem-tab-stream">
        <h2>Stream</h2>
        <p>Stream assigned to this event. Manage it in full detail from the Streams page.</p>
        <a href="<?php echo esc_url(admin_url(add_query_arg(
            array('post_type' => 'lem_event', 'page' => 'live-event-manager-stream-management', 'stream_id' => $live_stream_id),
            'edit.php'
        ))); ?>" class="button">Open Streams page</a>
        <?php if ($live_stream_id): ?>
        <div style="margin-top:20px;" id="lem-stream-details-wrap">
            <p>Stream: <code><?php echo esc_html($live_stream_id); ?></code></p>
            <div id="lem-stream-details-loading">Loading…</div>
            <div id="lem-stream-details-content" style="display:none;"></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Access log tab ──────────────────────────────────────────────── -->
    <div class="tab-content" id="lem-tab-access">
        <h2>Access Tokens</h2>
        <div id="lem-access-list"><p>Loading…</p></div>
    </div>
    <?php endif; ?>

</div><!-- /.wrap -->

<script>
jQuery(function($) {
    var eventId   = <?php echo wp_json_encode($event_id ?: null); ?>;
    var isNew     = <?php echo $is_new ? 'true' : 'false'; ?>;
    var activeTab = <?php echo wp_json_encode($active_tab); ?>;

    var lemCfg = (typeof lem_ajax !== 'undefined' && lem_ajax) ? lem_ajax : {
        ajax_url: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
        nonce:    <?php echo wp_json_encode(wp_create_nonce('lem_nonce')); ?>,
        stream_setup_nonce: <?php echo wp_json_encode(wp_create_nonce('lem_stream_setup_nonce')); ?>
    };
    var ajaxUrl = lemCfg.ajax_url;
    var nonce   = lemCfg.nonce;

    // ── Tab switching ──────────────────────────────────────────────────────
    // Show only the active pane on load
    $('.tab-content').hide();
    $('#lem-tab-' + activeTab).show();

    $('.nav-tab').on('click', function(e) {
        var tab = $(this).data('tab');
        if (!tab) return;
        if (isNew) { e.preventDefault(); return; }

        var url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        history.pushState({}, '', url.toString());

        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.tab-content').hide();
        $('#lem-tab-' + tab).show();

        if (tab === 'access') loadAccessLog();
        if (tab === 'stream' && eventId) loadStreamDetails();
    });

    // ── Show/hide paid fields based on access select ───────────────────────
    $('#lem-field-is-free').on('change', function() {
        if ($(this).val() === 'free') {
            $('#lem-paid-fields').hide();
        } else {
            $('#lem-paid-fields').show();
        }
    });

    // ── Title sync ─────────────────────────────────────────────────────────
    $('#lem-field-title').on('input', function() {
        $('#lem-editor-title-display').text($(this).val() || 'New Event');
    });

    // ── Save ───────────────────────────────────────────────────────────────
    $('#lem-save-btn').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).text('Saving…');
        $('#lem-save-status').text('').css('color', '');

        var data = {
            action:   'lem_save_event',
            nonce:    nonce,
            event_id: eventId || 0,
            title:    $('#lem-field-title').val(),
            slug:     $('#lem-field-slug').val(),
            status:   $('#lem-field-status').val(),
            lem_is_free:                 $('#lem-field-is-free').val(),
            lem_price_id:                $('#lem-field-price-id').val() || '',
            lem_amount:                  $('#lem-field-amount').val() || '',
            lem_display_price:           $('#lem-field-display-price').val() || '',
            lem_event_date:              $('#lem-field-event-date').val() || '',
            lem_event_end:               $('#lem-field-event-end').val() || '',
            lem_stream_provider:         $('#lem-field-stream-provider').val(),
            lem_live_stream_id:          $('#lem-field-stream-id').val() || '',
            lem_playback_id:             $('#lem-field-playback-id').val() || '',
            lem_playback_restriction_id: $('#lem-field-restriction-id').val() || '',
            lem_excerpt:                 $('#lem-field-excerpt').val() || '',
            lem_payment_provider:        $('#lem-field-payment-provider').val() || '',
        };

        $.post(ajaxUrl, data, function(res) {
            if (res.success) {
                $('#lem-save-status').text('Saved').css('color', '#00a32a');
                if (isNew && res.data.edit_url) {
                    window.location.href = res.data.edit_url;
                } else {
                    btn.prop('disabled', false).text('Save Changes');
                    setTimeout(function() { $('#lem-save-status').text('').css('color', ''); }, 3000);
                }
            } else {
                $('#lem-save-status').text(res.data || 'Error').css('color', '#d63638');
                btn.prop('disabled', false).text('Save Changes');
            }
        }).fail(function() {
            $('#lem-save-status').text('Network error').css('color', '#d63638');
            btn.prop('disabled', false).text('Save Changes');
        });
    });

    // ── Copy shortcode ─────────────────────────────────────────────────────
    $('#lem-copy-shortcode').on('click', function() {
        var text = $('#lem-shortcode-text').val();
        var btn  = $(this);
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function() {
                btn.text('Copied!');
                setTimeout(function() { btn.text('Copy'); }, 2000);
            });
        } else {
            var input = document.getElementById('lem-shortcode-text');
            input.select();
            document.execCommand('copy');
            btn.text('Copied!');
            setTimeout(function() { btn.text('Copy'); }, 2000);
        }
    });

    // ── Status bar polling ─────────────────────────────────────────────────
    function updateStatusBar() {
        if (!eventId) return;
        $.post(ajaxUrl, { action: 'lem_get_event_status', nonce: nonce, event_id: eventId }, function(res) {
            if (!res.success) return;
            var d   = res.data;
            var dot = $('#lem-stream-dot');
            if (d.stream_status === 'active') {
                dot.css('background', '#00a32a');
                $('#lem-stream-label').text('Stream live');
            } else if (d.stream_status === 'idle') {
                dot.css('background', '#d63638');
                $('#lem-stream-label').text('Stream offline');
            } else {
                dot.css('background', '#dba617');
                $('#lem-stream-label').text('Stream ' + d.stream_status);
            }
            $('#lem-tickets-count').text(d.tickets_sold);
        });
    }
    if (eventId && activeTab === 'configure') {
        updateStatusBar();
        setInterval(updateStatusBar, 30000);
    }

    // ── Access log ─────────────────────────────────────────────────────────
    function escHtml(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(String(s))); return d.innerHTML; }

    function loadAccessLog() {
        if (!eventId) return;
        $('#lem-access-list').html('<p>Loading…</p>');
        $.post(ajaxUrl, { action: 'lem_get_jwt_tokens', nonce: nonce, event_id: eventId }, function(res) {
            if (!res.success) {
                $('#lem-access-list').html('<p>Error loading tokens.</p>');
                return;
            }
            var tokens = res.data;
            if (!tokens || !tokens.length) {
                $('#lem-access-list').html('<p>No access tokens for this event.</p>');
                return;
            }
            var html = '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Email</th><th>Created</th><th>Expires</th><th>Actions</th></tr></thead><tbody>';
            tokens.forEach(function(t) {
                var isExpired = new Date() > new Date(t.expires_at);
                html += '<tr>';
                html += '<td>' + escHtml(t.email) + '</td>';
                html += '<td>' + escHtml(new Date(t.created_at).toLocaleString()) + '</td>';
                html += '<td>' + escHtml(new Date(t.expires_at).toLocaleString()) + (isExpired ? ' <span style="color:#d63638">(Expired)</span>' : '') + '</td>';
                html += '<td>';
                if (!isExpired) {
                    html += '<button class="button button-small lem-revoke-token" data-jti="' + escHtml(t.jti) + '">Revoke</button>';
                }
                html += '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            $('#lem-access-list').html(html);
        });
    }

    $(document).on('click', '.lem-revoke-token', function() {
        if (!confirm('Revoke this access token?')) return;
        var btn = $(this);
        var jti = btn.data('jti');
        btn.prop('disabled', true).text('Revoking…');
        $.post(ajaxUrl, { action: 'lem_revoke_jwt', nonce: nonce, jti: jti }, function(res) {
            if (res.success) {
                loadAccessLog();
            } else {
                btn.prop('disabled', false).text('Revoke');
                alert(res.data || 'Error');
            }
        });
    });

    if (activeTab === 'access') loadAccessLog();

    // ── Stream details ─────────────────────────────────────────────────────
    function loadStreamDetails() {
        var streamId = <?php echo wp_json_encode($live_stream_id); ?>;
        if (!streamId) return;
        $('#lem-stream-details-loading').show();
        $('#lem-stream-details-content').hide();
        $.post(ajaxUrl, { action: 'lem_get_stream_details', nonce: lemCfg.stream_setup_nonce, stream_id: streamId }, function(res) {
            $('#lem-stream-details-loading').hide();
            if (res.success) {
                var d = res.data;
                var html = '<table class="form-table" role="presentation">';
                html += '<tr><th>Status</th><td>' + escHtml(d.status || '—') + '</td></tr>';
                if (d.stream_key) { html += '<tr><th>Stream Key</th><td><code>' + escHtml(d.stream_key) + '</code></td></tr>'; }
                if (d.rtmp_url)   { html += '<tr><th>RTMP URL</th><td><code>'   + escHtml(d.rtmp_url)   + '</code></td></tr>'; }
                html += '</table>';
                $('#lem-stream-details-content').html(html).show();
            } else {
                $('#lem-stream-details-content').html('<p>' + escHtml(res.data || 'Could not load stream details.') + '</p>').show();
            }
        }).fail(function() {
            $('#lem-stream-details-loading').hide();
            $('#lem-stream-details-content').html('<p>Network error.</p>').show();
        });
    }
    if (activeTab === 'stream') loadStreamDetails();

});
</script>
