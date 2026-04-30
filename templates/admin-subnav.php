<?php
/**
 * Shared secondary navigation bar for admin pages not visible in the sidebar.
 * Include at the top of any hidden admin page template.
 *
 * Highlights the currently active page automatically via $_GET['page'].
 */
if (!defined('ABSPATH')) exit;

$_lem_base    = 'edit.php?post_type=lem_event';
$_lem_current = sanitize_text_field($_GET['page'] ?? '');

$_lem_nav = array(
    'Settings'  => array('slug' => 'live-event-manager-settings',      'url' => admin_url($_lem_base . '&page=live-event-manager-settings')),
    'Services'  => array('slug' => 'live-event-manager-services',       'url' => admin_url($_lem_base . '&page=live-event-manager-services')),
    'Templates' => array('slug' => 'live-event-manager-templates',      'url' => admin_url($_lem_base . '&page=live-event-manager-templates')),
    'Access'    => array('slug' => 'live-event-manager-jwt',            'url' => admin_url($_lem_base . '&page=live-event-manager-jwt')),
    'Revoke'    => array('slug' => 'live-event-manager-revoke-access',  'url' => admin_url($_lem_base . '&page=live-event-manager-revoke-access')),
    'Help'      => array('slug' => 'live-event-manager-user-guide',     'url' => admin_url($_lem_base . '&page=live-event-manager-user-guide')),
    'Debug'     => array('slug' => 'live-event-manager-debug',          'url' => admin_url($_lem_base . '&page=live-event-manager-debug')),
);
?>
<div class="lem-subnav" style="margin-bottom:20px;">
    <?php foreach ($_lem_nav as $label => $item):
        $is_active = ($item['slug'] === $_lem_current);
    ?>
    <a href="<?php echo esc_url($item['url']); ?>"
       class="button <?php echo $is_active ? 'button-primary' : 'button-secondary'; ?>"
       style="margin-right:6px; <?php echo $is_active ? 'pointer-events:none;' : ''; ?>">
        <?php echo esc_html($label); ?>
    </a>
    <?php endforeach; ?>
</div>
