<?php
/**
 * Obsidian — single-event.php
 * Premium dark cinematic streaming template for LEM.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html(get_the_title() . ' — ' . get_bloginfo('name')); ?></title>
<?php wp_head(); ?>
</head>
<body <?php body_class('lem-fullscreen-event lem-obsidian'); ?>>
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

$viewer_email        = $event_access['email'] ?? '';
$viewer_display_name = ! empty( $event_access['chat_name'] )
    ? $event_access['chat_name']
    : ( $viewer_email ? ucfirst( strtolower( strstr( $viewer_email, '@', true ) ) ) : 'Viewer' );

$ts             = $event_date ? strtotime($event_date) : false;
$formatted_date = $ts ? date('F j, Y', $ts) : '';
$formatted_time = $ts ? date('g:i A T', $ts) : '';
?>

<!-- ── Top nav ──────────────────────────────────────────────────────────── -->
<nav class="obs-nav">
    <div class="obs-nav-brand">
        <svg class="obs-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="5 3 19 12 5 21 5 3"></polygon>
        </svg>
        <span class="obs-nav-site"><?php echo esc_html(get_bloginfo('name')); ?></span>
    </div>
    <div class="obs-nav-mid">
        <span class="obs-nav-title"><?php echo esc_html(get_the_title()); ?></span>
        <?php if ($can_watch): ?>
            <span class="obs-live-badge">
                <span class="obs-live-dot"></span>LIVE
            </span>
        <?php endif; ?>
    </div>
    <div class="obs-nav-end">
        <a href="<?php echo esc_url(home_url('/events')); ?>" class="obs-nav-link">All Events</a>
        <?php if (is_user_logged_in()): ?>
            <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="obs-nav-link">Sign Out</a>
        <?php else: ?>
            <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="obs-nav-link">Sign In</a>
        <?php endif; ?>
    </div>
</nav>

<?php if ($can_watch): ?>

<!-- ── Watch layout ─────────────────────────────────────────────────────── -->
<div class="obs-watch-layout">

    <!-- Player column -->
    <div class="obs-player-col">
        <div class="obs-player-wrap">
            <mux-player
                playback-id="<?php echo esc_attr($playback_id); ?>"
                playback-token="<?php echo esc_attr($event_access['jwt_token']); ?>"
                stream-type="live"
                <?php echo $poster_url ? 'poster="' . esc_url($poster_url) . '"' : ''; ?>
                metadata-video-title="<?php echo esc_attr(get_the_title()); ?>"
                accent-color="#6366f1"
                style="width:100%;display:block;"
            ></mux-player>
        </div>

        <div class="obs-stream-meta">
            <div class="obs-stream-meta-main">
                <h1 class="obs-stream-title"><?php echo esc_html(get_the_title()); ?></h1>
                <?php if ($formatted_date): ?>
                    <span class="obs-stream-date">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?php echo esc_html($formatted_date . '  ·  ' . $formatted_time); ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php if ($event_summary): ?>
                <p class="obs-stream-desc"><?php echo esc_html($event_summary); ?></p>
            <?php endif; ?>
            <?php if (get_the_content()): ?>
                <div class="obs-stream-body"><?php the_content(); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chat column -->
    <aside class="obs-chat-col" id="lem-chat-col">

        <div class="obs-chat-header">
            <div class="obs-chat-header-left">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span>Stream Chat</span>
            </div>
            <span class="obs-viewer-chip"><?php echo esc_html($viewer_display_name); ?></span>
        </div>

        <div class="obs-chat-messages" id="lem-chat-messages" aria-live="polite">
            <div class="obs-chat-empty" id="lem-chat-empty">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="opacity:.35;margin-bottom:.6rem"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span>Chat starts when someone sends a message.</span>
            </div>
        </div>

        <div class="obs-chat-footer">
            <div class="obs-chat-input-row">
                <input
                    type="text"
                    id="lem-chat-input"
                    class="obs-chat-input"
                    placeholder="Send a message…"
                    maxlength="300"
                    autocomplete="off"
                >
                <button id="lem-chat-send" class="obs-chat-send" aria-label="Send">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15">
                        <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                </button>
            </div>
        </div>

    </aside>
</div><!-- /.obs-watch-layout -->

<script>
document.body.classList.add('lem-watch-mode');
window.lemWatchContext    = 'event';
window.lemWatchHasAccess  = true;
window.lemWatchEventId    = <?php echo (int) $event_id; ?>;
window.lemInitialJwt      = <?php echo wp_json_encode($event_access['jwt_token']); ?>;
window.lemViewerName      = <?php echo wp_json_encode($viewer_display_name); ?>;
window.lemSessionId       = <?php echo wp_json_encode($event_access['session_id'] ?? ''); ?>;
window.lemAjaxUrl         = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
window.lemNonce           = <?php echo wp_json_encode(wp_create_nonce('lem_nonce')); ?>;
window.lemVendor               = <?php echo wp_json_encode(get_option('lem_settings', [])['streaming_provider'] ?? 'mux'); ?>;
window.lemTokenRefreshEnabled  = <?php
    $factory  = LEM_Streaming_Provider_Factory::get_instance();
    $provider = $factory->get_active_provider($live_event_manager);
    echo ($provider && $provider->supports_token_refresh()) ? 'true' : 'false';
?>;
</script>

<?php else: ?>

<!-- ── Marketing / paywall gate ─────────────────────────────────────────── -->
<div class="obs-gate-wrap">

    <!-- Hero -->
    <div class="obs-hero">
        <?php if ($poster_url): ?>
            <div class="obs-hero-bg" style="background-image:url('<?php echo esc_url($poster_url); ?>');"></div>
        <?php else: ?>
            <div class="obs-hero-bg obs-hero-bg--gradient"></div>
        <?php endif; ?>
        <div class="obs-hero-overlay"></div>
        <div class="obs-hero-content">
            <p class="obs-eyebrow">
                <?php echo $is_free === 'free' ? 'Free Event' : 'Live Event'; ?>
            </p>
            <h1 class="obs-hero-title"><?php echo esc_html(get_the_title()); ?></h1>
            <?php if ($formatted_date): ?>
                <p class="obs-hero-date">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <?php echo esc_html($formatted_date . '  ·  ' . $formatted_time . ' UTC'); ?>
                </p>
            <?php endif; ?>
            <?php if ($event_summary): ?>
                <p class="obs-hero-summary"><?php echo esc_html($event_summary); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Ticket card + feature strip -->
    <div class="obs-below-hero">
        <div class="obs-ticket-card">
            <?php if ($live_event_manager): ?>
                <?php echo $live_event_manager->render_event_ticket_block([]); ?>
            <?php endif; ?>
        </div>

        <?php if (get_the_content()): ?>
            <div class="obs-about">
                <h2 class="obs-about-heading">About this event</h2>
                <div class="obs-about-body"><?php the_content(); ?></div>
            </div>
        <?php endif; ?>

        <div class="obs-features">
            <?php
            $features = [
                ['icon' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>', 'label' => 'Live Chat',       'desc' => 'Interact in real-time with other viewers'],
                ['icon' => '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>', 'label' => 'HD Streaming', 'desc' => 'Adaptive bitrate, any connection'],
                ['icon' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>', 'label' => 'Any Device', 'desc' => 'Desktop, tablet, or mobile'],
                ['icon' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>', 'label' => 'Secure Access', 'desc' => 'JWT-protected streaming'],
            ];
            foreach ($features as $f): ?>
            <div class="obs-feature">
                <div class="obs-feature-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><?php echo $f['icon']; ?></svg>
                </div>
                <div>
                    <div class="obs-feature-label"><?php echo esc_html($f['label']); ?></div>
                    <div class="obs-feature-desc"><?php echo esc_html($f['desc']); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div><!-- /.obs-gate-wrap -->

<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
