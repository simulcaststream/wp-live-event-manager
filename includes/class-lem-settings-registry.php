<?php
/**
 * Settings section registry.
 *
 * Lets adaptor and gating plugins contribute a sub-tab/section to the Services
 * admin page without modifying core templates. Sections are grouped by `tab`
 * (e.g. 'streaming', 'payments', 'chat', 'license', 'general') and rendered in
 * registration order.
 *
 * Usage:
 *   LEM_Settings_Registry::register_section('paypal_credentials', [
 *       'tab'        => 'payments',
 *       'title'      => 'PayPal',
 *       'render_cb'  => 'my_render_paypal_form',
 *       'capability' => 'manage_options',
 *   ]);
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_Settings_Registry {

    private static array $sections = array();

    /**
     * @param string $id   Unique slug for this section.
     * @param array  $args {
     *     @type string   $tab         Tab/group this section belongs to.
     *     @type string   $title       Section heading shown in admin.
     *     @type callable $render_cb   Renders the section body.
     *     @type callable $save_cb     Optional save handler called on form submission.
     *     @type string   $capability  Required capability (default 'manage_options').
     *     @type int      $priority    Render order within tab (default 10).
     * }
     */
    public static function register_section(string $id, array $args): void {
        $args = array_merge(array(
            'tab'        => 'general',
            'title'      => $id,
            'render_cb'  => null,
            'save_cb'    => null,
            'capability' => 'manage_options',
            'priority'   => 10,
        ), $args);

        $args['id']         = $id;
        self::$sections[$id] = $args;
    }

    public static function unregister_section(string $id): void {
        unset(self::$sections[$id]);
    }

    /**
     * Return all sections, optionally filtered to a specific tab. Sorted by priority.
     *
     * @return array<string, array>
     */
    public static function get_sections(?string $tab = null): array {
        $list = self::$sections;
        if ($tab !== null) {
            $list = array_filter($list, static fn($s) => $s['tab'] === $tab);
        }
        uasort($list, static fn($a, $b) => ($a['priority'] ?? 10) <=> ($b['priority'] ?? 10));
        return $list;
    }

    /**
     * Render every section under a tab. Skips sections whose capability is denied.
     */
    public static function render_tab(string $tab): void {
        foreach (self::get_sections($tab) as $section) {
            if (!current_user_can($section['capability'])) {
                continue;
            }
            if (!is_callable($section['render_cb'])) {
                continue;
            }
            echo '<section class="lem-settings-section" data-section="' . esc_attr($section['id']) . '">';
            if (!empty($section['title'])) {
                echo '<h3>' . esc_html($section['title']) . '</h3>';
            }
            call_user_func($section['render_cb'], $section);
            echo '</section>';
        }
    }

    /**
     * Dispatch a save event to every section's save_cb (if any).
     *
     * @param string $tab         Tab being saved.
     * @param array  $form_data   Sanitized form data ($_POST subset).
     */
    public static function save_tab(string $tab, array $form_data): void {
        foreach (self::get_sections($tab) as $section) {
            if (!current_user_can($section['capability'])) {
                continue;
            }
            if (is_callable($section['save_cb'])) {
                call_user_func($section['save_cb'], $form_data, $section);
            }
        }
    }
}
