<?php
/**
 * Stream Setup Page - RTMP Switchboard & Simulcast Management
 */

if (!defined('ABSPATH')) {
    exit;
}

global $live_event_manager;

$settings = get_option('lem_settings', array());
$global_live_stream_id = $settings['mux_live_stream_id'] ?? '';

// Get current event's live stream ID if editing
$current_event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
$event_live_stream_id = '';
if ($current_event_id) {
    $event_live_stream_id = get_post_meta($current_event_id, '_lem_live_stream_id', true);
}

// Use event-specific or global stream ID, or allow user selection
$live_stream_id = isset($_GET['stream_id']) ? sanitize_text_field($_GET['stream_id']) : ($event_live_stream_id ?: $global_live_stream_id);

// Get list of available streams via the active streaming provider.
$available_streams   = array();
$provider_configured = false;
$streaming_provider  = null;

if ($live_event_manager && class_exists('LEM_Streaming_Provider_Factory')) {
    $streaming_provider = LEM_Streaming_Provider_Factory::get_instance()->get_active_provider($live_event_manager);
    if ($streaming_provider && $streaming_provider->is_configured()) {
        $provider_configured = true;
        $request = new WP_REST_Request('GET', '/lem/v1/live-streams');
        $streams_result = $live_event_manager->list_live_streams($request);
        if (!is_wp_error($streams_result) && isset($streams_result['data'])) {
            $available_streams = $streams_result['data'];
        }
    }
}

// Note: AJAX handlers are registered in live-event-manager.php
// This page just displays the UI

// Get RTMP info
$rtmp_info = null;
if ($live_stream_id && $live_event_manager) {
    $request = new WP_REST_Request('GET', '/lem/v1/rtmp-info');
    $request->set_param('stream_id', $live_stream_id);
    $rtmp_result = $live_event_manager->get_rtmp_info($request);
    if (!is_wp_error($rtmp_result)) {
        $rtmp_info = $rtmp_result;
    }
}

// Get Simulcast targets
$simulcast_targets = array();
if ($live_stream_id && $live_event_manager) {
    $request = new WP_REST_Request('GET', '/lem/v1/simulcast-targets');
    $request->set_param('stream_id', $live_stream_id);
    $targets_result = $live_event_manager->get_simulcast_targets($request);
    if (!is_wp_error($targets_result) && isset($targets_result['data'])) {
        $simulcast_targets = $targets_result['data'];
    }
}

// Get stream status
$stream_status = null;
if ($live_stream_id && $live_event_manager) {
    $request = new WP_REST_Request('GET', '/lem/v1/stream-status');
    $request->set_param('stream_id', $live_stream_id);
    $status_result = $live_event_manager->get_stream_status($request);
    if (!is_wp_error($status_result)) {
        $stream_status = $status_result;
    }
}
?>

<div class="wrap">
    <h1>Stream Setup</h1>
    <p class="description">Configure your live stream settings, get RTMP credentials for OBS, and manage Simulcast targets.</p>
    
    <!-- Stream Selector -->
    <div class="postbox" style="padding: 20px; margin-bottom: 20px; background: #f9f9f9;">
        <h2 style="margin-top: 0;">Select Live Stream</h2>
        
        <?php if (!empty($available_streams)): ?>
            <form method="get" action="<?php echo admin_url('admin.php'); ?>" style="display: flex; gap: 10px; align-items: end;">
                <input type="hidden" name="page" value="live-event-manager-stream-setup">
                <?php if ($current_event_id): ?>
                    <input type="hidden" name="event_id" value="<?php echo esc_attr($current_event_id); ?>">
                <?php endif; ?>
                <div style="flex: 1;">
                    <label for="stream-select" style="display: block; font-weight: 600; margin-bottom: 5px;">Choose a stream:</label>
                    <select id="stream-select" name="stream_id" class="regular-text" style="width: 100%;">
                        <option value="">-- Select a stream --</option>
                        <?php foreach ($available_streams as $stream): ?>
                            <?php
                            $stream_id = $stream['id'] ?? '';
                            $stream_name = $stream['passthrough'] ?? $stream['playback_ids'][0]['id'] ?? $stream_id;
                            $selected = ($live_stream_id === $stream_id) ? 'selected' : '';
                            ?>
                            <option value="<?php echo esc_attr($stream_id); ?>" <?php echo $selected; ?>>
                                <?php echo esc_html($stream_name); ?> (<?php echo esc_html($stream['status'] ?? 'unknown'); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="button button-primary">Load Stream</button>
            </form>
            
            <?php if (empty($live_stream_id)): ?>
                <p class="description" style="margin-top: 10px; color: #666;">
                    Select a stream above to view RTMP credentials and manage Simulcast targets.
                </p>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($provider_configured): ?>
                <p class="description">
                    Unable to fetch streams from
                    <?php echo esc_html($streaming_provider ? $streaming_provider->get_name() : 'the active provider'); ?>.
                    Check your API credentials in
                    <a href="<?php echo admin_url('admin.php?page=live-event-manager-settings'); ?>">Settings</a>.
                </p>
            <?php elseif ($streaming_provider): ?>
                <p class="description">
                    <strong><?php echo esc_html($streaming_provider->get_name()); ?> credentials not configured.</strong>
                    Set them up in
                    <a href="<?php echo admin_url('admin.php?page=live-event-manager-settings'); ?>">Settings</a>.
                </p>
            <?php else: ?>
                <p class="description">
                    <strong>No streaming provider is registered.</strong>
                    Install the LEM Free Adaptors or LEM Premium plugin to enable streaming.
                </p>
            <?php endif; ?>
            
            <?php if (empty($live_stream_id)): ?>
                <p class="description" style="margin-top: 10px;">
                    You can also manually enter a stream ID:
                </p>
                <form method="get" action="<?php echo admin_url('admin.php'); ?>" style="display: flex; gap: 10px; align-items: end; margin-top: 10px;">
                    <input type="hidden" name="page" value="live-event-manager-stream-setup">
                    <?php if ($current_event_id): ?>
                        <input type="hidden" name="event_id" value="<?php echo esc_attr($current_event_id); ?>">
                    <?php endif; ?>
                    <div style="flex: 1;">
                        <label for="manual-stream-id" style="display: block; font-weight: 600; margin-bottom: 5px;">Stream ID:</label>
                        <input type="text" id="manual-stream-id" name="stream_id" class="regular-text" placeholder="Enter Mux Live Stream ID" value="<?php echo esc_attr($live_stream_id); ?>">
                    </div>
                    <button type="submit" class="button button-primary">Load Stream</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <?php if (!empty($live_stream_id)): ?>
        
        <div class="lem-stream-setup-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
            
            <!-- RTMP Switchboard -->
            <div class="postbox" style="padding: 20px;">
                <h2 style="margin-top: 0;">RTMP Switchboard</h2>
                <p class="description">Copy these credentials into OBS or your streaming software.</p>
                
                <?php if ($rtmp_info): ?>
                    <div class="lem-rtmp-info">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Stream Key:</label>
                            <div style="display: flex; gap: 10px;">
                                <input type="text" id="lem-stream-key" value="<?php echo esc_attr($rtmp_info['stream_key']); ?>" readonly class="regular-text" style="font-family: monospace;">
                                <button type="button" class="button button-small lem-copy-btn" data-copy="lem-stream-key">Copy</button>
                            </div>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Ingest URL:</label>
                            <div style="display: flex; gap: 10px;">
                                <input type="text" id="lem-ingest-url" value="<?php echo esc_attr($rtmp_info['ingest_url']); ?>" readonly class="regular-text" style="font-family: monospace;">
                                <button type="button" class="button button-small lem-copy-btn" data-copy="lem-ingest-url">Copy</button>
                            </div>
                        </div>
                        
                        <?php if (!empty($rtmp_info['playback_id'])): ?>
                        <div>
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Playback ID:</label>
                            <input type="text" value="<?php echo esc_attr($rtmp_info['playback_id']); ?>" readonly class="regular-text" style="font-family: monospace;">
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                        <h3 style="margin-top: 0; font-size: 14px;">OBS Setup Instructions:</h3>
                        <ol style="margin: 10px 0; padding-left: 20px;">
                            <li>Open OBS Settings → Stream</li>
                            <li>Set Service to "Custom"</li>
                            <li>Paste the <strong>Ingest URL</strong> above</li>
                            <li>Paste the <strong>Stream Key</strong> above</li>
                            <li>Click "OK" and start streaming!</li>
                        </ol>
                    </div>
                <?php else: ?>
                    <p class="description">Unable to fetch RTMP info. Check your Mux API credentials in Settings.</p>
                <?php endif; ?>
            </div>
            
            <!-- Stream Status -->
            <div class="postbox" style="padding: 20px;">
                <h2 style="margin-top: 0;">Stream Status</h2>
                
                <?php if ($stream_status): ?>
                    <div class="lem-stream-status">
                        <div style="margin-bottom: 15px;">
                            <strong>Status:</strong> 
                            <span style="padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; 
                                background: <?php echo $stream_status['is_active'] ? '#4CAF50' : '#9E9E9E'; ?>; 
                                color: white;">
                                <?php echo esc_html(ucfirst($stream_status['status'])); ?>
                            </span>
                        </div>
                        
                        <?php if ($stream_status['is_active']): ?>
                            <p style="color: #4CAF50;">✓ Stream is live and active</p>
                        <?php else: ?>
                            <p style="color: #9E9E9E;">Stream is idle</p>
                            <?php if (!empty($stream_status['recent_asset'])): ?>
                                <p class="description">Most recent recording available for VOD playback.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <button type="button" class="button" id="lem-refresh-status" style="margin-top: 10px;">Refresh Status</button>
                    </div>
                <?php else: ?>
                    <p class="description">Unable to fetch stream status.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Simulcast Targets -->
        <div class="postbox" style="margin-top: 20px; padding: 20px;">
            <h2>Simulcast Targets</h2>
            <p class="description">Forward your stream to YouTube, Twitch, or other RTMP destinations automatically.</p>
            
            <div id="lem-simulcast-targets-list">
                <?php if (!empty($simulcast_targets)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>URL</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($simulcast_targets as $target): ?>
                                <tr>
                                    <td><?php echo esc_html($target['url'] ?? $target['rtmp_url'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span style="padding: 4px 12px; border-radius: 12px; font-size: 12px; 
                                            background: <?php echo ($target['status'] ?? '') === 'active' ? '#4CAF50' : '#9E9E9E'; ?>; 
                                            color: white;">
                                            <?php echo esc_html(ucfirst($target['status'] ?? 'unknown')); ?>
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
                    <p class="description">No Simulcast targets configured. Add one below to start forwarding your stream.</p>
                <?php endif; ?>
            </div>
            
            <div style="margin-top: 20px; padding: 20px; background: #f9f9f9; border-radius: 4px;">
                <h3 style="margin-top: 0;">Add Simulcast Target</h3>
                <form id="lem-add-simulcast-form" style="display: grid; grid-template-columns: 2fr 1fr auto; gap: 10px; align-items: end;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 5px;">RTMP URL:</label>
                        <input type="text" id="lem-simulcast-url" class="regular-text" 
                               placeholder="rtmp://a.rtmp.youtube.com/live2/..." required>
                        <p class="description">YouTube: rtmp://a.rtmp.youtube.com/live2/[STREAM_KEY]</p>
                        <p class="description">Twitch: rtmp://live.twitch.tv/app/[STREAM_KEY]</p>
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 5px;">Stream Key (optional):</label>
                        <input type="text" id="lem-simulcast-key" class="regular-text" 
                               placeholder="Leave empty if included in URL">
                    </div>
                    <div>
                        <button type="submit" class="button button-primary">Add Target</button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="postbox" style="padding: 15px; margin-top: 20px; background: #e7f3ff; border-left: 4px solid #2196F3;">
            <p style="margin: 0;">
                <strong>Current Stream:</strong> <?php echo esc_html($live_stream_id); ?>
                <?php if ($current_event_id): ?>
                    | <a href="<?php echo admin_url('post.php?post=' . $current_event_id . '&action=edit'); ?>">Edit Event</a>
                <?php endif; ?>
                | <a href="<?php echo admin_url('admin.php?page=live-event-manager-stream-management'); ?>">Manage Streams</a>
                | <button type="button" id="lem-save-stream-id" class="button button-small" style="margin-left: 10px;">Save to Settings</button>
            </p>
        </div>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce = <?php echo wp_json_encode(wp_create_nonce('lem_stream_setup_nonce')); ?>;
    var streamId = <?php echo wp_json_encode($live_stream_id); ?>;
    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    
    // Copy buttons
    $('.lem-copy-btn').on('click', function() {
        var targetId = $(this).data('copy');
        var input = $('#' + targetId);
        input.select();
        document.execCommand('copy');
        
        var btn = $(this);
        var originalText = btn.text();
        btn.text('Copied!').addClass('button-primary');
        setTimeout(function() {
            btn.text(originalText).removeClass('button-primary');
        }, 2000);
    });
    
    // Refresh status
    $('#lem-refresh-status').on('click', function() {
        location.reload();
    });
    
    // Add Simulcast target
    $('#lem-add-simulcast-form').on('submit', function(e) {
        e.preventDefault();
        
        var url = $('#lem-simulcast-url').val().trim();
        var streamKey = $('#lem-simulcast-key').val().trim();
        
        if (!url) {
            alert('Please enter an RTMP URL');
            return;
        }
        
        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Adding...');
        
        $.post(ajaxUrl, {
            action: 'lem_create_simulcast_target',
            stream_id: streamId,
            url: url,
            stream_key: streamKey,
            nonce: nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + (response.data || 'Failed to add target'));
                btn.prop('disabled', false).text('Add Target');
            }
        }).fail(function() {
            alert('Network error. Please try again.');
            btn.prop('disabled', false).text('Add Target');
        });
    });
    
    // Delete Simulcast target
    $('.lem-delete-target').on('click', function() {
        if (!confirm('Are you sure you want to delete this Simulcast target?')) {
            return;
        }
        
        var targetId = $(this).data('target-id');
        var btn = $(this);
        btn.prop('disabled', true).text('Deleting...');
        
        $.post(ajaxUrl, {
            action: 'lem_delete_simulcast_target',
            stream_id: streamId,
            target_id: targetId,
            nonce: nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error: ' + (response.data || 'Failed to delete target'));
                btn.prop('disabled', false).text('Delete');
            }
        }).fail(function() {
            alert('Network error. Please try again.');
            btn.prop('disabled', false).text('Delete');
        });
    });
    
    // Save stream ID to settings
    $('#lem-save-stream-id').on('click', function() {
        if (!streamId) {
            alert('No stream selected');
            return;
        }
        
        var btn = $(this);
        btn.prop('disabled', true).text('Saving...');
        
        $.post(ajaxUrl, {
            action: 'lem_save_stream_id',
            stream_id: streamId,
            nonce: nonce
        }, function(response) {
            if (response.success) {
                alert('Stream ID saved to settings!');
                btn.text('Saved!').removeClass('button-small').addClass('button-primary');
                setTimeout(function() {
                    btn.text('Save to Settings').removeClass('button-primary').addClass('button-small');
                    btn.prop('disabled', false);
                }, 2000);
            } else {
                alert('Error: ' + (response.data || 'Failed to save stream ID'));
                btn.prop('disabled', false).text('Save to Settings');
            }
        }).fail(function() {
            alert('Network error. Please try again.');
            btn.prop('disabled', false).text('Save to Settings');
        });
    });
});
</script>

<style>
.lem-stream-setup-grid {
    margin-top: 20px;
}

.lem-rtmp-info input[readonly] {
    background: #f9f9f9;
    cursor: text;
}

@media (max-width: 1200px) {
    .lem-stream-setup-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>
