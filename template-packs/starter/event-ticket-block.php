<?php
/**
 * Starter Template — event-ticket-block.php
 *
 * The paywall / ticket purchase block. Rendered via render_event_ticket_block()
 * both from the Gutenberg block and directly from single-event.php.
 *
 * ── All variables in scope ────────────────────────────────────────────────
 *   object $event { event_id, title, event_date, is_free, price_id, playback_id, post_status }
 *   array  $event_access { can_watch, jwt_token, session_id, error_message, success_message, email }
 *   string $button_text           CTA label
 *   string $email_placeholder     Input placeholder
 *   bool   $show_price
 *   string $theme                 'dark'|'light'
 *   string $size                  'small'|'large'
 *   bool   $show_event_details
 *
 * ── Required element hooks (public.js + inline JS use these) ─────────────
 *   .lem-event-ticket-block[data-event-id]   outer wrapper
 *   #lem-email-{event_id}                    email input
 *   .lem-ticket-button[data-event-id]        CTA button (with data-is-free, data-price-id)
 *   .lem-message-container                   AJAX message area
 *   .lem-button-text / .lem-button-loading   button state spans
 *   .lem-tab-btn[data-tab]                   tab buttons
 *   .lem-tab-panel / .lem-tab-panel--hidden  tab panels
 * ─────────────────────────────────────────────────────────────────────────
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

$event_access    = $event_access ?? [];
$success_message = $event_access['success_message'] ?? '';
$error_message   = $event_access['error_message'] ?? '';
$jwt_token       = $event_access['jwt_token'] ?? '';
$session_id      = $event_access['session_id'] ?? '';
$can_watch_stream = !empty($event_access['can_watch']) && !empty($jwt_token) && !empty($playback_id);
?>

<script>
window.lemWatchContext   = 'event';
window.lemWatchHasAccess = <?php echo $can_watch_stream ? 'true' : 'false'; ?>;
window.lemWatchEventId   = <?php echo (int) $event_id; ?>;
<?php if ($can_watch_stream && $jwt_token): ?>
window.lemInitialJwt  = <?php echo wp_json_encode($jwt_token); ?>;
<?php endif; ?>
<?php if ($session_id): ?>
window.lemSessionId = <?php echo wp_json_encode($session_id); ?>;
<?php endif; ?>
</script>

<div class="lem-event-ticket-block" data-event-id="<?php echo esc_attr($event_id); ?>"
     style="font-family:system-ui,sans-serif;max-width:420px;margin:0 auto;">

    <?php if ($can_watch_stream): ?>
        <h2><?php echo esc_html($event_title); ?></h2>
        <p>You have access — press play to join.</p>
        <mux-player
            playback-id="<?php echo esc_attr($playback_id); ?>"
            playback-token="<?php echo esc_attr($jwt_token); ?>"
            style="width:100%;"
        ></mux-player>

        <!-- Resend link form -->
        <form method="post" class="lem-resend-form" data-event-id="<?php echo esc_attr($event_id); ?>" style="margin-top:1.5rem;">
            <?php wp_nonce_field('lem_request_new_link', 'lem_new_link_nonce'); ?>
            <input type="hidden" name="lem_event_id" value="<?php echo esc_attr($event_id); ?>">
            <div class="lem-resend-message" style="display:none;margin-bottom:.5rem;"></div>
            <input type="email" name="email" class="lem-resend-email" placeholder="your@email.com" required style="width:100%;padding:.5rem;margin-bottom:.5rem;">
            <button type="submit" name="lem_request_new_link">Send me a fresh link</button>
        </form>

    <?php else: ?>

        <!-- Tabs -->
        <div class="lem-tabs" style="display:flex;gap:.5rem;margin-bottom:1rem;">
            <button class="lem-tab-btn lem-tab-btn--active"
                    data-tab="join-<?php echo esc_attr($event_id); ?>"
                    aria-controls="lem-panel-join-<?php echo esc_attr($event_id); ?>"
                    aria-selected="true" role="tab">
                <?php echo $is_free ? 'Join Free' : 'Buy Ticket'; ?>
            </button>
            <button class="lem-tab-btn"
                    data-tab="resend-<?php echo esc_attr($event_id); ?>"
                    aria-controls="lem-panel-resend-<?php echo esc_attr($event_id); ?>"
                    aria-selected="false" role="tab">
                Already purchased?
            </button>
        </div>

        <!-- Join / Buy panel -->
        <div class="lem-tab-panel" id="lem-panel-join-<?php echo esc_attr($event_id); ?>" role="tabpanel">
            <?php if ($success_message): ?><p style="color:green;"><?php echo esc_html($success_message); ?></p><?php endif; ?>
            <?php if ($error_message):   ?><p style="color:red;"><?php echo esc_html($error_message); ?></p><?php endif; ?>

            <input type="email"
                   id="lem-email-<?php echo esc_attr($event_id); ?>"
                   placeholder="<?php echo esc_attr($email_placeholder); ?>"
                   style="width:100%;padding:.6rem;margin-bottom:.75rem;"
                   required autocomplete="email">

            <div class="lem-message-container" style="display:none;margin-bottom:.75rem;"></div>

            <button class="lem-ticket-button"
                    data-event-id="<?php echo esc_attr($event_id); ?>"
                    data-is-free="<?php echo $is_free ? 'true' : 'false'; ?>"
                    data-price-id="<?php echo esc_attr($price_id); ?>"
                    data-button-text="<?php echo esc_attr($button_text); ?>"
                    style="width:100%;padding:.7rem;background:#6366f1;color:#fff;border:none;border-radius:4px;cursor:pointer;">
                <span class="lem-button-text"><?php echo esc_html($button_text); ?></span>
                <span class="lem-button-loading" style="display:none;">Loading…</span>
            </button>
        </div>

        <!-- Resend panel (hidden by default) -->
        <div class="lem-tab-panel lem-tab-panel--hidden" id="lem-panel-resend-<?php echo esc_attr($event_id); ?>" role="tabpanel">
            <p>Enter the email you used when purchasing and we'll resend your magic link.</p>
            <form method="post" class="lem-resend-form" data-event-id="<?php echo esc_attr($event_id); ?>">
                <?php wp_nonce_field('lem_request_new_link', 'lem_new_link_nonce'); ?>
                <input type="hidden" name="lem_event_id" value="<?php echo esc_attr($event_id); ?>">
                <div class="lem-resend-message" style="display:none;margin-bottom:.75rem;"></div>
                <input type="email" name="email" class="lem-resend-email" placeholder="your@email.com" required
                       style="width:100%;padding:.6rem;margin-bottom:.75rem;">
                <button type="submit" name="lem_request_new_link"
                        class="lem-ticket-button"
                        style="width:100%;padding:.7rem;background:#6366f1;color:#fff;border:none;border-radius:4px;cursor:pointer;">
                    <span class="lem-button-text">Send Magic Link</span>
                    <span class="lem-button-loading" style="display:none;">Sending…</span>
                </button>
            </form>
        </div>

    <?php endif; ?>
</div>

<?php /* Inline JS — handles tabs + AJAX. Identical logic to the default template. */ ?>
<script>
jQuery(document).ready(function($) {
    // Tab switching
    $('.lem-tab-btn').on('click', function() {
        var $btn   = $(this);
        var tabId  = $btn.data('tab');
        var $block = $btn.closest('.lem-event-ticket-block');
        $block.find('.lem-tab-btn').removeClass('lem-tab-btn--active').attr('aria-selected', 'false');
        $btn.addClass('lem-tab-btn--active').attr('aria-selected', 'true');
        $block.find('.lem-tab-panel').addClass('lem-tab-panel--hidden');
        $block.find('#lem-panel-' + tabId).removeClass('lem-tab-panel--hidden');
    });

    function showMsg(container, msg, type) {
        container.removeClass('lem-message-success lem-message-error');
        container.addClass(type === 'error' ? 'lem-message-error' : 'lem-message-success');
        container.text(msg).fadeIn(200);
    }

    // CTA button
    $('.lem-ticket-button[data-event-id]').on('click', function() {
        var btn     = $(this);
        var eventId = btn.data('event-id');
        var isFree  = btn.data('is-free') === 'true';
        var priceId = btn.data('price-id');
        var email   = $('#lem-email-' + eventId).val().trim();
        var msgBox  = btn.closest('.lem-event-ticket-block').find('.lem-message-container');

        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            msgBox.show();
            showMsg(msgBox, 'Please enter a valid email address.', 'error');
            return;
        }
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
                    } else { msgBox.show(); showMsg(msgBox, r.data || 'Something went wrong.', 'error'); }
                },
                error: function() { msgBox.show(); showMsg(msgBox, 'Network error. Please try again.', 'error'); },
                complete: function() { btn.prop('disabled', false); btn.find('.lem-button-loading').hide(); btn.find('.lem-button-text').show(); }
            });
        } else {
            if (!priceId) { msgBox.show(); showMsg(msgBox, 'Price not configured. Contact support.', 'error'); btn.prop('disabled', false); btn.find('.lem-button-loading').hide(); btn.find('.lem-button-text').show(); return; }
            $.ajax({
                url: lem_ajax.ajax_url, type: 'POST',
                data: { action: 'lem_create_stripe_session', event_id: eventId, email: email, price_id: priceId, nonce: lem_ajax.nonce },
                success: function(r) {
                    if (r.success && r.data && r.data.checkout_url) { window.location.href = r.data.checkout_url; }
                    else { msgBox.show(); showMsg(msgBox, r.data || 'Unable to start checkout.', 'error'); }
                },
                error: function() { msgBox.show(); showMsg(msgBox, 'Network error. Please try again.', 'error'); },
                complete: function() { btn.prop('disabled', false); btn.find('.lem-button-loading').hide(); btn.find('.lem-button-text').show(); }
            });
        }
    });
});
</script>
