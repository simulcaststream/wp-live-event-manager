<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
    <h1>Access</h1>
    <?php require __DIR__ . '/admin-subnav.php'; ?>

    <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=lem_event&page=live-event-manager-jwt')); ?>"
           class="nav-tab nav-tab-active">Tokens</a>
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=lem_event&page=live-event-manager-revoke-access')); ?>"
           class="nav-tab">Revoke Access</a>
    </nav>

    <div class="lem-admin-container">
        <div class="lem-section">
            <h2>Active JWT Tokens</h2>
            
            <div class="lem-card">
                <div id="lem-jwt-list">
                    <p>Loading JWT tokens...</p>
                </div>
            </div>
        </div>
        
        <div class="lem-section">
            <h2>JWT Settings</h2>
            
            <div class="lem-card">
                <form method="post" action="" id="lem-jwt-settings-form">
                    <?php wp_nonce_field('lem_jwt_settings', 'lem_jwt_settings_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="jwt_expiration_hours">Initial JWT Expiration (Hours)</label>
                            </th>
                            <td>
                                <input type="number" id="jwt_expiration_hours" name="jwt_expiration_hours" 
                                       value="<?php echo esc_attr(get_option('lem_settings')['jwt_expiration_hours'] ?? 24); ?>" 
                                       class="small-text" min="1" max="168">
                                <p class="description">
                                    How long initial JWT tokens should be valid (1-168 hours, default: 24 hours).
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="jwt_refresh_duration_minutes">JWT Refresh Duration (Minutes)</label>
                            </th>
                            <td>
                                <input type="number" id="jwt_refresh_duration_minutes" name="jwt_refresh_duration_minutes" 
                                       value="<?php echo esc_attr(get_option('lem_settings')['jwt_refresh_duration_minutes'] ?? 15); ?>" 
                                       class="small-text" min="5" max="60">
                                <p class="description">
                                    How long refresh JWTs should be valid (5-60 minutes, default: 15 minutes).
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="submit" id="submit" class="button button-primary" value="Save JWT Settings">
                    </p>
                </form>
            </div>
        </div>
        

    </div>
</div>

<style>
.lem-notification {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 8px;
    color: white;
    font-weight: 500;
    z-index: 9999;
    max-width: 400px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateX(100%);
    transition: transform 0.3s ease;
}

.lem-notification.show {
    transform: translateX(0);
}

.lem-notification.success {
    background: linear-gradient(135deg, #4CAF50, #45a049);
}

.lem-notification.error {
    background: linear-gradient(135deg, #f44336, #d32f2f);
}

.lem-notification.info {
    background: linear-gradient(135deg, #2196F3, #1976D2);
}

.lem-notification.warning {
    background: linear-gradient(135deg, #ff9800, #f57c00);
}

.lem-notification .close {
    float: right;
    margin-left: 10px;
    cursor: pointer;
    opacity: 0.8;
}

.lem-notification .close:hover {
    opacity: 1;
}

/* Modal styles */
.lem-modal {
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
}

.lem-modal-content {
    background-color: #fefefe;
    padding: 20px;
    border-radius: 8px;
    width: 80%;
    max-width: 600px;
    position: relative;
}

.lem-modal-close {
    position: absolute;
    right: 15px;
    top: 10px;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    color: #aaa;
}

.lem-modal-close:hover {
    color: #000;
}
</style>

<script>
jQuery(document).ready(function($) {
    var lemJwtCache = [];

    // HTML escape helper
    function escHtml(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }

    // Notification function
    function showNotification(message, type = 'info') {
        // Remove existing notifications
        $('.lem-notification').remove();

        var notification = $('<div class="lem-notification ' + type + '">' +
            '<span class="close">&times;</span>' +
            '<span class="message">' + escHtml(message) + '</span>' +
        '</div>');
        
        $('body').append(notification);
        
        // Show notification
        setTimeout(function() {
            notification.addClass('show');
        }, 100);
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            hideNotification(notification);
        }, 5000);
        
        // Close button
        notification.find('.close').on('click', function() {
            hideNotification(notification);
        });
    }
    
    function hideNotification(notification) {
        notification.removeClass('show');
        setTimeout(function() {
            notification.remove();
        }, 300);
    }
    // Load JWT tokens on page load
    loadJWTTokens();
    
    // Handle JWT settings form submission
    $('#lem-jwt-settings-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var submitBtn = form.find('input[type="submit"]');
        
        // Disable submit button
        submitBtn.prop('disabled', true).val('Saving...');
        
        var formData = {
            action: 'lem_save_jwt_settings',
            nonce: lem_ajax.nonce,
            jwt_expiration_hours: $('#jwt_expiration_hours').val(),
            jwt_refresh_duration_minutes: $('#jwt_refresh_duration_minutes').val()
        };
        
        $.post(lem_ajax.ajax_url, formData, function(response) {
            if (response.success) {
                showNotification('JWT settings saved successfully!', 'success');
            } else {
                showNotification('Error: ' + response.data, 'error');
            }
        }).fail(function() {
            showNotification('Network error. Please try again.', 'error');
        }).always(function() {
            submitBtn.prop('disabled', false).val('Save JWT Settings');
        });
    });
    
    function loadJWTTokens() {
        $('#lem-jwt-list').html('<p>Loading JWT tokens...</p>');
        
        $.post(lem_ajax.ajax_url, {
            action: 'lem_get_jwt_tokens',
            nonce: lem_ajax.nonce
        }, function(response) {
            if (response.success) {
                displayJWTTokens(response.data);
            } else {
                $('#lem-jwt-list').html('<div class="notice notice-error"><p><strong>Error loading JWT tokens:</strong> ' + escHtml(response.data) + '</p></div>');
            }
        }).fail(function(xhr, status, error) {
            $('#lem-jwt-list').html('<div class="notice notice-error"><p><strong>Network Error:</strong> ' + escHtml(error) + '</p><p>Status: ' + escHtml(status) + '</p><p>Response: ' + escHtml(xhr.responseText) + '</p></div>');
        });
    }
    
    function displayJWTTokens(tokens) {
        lemJwtCache = tokens;

        if (tokens.length === 0) {
            $('#lem-jwt-list').html('<p>No tokens found (active or recently revoked).</p>');
            return;
        }

        var activeCount  = tokens.filter(function(t) { return t.token_status === 'active'; }).length;
        var revokedCount = tokens.filter(function(t) { return t.token_status === 'revoked'; }).length;
        var summary = '<p style="margin-bottom:10px;">'
            + '<strong>' + activeCount + '</strong> active'
            + (revokedCount > 0 ? ', <strong>' + revokedCount + '</strong> <span style="color:#c00;">revoked (last 48 h)</span>' : '')
            + '. Revoked rows appear when a webhook failed mid-flight — use the access code (first 8 chars of JTI) to resend the magic link.</p>';

        var html = summary + '<table class="wp-list-table widefat fixed striped">';
        html += '<thead><tr><th>Status</th><th>JWT ID (JTI)</th><th>Access Code</th><th>Email</th><th>Event</th><th>Ticket</th><th>Payment ID</th><th>Redis Session</th><th>Redis Cache</th><th>Created</th><th>Expires</th><th>Actions</th></tr></thead><tbody>';
        
        tokens.forEach(function(token, index) {
            var created   = new Date(token.created_at).toLocaleString();
            var expires   = new Date(token.expires_at).toLocaleString();
            var status    = token.token_status || (new Date() > new Date(token.expires_at) ? 'expired' : 'active');
            var isActive  = status === 'active';
            var isRevoked = status === 'revoked';
            var isExpired = status === 'expired';

            var statusBadge = isRevoked
                ? '<span style="background:#c00;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">REVOKED</span>'
                : (isExpired
                    ? '<span style="background:#888;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">EXPIRED</span>'
                    : '<span style="background:#0a0;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">ACTIVE</span>');

            var accessCode = token.jti ? token.jti.substr(0, 8).toUpperCase() : '—';

            var redisAvailable = token.redis && token.redis.available;
            var redisSession   = '<span style="color:#999;">Unavailable</span>';
            var redisDetails   = '<span style="color:#999;">Unavailable</span>';

            if (redisAvailable) {
                redisSession = token.redis.session_id
                    ? '<code>' + escHtml(token.redis.session_id) + '</code>'
                    : '<span style="color:#999;">None</span>';
                redisDetails = '<button class="button button-small lem-view-redis" data-index="' + index + '">Inspect</button>';
            }

            var rowStyle = isRevoked ? 'background:#fff5f5;' : (isExpired ? 'background:#fafafa;opacity:.7;' : '');

            html += '<tr style="' + rowStyle + '">';
            html += '<td>' + statusBadge + '</td>';
            html += '<td><code style="font-size:11px;">' + escHtml(token.jti) + '</code></td>';
            html += '<td><code>' + escHtml(accessCode) + '</code></td>';
            html += '<td>' + escHtml(token.email) + '</td>';
            html += '<td>' + escHtml(token.event_id) + '</td>';
            var ticketType = (token.payment_id && String(token.payment_id).length > 0) ? 'Paid' : 'Free';
            html += '<td>' + escHtml(ticketType) + '</td>';
            html += '<td>' + (token.payment_id ? '<code style="font-size:11px;">' + escHtml(token.payment_id) + '</code>' : '<span style="color:#999;">—</span>') + '</td>';
            html += '<td>' + redisSession + '</td>';
            html += '<td>' + redisDetails + '</td>';
            html += '<td>' + escHtml(created) + '</td>';
            html += '<td>' + escHtml(expires) + '</td>';
            html += '<td>';
            if (isActive) {
                html += '<button class="button button-small lem-revoke-jwt" data-jti="' + escHtml(token.jti) + '">Revoke</button>';
            } else if (isRevoked) {
                html += '<span style="color:#999;font-size:11px;">Revoked' + (token.revoked_at ? ' ' + new Date(token.revoked_at).toLocaleString() : '') + '</span>';
            } else {
                html += '<span style="color:#999;">Expired</span>';
            }
            html += '</td>';
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        $('#lem-jwt-list').html(html);
    }
    
    // View JWT
    $(document).on('click', '.lem-view-jwt', function() {
        var jwt = $(this).data('jwt');
        var modal = $('<div class="lem-modal">' +
            '<div class="lem-modal-content">' +
            '<span class="lem-modal-close">&times;</span>' +
            '<h3>JWT Token</h3>' +
            '<textarea readonly style="width: 100%; height: 200px; font-family: monospace; font-size: 12px;">' + jwt + '</textarea>' +
            '<button class="button button-primary lem-copy-jwt" style="margin-top: 10px;">Copy to Clipboard</button>' +
            '</div>' +
        '</div>');
        
        $('body').append(modal);
        
        // Close modal
        modal.find('.lem-modal-close').on('click', function() {
            modal.remove();
        });
        
        // Copy to clipboard
        modal.find('.lem-copy-jwt').on('click', function() {
            var textarea = modal.find('textarea')[0];
            textarea.select();
            document.execCommand('copy');
            showNotification('JWT copied to clipboard!', 'success');
        });
        
        // Close on outside click
        modal.on('click', function(e) {
            if (e.target === this) {
                modal.remove();
            }
        });
    });
    
    $(document).on('click', '.lem-view-redis', function() {
        var index = $(this).data('index');
        var token = lemJwtCache[index] || null;
        var redisData = token && token.redis ? JSON.stringify(token.redis, null, 2) : 'Redis data unavailable.';

        var modal = $('<div class="lem-modal">' +
            '<div class="lem-modal-content">' +
            '<span class="lem-modal-close">&times;</span>' +
            '<h3>Redis Cache Details</h3>' +
            '<textarea readonly style="width: 100%; height: 220px; font-family: monospace; font-size: 12px;">' + redisData + '</textarea>' +
            '<button class="button button-primary lem-copy-redis" style="margin-top: 10px;">Copy to Clipboard</button>' +
            '</div>' +
        '</div>');

        $('body').append(modal);

        modal.find('.lem-modal-close').on('click', function() {
            modal.remove();
        });

        modal.find('.lem-copy-redis').on('click', function() {
            var textarea = modal.find('textarea')[0];
            textarea.select();
            document.execCommand('copy');
            showNotification('Redis info copied to clipboard!', 'success');
        });

        modal.on('click', function(e) {
            if (e.target === this) {
                modal.remove();
            }
        });
    });

    // Revoke JWT
    $(document).on('click', '.lem-revoke-jwt', function() {
        if (!confirm('Are you sure you want to revoke this JWT token?')) {
            return;
        }
        
        var jti = $(this).data('jti');
        var $button = $(this);
        
        $button.prop('disabled', true).text('Revoking...');
        
        $.post(lem_ajax.ajax_url, {
            action: 'lem_revoke_jwt',
            nonce: lem_ajax.nonce,
            jti: jti
        }, function(response) {
            if (response.success) {
                showNotification('JWT token revoked successfully!', 'success');
                loadJWTTokens(); // Refresh the list
            } else {
                showNotification('Error: ' + response.data, 'error');
                $button.prop('disabled', false).text('Revoke');
            }
        }).fail(function() {
            showNotification('Network error. Please try again.', 'error');
            $button.prop('disabled', false).text('Revoke');
        });
    });
    

});
</script> 