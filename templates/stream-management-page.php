<?php
/**
 * Live Streams – unified stream management + setup page.
 *
 * URL params:
 *   stream_id  – show the setup panel for this stream below the table
 *   event_id   – pre-select the stream assigned to this event
 */

if (!defined('ABSPATH')) exit;

global $live_event_manager;

// ── Cache bust (linked from empty-state) ─────────────────────────────────────
if (!empty($_GET['lem_bust_cache']) && current_user_can('manage_options')) {
    $settings_tmp = get_option('lem_settings', []);
    $pid_tmp      = $settings_tmp['streaming_provider'] ?? 'mux';
    LEM_Cache::del($pid_tmp . ':live_streams');
}

$settings           = get_option('lem_settings', []);
$factory            = LEM_Streaming_Provider_Factory::get_instance();
$active_provider_id = $settings['streaming_provider'] ?? 'mux';
$provider           = $factory->get_provider($active_provider_id, $live_event_manager);

// is_configured() is the interface-level check — works for every provider.
$has_api_credentials = $provider && $provider->is_configured();
$fully_configured    = $has_api_credentials;
$create_fields       = $provider ? $provider->get_create_stream_fields() : array();
$can_create          = $has_api_credentials && !empty($create_fields);

// ── Resolve the active stream ─────────────────────────────────────────────────
$current_event_id = isset($_GET['event_id'])  ? intval($_GET['event_id'])                       : 0;
$url_stream_id    = isset($_GET['stream_id']) ? sanitize_text_field($_GET['stream_id'])         : '';
$event_stream_id  = $current_event_id         ? get_post_meta($current_event_id, '_lem_live_stream_id', true) : '';
$active_stream_id = $url_stream_id ?: $event_stream_id ?: ($settings['lem_live_stream_id'] ?? $settings['mux_live_stream_id'] ?? '');

// ── Fetch stream list ─────────────────────────────────────────────────────────
$available_streams = [];
$fetch_error       = null;

if ($live_event_manager && $has_api_credentials) {
    $request        = new WP_REST_Request('GET', '/lem/v1/live-streams');
    // Always bypass cache on the admin page so the list is always up to date
    $streams_result = $live_event_manager->list_live_streams($request, true);
    if (is_wp_error($streams_result)) {
        $fetch_error = $streams_result->get_error_message();
    } elseif (is_array($streams_result) && isset($streams_result['data'])) {
        $available_streams = $streams_result['data'];
    } else {
        $fetch_error = 'Unexpected response from API (type: ' . gettype($streams_result) . ')';
    }
} elseif (!$live_event_manager) {
    $fetch_error = 'Plugin instance not available. Try deactivating and reactivating the plugin.';
} elseif (!$has_api_credentials) {
    $fetch_error = 'API credentials not configured.';
}


// ── Fetch setup data for active stream ───────────────────────────────────────
$rtmp_info         = null;
$stream_status     = null;
$simulcast_targets = [];
$active_stream_obj = null;

if ($active_stream_id && $live_event_manager && $has_api_credentials) {
    // Find stream object in list
    foreach ($available_streams as $s) {
        if (($s['id'] ?? '') === $active_stream_id) {
            $active_stream_obj = $s;
            break;
        }
    }

    // RTMP info
    $req = new WP_REST_Request('GET', '/lem/v1/rtmp-info');
    $req->set_param('stream_id', $active_stream_id);
    $r = $live_event_manager->get_rtmp_info($req);
    if (!is_wp_error($r)) $rtmp_info = $r;

    // Stream status
    $req = new WP_REST_Request('GET', '/lem/v1/stream-status');
    $req->set_param('stream_id', $active_stream_id);
    $r = $live_event_manager->get_stream_status($req);
    if (!is_wp_error($r)) $stream_status = $r;

    // Simulcast targets
    $req = new WP_REST_Request('GET', '/lem/v1/simulcast-targets');
    $req->set_param('stream_id', $active_stream_id);
    $r = $live_event_manager->get_simulcast_targets($req);
    if (!is_wp_error($r) && isset($r['data'])) $simulcast_targets = $r['data'];
}

// ── Helper ───────────────────────────────────────────────────────────────────
function lem_streams_url($stream_id = '') {
    $args = ['post_type' => 'lem_event', 'page' => 'live-event-manager-stream-management'];
    if ($stream_id) $args['stream_id'] = $stream_id;
    return admin_url(add_query_arg($args, 'edit.php'));
}
?>

<div class="wrap lem-streams-wrap">

    <!-- ── Page header ────────────────────────────────────────────────────── -->
    <div class="lem-streams-header">
        <h1>Live Streams</h1>
        <?php if ($can_create): ?>
        <button type="button" class="button button-primary" id="lem-toggle-create">
            + New Stream
        </button>
        <?php endif; ?>
    </div>

    <?php if ($fetch_error): ?>
    <div class="notice notice-error inline" style="margin-top:12px;">
        <p>
            <strong>Could not load streams:</strong> <?php echo esc_html($fetch_error); ?>
            &nbsp;—&nbsp;
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=lem_event&page=live-event-manager-services&service=streaming')); ?>">Check Services credentials</a>
        </p>
    </div>
    <?php endif; ?>

    <?php if (!$has_api_credentials && !$fetch_error): ?>
    <div class="notice notice-warning inline" style="margin-top:12px;">
        <p>
            <strong><?php echo esc_html(ucfirst($active_provider_id)); ?> is not fully configured.</strong>
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=lem_event&page=live-event-manager-services&service=streaming')); ?>">Complete setup in Services &rarr;</a>
        </p>
    </div>
    <?php endif; ?>

    <!-- ── Create stream panel (hidden by default) ────────────────────────── -->
    <?php if ($can_create): ?>
    <div id="lem-create-panel" class="lem-panel" style="display:none;">
        <h2>New Stream</h2>
        <form id="lem-create-stream-form">
            <input type="hidden" name="provider" value="<?php echo esc_attr($active_provider_id); ?>">
            <table class="form-table" style="margin:0;">
                <?php foreach ($create_fields as $field):
                    $fkey   = $field['key']         ?? '';
                    $flabel = $field['label']        ?? $fkey;
                    $ftype  = $field['type']         ?? 'text';
                    $fph    = $field['placeholder']  ?? '';
                    $fdesc  = $field['description']  ?? '';
                    $freq   = !empty($field['required']);
                    $fdef   = $field['default']      ?? '';
                    $fid    = 'lem-new-' . esc_attr($fkey);
                ?>
                <tr>
                    <th><label for="<?php echo $fid; ?>"><?php echo esc_html($flabel); ?><?php if ($freq): ?> <span class="lem-req">*</span><?php endif; ?></label></th>
                    <td>
                        <?php if ($ftype === 'select' && !empty($field['options'])): ?>
                        <select id="<?php echo $fid; ?>" name="<?php echo esc_attr($fkey); ?>" style="width:220px;">
                            <?php foreach ($field['options'] as $oval => $olabel): ?>
                            <option value="<?php echo esc_attr($oval); ?>" <?php selected($fdef, $oval); ?>><?php echo esc_html($olabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php elseif ($ftype === 'checkbox'): ?>
                        <label class="lem-toggle-label">
                            <input type="checkbox" id="<?php echo $fid; ?>" name="<?php echo esc_attr($fkey); ?>" value="1">
                            <?php echo esc_html($flabel); ?>
                        </label>
                        <?php else: ?>
                        <input type="<?php echo esc_attr($ftype); ?>" id="<?php echo $fid; ?>" name="<?php echo esc_attr($fkey); ?>"
                               class="regular-text" placeholder="<?php echo esc_attr($fph); ?>"
                               <?php if ($freq) echo 'required'; ?>>
                        <?php endif; ?>
                        <?php if ($fdesc): ?><p class="description"><?php echo wp_kses_post($fdesc); ?></p><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <div class="lem-panel-footer">
                <button type="submit" class="button button-primary">Create Stream</button>
                <button type="button" class="button" id="lem-cancel-create">Cancel</button>
            </div>
        </form>
    </div>
    <?php elseif ($has_api_credentials && empty($create_fields)): ?>
    <div class="notice notice-info inline" style="margin-bottom:12px;">
        <p><?php echo esc_html(ucfirst($active_provider_id)); ?> streams are created by pushing from your encoder — no stream creation needed here.</p>
    </div>
    <?php endif; ?>

    <!-- ── Streams table ──────────────────────────────────────────────────── -->
    <div class="lem-panel" style="margin-top:0;">

        <?php if (empty($available_streams)): ?>
            <p class="description" style="padding:12px 0;">
                <?php if ($fetch_error): ?>
                    Could not load streams — see the error above.
                <?php elseif ($has_api_credentials): ?>
                    No streams found in your <?php echo esc_html(ucfirst($active_provider_id)); ?> account.
                    Click <strong>+ New Stream</strong> to create one, or
                    <a href="<?php echo esc_url(add_query_arg('lem_bust_cache', '1')); ?>">refresh the list</a>
                    if you just created a stream elsewhere.
                <?php else: ?>
                    Configure your <a href="<?php echo esc_url(admin_url('edit.php?post_type=lem_event&page=live-event-manager-services&service=streaming')); ?>">streaming credentials</a> to get started.
                <?php endif; ?>
            </p>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped lem-streams-table">
            <thead>
                <tr>
                    <th class="col-name">Name / ID</th>
                    <th class="col-status">Status</th>
                    <th class="col-playback">Playback ID</th>
                    <th class="col-key">Stream Key</th>
                    <th class="col-created">Created</th>
                    <th class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($available_streams as $stream):
                    $ns           = $provider->normalize_stream($stream);
                    $sid          = $ns['id'];
                    $sname        = $ns['name'];
                    $sstatus      = $ns['status'];
                    $playback_id  = $ns['playback_id'] ?: 'N/A';
                    $stream_key   = $ns['stream_key'];
                    $created_at   = $ns['created_at']
                        ? date('M j, Y', strtotime($ns['created_at']))
                        : '—';
                    $is_active_row = ($sid === $active_stream_id);
                    $status_color  = match($sstatus) {
                        'active' => '#46b450', 'idle' => '#9e9e9e', default => '#f0c30f'
                    };
                    if (empty($sid)) continue;
                ?>
                <tr class="<?php echo $is_active_row ? 'lem-row-selected' : ''; ?>" data-stream-id="<?php echo esc_attr($sid); ?>">
                    <td>
                        <strong><?php echo esc_html($sname); ?></strong><br>
                        <code class="lem-id"><?php echo esc_html($sid); ?></code>
                    </td>
                    <td>
                        <span class="lem-badge" style="background:<?php echo $status_color; ?>;">
                            <?php echo esc_html(ucfirst($sstatus)); ?>
                        </span>
                    </td>
                    <td>
                        <code class="lem-id"><?php echo esc_html($playback_id); ?></code>
                    </td>
                    <td>
                        <code class="lem-id"><?php echo esc_html(strlen($stream_key) > 16 ? substr($stream_key, 0, 16) . '…' : $stream_key); ?></code>
                        <?php if ($stream_key): ?>
                        <button type="button" class="button button-small lem-copy-key"
                                data-key="<?php echo esc_attr($stream_key); ?>"
                                title="Copy stream key">&#10064;</button>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($created_at); ?></td>
                    <td>
                        <div class="lem-row-actions">
                            <a href="<?php echo esc_url(lem_streams_url($sid)); ?>"
                               class="button button-small <?php echo $is_active_row ? 'button-primary' : ''; ?>"
                               title="View RTMP info &amp; simulcast for this stream">
                                <?php echo $is_active_row ? '▲ Setup' : 'Setup'; ?>
                            </a>
                            <?php if (!empty($provider->get_edit_stream_fields())): ?>
                            <button type="button" class="button button-small lem-edit-stream"
                                    data-stream-id="<?php echo esc_attr($sid); ?>"
                                    data-stream-name="<?php echo esc_attr($sname); ?>"
                                    data-stream-raw="<?php echo esc_attr(wp_json_encode($stream)); ?>">
                                Edit
                            </button>
                            <?php endif; ?>
                            <button type="button" class="button button-small button-link-delete lem-delete-stream"
                                    data-stream-id="<?php echo esc_attr($sid); ?>"
                                    data-stream-name="<?php echo esc_attr($sname); ?>">
                                Delete
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php /* ── Setup panel (shown when a stream is selected) ─────────────── */ ?>
    <?php if ($active_stream_id): ?>

    <?php
    $panel_ns   = $provider && $active_stream_obj ? $provider->normalize_stream($active_stream_obj) : array();
    $panel_name = $panel_ns['name'] ?? $active_stream_id;
    ?>
    <div class="lem-panel lem-setup-panel">
        <div class="lem-setup-header">
            <h2>
                Setup:
                <span><?php echo esc_html($panel_name); ?></span>
                <code class="lem-id" style="font-size:12px;"><?php echo esc_html($active_stream_id); ?></code>
            </h2>
            <a href="<?php echo esc_url(lem_streams_url()); ?>" class="button button-small">✕ Close</a>
        </div>

        <div class="lem-setup-grid">

            <!-- RTMP Switchboard -->
            <div>
                <h3>RTMP / OBS Credentials</h3>
                <?php if ($rtmp_info): ?>
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th>Ingest URL</th>
                        <td>
                            <div class="lem-copy-row">
                                <input type="text" id="lem-ingest-url" value="<?php echo esc_attr($rtmp_info['ingest_url']); ?>" readonly class="regular-text code">
                                <button type="button" class="button button-small lem-copy-btn" data-copy="lem-ingest-url">Copy</button>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>Stream Key</th>
                        <td>
                            <div class="lem-copy-row">
                                <input type="text" id="lem-stream-key" value="<?php echo esc_attr($rtmp_info['stream_key']); ?>" readonly class="regular-text code">
                                <button type="button" class="button button-small lem-copy-btn" data-copy="lem-stream-key">Copy</button>
                            </div>
                        </td>
                    </tr>
                    <?php if (!empty($rtmp_info['playback_id'])): ?>
                    <tr>
                        <th>Playback ID</th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($rtmp_info['playback_id']); ?>" readonly class="regular-text code">
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                <details class="lem-obs-guide">
                    <summary>OBS setup instructions</summary>
                    <ol>
                        <li>Open OBS → Settings → Stream</li>
                        <li>Set Service to <strong>Custom</strong></li>
                        <li>Paste the <strong>Ingest URL</strong> above into <em>Server</em></li>
                        <li>Paste the <strong>Stream Key</strong> above</li>
                        <li>Click OK and start streaming!</li>
                    </ol>
                </details>
                <?php else: ?>
                <p class="description">Unable to fetch RTMP info. Check your API credentials.</p>
                <?php endif; ?>
            </div>

            <!-- Stream Status -->
            <div>
                <h3>
                    Stream Status
                    <button type="button" class="button button-small" id="lem-refresh-status" style="float:right; margin-top:-2px;">Refresh</button>
                </h3>
                <?php if ($stream_status): ?>
                <?php
                $is_live   = !empty($stream_status['is_active']);
                $s_status  = $stream_status['status'] ?? 'unknown';
                $s_color   = $is_live ? '#46b450' : '#9e9e9e';
                ?>
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                    <span class="lem-badge" style="background:<?php echo $s_color; ?>; font-size:13px; padding:6px 14px;">
                        <?php echo esc_html(ucfirst($s_status)); ?>
                    </span>
                    <?php if ($is_live): ?>
                    <span style="color:#46b450;">&#9679; Live</span>
                    <?php endif; ?>
                </div>
                <?php if (!$is_live && !empty($stream_status['recent_asset'])): ?>
                <p class="description">Most recent recording available for VOD playback.</p>
                <?php endif; ?>
                <?php else: ?>
                <p class="description">Status unavailable.</p>
                <?php endif; ?>

                <!-- Save to settings / link to event -->
                <div style="margin-top:16px; padding-top:16px; border-top:1px solid #e0e0e0;">
                    <button type="button" class="button button-small" id="lem-save-stream-id">
                        Save as default stream
                    </button>
                    <?php if ($current_event_id): ?>
                    &nbsp;
                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $current_event_id . '&action=edit')); ?>"
                       class="button button-small">← Back to Event</a>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- .lem-setup-grid -->

        <!-- Simulcast Targets -->
        <?php if ($provider && $provider->supports_simulcast()): ?>
        <div style="margin-top:24px; padding-top:20px; border-top:1px solid #e0e0e0;">
            <h3>Simulcast Targets</h3>
            <p class="description">Forward this stream to YouTube, Twitch, or any RTMP destination.</p>

            <div id="lem-simulcast-list">
                <?php if (!empty($simulcast_targets)): ?>
                <table class="wp-list-table widefat fixed striped" style="margin-bottom:16px;">
                    <thead>
                        <tr>
                            <th>RTMP URL</th>
                            <th style="width:120px;">Status</th>
                            <th style="width:80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($simulcast_targets as $target): ?>
                        <tr>
                            <td><code><?php echo esc_html($target['url'] ?? $target['rtmp_url'] ?? 'N/A'); ?></code></td>
                            <td>
                                <?php $ts = $target['status'] ?? 'unknown'; ?>
                                <span class="lem-badge" style="background:<?php echo $ts === 'active' ? '#46b450' : '#9e9e9e'; ?>;">
                                    <?php echo esc_html(ucfirst($ts)); ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="button button-small lem-delete-target"
                                        data-target-id="<?php echo esc_attr($target['id'] ?? ''); ?>">
                                    Delete
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p class="description" style="margin-bottom:16px;">No simulcast targets yet.</p>
                <?php endif; ?>
            </div>

            <form id="lem-add-simulcast-form" class="lem-simulcast-form">
                <div>
                    <label style="font-weight:600; display:block; margin-bottom:4px;">RTMP URL</label>
                    <input type="text" id="lem-simulcast-url" class="regular-text"
                           placeholder="rtmp://a.rtmp.youtube.com/live2/STREAM_KEY" required>
                    <p class="description">YouTube: rtmp://a.rtmp.youtube.com/live2/… &nbsp;|&nbsp; Twitch: rtmp://live.twitch.tv/app/…</p>
                </div>
                <div style="align-self:flex-start; padding-top:22px;">
                    <button type="submit" class="button button-primary">Add Target</button>
                </div>
            </form>
        </div>
        <?php endif; // supports_simulcast ?>

    </div><!-- .lem-setup-panel -->
    <?php endif; ?>

</div><!-- .lem-streams-wrap -->

<!-- ── Edit Stream Modal ─────────────────────────────────────────────────── -->
<?php
$edit_fields = $provider ? $provider->get_edit_stream_fields() : array();
if (!empty($edit_fields)):
?>
<div id="lem-edit-modal" class="lem-modal-overlay" style="display:none;">
    <div class="lem-modal">
        <h2>Edit Stream</h2>
        <form id="lem-edit-stream-form">
            <input type="hidden" id="lem-edit-stream-id" name="stream_id">
            <table class="form-table" style="margin:0;">
                <?php foreach ($edit_fields as $ef):
                    $efkey  = $ef['key']   ?? '';
                    $eflbl  = $ef['label'] ?? $efkey;
                    $eftype = $ef['type']  ?? 'text';
                    $efid   = 'lem-edit-' . esc_attr($efkey);
                ?>
                <tr>
                    <th><label for="<?php echo $efid; ?>"><?php echo esc_html($eflbl); ?></label></th>
                    <td>
                        <?php if ($eftype === 'checkbox'): ?>
                        <label class="lem-toggle-label">
                            <input type="checkbox" id="<?php echo $efid; ?>" name="<?php echo esc_attr($efkey); ?>" value="1">
                            <?php echo esc_html($eflbl); ?>
                        </label>
                        <?php else: ?>
                        <input type="<?php echo esc_attr($eftype); ?>" id="<?php echo $efid; ?>" name="<?php echo esc_attr($efkey); ?>"
                               class="regular-text" <?php if (!empty($ef['required'])) echo 'required'; ?>>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <div class="lem-modal-footer">
                <button type="submit" class="button button-primary">Save Changes</button>
                <button type="button" class="button lem-close-modal">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
jQuery(document).ready(function($) {
    var mgmtNonce  = <?php echo wp_json_encode(wp_create_nonce('lem_stream_management_nonce')); ?>;
    var setupNonce = <?php echo wp_json_encode(wp_create_nonce('lem_stream_setup_nonce')); ?>;
    var ajaxUrl    = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var streamId   = '<?php echo esc_js($active_stream_id); ?>';

    // ── Create panel toggle ───────────────────────────────────────────────
    $('#lem-toggle-create').on('click', function() {
        var $panel = $('#lem-create-panel');
        var open   = $panel.is(':visible');
        $panel.slideToggle(150);
        $(this).text(open ? '+ New Stream' : '✕ Cancel');
    });

    $('#lem-cancel-create').on('click', function() {
        $('#lem-create-panel').slideUp(150);
        $('#lem-toggle-create').text('+ New Stream');
    });

    // ── Create stream ─────────────────────────────────────────────────────
    $('#lem-create-stream-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var btn   = $form.find('button[type="submit"]');

        // Collect all named form fields (generic — works for any provider).
        var data = { action: 'lem_create_stream', nonce: mgmtNonce };
        $form.find('[name]').each(function() {
            var $el = $(this);
            var key = $el.attr('name');
            if ($el.is(':checkbox')) {
                data[key] = $el.is(':checked') ? '1' : '0';
            } else {
                data[key] = $el.val();
            }
        });

        if (!data['passthrough'] && !data['name']) {
            $form.find('input[type="text"]:first').focus();
            return;
        }

        btn.prop('disabled', true).text('Creating…');

        $.post(ajaxUrl, data, function(response) {
            if (response.success) {
                location.reload();
            } else {
                var msg = response.data || 'Failed to create stream';
                if (typeof msg === 'object' && msg.message) msg = msg.message;
                showNotice('Error: ' + msg, 'error');
                btn.prop('disabled', false).text('Create Stream');
            }
        }).fail(function(xhr, status, error) {
            showNotice('Network error: ' + error, 'error');
            btn.prop('disabled', false).text('Create Stream');
        });
    });

    // ── Edit stream ───────────────────────────────────────────────────────
    $(document).on('click', '.lem-edit-stream', function() {
        var $btn = $(this);
        var raw  = {};
        try { raw = JSON.parse($btn.data('stream-raw') || '{}'); } catch(e) {}

        $('#lem-edit-stream-id').val($btn.data('stream-id'));

        // Populate edit fields from raw stream data where names match.
        $('#lem-edit-stream-form [name]').each(function() {
            var $el  = $(this);
            var key  = $el.attr('name');
            var val  = raw[key];
            if ($el.is(':checkbox')) {
                $el.prop('checked', !!val);
            } else if (val !== undefined) {
                $el.val(val);
            }
        });
        // Fallback: stream name label is commonly 'passthrough' or 'name'
        var nameVal = $btn.data('stream-name');
        var $nameEl = $('#lem-edit-stream-form [name="passthrough"],[name="name"]').first();
        if ($nameEl.length && nameVal) $nameEl.val(nameVal);

        openModal();
    });

    $('#lem-edit-stream-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var sid   = $('#lem-edit-stream-id').val();
        if (!sid) return;

        var btn = $form.find('button[type="submit"]');
        btn.prop('disabled', true).text('Saving…');

        // Collect all named fields from the modal form.
        var data = { action: 'lem_update_stream', stream_id: sid, nonce: mgmtNonce };
        $form.find('[name]').each(function() {
            var $el = $(this);
            var key = $el.attr('name');
            if (key === 'stream_id') return; // already set above
            if ($el.is(':checkbox')) {
                data[key] = $el.is(':checked') ? '1' : '0';
            } else {
                data[key] = $el.val();
            }
        });

        $.post(ajaxUrl, data, function(response) {
            if (response.success) {
                location.reload();
            } else {
                showNotice('Error: ' + (response.data || 'Update failed'), 'error');
                btn.prop('disabled', false).text('Save Changes');
            }
        }).fail(function() {
            showNotice('Network error. Please try again.', 'error');
            btn.prop('disabled', false).text('Save Changes');
        });
    });

    // ── Delete stream ─────────────────────────────────────────────────────
    $(document).on('click', '.lem-delete-stream', function() {
        var sid  = $(this).data('stream-id');
        var name = $(this).data('stream-name');
        if (!confirm('Delete "' + name + '"? This cannot be undone.')) return;

        var btn = $(this);
        btn.prop('disabled', true).text('Deleting…');

        $.post(ajaxUrl, {
            action:    'lem_delete_stream',
            stream_id: sid,
            nonce:     mgmtNonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                showNotice('Error: ' + (response.data || 'Delete failed'), 'error');
                btn.prop('disabled', false).text('Delete');
            }
        }).fail(function() {
            showNotice('Network error. Please try again.', 'error');
            btn.prop('disabled', false).text('Delete');
        });
    });

    // ── Copy key button (in stream table) ────────────────────────────────
    $(document).on('click', '.lem-copy-key', function() {
        copyText($(this).data('key'), $(this));
    });

    // ── Copy buttons (in RTMP panel) ─────────────────────────────────────
    $('.lem-copy-btn').on('click', function() {
        var $input = $('#' + $(this).data('copy'));
        copyText($input.val(), $(this));
    });

    // ── Refresh status ────────────────────────────────────────────────────
    $('#lem-refresh-status').on('click', function() {
        location.reload();
    });

    // ── Save as default stream ────────────────────────────────────────────
    $('#lem-save-stream-id').on('click', function() {
        if (!streamId) return;
        var btn = $(this);
        btn.prop('disabled', true).text('Saving…');
        $.post(ajaxUrl, {
            action:    'lem_save_stream_id',
            stream_id: streamId,
            nonce:     setupNonce
        }, function(response) {
            if (response.success) {
                btn.text('Saved ✓');
                setTimeout(function() {
                    btn.prop('disabled', false).text('Save as default stream');
                }, 2500);
            } else {
                showNotice('Error: ' + (response.data || 'Could not save'), 'error');
                btn.prop('disabled', false).text('Save as default stream');
            }
        });
    });

    // ── Add Simulcast target ──────────────────────────────────────────────
    $('#lem-add-simulcast-form').on('submit', function(e) {
        e.preventDefault();
        var url = $('#lem-simulcast-url').val().trim();
        if (!url) return;

        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Adding…');

        $.post(ajaxUrl, {
            action:    'lem_create_simulcast_target',
            stream_id: streamId,
            url:       url,
            nonce:     setupNonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                showNotice('Error: ' + (response.data || 'Failed to add target'), 'error');
                btn.prop('disabled', false).text('Add Target');
            }
        }).fail(function() {
            showNotice('Network error.', 'error');
            btn.prop('disabled', false).text('Add Target');
        });
    });

    // ── Delete Simulcast target ───────────────────────────────────────────
    $(document).on('click', '.lem-delete-target', function() {
        if (!confirm('Delete this simulcast target?')) return;
        var tid = $(this).data('target-id');
        var btn = $(this);
        btn.prop('disabled', true).text('Deleting…');

        $.post(ajaxUrl, {
            action:    'lem_delete_simulcast_target',
            stream_id: streamId,
            target_id: tid,
            nonce:     setupNonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                showNotice('Error: ' + (response.data || 'Failed'), 'error');
                btn.prop('disabled', false).text('Delete');
            }
        });
    });

    // ── Modal helpers ─────────────────────────────────────────────────────
    function openModal()  { $('#lem-edit-modal').css('display', 'flex'); }
    function closeModal() { $('#lem-edit-modal').hide(); }

    $('.lem-close-modal').on('click', closeModal);
    $('#lem-edit-modal').on('click', function(e) {
        if ($(e.target).is('#lem-edit-modal')) closeModal();
    });

    // ── Copy helper ───────────────────────────────────────────────────────
    function copyText(text, $btn) {
        if (!text) return;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text);
        } else {
            var $tmp = $('<input>').val(text).appendTo('body').select();
            document.execCommand('copy');
            $tmp.remove();
        }
        var orig = $btn.text();
        $btn.text('Copied!').addClass('button-primary');
        setTimeout(function() { $btn.text(orig).removeClass('button-primary'); }, 2000);
    }

    // HTML escape helper
    function escHtml(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }

    // ── Inline notice helper ──────────────────────────────────────────────
    function showNotice(msg, type) {
        var cls = type === 'error' ? 'notice-error' : 'notice-success';
        var $n  = $('<div class="notice ' + cls + ' is-dismissible"><p>' + escHtml(msg) + '</p></div>');
        $('.lem-streams-wrap h1').after($n);
        setTimeout(function() { $n.fadeOut(400, function() { $n.remove(); }); }, 5000);
    }
});
</script>

<style>
/* ── Layout ── */
.lem-streams-wrap { max-width: 1200px; }

.lem-streams-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}
.lem-streams-header h1 { margin: 0; }

/* ── Panels ── */
.lem-panel {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 3px;
    padding: 20px 24px;
    margin-bottom: 16px;
}
.lem-panel h2 {
    margin: 0 0 16px;
    font-size: 16px;
    font-weight: 600;
}
.lem-panel h3 {
    font-size: 14px;
    margin: 0 0 12px;
}
.lem-panel-footer {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 8px;
}
.lem-req { color: #d63638; }

/* ── Table ── */
.lem-streams-table { border-collapse: collapse; }
.lem-streams-table th,
.lem-streams-table td { vertical-align: middle; }
.col-name    { width: 26%; }
.col-status  { width: 90px; }
.col-playback{ width: 18%; }
.col-key     { width: 18%; }
.col-created { width: 110px; }
.col-actions { width: 180px; }

.lem-row-selected td { background: #f0f6fc !important; }

.lem-id {
    font-size: 11px;
    color: #666;
    word-break: break-all;
}

.lem-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    color: #fff;
    white-space: nowrap;
}

.lem-row-actions { display: flex; gap: 4px; flex-wrap: wrap; }

.lem-copy-key {
    font-size: 12px;
    padding: 1px 5px;
    vertical-align: middle;
}

/* ── Setup panel ── */
.lem-setup-panel { border-top: 3px solid #0073aa; }

.lem-setup-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}
.lem-setup-header h2 {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.lem-setup-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}
@media (max-width: 1100px) {
    .lem-setup-grid { grid-template-columns: 1fr; }
}

.lem-copy-row {
    display: flex;
    gap: 8px;
    align-items: center;
}
.lem-copy-row input { flex: 1; }

.lem-obs-guide {
    margin-top: 16px;
    padding: 12px;
    background: #f6f7f7;
    border: 1px solid #e0e0e0;
    border-radius: 3px;
    font-size: 13px;
}
.lem-obs-guide summary { cursor: pointer; font-weight: 600; }
.lem-obs-guide ol { margin: 8px 0 0 16px; }
.lem-obs-guide li { margin-bottom: 4px; }

/* ── Simulcast form ── */
.lem-simulcast-form {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    flex-wrap: wrap;
}
.lem-simulcast-form > div:first-child { flex: 1; min-width: 260px; }

/* ── Modal ── */
.lem-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.5);
    z-index: 100000;
    align-items: center;
    justify-content: center;
}
.lem-modal {
    background: #fff;
    border-radius: 4px;
    padding: 28px 32px;
    max-width: 480px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 8px 32px rgba(0,0,0,.18);
}
.lem-modal h2 { margin-top: 0; }
.lem-modal-footer {
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 8px;
}

/* ── Misc ── */
.lem-toggle-label { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
</style>
