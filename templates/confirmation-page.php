<?php
if (!defined('ABSPATH')) exit;
/**
 * Confirmation Page Template
 */

get_header();

global $live_event_manager;

$session_id = sanitize_text_field(wp_unslash($_GET['session_id'] ?? ''));
$event_id   = sanitize_text_field(wp_unslash($_GET['event_id'] ?? ''));
$jwt        = sanitize_text_field(wp_unslash($_GET['jwt'] ?? ''));
$jti        = sanitize_text_field(wp_unslash($_GET['jti'] ?? ''));
$email      = '';
$event      = null;

if ($live_event_manager) {
    $live_event_manager->debug_log('Confirmation page loaded', array(
        'session_id' => $session_id,
        'event_id'   => $event_id,
        'has_jwt'    => !empty($jwt),
        'has_jti'    => !empty($jti)
    ));
}

if (!empty($session_id) && empty($jwt) && $live_event_manager) {
    $token = $live_event_manager->get_jwt_row_by_payment_id($session_id);

    if ($token) {
        $email = $token->email;
        $event_id = $token->event_id;
        $jti = $token->jti;
        $jwt = $token->jwt_token;
        if ($live_event_manager) {
            $live_event_manager->debug_log('Found JWT token in database', array(
                'jti' => $jti,
                'email' => $email,
                'event_id' => $event_id
            ));
        }
    } else {
        // No JWT yet — reconcile with payment provider API (webhook fallback).
        if ($live_event_manager) {
            $stripe_result = $live_event_manager->reconcile_payment_session($session_id);
            if ($stripe_result && ! is_wp_error($stripe_result) && is_array($stripe_result) && isset($stripe_result['jwt'])) {
                $jwt = $stripe_result['jwt'];
                $jti = $stripe_result['jti'] ?? '';
                $email = $stripe_result['email'] ?? '';
                if (empty($event_id) && ! empty($stripe_result['event_id'])) {
                    $event_id = $stripe_result['event_id'];
                }
                if ($live_event_manager) {
                    $live_event_manager->debug_log('Immediate Stripe check successful', array(
                        'session_id' => $session_id,
                        'jti' => $jti,
                        'email' => $email,
                        'event_id' => $event_id
                    ));
                }
            }
        }
    }
}

if (!empty($jwt) && empty($jti)) {
    $parts = explode('.', $jwt);
    if (count($parts) === 3) {
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if ($payload && isset($payload['jti'])) {
            $jti = $payload['jti'];
        }
    }
}

if (!empty($jti)) {
    global $wpdb;
    $table = $wpdb->prefix . 'lem_jwt_tokens';
    $token = $wpdb->get_row($wpdb->prepare(
        "SELECT email, event_id FROM {$table} WHERE jti = %s",
        $jti
    ));
    if ($token) {
        $email = $token->email;
        if (empty($event_id)) {
            $event_id = $token->event_id;
        }
    }
}

if (!empty($event_id)) {
    $event_post = get_post($event_id);
    if ($event_post && $event_post->post_type === 'lem_event') {
        $event = $event_post;
    }
}

$unique_code = '';
if (!empty($jti)) {
    $unique_code = substr($jti, 0, 8);
} elseif (!empty($jwt)) {
    $unique_code = substr(md5($jwt), 0, 8);
}

$has_session_reference = !empty($session_id);
$has_token_reference   = !empty($jwt) || !empty($jti);
$pending_confirmation  = $has_session_reference && !$has_token_reference;
$has_valid_data        = $has_session_reference || $has_token_reference;

$watch_event_id = $event ? $event->ID : $event_id;
$watch_url      = ($watch_event_id && $live_event_manager)
    ? $live_event_manager->get_event_url($watch_event_id)
    : ($watch_event_id ? get_permalink($watch_event_id) : home_url('/'));

// Match the email: one-time magic link opens the gate (?magic=) instead of a bare event URL.
if (
    !$pending_confirmation
    && $watch_event_id
    && $live_event_manager
    && !empty($email)
    && class_exists('LEM_Access')
    && !LEM_Access::is_email_revoked_for_event($email, (int) $watch_event_id)
) {
    $magic_token = '';
    if (!empty($session_id)) {
        $tkey = 'lem_conf_magic_' . md5($session_id);
        $magic_token = get_transient($tkey);
        if (!is_string($magic_token) || $magic_token === '') {
            $magic_token = $live_event_manager->generate_magic_token($email, (int) $watch_event_id, null);
            if ($magic_token) {
                set_transient($tkey, $magic_token, HOUR_IN_SECONDS);
            }
        }
    } else {
        $magic_token = $live_event_manager->generate_magic_token($email, (int) $watch_event_id, null);
    }
    if (!empty($magic_token)) {
        $watch_url = $live_event_manager->get_event_url((int) $watch_event_id, array('magic' => $magic_token));
    }
}

$event_date_raw = $event ? get_post_meta($event->ID, '_lem_event_date', true) : '';
$event_date_fmt = $event_date_raw ? date('F j, Y', strtotime($event_date_raw)) . ' at ' . date('g:i A', strtotime($event_date_raw)) : '';
?>

<div class="lem-live-events-app lem-confirm-app">

    <!-- Nav -->
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
        <div class="lem-nav-right">
            <?php if ($watch_event_id): ?>
                <a href="<?php echo esc_url(get_permalink($watch_event_id) ?: home_url('/events')); ?>" class="lem-nav-link">
                    <?php echo $event ? esc_html($event->post_title) : 'Event'; ?>
                </a>
            <?php endif; ?>
            <a href="<?php echo esc_url(home_url('/events')); ?>" class="lem-nav-link">All events</a>
        </div>
    </nav>

    <!-- Main -->
    <div class="lem-confirm-body">
        <div class="lem-confirm-card">

            <!-- Status icon -->
            <div class="lem-confirm-icon <?php echo $pending_confirmation ? 'is-pending' : 'is-success'; ?>">
                <?php if ($pending_confirmation): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                <?php endif; ?>
            </div>

            <!-- Heading -->
            <div class="lem-confirm-heading">
                <h1><?php echo $pending_confirmation ? 'Confirming your payment…' : "You're in"; ?></h1>
                <?php if ($event): ?>
                    <p class="lem-confirm-event-name"><?php echo esc_html($event->post_title); ?></p>
                <?php endif; ?>
                <p class="lem-confirm-sub">
                    <?php if ($pending_confirmation): ?>
                        We are confirming your payment. This page will update automatically, and your magic link will be emailed once it clears.
                    <?php else: ?>
                        Your magic link is on its way. Use the button below to jump straight to the stream.
                    <?php endif; ?>
                </p>
            </div>

            <?php if (!$pending_confirmation && !empty($jwt) && $watch_event_id): ?>
            <!-- Primary CTA -->
            <a href="<?php echo esc_url($watch_url); ?>" class="lem-confirm-cta">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                    <polygon points="5,3 19,12 5,21"></polygon>
                </svg>
                Watch now
            </a>
            <?php endif; ?>

            <!-- Meta row -->
            <?php if ($event || !empty($email) || $event_date_fmt): ?>
            <ul class="lem-confirm-meta">
                <?php if (!empty($email)): ?>
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        Magic link sent to <strong><?php echo esc_html($email); ?></strong>
                    </li>
                <?php endif; ?>
                <?php if ($event_date_fmt): ?>
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <?php echo esc_html($event_date_fmt); ?>
                    </li>
                <?php endif; ?>
            </ul>
            <?php endif; ?>

            <?php if (!$pending_confirmation && !$has_valid_data): ?>
            <div class="lem-confirm-notice is-warning">
                We couldn't find a recent payment or magic link. Double-check the URL or contact support with your receipt.
            </div>
            <?php endif; ?>

            <!-- Access code -->
            <?php if (!empty($unique_code)): ?>
            <div class="lem-confirm-code-row">
                <div class="lem-confirm-code-label">Your access code</div>
                <div class="lem-confirm-code-wrap">
                    <span class="lem-confirm-code" id="lem-unique-code"><?php echo esc_html(strtoupper($unique_code)); ?></span>
                    <button class="lem-confirm-copy-btn" id="lem-copy-code" type="button" aria-label="Copy code">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        Copy
                    </button>
                </div>
                <p class="lem-confirm-code-hint">Keep this if you need to request a new link later.</p>
            </div>
            <?php endif; ?>

            <!-- Divider -->
            <div class="lem-confirm-divider"></div>

            <!-- Resend -->
            <details class="lem-confirm-resend">
                <summary>
                    Need to resend the link?
                    <svg class="lem-resend-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><polyline points="6,9 12,15 18,9"></polyline></svg>
                </summary>
                <div class="lem-confirm-resend-body">
                    <p>Enter the email and 8-character code above to get a fresh magic link instantly.</p>
                    <div class="lem-confirm-resend-fields">
                        <input type="email" id="lem-regenerate-email" class="lem-confirm-input" placeholder="you@example.com" value="<?php echo esc_attr($email); ?>">
                        <input type="text"  id="lem-regenerate-code" class="lem-confirm-input" placeholder="Access code (8 chars)" maxlength="8" value="<?php echo esc_attr(strtoupper($unique_code)); ?>">
                        <button type="button" class="lem-confirm-resend-btn" id="lem-regenerate-button">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15A9 9 0 1 1 23 10"></path></svg>
                            Send link
                        </button>
                    </div>
                    <div id="lem-regenerate-result" class="lem-confirm-resend-result" style="display:none;"></div>
                </div>
            </details>

            <!-- What's next -->
            <ul class="lem-confirm-tips">
                <li>Check your spam/promotions folder if the email doesn't arrive.</li>
                <li>The magic link is one-time use — request a resend if you switch devices.</li>
                <li>Your access code is your backup — keep it safe.</li>
            </ul>

        </div>
    </div>

</div>

<style>
.lem-confirm-app { font-family: 'Inter', 'Segoe UI', Roboto, sans-serif; }

/* Force dark bg even when WP theme adds light body bg */
.lem-confirm-app ~ * { display: none; }
body:has(.lem-confirm-app) { background: #0f0f1e; margin: 0; }

.lem-confirm-body {
    display: flex;
    align-items: flex-start;
    justify-content: center;
    min-height: calc(100vh - 58px);
    padding: 3rem 1.25rem 4rem;
}

.lem-confirm-card {
    width: 100%;
    max-width: 520px;
    background: #16162a;
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 20px;
    padding: 2.5rem 2rem;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    box-shadow: 0 24px 60px rgba(0,0,0,0.45);
}

/* Status icon */
.lem-confirm-icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
}
.lem-confirm-icon svg { width: 26px; height: 26px; }
.lem-confirm-icon.is-success {
    background: rgba(44, 177, 145, 0.15);
    color: #2cb191;
    border: 1px solid rgba(44,177,145,0.3);
}
.lem-confirm-icon.is-pending {
    background: rgba(255, 184, 97, 0.12);
    color: #ffb861;
    border: 1px solid rgba(255,184,97,0.28);
}

/* Heading block */
.lem-confirm-heading { text-align: center; }
.lem-confirm-heading h1 {
    margin: 0 0 0.35rem;
    font-size: 1.75rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: #fff;
}
.lem-confirm-event-name {
    margin: 0 0 0.6rem;
    font-size: 0.9rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #7f5af0;
}
.lem-confirm-sub {
    margin: 0;
    font-size: 0.95rem;
    line-height: 1.6;
    color: rgba(255,255,255,0.5);
}

/* CTA button */
.lem-confirm-cta {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    padding: 0.9rem 1.6rem;
    border-radius: 12px;
    background: linear-gradient(135deg, #7f5af0, #2cb1bc);
    color: #fff;
    font-weight: 700;
    font-size: 1rem;
    text-decoration: none;
    letter-spacing: 0.02em;
    box-shadow: 0 12px 32px rgba(127,90,240,0.3);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.lem-confirm-cta:hover {
    transform: translateY(-2px);
    box-shadow: 0 18px 40px rgba(127,90,240,0.38);
    color: #fff;
}

/* Meta list */
.lem-confirm-meta {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.55rem;
}
.lem-confirm-meta li {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    font-size: 0.88rem;
    color: rgba(255,255,255,0.45);
}
.lem-confirm-meta li svg { flex-shrink: 0; stroke: rgba(255,255,255,0.3); }
.lem-confirm-meta li strong { color: rgba(255,255,255,0.7); font-weight: 600; }

/* Notice */
.lem-confirm-notice {
    padding: 0.8rem 1rem;
    border-radius: 10px;
    font-size: 0.88rem;
    line-height: 1.5;
}
.lem-confirm-notice.is-warning {
    background: rgba(255,184,97,0.1);
    border: 1px solid rgba(255,184,97,0.2);
    color: #ffb861;
}

/* Access code */
.lem-confirm-code-row {
    background: rgba(127,90,240,0.08);
    border: 1px solid rgba(127,90,240,0.2);
    border-radius: 12px;
    padding: 1rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.lem-confirm-code-label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: rgba(127,90,240,0.7);
}
.lem-confirm-code-wrap {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}
.lem-confirm-code {
    font-size: 1.5rem;
    font-weight: 800;
    letter-spacing: 0.22em;
    color: #fff;
    font-variant-numeric: tabular-nums;
}
.lem-confirm-copy-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.75rem;
    border-radius: 8px;
    border: 1px solid rgba(127,90,240,0.35);
    background: rgba(127,90,240,0.12);
    color: #a78bfa;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s ease, border-color 0.18s ease;
    white-space: nowrap;
}
.lem-confirm-copy-btn:hover {
    background: rgba(127,90,240,0.22);
    border-color: rgba(127,90,240,0.6);
}
.lem-confirm-code-hint {
    margin: 0;
    font-size: 0.78rem;
    color: rgba(255,255,255,0.3);
}

/* Divider */
.lem-confirm-divider {
    height: 1px;
    background: rgba(255,255,255,0.07);
}

/* Resend */
.lem-confirm-resend summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    list-style: none;
    font-size: 0.9rem;
    font-weight: 600;
    color: rgba(255,255,255,0.4);
    padding: 0.25rem 0;
    user-select: none;
}
.lem-confirm-resend summary::-webkit-details-marker { display: none; }
.lem-confirm-resend .lem-resend-chevron { transition: transform 0.2s ease; }
.lem-confirm-resend[open] .lem-resend-chevron { transform: rotate(180deg); }
.lem-confirm-resend summary:hover { color: rgba(255,255,255,0.65); }
.lem-confirm-resend-body {
    padding-top: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.lem-confirm-resend-body p {
    margin: 0;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.35);
}
.lem-confirm-resend-fields {
    display: grid;
    gap: 0.6rem;
}
.lem-confirm-input {
    width: 100%;
    padding: 0.75rem 0.9rem;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.06);
    color: #e2e2f0;
    font-size: 0.9rem;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    box-sizing: border-box;
}
.lem-confirm-input::placeholder { color: rgba(255,255,255,0.25); }
.lem-confirm-input:focus {
    outline: none;
    border-color: #7f5af0;
    box-shadow: 0 0 0 3px rgba(127,90,240,0.18);
}
.lem-confirm-resend-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    padding: 0.75rem 1.25rem;
    border-radius: 10px;
    border: 1px solid rgba(127,90,240,0.4);
    background: rgba(127,90,240,0.12);
    color: #a78bfa;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s ease, border-color 0.18s ease;
}
.lem-confirm-resend-btn:hover {
    background: rgba(127,90,240,0.22);
    border-color: rgba(127,90,240,0.65);
}
.lem-confirm-resend-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.lem-confirm-resend-result {
    padding: 0.65rem 0.9rem;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 500;
}
.lem-confirm-resend-result.is-success {
    background: rgba(44,177,145,0.12);
    border: 1px solid rgba(44,177,145,0.25);
    color: #2cb191;
}
.lem-confirm-resend-result.is-error {
    background: rgba(255,107,107,0.1);
    border: 1px solid rgba(255,107,107,0.2);
    color: #ff6b6b;
}

/* Tips */
.lem-confirm-tips {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}
.lem-confirm-tips li {
    font-size: 0.82rem;
    color: rgba(255,255,255,0.25);
    padding-left: 1rem;
    position: relative;
}
.lem-confirm-tips li::before {
    content: '·';
    position: absolute;
    left: 0;
    color: rgba(127,90,240,0.5);
}

@media (max-width: 560px) {
    .lem-confirm-card { padding: 1.75rem 1.25rem; }
    .lem-confirm-heading h1 { font-size: 1.4rem; }
}
</style>

<script>
document.getElementById('lem-copy-code')?.addEventListener('click', function() {
    const code = document.getElementById('lem-unique-code')?.textContent?.trim();
    if (!code) return;
    navigator.clipboard.writeText(code).then(() => {
        const btn = this;
        const orig = btn.innerHTML;
        btn.innerHTML = '✓ Copied';
        setTimeout(() => btn.innerHTML = orig, 2000);
    });
});

<?php if ($pending_confirmation && !empty($session_id)) : ?>
<script>
jQuery(function($) {
    var attempts = 0;
    var maxAttempts = 15;
    var sessionId = <?php echo wp_json_encode($session_id); ?>;
    var eventId = <?php echo wp_json_encode($event_id); ?>;
    var ajaxConfig = (typeof lem_ajax !== 'undefined') ? lem_ajax : {
        ajax_url: <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
        nonce: <?php echo wp_json_encode(wp_create_nonce('lem_nonce')); ?>
    };

    function pollReconcile() {
        if (attempts >= maxAttempts) {
            return;
        }
        attempts++;
        $.post(ajaxConfig.ajax_url, {
            action: 'lem_reconcile_payment',
            nonce: ajaxConfig.nonce,
            session_id: sessionId,
            event_id: eventId,
            provider_id: 'stripe'
        }).done(function(r) {
            if (r && r.success && r.data && r.data.granted) {
                window.location.reload();
                return;
            }
            setTimeout(pollReconcile, 2000);
        }).fail(function() {
            setTimeout(pollReconcile, 3000);
        });
    }

    setTimeout(pollReconcile, 1500);
});
</script>
<?php endif; ?>

jQuery(function($) {
    const $button = $('#lem-regenerate-button');
    const $result = $('#lem-regenerate-result');
    const ajaxConfig = (typeof lem_ajax !== 'undefined') ? lem_ajax : {
        ajax_url: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
        nonce: '<?php echo esc_js(wp_create_nonce('lem_nonce')); ?>'
    };

    $button.on('click', function() {
        const email = $('#lem-regenerate-email').val().trim();
        const code  = $('#lem-regenerate-code').val().trim();
        if (!email || !code) {
            $result.removeClass('is-success').addClass('is-error').text('Please fill in both fields.').show();
            return;
        }
        $button.prop('disabled', true).text('Sending…');
        $result.hide().removeClass('is-success is-error');
        $.post(ajaxConfig.ajax_url, { action: 'lem_regenerate_jwt', email, code, nonce: ajaxConfig.nonce })
            .done(function(r) {
                if (r?.success) {
                    $result.addClass('is-success').text(r.data || 'New link sent — check your inbox.').show();
                } else {
                    $result.addClass('is-error').text(r?.data || 'Could not resend. Try again shortly.').show();
                }
            })
            .fail(function() { $result.addClass('is-error').text('Network error. Please try again.').show(); })
            .always(function() { $button.prop('disabled', false).html('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15A9 9 0 1 1 23 10"></path></svg> Send link'); });
    });
});
</script>

<?php get_footer(); ?>
