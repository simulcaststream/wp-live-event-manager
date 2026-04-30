<?php
/**
 * Admin — revoke viewer access (event, then email from JWT records).
 */
if (!defined('ABSPATH')) {
    exit;
}

$events = get_posts(array(
    'post_type'      => 'lem_event',
    'posts_per_page' => 500,
    'post_status'    => 'publish',
    'orderby'        => 'title',
    'order'          => 'ASC',
));
?>
<div class="wrap">
    <h1><?php esc_html_e('Access', 'live-event-manager'); ?></h1>
    <?php require __DIR__ . '/admin-subnav.php'; ?>

    <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=lem_event&page=live-event-manager-jwt')); ?>"
           class="nav-tab">Tokens</a>
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=lem_event&page=live-event-manager-revoke-access')); ?>"
           class="nav-tab nav-tab-active">Revoke Access</a>
    </nav>

    <p><?php esc_html_e('Blocks the viewer email for the selected event, revokes database tokens, and deletes Redis sessions and playback cache for that email and event.', 'live-event-manager'); ?></p>

    <?php if (!empty($notice) && $notice === 'success') : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Access revoked for that email and event.', 'live-event-manager'); ?></p></div>
    <?php elseif (!empty($notice) && $notice === 'invalid') : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Please enter a valid email and select an event.', 'live-event-manager'); ?></p></div>
    <?php endif; ?>

    <form method="post" action="" style="max-width: 32rem; margin-top: 1.5rem;">
        <?php wp_nonce_field('lem_revoke_access', 'lem_revoke_nonce'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="revoke_event_id"><?php esc_html_e('Event', 'live-event-manager'); ?></label></th>
                <td>
                    <select name="revoke_event_id" id="revoke_event_id" required>
                        <option value=""><?php esc_html_e('— Select —', 'live-event-manager'); ?></option>
                        <?php foreach ($events as $ev) : ?>
                            <option value="<?php echo esc_attr((string) $ev->ID); ?>"><?php echo esc_html($ev->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="revoke_email"><?php esc_html_e('Viewer email', 'live-event-manager'); ?></label></th>
                <td>
                    <select name="revoke_email" id="revoke_email" class="regular-text" required>
                        <option value=""><?php esc_html_e('— Select an event first —', 'live-event-manager'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Emails listed here have a JWT record for the selected event.', 'live-event-manager'); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button(__('Revoke access', 'live-event-manager'), 'primary', 'lem_revoke_submit'); ?>
    </form>
</div>
<script>
jQuery(function($) {
    var cfg = (typeof lem_ajax !== 'undefined' && lem_ajax && lem_ajax.ajax_url) ? lem_ajax : <?php echo wp_json_encode(array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('lem_nonce'),
    )); ?>;
    var $revokeEvent = $('#revoke_event_id');
    var $revokeEmail = $('#revoke_email');
    if (!cfg || !$revokeEvent.length || !$revokeEmail.length) {
        return;
    }
    function resetRevokeEmailPlaceholder(msg) {
        $revokeEmail.empty().append($('<option>', { value: '', text: msg }));
    }
    function loadRevokeEmailsForEvent() {
        var eventId = $revokeEvent.val();
        if (!eventId) {
            resetRevokeEmailPlaceholder('— Select an event first —');
            return;
        }
        resetRevokeEmailPlaceholder('— Loading… —');
        $.ajax({
            url: cfg.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'lem_revoke_emails_for_event',
                nonce: cfg.nonce,
                event_id: eventId
            }
        }).done(function(res) {
            $revokeEmail.empty();
            if (res && res.success && res.data && res.data.emails && res.data.emails.length) {
                $revokeEmail.append($('<option>', { value: '', text: '— Select —' }));
                res.data.emails.forEach(function(email) {
                    $revokeEmail.append($('<option>', { value: email, text: email }));
                });
            } else {
                resetRevokeEmailPlaceholder('— No emails found for this event —');
            }
        }).fail(function() {
            resetRevokeEmailPlaceholder('— Could not load emails —');
        });
    }
    $revokeEvent.on('change', loadRevokeEmailsForEvent);
});
</script>
