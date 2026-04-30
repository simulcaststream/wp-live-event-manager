<?php
/**
 * User Guide Page Template
 * Displays comprehensive documentation for the Live Event Manager plugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get the markdown content
$markdown_file = LEM_PLUGIN_DIR . 'docs/user-guide.md';
$markdown_content = '';

if (file_exists($markdown_file)) {
    $markdown_content = file_get_contents($markdown_file);
} else {
    $markdown_content = '# Live Event Manager - User Guide

## Overview
The Live Event Manager is a comprehensive WordPress plugin for secure, scalable live event management.

## Quick Start
1. **Plugin Activation**: Go to WordPress Admin → Plugins and activate
2. **Basic Configuration**: Configure Mux and Stripe credentials
3. **Create Events**: Add new events with video and payment settings

## Features
- ✅ Complete event management system
- ✅ Secure payment processing with Stripe
- ✅ Professional video streaming with Mux
- ✅ Privacy-compliant access control with JWT
- ✅ Comprehensive admin interface
- ✅ Performance optimization with Redis caching

*Note: Full documentation is available in the docs/user-guide.md file*';
}

// Convert markdown to HTML (basic conversion)
function convert_markdown_to_html($markdown) {
    // Headers (ids for in-page navigation; plain text only in heading lines)
    $markdown = preg_replace_callback('/^### (.*)$/m', function ($m) {
        $t = trim($m[1]);
        return '<h3 id="' . esc_attr(sanitize_title($t)) . '">' . esc_html($t) . '</h3>';
    }, $markdown);
    $markdown = preg_replace_callback('/^## (.*)$/m', function ($m) {
        $t = trim($m[1]);
        return '<h2 id="' . esc_attr(sanitize_title($t)) . '">' . esc_html($t) . '</h2>';
    }, $markdown);
    $markdown = preg_replace_callback('/^# (.*)$/m', function ($m) {
        $t = trim($m[1]);
        return '<h1 id="' . esc_attr(sanitize_title($t)) . '">' . esc_html($t) . '</h1>';
    }, $markdown);
    
    // Bold
    $markdown = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $markdown);
    
    // Italic
    $markdown = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $markdown);
    
    // Code blocks
    $markdown = preg_replace('/```(.*?)```/s', '<pre><code>$1</code></pre>', $markdown);
    $markdown = preg_replace('/`(.*?)`/', '<code>$1</code>', $markdown);
    
    // Lists
    $markdown = preg_replace('/^- (.*$)/m', '<li>$1</li>', $markdown);
    $markdown = preg_replace('/^1\. (.*$)/m', '<li>$1</li>', $markdown);
    
    // Wrap lists in ul/ol
    $markdown = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $markdown);
    
    // Links (only allow http, https, mailto protocols)
    $markdown = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($matches) {
        $text = $matches[1];
        $url  = trim($matches[2]);
        if (preg_match('#^(https?://|mailto:)#i', $url)) {
            return '<a href="' . esc_url($url) . '">' . $text . '</a>';
        }
        return $text; // Strip the link if protocol is not allowed
    }, $markdown);
    
    // Line breaks
    $markdown = str_replace("\n\n", '</p><p>', $markdown);
    $markdown = '<p>' . $markdown . '</p>';
    
    // Clean up empty paragraphs
    $markdown = str_replace('<p></p>', '', $markdown);
    
    return $markdown;
}

$html_content = convert_markdown_to_html($markdown_content);
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Live Event Manager - User Guide</h1>
    <?php require __DIR__ . '/admin-subnav.php'; ?>
    <div class="lem-user-guide-container">
        <div class="lem-user-guide-sidebar">
            <div class="lem-user-guide-nav">
                <h3>Quick Navigation</h3>
                <ul>
                    <li><a href="#overview">Overview</a></li>
                    <li><a href="#quick-start">Quick start</a></li>
                    <li><a href="#streaming-providers">Streaming providers</a></li>
                    <li><a href="#live-streams">Live Streams</a></li>
                    <li><a href="#events">Events</a></li>
                    <li><a href="#access-tokens-and-revocations">Access &amp; revocations</a></li>
                    <li><a href="#payments">Payments</a></li>
                    <li><a href="#template-packs">Template packs</a></li>
                    <li><a href="#viewer-experience">Viewer experience</a></li>
                    <li><a href="#optional-ably-live-chat">Ably chat</a></li>
                    <li><a href="#rest-api">REST API</a></li>
                    <li><a href="#admin-pages-reference">Admin pages</a></li>
                    <li><a href="#troubleshooting">Troubleshooting</a></li>
                </ul>
            </div>
            
            <div class="lem-user-guide-status">
                <h3>System Status</h3>
                <div class="lem-status-item">
                    <span class="lem-status-label">Plugin Status:</span>
                    <span class="lem-status-value lem-status-success">✅ Active</span>
                </div>
                <div class="lem-status-item">
                    <span class="lem-status-label">Database:</span>
                    <span class="lem-status-value lem-status-success">✅ Ready</span>
                </div>
                <div class="lem-status-item">
                    <span class="lem-status-label">Upstash (cache):</span>
                    <?php 
                    // Get plugin instance safely
                    $plugin_instance = null;
                    if (class_exists('LiveEventManager')) {
                        $plugin_instance = new LiveEventManager();
                    }
                    
                    if ($plugin_instance) {
                        $redis = $plugin_instance->get_redis_connection();
                        if ($redis) {
                            echo '<span class="lem-status-value lem-status-success">✅ Connected</span>';
                        } else {
                            echo '<span class="lem-status-value lem-status-warning">⚠️ Not Configured</span>';
                        }
                    } else {
                        echo '<span class="lem-status-value lem-status-error">❌ Plugin Error</span>';
                    }
                    ?>
                </div>
                <div class="lem-status-item">
                    <span class="lem-status-label">Mux API:</span>
                    <?php 
                    if ($plugin_instance) {
                        $credentials = $plugin_instance->get_mux_api_credentials();
                        if ($credentials && isset($credentials['token_id'])) {
                            echo '<span class="lem-status-value lem-status-success">✅ Configured</span>';
                        } else {
                            echo '<span class="lem-status-value lem-status-warning">⚠️ Not Configured</span>';
                        }
                    } else {
                        echo '<span class="lem-status-value lem-status-error">❌ Plugin Error</span>';
                    }
                    ?>
                </div>
                <div class="lem-status-item">
                    <span class="lem-status-label">Stripe:</span>
                    <?php 
                    $settings = get_option('lem_settings', array());
                    if (!empty($settings['stripe_publishable_key'])) {
                        echo '<span class="lem-status-value lem-status-success">✅ Configured</span>';
                    } else {
                        echo '<span class="lem-status-value lem-status-warning">⚠️ Not Configured</span>';
                    }
                    ?>
                </div>
            </div>
            
            <div class="lem-user-guide-actions">
                <h3>Quick Actions</h3>
                <a href="<?php echo admin_url('post-new.php?post_type=lem_event'); ?>" class="button button-primary">
                    Create New Event
                </a>
                <a href="<?php echo admin_url('edit.php?post_type=lem_event&page=live-event-manager-stream-management'); ?>" class="button">
                    Live Streams
                </a>
                <a href="<?php echo admin_url('edit.php?post_type=lem_event&page=live-event-manager-stream-vendors'); ?>" class="button">
                    Vendors
                </a>
                <a href="<?php echo admin_url('edit.php?post_type=lem_event&page=live-event-manager-settings'); ?>" class="button">
                    Settings
                </a>
                <a href="<?php echo admin_url('edit.php?post_type=lem_event&page=live-event-manager-publisher'); ?>" class="button">
                    Publisher
                </a>
            </div>
        </div>
        
        <div class="lem-user-guide-content">
            <?php echo wp_kses_post($html_content); ?>
        </div>
    </div>
</div>

<style>
.lem-user-guide-container {
    display: flex;
    gap: 30px;
    margin-top: 20px;
}

.lem-user-guide-sidebar {
    width: 300px;
    flex-shrink: 0;
}

.lem-user-guide-content {
    flex: 1;
    background: #fff;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.lem-user-guide-nav,
.lem-user-guide-status,
.lem-user-guide-actions {
    background: #fff;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.lem-user-guide-nav h3,
.lem-user-guide-status h3,
.lem-user-guide-actions h3 {
    margin-top: 0;
    margin-bottom: 15px;
    color: #23282d;
    font-size: 16px;
}

.lem-user-guide-nav ul {
    margin: 0;
    padding: 0;
    list-style: none;
}

.lem-user-guide-nav li {
    margin-bottom: 8px;
}

.lem-user-guide-nav a {
    color: #0073aa;
    text-decoration: none;
    padding: 5px 0;
    display: block;
}

.lem-user-guide-nav a:hover {
    color: #005a87;
    text-decoration: underline;
}

.lem-status-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
}

.lem-status-item:last-child {
    border-bottom: none;
}

.lem-status-label {
    font-weight: 500;
    color: #555;
}

.lem-status-value {
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 12px;
}

.lem-status-success {
    background: #d4edda;
    color: #155724;
}

.lem-status-warning {
    background: #fff3cd;
    color: #856404;
}

.lem-status-error {
    background: #f8d7da;
    color: #721c24;
}

.lem-user-guide-actions .button {
    display: block;
    width: 100%;
    margin-bottom: 10px;
    text-align: center;
}

.lem-user-guide-actions .button:last-child {
    margin-bottom: 0;
}

/* Content styling */
.lem-user-guide-content h1 {
    color: #23282d;
    font-size: 28px;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #0073aa;
}

.lem-user-guide-content h2 {
    color: #23282d;
    font-size: 22px;
    margin-top: 30px;
    margin-bottom: 15px;
    padding-bottom: 8px;
    border-bottom: 1px solid #e5e5e5;
}

.lem-user-guide-content h3 {
    color: #23282d;
    font-size: 18px;
    margin-top: 25px;
    margin-bottom: 12px;
}

.lem-user-guide-content p {
    line-height: 1.6;
    margin-bottom: 15px;
    color: #444;
}

.lem-user-guide-content ul,
.lem-user-guide-content ol {
    margin-bottom: 15px;
    padding-left: 20px;
}

.lem-user-guide-content li {
    margin-bottom: 8px;
    line-height: 1.5;
}

.lem-user-guide-content code {
    background: #f8f9fa;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
    font-size: 13px;
}

.lem-user-guide-content pre {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 5px;
    overflow-x: auto;
    margin: 15px 0;
}

.lem-user-guide-content pre code {
    background: none;
    padding: 0;
}

.lem-user-guide-content a {
    color: #0073aa;
    text-decoration: none;
}

.lem-user-guide-content a:hover {
    color: #005a87;
    text-decoration: underline;
}

.lem-user-guide-content strong {
    font-weight: 600;
    color: #23282d;
}

.lem-user-guide-content em {
    font-style: italic;
    color: #666;
}

/* Responsive design */
@media (max-width: 768px) {
    .lem-user-guide-container {
        flex-direction: column;
    }
    
    .lem-user-guide-sidebar {
        width: 100%;
    }
    
    .lem-user-guide-content {
        padding: 20px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Smooth scrolling for anchor links
    $('.lem-user-guide-nav a').on('click', function(e) {
        e.preventDefault();
        var target = $(this).attr('href');
        var $target = $(target);
        
        if ($target.length) {
            $('html, body').animate({
                scrollTop: $target.offset().top - 100
            }, 500);
        }
    });
    
    // Add IDs to headers for navigation
    $('.lem-user-guide-content h1, .lem-user-guide-content h2, .lem-user-guide-content h3').each(function() {
        var $header = $(this);
        var text = $header.text();
        var id = text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        $header.attr('id', id);
    });
});
</script> 