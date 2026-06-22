<?php
/**
 * Event Ticket Block / Paywall Template
 *
 * ── Template API ──────────────────────────────────────────────────────────────
 * This file can be overridden by a custom template pack. Copy it into your pack
 * as `{slug}/event-ticket-block.php` and all variables listed below will be in scope.
 *
 * Variables available:
 *
 *   object $event {
 *       int    event_id      WP post ID
 *       string title         Event post title
 *       string event_date    ISO 8601 start datetime
 *       bool   is_free       true for free events
 *       string price_id      Stripe Price ID (paid events only)
 *       string playback_id   Provider playback ID
 *       string post_status   WP post status ('publish', 'draft', etc.)
 *   }
 *   array  $event_access {
 *       bool   can_watch        User has a valid session and JWT
 *       string jwt_token        Mux (or provider) signed JWT for the player
 *       string session_id       Active session cookie value
 *       object|null event       Event post object
 *       string error_message    Human-readable error, or empty string
 *       string success_message  Human-readable success notice, or empty string
 *       string email            Viewer email (when available)
 *   }
 *   string $button_text           Block attribute — CTA button label
 *   string $email_placeholder     Block attribute — email input placeholder
 *   bool   $show_price            Block attribute — whether to display price
 *   string $theme                 Block attribute — 'dark' | 'light'
 *   string $size                  Block attribute — 'small' | 'large'
 *   bool   $show_event_details    Block attribute — show title/date section
 *
 * AJAX endpoints used by this template:
 *   lem_free_event_access     Free access — POST email, event_id, nonce
 *   lem_create_stripe_session Paid access — POST event_id, email, price_id, nonce
 *   lem_request_new_link      Resend magic link — POST with _lem_nonce nonce
 * ─────────────────────────────────────────────────────────────────────────────
 */

if (!defined('ABSPATH')) {
    exit;
}

$event_id      = $event->event_id;
$event_title   = $event->title;
$event_date    = $event->event_date;
$is_free       = $event->is_free;
$price_id      = $event->price_id;
$playback_id   = $event->playback_id;
$poster_url    = get_the_post_thumbnail_url($event_id, 'large') ?: '';

$event_timestamp = $event_date ? strtotime($event_date) : false;
$formatted_date  = $event_timestamp ? date('F j, Y', $event_timestamp) : '';
$formatted_time  = $event_timestamp ? date('g:i A', $event_timestamp) : '';

$price_display = '';
if ($show_price && !$is_free && !empty($price_id)) {
    $price_display = __('Paid Event', 'live-event-manager');
} elseif ($show_price && $is_free) {
    $price_display = __('Free Event', 'live-event-manager');
}

$event_access     = $event_access ?? array();
$success_message  = $event_access['success_message'] ?? '';
$error_message    = $event_access['error_message'] ?? '';
$jwt_token        = $event_access['jwt_token'] ?? '';
$session_id       = $event_access['session_id'] ?? '';
$can_watch_stream = !empty($event_access['can_watch']) && !empty($jwt_token) && !empty($playback_id);
?>

<script>
window.lemWatchContext = 'event';
window.lemWatchHasAccess = <?php echo $can_watch_stream ? 'true' : 'false'; ?>;
window.lemWatchEventId = <?php echo (int) $event_id; ?>;
<?php if ($can_watch_stream && $jwt_token): ?>
window.lemInitialJwt = <?php echo wp_json_encode($jwt_token); ?>;
<?php endif; ?>
<?php if ($session_id): ?>
window.lemSessionId = <?php echo wp_json_encode($session_id); ?>;
<?php endif; ?>
</script>

<div class="lem-event-ticket-block lem-theme-<?php echo esc_attr($theme); ?> lem-size-<?php echo esc_attr($size); ?>" data-event-id="<?php echo esc_attr($event_id); ?>">

    <?php if ($can_watch_stream): ?>
        <div class="lem-section lem-text-center">
            <h1><?php echo esc_html($event_title); ?></h1>
            <p class="lem-lede"><?php esc_html_e('You have access. Press play to join the stream.', 'live-event-manager'); ?></p>
        </div>

        <div class="lem-section lem-video">
            <mux-player
                playback-id="<?php echo esc_attr($playback_id); ?>"
                playback-token="<?php echo esc_attr($jwt_token); ?>"
                <?php echo $poster_url ? 'poster="' . esc_url($poster_url) . '"' : ''; ?>
                metadata-video-title="<?php echo esc_attr($event_title); ?>"
                metadata-viewer-user-id="<?php echo esc_attr($event_id); ?>"
                accent-color="#7f5af0"
                style="width:100%;max-height:70vh;"
            ></mux-player>
        </div>

        <div class="lem-section lem-stack">
            <h2><?php esc_html_e('Need a new link?', 'live-event-manager'); ?></h2>
            <p><?php esc_html_e('Switching devices or lost the email? Reissue your magic link in seconds.', 'live-event-manager'); ?></p>
            <form method="post" class="lem-resend-form" style="max-width: 420px; margin:0 auto;" data-event-id="<?php echo esc_attr($event_id); ?>">
                <?php wp_nonce_field('lem_request_new_link', 'lem_new_link_nonce'); ?>
                <label for="lem_email_<?php echo esc_attr($event_id); ?>" class="lem-form-label"><?php esc_html_e('Email address', 'live-event-manager'); ?></label>
                <input type="email" id="lem_email_<?php echo esc_attr($event_id); ?>" name="email" class="lem-input lem-resend-email" placeholder="you@example.com" required>
                <input type="hidden" name="lem_event_id" value="<?php echo esc_attr($event_id); ?>">
                <div class="lem-resend-message" style="display:none;margin:.75rem 0;"></div>
                <p><button type="submit" name="lem_request_new_link" class="lem-button lem-button-primary"><?php esc_html_e('Send me a fresh link', 'live-event-manager'); ?></button></p>
            </form>
        </div>

        <div class="lem-section lem-text-center">
            <a href="<?php echo esc_url(home_url('/events')); ?>" class="lem-button lem-button-secondary"><?php esc_html_e('Explore more events', 'live-event-manager'); ?></a>
        </div>
    <?php else: ?>

        <!-- ── Tab navigation ──────────────────────────────────────────── -->
        <div class="lem-tabs" role="tablist">
            <button class="lem-tab-btn lem-tab-btn--active"
                    role="tab"
                    aria-selected="true"
                    aria-controls="lem-panel-join-<?php echo esc_attr($event_id); ?>"
                    data-tab="join-<?php echo esc_attr($event_id); ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="5,3 19,12 5,21"></polygon></svg>
                <?php echo $is_free ? esc_html__('Join Free', 'live-event-manager') : esc_html__('Buy Ticket', 'live-event-manager'); ?>
            </button>
            <button class="lem-tab-btn"
                    role="tab"
                    aria-selected="false"
                    aria-controls="lem-panel-resend-<?php echo esc_attr($event_id); ?>"
                    data-tab="resend-<?php echo esc_attr($event_id); ?>">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                <?php esc_html_e('Already Purchased?', 'live-event-manager'); ?>
            </button>
        </div>

        <!-- ── Tab panel: Join / Buy ────────────────────────────────────── -->
        <div class="lem-tab-panel"
             id="lem-panel-join-<?php echo esc_attr($event_id); ?>"
             role="tabpanel">

            <?php if (!empty($success_message)): ?>
                <div class="lem-message lem-message-success"><?php echo esc_html($success_message); ?></div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="lem-message lem-message-error"><?php echo esc_html($error_message); ?></div>
            <?php endif; ?>

            <div class="lem-input-icon-wrap">
                <svg class="lem-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                    <polyline points="22,6 12,13 2,6"></polyline>
                </svg>
                <input type="email"
                       id="lem-email-<?php echo esc_attr($event_id); ?>"
                       class="lem-email-input"
                       placeholder="<?php echo esc_attr($email_placeholder); ?>"
                       autocomplete="email"
                       required>
            </div>

            <div class="lem-message-container" style="display:none;"></div>

            <button type="button"
                    class="lem-ticket-button"
                    data-event-id="<?php echo esc_attr($event_id); ?>"
                    data-is-free="<?php echo $is_free ? 'true' : 'false'; ?>"
                    data-price-id="<?php echo esc_attr($price_id); ?>"
                    data-button-text="<?php echo esc_attr($button_text); ?>">
                <span class="lem-button-text">
                    <?php if (!$is_free): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><polygon points="5,3 19,12 5,21"></polygon></svg>
                    <?php endif; ?>
                    <?php echo esc_html($button_text); ?>
                </span>
                <span class="lem-button-loading" style="display:none;">
                    <svg class="lem-spinner" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"></circle></svg>
                </span>
            </button>

            <?php if (!$is_free): ?>
                <div class="lem-stripe-badge">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <?php esc_html_e('Secure checkout powered by', 'live-event-manager'); ?>
                    <strong style="letter-spacing:0.04em;font-style:italic;">Stripe</strong>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Tab panel: Already Purchased ─────────────────────────────── -->
        <div class="lem-tab-panel lem-tab-panel--hidden"
             id="lem-panel-resend-<?php echo esc_attr($event_id); ?>"
             role="tabpanel">

            <p class="lem-resend-hint">
                <?php esc_html_e('Enter the email address you used when purchasing. We\'ll send a fresh magic link straight to your inbox — no password needed.', 'live-event-manager'); ?>
            </p>

            <?php if (!empty($success_message)): ?>
                <div class="lem-message lem-message-success"><?php echo esc_html($success_message); ?></div>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <div class="lem-message lem-message-error"><?php echo esc_html($error_message); ?></div>
            <?php endif; ?>

            <form method="post" class="lem-resend-form" data-event-id="<?php echo esc_attr($event_id); ?>">
                <?php wp_nonce_field('lem_request_new_link', 'lem_new_link_nonce'); ?>
                <div class="lem-resend-message" style="display:none;margin-bottom:.75rem;"></div>
                <div class="lem-input-icon-wrap">
                    <svg class="lem-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                        <polyline points="22,6 12,13 2,6"></polyline>
                    </svg>
                    <input type="email" name="email" class="lem-email-input lem-resend-email" placeholder="you@example.com" autocomplete="email" required>
                </div>
                <input type="hidden" name="lem_event_id" value="<?php echo esc_attr($event_id); ?>">
                <button type="submit" name="lem_request_new_link" class="lem-ticket-button" style="margin-top:0.75rem;">
                    <span class="lem-button-text">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22,2 15,22 11,13 2,9"></polygon></svg>
                        <?php esc_html_e('Send Magic Link', 'live-event-manager'); ?>
                    </span>
                </button>
            </form>
        </div>

    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    // ── Tab switching ──────────────────────────────────────────────────────
    $('.lem-tab-btn').on('click', function() {
        const $btn    = $(this);
        const tabId   = $btn.data('tab');
        const $block  = $btn.closest('.lem-event-ticket-block');

        // Update button states
        $block.find('.lem-tab-btn').removeClass('lem-tab-btn--active').attr('aria-selected', 'false');
        $btn.addClass('lem-tab-btn--active').attr('aria-selected', 'true');

        // Show / hide panels
        $block.find('.lem-tab-panel').addClass('lem-tab-panel--hidden');
        $block.find('#lem-panel-' + tabId).removeClass('lem-tab-panel--hidden');
    });

    // ── Helpers ────────────────────────────────────────────────────────────
    function showMessage(container, message, type) {
        const classes = ['lem-message-success', 'lem-message-error'];
        container.removeClass(classes.join(' '));
        container.addClass(type === 'error' ? 'lem-message-error' : 'lem-message-success');
        container.text(message).fadeIn(200);
    }

    function isValidEmail(email) {
        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    }

    // ── Join / Buy button (only the one with data-event-id) ───────────────
    $('.lem-ticket-button[data-event-id]').on('click', function() {
        const button = $(this);
        const eventId = button.data('event-id');
        const isFree = button.data('is-free') === 'true';
        const priceId = button.data('price-id');
        const emailInput = $('#lem-email-' + eventId);
        const email = emailInput.val().trim();
        const messageContainer = button.closest('.lem-event-ticket-block').find('.lem-message-container');

        if (!email || !isValidEmail(email)) {
            messageContainer.show();
            showMessage(messageContainer, '<?php echo esc_js(__('Please enter a valid email address.', 'live-event-manager')); ?>', 'error');
            emailInput.addClass('lem-error');
            emailInput.focus();
            return;
        }

        emailInput.removeClass('lem-error');
        messageContainer.hide();

        button.prop('disabled', true);
        button.find('.lem-button-text').hide();
        button.find('.lem-button-loading').show();

        if (isFree) {
            $.ajax({
                url: lem_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'lem_free_event_access',
                    email: email,
                    event_id: eventId,
                    nonce: lem_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const message = response.data && response.data.message ? response.data.message : 'Access granted! Check your email for the magic link.';
                        showMessage(messageContainer, message, 'success');
                        
                        // Clear email input
                        emailInput.val('');
                        
                        // If watch URL is provided, redirect after a short delay
                        if (response.data && response.data.watch_url) {
                            setTimeout(function() {
                                window.location.href = response.data.watch_url;
                            }, 2000);
                        }
                    } else {
                        const message = response.data && response.data.message ? response.data.message : response.data;
                        showMessage(messageContainer, message || '<?php echo esc_js(__('Unable to generate access. Please try again.', 'live-event-manager')); ?>', 'error');
                    }
                },
                error: function() {
                    showMessage(messageContainer, '<?php echo esc_js(__('Network error. Please try again.', 'live-event-manager')); ?>', 'error');
                },
                complete: function() {
                    button.prop('disabled', false);
                    button.find('.lem-button-loading').hide();
                    button.find('.lem-button-text').show();
                }
            });
        } else {
            // Only require price ID for paid events (isFree is already declared above)
            if (!isFree && !priceId) {
                showMessage(messageContainer, '<?php echo esc_js(__('Price ID not configured. Please contact support.', 'live-event-manager')); ?>', 'error');
                button.prop('disabled', false);
                button.find('.lem-button-loading').hide();
                button.find('.lem-button-text').show();
                return;
            }

            $.ajax({
                url: lem_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'lem_create_stripe_session',
                    event_id: eventId,
                    email: email,
                    price_id: priceId,
                    nonce: lem_ajax.nonce
                },
                success: function(response) {
                    if (response.success && response.data && response.data.checkout_url) {
                        window.location.href = response.data.checkout_url;
                    } else {
                        const message = response.data && response.data.message ? response.data.message : response.data;
                        showMessage(messageContainer, message || '<?php echo esc_js(__('Unable to start Stripe checkout. Please try again.', 'live-event-manager')); ?>', 'error');
                    }
                },
                error: function() {
                    showMessage(messageContainer, '<?php echo esc_js(__('Network error. Please try again.', 'live-event-manager')); ?>', 'error');
                },
                complete: function() {
                    button.prop('disabled', false);
                    button.find('.lem-button-loading').hide();
                    button.find('.lem-button-text').show();
                }
            });
        }
    });
});
</script> 