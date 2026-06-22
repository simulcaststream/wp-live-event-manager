<?php
/**
 * Obsidian — event-ticket-block.php
 * Premium dark paywall / ticket block for LEM.
 */
if (!defined('ABSPATH')) exit;

$event_id      = $event->event_id;
$event_title   = $event->title;
$event_date    = $event->event_date;
$is_free       = $event->is_free;
$price_id      = $event->price_id;
$playback_id   = $event->playback_id;
$poster_url    = get_the_post_thumbnail_url($event_id, 'large') ?: '';

$ts             = $event_date ? strtotime($event_date) : false;
$formatted_date = $ts ? date('F j, Y', $ts) : '';
$formatted_time = $ts ? date('g:i A', $ts) : '';

$event_access     = $event_access ?? [];
$success_message  = $event_access['success_message'] ?? '';
$error_message    = $event_access['error_message'] ?? '';
$jwt_token        = $event_access['jwt_token'] ?? '';
$session_id       = $event_access['session_id'] ?? '';
$can_watch_stream = !empty($event_access['can_watch']) && !empty($jwt_token) && !empty($playback_id);
?>

<script>
window.lemWatchContext   = 'event';
window.lemWatchHasAccess = <?php echo $can_watch_stream ? 'true' : 'false'; ?>;
window.lemWatchEventId   = <?php echo (int) $event_id; ?>;
<?php if ($can_watch_stream && $jwt_token): ?>
window.lemInitialJwt = <?php echo wp_json_encode($jwt_token); ?>;
<?php endif; ?>
<?php if ($session_id): ?>
window.lemSessionId = <?php echo wp_json_encode($session_id); ?>;
<?php endif; ?>
</script>

<div class="lem-event-ticket-block obs-block lem-theme-<?php echo esc_attr($theme); ?>" data-event-id="<?php echo esc_attr($event_id); ?>">

    <?php if ($can_watch_stream): ?>

        <!-- ── Access granted ──────────────────────────────────────────── -->
        <div class="obs-block-granted">
            <div class="obs-access-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Access confirmed
            </div>
            <h2 class="obs-block-title"><?php echo esc_html($event_title); ?></h2>
            <p class="obs-block-sub">Press play to join the stream.</p>
        </div>

        <div class="obs-player-embed">
            <mux-player
                playback-id="<?php echo esc_attr($playback_id); ?>"
                playback-token="<?php echo esc_attr($jwt_token); ?>"
                <?php echo $poster_url ? 'poster="' . esc_url($poster_url) . '"' : ''; ?>
                metadata-video-title="<?php echo esc_attr($event_title); ?>"
                accent-color="#6366f1"
                style="width:100%;display:block;"
            ></mux-player>
        </div>

        <div class="obs-block-resend">
            <p class="obs-resend-label">Need a fresh link?</p>
            <form method="post" class="lem-resend-form obs-resend-form-inline" data-event-id="<?php echo esc_attr($event_id); ?>">
                <?php wp_nonce_field('lem_request_new_link', 'lem_new_link_nonce'); ?>
                <input type="hidden" name="lem_event_id" value="<?php echo esc_attr($event_id); ?>">
                <div class="lem-resend-message obs-notice" style="display:none;margin-bottom:.5rem;"></div>
                <input type="email" name="email" class="obs-input lem-resend-email" placeholder="your@email.com" required autocomplete="email">
                <button type="submit" name="lem_request_new_link" class="obs-btn obs-btn--ghost">
                    Resend link
                </button>
            </form>
        </div>

    <?php else: ?>

        <!-- ── Paywall gate ─────────────────────────────────────────────── -->
        <div class="obs-block-head">
            <?php if ($show_event_details && $event_title): ?>
                <h2 class="obs-block-title"><?php echo esc_html($event_title); ?></h2>
                <?php if ($formatted_date): ?>
                    <p class="obs-block-date">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?php echo esc_html($formatted_date . '  ·  ' . $formatted_time); ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($show_price): ?>
                <span class="obs-price-chip"><?php echo $is_free ? 'Free' : 'Paid'; ?></span>
            <?php endif; ?>
        </div>

        <!-- Tabs -->
        <div class="obs-tabs" role="tablist">
            <button class="obs-tab obs-tab--active lem-tab-btn"
                    role="tab" aria-selected="true"
                    data-tab="join-<?php echo esc_attr($event_id); ?>"
                    aria-controls="lem-panel-join-<?php echo esc_attr($event_id); ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21"/></svg>
                <?php echo $is_free ? 'Join Free' : 'Buy Ticket'; ?>
            </button>
            <button class="obs-tab lem-tab-btn"
                    role="tab" aria-selected="false"
                    data-tab="resend-<?php echo esc_attr($event_id); ?>"
                    aria-controls="lem-panel-resend-<?php echo esc_attr($event_id); ?>">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                Already purchased?
            </button>
        </div>

        <!-- Panel: Join / Buy -->
        <div class="lem-tab-panel obs-panel"
             id="lem-panel-join-<?php echo esc_attr($event_id); ?>"
             role="tabpanel">

            <?php if ($success_message): ?>
                <div class="obs-notice obs-notice--success"><?php echo esc_html($success_message); ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="obs-notice obs-notice--error"><?php echo esc_html($error_message); ?></div>
            <?php endif; ?>

            <div class="obs-input-wrap">
                <svg class="obs-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>
                </svg>
                <input type="email"
                       id="lem-email-<?php echo esc_attr($event_id); ?>"
                       class="obs-input obs-input--icon lem-email-input"
                       placeholder="<?php echo esc_attr($email_placeholder); ?>"
                       autocomplete="email" required>
            </div>

            <div class="lem-message-container obs-ajax-msg" style="display:none;"></div>

            <button type="button"
                    class="obs-cta-btn lem-ticket-button"
                    data-event-id="<?php echo esc_attr($event_id); ?>"
                    data-is-free="<?php echo $is_free ? 'true' : 'false'; ?>"
                    data-price-id="<?php echo esc_attr($price_id); ?>"
                    data-button-text="<?php echo esc_attr($button_text); ?>">
                <span class="lem-button-text obs-btn-label">
                    <?php if (!$is_free): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><polygon points="5 3 19 12 5 21"/></svg>
                    <?php endif; ?>
                    <?php echo esc_html($button_text); ?>
                </span>
                <span class="lem-button-loading obs-btn-loading" style="display:none;">
                    <svg class="obs-spin" viewBox="0 0 24 24" width="18" height="18"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-dasharray="31.4 62.8"/></svg>
                </span>
            </button>

            <?php if (!$is_free): ?>
                <div class="obs-stripe-note">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Secure checkout powered by <strong>Stripe</strong>
                </div>
            <?php endif; ?>
        </div>

        <!-- Panel: Already Purchased -->
        <div class="lem-tab-panel lem-tab-panel--hidden obs-panel"
             id="lem-panel-resend-<?php echo esc_attr($event_id); ?>"
             role="tabpanel">

            <p class="obs-resend-hint">Enter the email you used when registering and we'll send a fresh magic link straight to your inbox.</p>

            <?php if ($success_message): ?>
                <div class="obs-notice obs-notice--success"><?php echo esc_html($success_message); ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="obs-notice obs-notice--error"><?php echo esc_html($error_message); ?></div>
            <?php endif; ?>

            <form method="post" class="lem-resend-form" data-event-id="<?php echo esc_attr($event_id); ?>">
                <?php wp_nonce_field('lem_request_new_link', 'lem_new_link_nonce'); ?>
                <input type="hidden" name="lem_event_id" value="<?php echo esc_attr($event_id); ?>">
                <div class="lem-resend-message obs-notice" style="display:none;margin-bottom:.75rem;"></div>
                <div class="obs-input-wrap">
                    <svg class="obs-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>
                    </svg>
                    <input type="email" name="email" class="obs-input obs-input--icon lem-email-input lem-resend-email"
                           placeholder="your@email.com" autocomplete="email" required>
                </div>
                <button type="submit" name="lem_request_new_link"
                        class="obs-cta-btn lem-ticket-button" style="margin-top:0.75rem;">
                    <span class="lem-button-text obs-btn-label">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22,2 15,22 11,13 2,9"/></svg>
                        Send Magic Link
                    </span>
                    <span class="lem-button-loading obs-btn-loading" style="display:none;">
                        <svg class="obs-spin" viewBox="0 0 24 24" width="18" height="18"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-dasharray="31.4 62.8"/></svg>
                    </span>
                </button>
            </form>
        </div>

    <?php endif; ?>
</div><!-- /.obs-block -->

<script>
jQuery(document).ready(function($) {
    // Tab switching — keeps .lem-tab-btn / .lem-tab-panel hooks intact
    $('.lem-tab-btn').on('click', function() {
        var $btn   = $(this);
        var tabId  = $btn.data('tab');
        var $block = $btn.closest('.lem-event-ticket-block');
        $block.find('.lem-tab-btn').removeClass('obs-tab--active lem-tab-btn--active').attr('aria-selected', 'false');
        $btn.addClass('obs-tab--active lem-tab-btn--active').attr('aria-selected', 'true');
        $block.find('.lem-tab-panel').addClass('lem-tab-panel--hidden');
        $block.find('#lem-panel-' + tabId).removeClass('lem-tab-panel--hidden');
    });

    function showMsg(container, msg, type) {
        container.removeClass('obs-notice--success obs-notice--error');
        container.addClass(type === 'error' ? 'obs-notice--error' : 'obs-notice--success');
        container.addClass('obs-notice').text(msg).fadeIn(200);
    }

    $('.lem-ticket-button[data-event-id]').on('click', function() {
        var btn     = $(this);
        var eventId = btn.data('event-id');
        var isFree  = btn.data('is-free') === 'true';
        var priceId = btn.data('price-id');
        var email   = $('#lem-email-' + eventId).val().trim();
        var msgBox  = btn.closest('.lem-event-ticket-block').find('.lem-message-container');

        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            msgBox.show(); showMsg(msgBox, 'Please enter a valid email address.', 'error');
            $('#lem-email-' + eventId).addClass('obs-input--error').focus();
            return;
        }
        $('#lem-email-' + eventId).removeClass('obs-input--error');
        msgBox.hide();
        btn.prop('disabled', true);
        btn.find('.lem-button-text').hide();
        btn.find('.lem-button-loading').show();

        if (isFree) {
            $.ajax({
                url: lem_ajax.ajax_url, type: 'POST',
                data: { action: 'lem_free_event_access', email: email, event_id: eventId, nonce: lem_ajax.nonce },
                success: function(r) {
                    if (r.success) {
                        showMsg(msgBox, r.data.message || 'Check your email for your magic link.', 'success');
                        msgBox.show();
                        $('#lem-email-' + eventId).val('');
                        if (r.data && r.data.watch_url) { setTimeout(function() { window.location.href = r.data.watch_url; }, 2000); }
                    } else { msgBox.show(); showMsg(msgBox, r.data || 'Something went wrong. Try again.', 'error'); }
                },
                error: function() { msgBox.show(); showMsg(msgBox, 'Network error. Please try again.', 'error'); },
                complete: function() { btn.prop('disabled', false); btn.find('.lem-button-loading').hide(); btn.find('.lem-button-text').show(); }
            });
        } else {
            if (!priceId) { msgBox.show(); showMsg(msgBox, 'Price not configured. Contact the event organiser.', 'error'); btn.prop('disabled', false); btn.find('.lem-button-loading').hide(); btn.find('.lem-button-text').show(); return; }
            $.ajax({
                url: lem_ajax.ajax_url, type: 'POST',
                data: { action: 'lem_create_stripe_session', event_id: eventId, email: email, price_id: priceId, nonce: lem_ajax.nonce },
                success: function(r) {
                    if (r.success && r.data && r.data.checkout_url) { window.location.href = r.data.checkout_url; }
                    else { msgBox.show(); showMsg(msgBox, r.data || 'Unable to start checkout. Try again.', 'error'); }
                },
                error: function() { msgBox.show(); showMsg(msgBox, 'Network error. Please try again.', 'error'); },
                complete: function() { btn.prop('disabled', false); btn.find('.lem-button-loading').hide(); btn.find('.lem-button-text').show(); }
            });
        }
    });
});
</script>
