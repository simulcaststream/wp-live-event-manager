<?php
/**
 * Obsidian — page-events.php
 * Premium dark events listing page for LEM.
 *
 * Standalone template (no get_header/get_footer) — dark design renders
 * correctly regardless of the active WordPress theme.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Events — <?php bloginfo('name'); ?></title>
<?php wp_head(); ?>
<style>
/* ── Obsidian events listing — page-events.php ──────────────────────────── */

/* Full-page container */
.lem-obsidian.obs-events-page {
    min-height: 100vh;
    background: var(--obs-bg);
}

/* ── Hero header ─────────────────────────────────────────────────────────── */
.obs-evlist-hero {
    position: relative;
    padding-top: var(--obs-nav-h);
    overflow: hidden;
}
.obs-evlist-hero-bg {
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, #0d0e1f 0%, #1a0b2e 50%, #0a1628 100%);
    z-index: 0;
}
.obs-evlist-hero-bg::after {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 60% 55% at 70% 40%, rgba(99,102,241,0.12) 0%, transparent 70%),
        radial-gradient(ellipse 50% 50% at 20% 70%, rgba(168,85,247,0.08) 0%, transparent 70%);
}
.obs-evlist-hero-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, rgba(7,8,14,0) 60%, var(--obs-bg) 100%);
    z-index: 1;
}
.obs-evlist-hero-content {
    position: relative;
    z-index: 2;
    max-width: 860px;
    margin: 0 auto;
    padding: 4.5rem 2rem 4rem;
    text-align: center;
}
.obs-evlist-hero-content .obs-eyebrow { margin-bottom: 1.1rem; }
.obs-evlist-hero-content .obs-hero-title {
    margin-bottom: 1rem;
    font-size: clamp(2.2rem, 5.5vw, 3.4rem);
}
.obs-evlist-subtitle {
    font-size: 1.05rem;
    color: var(--obs-text-2);
    line-height: 1.6;
    max-width: 500px;
    margin: 0 auto;
}

/* ── Grid section ────────────────────────────────────────────────────────── */
.obs-evlist-section {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 2rem 5rem;
}

.obs-evlist-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
    gap: 1.5rem;
}

/* ── Card ────────────────────────────────────────────────────────────────── */
.obs-ev-card {
    display: flex;
    flex-direction: column;
    background: var(--obs-surface);
    border: 1px solid var(--obs-border);
    border-radius: calc(var(--obs-radius) * 1.5);
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    transition: border-color var(--obs-transition), transform var(--obs-transition), box-shadow var(--obs-transition);
}
.obs-ev-card:hover {
    border-color: rgba(99,102,241,0.45);
    transform: translateY(-4px);
    box-shadow: 0 20px 48px rgba(0,0,0,0.5), 0 0 0 1px rgba(99,102,241,0.12);
}

/* Poster */
.obs-ev-poster {
    position: relative;
    height: 210px;
    overflow: hidden;
    background: var(--obs-surface-2);
    flex-shrink: 0;
}
.obs-ev-poster img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.45s ease;
}
.obs-ev-card:hover .obs-ev-poster img {
    transform: scale(1.05);
}
.obs-ev-poster-gradient {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        to bottom,
        rgba(7,8,14,0) 35%,
        rgba(7,8,14,0.78) 100%
    );
}
.obs-ev-poster-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, var(--obs-surface-2) 0%, rgba(99,102,241,0.05) 100%);
    color: var(--obs-text-3);
}
.obs-ev-poster-chips {
    position: absolute;
    top: .8rem;
    left: .85rem;
    display: flex;
    gap: .4rem;
    flex-wrap: wrap;
    z-index: 1;
}
.obs-ev-chip {
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    padding: .24rem .65rem;
    border-radius: 20px;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    line-height: 1;
}
.obs-ev-chip--free {
    background: rgba(16,185,129,.18);
    border: 1px solid rgba(16,185,129,.3);
    color: #34d399;
}
.obs-ev-chip--paid {
    background: rgba(99,102,241,.2);
    border: 1px solid rgba(99,102,241,.4);
    color: #a5b4fc;
}
.obs-ev-chip--date {
    background: rgba(7,8,14,.6);
    border: 1px solid var(--obs-border-2);
    color: var(--obs-text-2);
}

/* Card body */
.obs-ev-body {
    padding: 1.3rem 1.4rem 1.5rem;
    display: flex;
    flex-direction: column;
    flex: 1;
}
.obs-ev-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--obs-text);
    letter-spacing: -0.01em;
    line-height: 1.35;
    margin: 0 0 .5rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.obs-ev-time {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    font-size: .8rem;
    color: var(--obs-text-3);
    margin-bottom: .7rem;
}
.obs-ev-excerpt {
    font-size: .875rem;
    color: var(--obs-text-2);
    line-height: 1.55;
    flex: 1;
    margin: 0 0 1rem;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.obs-ev-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: .9rem;
    border-top: 1px solid var(--obs-border);
    margin-top: auto;
}
.obs-ev-price {
    font-size: .85rem;
    font-weight: 600;
    color: var(--obs-text-2);
}
.obs-ev-cta {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .5rem 1rem;
    border-radius: var(--obs-radius-sm);
    background: rgba(99,102,241,.12);
    border: 1px solid rgba(99,102,241,.25);
    color: var(--obs-accent-1);
    font-size: .8rem;
    font-weight: 600;
    transition: background var(--obs-transition), border-color var(--obs-transition), color var(--obs-transition);
    white-space: nowrap;
}
.obs-ev-cta svg { transition: transform var(--obs-transition); }
.obs-ev-card:hover .obs-ev-cta {
    background: rgba(99,102,241,.2);
    border-color: rgba(99,102,241,.45);
    color: #c7d2fe;
}
.obs-ev-card:hover .obs-ev-cta svg {
    transform: translateX(3px);
}

/* ── Empty state ─────────────────────────────────────────────────────────── */
.obs-evlist-empty {
    grid-column: 1 / -1;
    text-align: center;
    padding: 5rem 2rem;
}
.obs-evlist-empty-icon {
    width: 56px;
    height: 56px;
    margin: 0 auto 1.25rem;
    border-radius: 50%;
    background: rgba(99,102,241,.08);
    border: 1px solid rgba(99,102,241,.2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--obs-accent-1);
}
.obs-evlist-empty h2 {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--obs-text);
    margin: 0 0 .6rem;
    letter-spacing: -.01em;
}
.obs-evlist-empty p {
    font-size: .95rem;
    color: var(--obs-text-2);
    max-width: 320px;
    margin: 0 auto;
    line-height: 1.55;
}

/* ── Pagination ──────────────────────────────────────────────────────────── */
.obs-evlist-pager {
    margin-top: 3rem;
    display: flex;
    justify-content: center;
}
.obs-evlist-pager .page-numbers {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    align-items: center;
    gap: .4rem;
}
.obs-evlist-pager .page-numbers li a,
.obs-evlist-pager .page-numbers li span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    height: 38px;
    padding: 0 .8rem;
    border-radius: var(--obs-radius-sm);
    font-size: .85rem;
    font-weight: 600;
    text-decoration: none;
    background: var(--obs-surface);
    border: 1px solid var(--obs-border);
    color: var(--obs-text-2);
    transition: background var(--obs-transition), border-color var(--obs-transition), color var(--obs-transition);
}
.obs-evlist-pager .page-numbers li a:hover {
    background: var(--obs-surface-2);
    border-color: rgba(99,102,241,.4);
    color: var(--obs-text);
}
.obs-evlist-pager .page-numbers li span.current {
    background: var(--obs-accent-1);
    border-color: var(--obs-accent-1);
    color: #fff;
    box-shadow: 0 4px 14px var(--obs-accent-glow);
}

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 680px) {
    .obs-evlist-hero-content { padding: 3rem 1.25rem 2.75rem; }
    .obs-evlist-section       { padding: 0 1.25rem 3rem; }
    .obs-evlist-grid          { grid-template-columns: 1fr; }
}
</style>
</head>
<body <?php body_class('lem-obsidian obs-events-page'); ?>>

<!-- ── Top nav ──────────────────────────────────────────────────────────── -->
<nav class="obs-nav">
    <div class="obs-nav-brand">
        <svg class="obs-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="5 3 19 12 5 21 5 3"></polygon>
        </svg>
        <span class="obs-nav-site"><?php echo esc_html(get_bloginfo('name')); ?></span>
    </div>
    <div class="obs-nav-mid">
        <span class="obs-nav-title">Events</span>
    </div>
    <div class="obs-nav-end">
        <?php if (is_user_logged_in()): ?>
            <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="obs-nav-link">Sign Out</a>
        <?php else: ?>
            <a href="<?php echo esc_url(wp_login_url()); ?>" class="obs-nav-link">Sign In</a>
        <?php endif; ?>
    </div>
</nav>

<!-- ── Hero header ──────────────────────────────────────────────────────── -->
<header class="obs-evlist-hero">
    <div class="obs-evlist-hero-bg"></div>
    <div class="obs-evlist-hero-overlay"></div>
    <div class="obs-evlist-hero-content">
        <p class="obs-eyebrow">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21"/></svg>
            Line-up
        </p>
        <h1 class="obs-hero-title">Upcoming &amp; On-Demand</h1>
        <p class="obs-evlist-subtitle">Hand-picked live sessions and recordings. Reserve your spot or jump straight in.</p>
    </div>
</header>

<!-- ── Events grid ──────────────────────────────────────────────────────── -->
<?php
$paged = get_query_var('paged') ? (int) get_query_var('paged') : 1;

$events_query = new WP_Query([
    'post_type'      => 'lem_event',
    'posts_per_page' => 12,
    'paged'          => $paged,
    'meta_key'       => '_lem_event_date',
    'orderby'        => 'date',
    'order'          => 'DESC',
    'post_status'    => 'publish',
]);
?>
<div class="obs-evlist-section">
    <div class="obs-evlist-grid">

        <?php if ($events_query->have_posts()): ?>
            <?php while ($events_query->have_posts()): $events_query->the_post();
                $eid           = get_the_ID();
                $event_date    = get_post_meta($eid, '_lem_event_date', true);
                $is_free_meta  = get_post_meta($eid, '_lem_is_free', true);
                $display_price = get_post_meta($eid, '_lem_display_price', true);
                $excerpt       = get_post_meta($eid, '_lem_excerpt', true)
                              ?: wp_trim_words(strip_tags(get_the_content()), 26, '…');
                $thumbnail     = get_the_post_thumbnail_url($eid, 'medium_large');
                $is_free       = ($is_free_meta !== 'paid');

                $formatted_date = $formatted_time = '';
                if ($event_date) {
                    $ts             = strtotime($event_date);
                    $formatted_date = date('M j, Y', $ts);
                    $formatted_time = date('g:i A', $ts);
                }
            ?>
            <a href="<?php echo esc_url(get_permalink()); ?>" class="obs-ev-card">

                <!-- Poster -->
                <div class="obs-ev-poster">
                    <?php if ($thumbnail): ?>
                        <img src="<?php echo esc_url($thumbnail); ?>"
                             alt="<?php echo esc_attr(get_the_title()); ?>">
                        <div class="obs-ev-poster-gradient"></div>
                    <?php else: ?>
                        <div class="obs-ev-poster-placeholder">
                            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><polygon points="5 3 19 12 5 21"/></svg>
                        </div>
                    <?php endif; ?>
                    <div class="obs-ev-poster-chips">
                        <span class="obs-ev-chip <?php echo $is_free ? 'obs-ev-chip--free' : 'obs-ev-chip--paid'; ?>">
                            <?php echo $is_free ? 'Free' : 'Ticketed'; ?>
                        </span>
                        <?php if ($formatted_date): ?>
                            <span class="obs-ev-chip obs-ev-chip--date"><?php echo esc_html($formatted_date); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Body -->
                <div class="obs-ev-body">
                    <h2 class="obs-ev-title"><?php echo esc_html(get_the_title()); ?></h2>

                    <?php if ($formatted_time): ?>
                        <span class="obs-ev-time">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <?php echo esc_html($formatted_time . '  ·  ' . $formatted_date); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($excerpt): ?>
                        <p class="obs-ev-excerpt"><?php echo esc_html($excerpt); ?></p>
                    <?php endif; ?>

                    <div class="obs-ev-footer">
                        <span class="obs-ev-price">
                            <?php if (!$is_free && $display_price): ?>
                                <?php echo esc_html($display_price); ?>
                            <?php else: ?>
                                Free to join
                            <?php endif; ?>
                        </span>
                        <span class="obs-ev-cta">
                            View event
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </span>
                    </div>
                </div>

            </a>
            <?php endwhile; wp_reset_postdata(); ?>

        <?php else: ?>
            <div class="obs-evlist-empty">
                <div class="obs-evlist-empty-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <h2>Nothing on the calendar&hellip; yet</h2>
                <p>New events drop regularly. Check back soon or subscribe to stay in the loop.</p>
            </div>
        <?php endif; ?>

    </div><!-- /.obs-evlist-grid -->

    <?php if ($events_query->max_num_pages > 1): ?>
        <div class="obs-evlist-pager">
            <?php
            echo paginate_links([
                'base'      => str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999))),
                'format'    => '?paged=%#%',
                'current'   => max(1, get_query_var('paged')),
                'total'     => $events_query->max_num_pages,
                'prev_text' => '&larr; Previous',
                'next_text' => 'Next &rarr;',
                'type'      => 'list',
                'end_size'  => 2,
                'mid_size'  => 2,
            ]);
            ?>
        </div>
    <?php endif; ?>

</div><!-- /.obs-evlist-section -->

<?php wp_footer(); ?>
</body>
</html>
