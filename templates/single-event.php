<?php
/**
 * Single event watch page — Twitch-style layout
 * Bypasses theme header/footer for a full-screen app shell.
 *
 * ── Template API ──────────────────────────────────────────────────────────────
 * This file can be overridden by a custom template pack. Copy it into your pack
 * as `{slug}/single-event.php` and all variables listed below will be in scope.
 *
 * Variables available:
 *
 *   global LiveEventManager $live_event_manager  Main plugin instance
 *   int    $event_id         WP post ID of the current event
 *   string $event_date       ISO 8601 event start datetime (post meta _lem_event_date)
 *   bool   $is_free          true when the event requires no payment
 *   string $playback_id      Provider playback ID (Mux playback ID, OME stream key, etc.)
 *   string $poster_url       Featured image URL, or empty string
 *   string $event_summary    Short description (post meta _lem_excerpt)
 *   array  $event_access {
 *       bool   can_watch        User has a valid session and JWT
 *       string jwt_token        Mux (or provider) signed JWT for the player
 *       string session_id       Active session cookie value
 *       object|null event       Event post object
 *       string error_message    Human-readable error, or empty string
 *       string success_message  Human-readable success notice, or empty string
 *       string email            Viewer email (when available)
 *   }
 *   bool   $can_watch        Shorthand: has access AND has jwt_token AND has playback_id
 *
 * Helper methods on $live_event_manager:
 *   render_event_ticket_block([])  Renders the paywall/ticket block HTML
 *   get_event_access_state($id)    Re-checks access state (already cached)
 * ─────────────────────────────────────────────────────────────────────────────
 */

// Standalone HTML — avoids the theme header rendering above our nav
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html(get_the_title() . ' — ' . get_bloginfo('name')); ?></title>
<?php wp_head(); ?>
</head>
<body <?php body_class('lem-fullscreen-event'); ?>>
<?php

global $live_event_manager;

$event_id      = get_the_ID();
$event_date    = get_post_meta($event_id, '_lem_event_date', true);
$is_free       = get_post_meta($event_id, '_lem_is_free', true);
$playback_id   = get_post_meta($event_id, '_lem_playback_id', true);
$poster_url    = get_the_post_thumbnail_url($event_id, 'large') ?: '';
$event_summary = get_post_meta($event_id, '_lem_excerpt', true)
    ?: wp_trim_words(strip_tags(get_the_content(null, false, $event_id)), 40);

$event_access = [];
$can_watch    = false;
if ($live_event_manager) {
    $event_access = $live_event_manager->get_event_access_state($event_id);
    $can_watch    = !empty($event_access['can_watch'])
                 && !empty($event_access['jwt_token'])
                 && !empty($playback_id);
}

$formatted_date = '';
$formatted_time = '';
if ($event_date) {
    $ts             = strtotime($event_date);
    $formatted_date = date('F j, Y', $ts);
    $formatted_time = date('g:i A T', $ts);
}

// Chat display name: server-generated per session (Redis), then email prefix, then fallback.
$viewer_email        = $event_access['email'] ?? '';
$viewer_display_name = ! empty( $event_access['chat_name'] )
    ? $event_access['chat_name']
    : ( ! empty( $viewer_email ) ? ucfirst( strtolower( strstr( $viewer_email, '@', true ) ) ) : 'Viewer' );
?>

<div class="lem-live-events-app lem-watch-app">

    <!-- ── Top Nav ──────────────────────────────────────────────────────────── -->
    <nav class="lem-top-nav">
        <div class="lem-nav-left">
            <div class="lem-logo">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 18V5l12-2v13"></path>
                    <circle cx="6" cy="18" r="3"></circle>
                    <circle cx="18" cy="16" r="3"></circle>
                </svg>
                <span>LiveEvents</span>
            </div>
        </div>
        <div class="lem-nav-center">
            <a href="<?php echo esc_url(home_url('/events')); ?>" class="lem-nav-link">Events</a>
        </div>
        <div class="lem-nav-right">
            
        </div>
    </nav>

    <?php if ($can_watch): ?>

        <!-- ── Watch layout ───────────────────────────────────────────────── -->
        <div class="lem-watch-layout">

            <!-- ── Left: player + info ──────────────────────────────────── -->
            <div class="lem-player-col">

                <!-- Player -->
                <div class="lem-player-wrap">
                    <mux-player
                        playback-id="<?php echo esc_attr($playback_id); ?>"
                        playback-token="<?php echo esc_attr($event_access['jwt_token']); ?>"
                        stream-type="live"
                        <?php echo $poster_url ? 'poster="' . esc_url($poster_url) . '"' : ''; ?>
                        metadata-video-title="<?php echo esc_attr(get_the_title()); ?>"
                        accent-color="#7f5af0"
                        style="width:100%;display:block;"
                    ></mux-player>
                </div>

                <!-- Stream info strip -->
                <div class="lem-stream-info">
                    <div class="lem-stream-info-main">
                        <h1 class="lem-stream-title"><?php echo esc_html(get_the_title()); ?></h1>
                        <?php if ($formatted_date): ?>
                            <span class="lem-stream-date">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                <?php echo esc_html($formatted_date . ' · ' . $formatted_time); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($event_summary)): ?>
                        <p class="lem-stream-desc"><?php echo esc_html($event_summary); ?></p>
                    <?php endif; ?>
                    <?php if (!empty(get_the_content())): ?>
                        <div class="lem-stream-body"><?php the_content(); ?></div>
                    <?php endif; ?>
                </div>

            </div><!-- /.lem-player-col -->

            <!-- ── Right: chat ──────────────────────────────────────────── -->
            <aside class="lem-chat-col" id="lem-chat-col">

                <div class="lem-chat-header">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                    Stream Chat
                </div>

                <div class="lem-chat-messages" id="lem-chat-messages" aria-live="polite">
                    <div class="lem-chat-empty" id="lem-chat-empty">
                        Chat starts when someone sends a message.
                    </div>
                </div>

                <div class="lem-chat-footer">
                    <div class="lem-chat-input-wrap">
                        <input
                            type="text"
                            id="lem-chat-input"
                            class="lem-chat-input-field"
                            placeholder="Send a message…"
                            maxlength="300"
                            autocomplete="off"
                        >
                        <button id="lem-chat-send" class="lem-chat-send-btn" aria-label="Send">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                        </button>
                    </div>
                    <p class="lem-chat-rules">Be kind and keep it on-topic.</p>
                </div>

            </aside>

        </div><!-- /.lem-watch-layout -->

        <script>
        // Lock body scroll for the player layout (marketing page must remain scrollable)
        document.body.classList.add('lem-watch-mode');

        // Pass context to JS.
        // Security note: lemInitialJwt is exposed as a window global so the
        // Mux player and token-refresh logic in public.js can read it.  The
        // JWT is short-lived, scoped to a single playback ID, and already
        // present in the DOM as a <mux-player> attribute, so the additional
        // exposure via a global is acceptable.  If you move to a server-side
        // token proxy in the future, this global can be removed.
        window.lemWatchContext    = 'event';
        window.lemWatchHasAccess  = true;
        window.lemWatchEventId    = <?php echo (int) $event_id; ?>;
        window.lemInitialJwt      = <?php echo wp_json_encode($event_access['jwt_token']); ?>;
        window.lemViewerName      = <?php echo wp_json_encode($viewer_display_name); ?>;
        window.lemSessionId       = <?php echo wp_json_encode($event_access['session_id'] ?? ''); ?>;
        window.lemAjaxUrl         = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        window.lemNonce           = <?php echo wp_json_encode(wp_create_nonce('lem_nonce')); ?>;
        window.lemVendor               = <?php echo wp_json_encode(get_option('lem_settings', [])['streaming_provider'] ?? 'ome'); ?>;
        window.lemTokenRefreshEnabled  = <?php
            $factory  = LEM_Streaming_Provider_Factory::get_instance();
            $provider = $factory->get_active_provider($live_event_manager);
            echo ($provider && $provider->supports_token_refresh()) ? 'true' : 'false';
        ?>;
        </script>

    <?php else: ?>

        <!-- ── Marketing / paywall gate ──────────────────────────────────── -->
        <div class="lem-marketing-page">
            <?php
            // PayPal capture handler redirects here with ?lem_payment=processing or ?lem_payment_error=1
            $lem_pay_return = isset($_GET['lem_payment']) ? sanitize_key(wp_unslash($_GET['lem_payment'])) : '';
            $lem_pay_err    = isset($_GET['lem_payment_error']);
            if ($lem_pay_err) :
                ?>
            <div class="lem-payment-return-notice lem-payment-return-notice--error" role="alert" style="margin:12px 18px 0;padding:12px 16px;border-radius:8px;background:#fcf0f1;border:1px solid #d63638;color:#1d2327;font-size:14px;line-height:1.45;">
                <?php esc_html_e('Payment could not be confirmed. Please try again or use another payment method.', 'live-event-manager'); ?>
            </div>
            <?php elseif ($lem_pay_return === 'processing') :
                $paypal_order = isset($_GET['paypal_order']) ? sanitize_text_field(wp_unslash($_GET['paypal_order'])) : '';
                ?>
            <div class="lem-payment-return-notice lem-payment-return-notice--ok" role="status" style="margin:12px 18px 0;padding:12px 16px;border-radius:8px;background:#edfaed;border:1px solid #46b450;color:#1d2327;font-size:14px;line-height:1.45;">
                <?php esc_html_e('Payment received. Confirming your access now — check your email for your magic link.', 'live-event-manager'); ?>
            </div>
            <?php if ($paypal_order !== '') : ?>
            <script>
            window.lemPaymentReconcile = {
                session_id: <?php echo wp_json_encode($paypal_order); ?>,
                provider_id: 'paypal',
                event_id: <?php echo wp_json_encode((string) get_the_ID()); ?>
            };
            </script>
            <?php endif; ?>
            <?php endif; ?>
            <div class="lem-marketing-hero">
                <h1 class="lem-marketing-title"><?php echo esc_html(get_the_title()); ?></h1>
                <?php if ($formatted_date): ?>
                    <p class="lem-marketing-date">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <?php echo esc_html($formatted_date . ' · ' . $formatted_time . ' UTC'); ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($event_summary)): ?>
                    <p class="lem-marketing-description"><?php echo esc_html($event_summary); ?></p>
                <?php endif; ?>
                <?php if ($poster_url): ?>
                    <div class="lem-marketing-image">
                        <img src="<?php echo esc_url($poster_url); ?>" alt="<?php echo esc_attr(get_the_title()); ?>">
                    </div>
                <?php endif; ?>
            </div>

            <div class="lem-marketing-section">
                <div class="lem-marketing-card">
                    <?php if ($live_event_manager): ?>
                        <?php echo $live_event_manager->render_event_ticket_block([]); ?>
                    <?php endif; ?>
                </div>

                <div class="lem-marketing-features">
                    <div class="lem-feature">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                        <h3>Live Chat</h3>
                        <p>Interact with other viewers in real-time</p>
                    </div>
                    <div class="lem-feature">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"></polygon><rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect></svg>
                        <h3>HD Streaming</h3>
                        <p>Adaptive bitrate, works on any connection</p>
                    </div>
                    <div class="lem-feature">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                        <h3>Any Device</h3>
                        <p>Desktop, tablet, or mobile</p>
                    </div>
                    <div class="lem-feature">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                        <h3>Secure Access</h3>
                        <p>Protected by signed JWT tokens</p>
                    </div>
                </div>

                <?php if (!empty(get_the_content())): ?>
                    <div class="lem-marketing-details">
                        <h2>About This Event</h2>
                        <div class="lem-content"><?php the_content(); ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

</div><!-- /.lem-live-events-app -->

<?php wp_footer(); ?>
</body>
</html>
