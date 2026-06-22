<?php
/**
 * LEM Template Manager
 *
 * Manages custom template pack discovery, installation, activation, and path
 * resolution. Template packs are stored in wp-content/lem-templates/{slug}/
 * so they survive plugin updates.
 *
 * template.json may include an optional "files" array: relative paths (under the
 * pack root) that are allowed in addition to the default install set when unpacking
 * a ZIP. Each path must match /^[a-zA-Z0-9][a-zA-Z0-9_.\/-]*$/ with no "..".
 *
 * Add-on plugins can call register_pack_source() on plugins_loaded to expose packs
 * that live inside their plugin directory (returns manifest-shaped arrays with
 * required keys: slug, name, version, path; optional: url for asset base URL).
 *
 * Filters:
 *   lem_resolve_template_file — (string $absolute_path, string $filename)
 *   lem_installed_template_packs — (array $templates)
 *   lem_before_template_pack_install — (array $meta, string $slug, string $tmp_path) may return WP_Error
 *   lem_template_pack_install_metadata — (array $meta, string $slug) after manifest parse, before extract
 *   lem_template_pack_update_response — (null|array $response, string $slug, array $meta) reserved; not used by core yet
 *
 * Actions:
 *   lem_template_pack_source_error — (\Throwable $e, callable $callback) when a source callback throws
 *   lem_template_pack_installed — (string $slug, array $meta)
 *   lem_template_pack_activated — (string $slug, array $meta, string $previous_slug)
 *   lem_template_pack_deleted — (string $slug, array $meta)
 *
 * Reserved manifest keys (passed through, ignored by core): marketplace, product_id,
 * license_required, update_url, update_id.
 *
 * Canonical schema: docs/template-pack.schema.json
 *
 * Usage (from anywhere in the plugin):
 *   LEM_Template_Manager::resolve_template_file('single-event.php')
 *   LEM_Template_Manager::get_installed_templates()
 */
if (!defined('ABSPATH')) {
    exit;
}

class LEM_Template_Manager {

    /** Base directory for all user-installed template packs. */
    const TEMPLATES_BASE_DIR = WP_CONTENT_DIR . '/lem-templates/';
    const TEMPLATES_BASE_URL = WP_CONTENT_URL . '/lem-templates/';

    /** Reserved slug — always points to the built-in plugin templates. */
    const DEFAULT_SLUG = 'default';

    /** Maximum ZIP upload size: 5 MB */
    const MAX_ZIP_SIZE = 5242880;

    /** Current template.json manifest format version. Bump when breaking manifest fields. */
    const LEM_FORMAT_VERSION = 1;

    /**
     * Default template PHP/CSS/JS paths allowed in every pack ZIP (union with template.json "files").
     *
     * @var string[]
     */
    private static $default_pack_file_relatives = array(
        'template.json',
        'single-event.php',
        'event-ticket-block.php',
        'page-events.php',
        'confirmation-page.php',
        'device-swap-form.php',
        'gated-video-block.php',
        'assets/style.css',
        'assets/script.js',
    );

    /**
     * @var callable[]
     */
    private static $pack_sources = array();

    /**
     * Per-request cache for collect_registered_packs() — invalidated when a new source is registered.
     * @var array<string, array>|null
     */
    private static ?array $registered_packs_cache = null;

    /**
     * Register a callback that returns template pack definitions shipped by another plugin.
     *
     * Each item must include: slug, name, version, path (absolute directory containing template.json).
     * Optional: description, author, author_url, type, url (public base URL for assets if path is not under wp-content).
     *
     * @param callable():array $callback Returns a list of pack manifest arrays.
     */
    public static function register_pack_source(callable $callback): void {
        self::$pack_sources[]         = $callback;
        self::$registered_packs_cache = null;
    }

    /**
     * Clear the per-request registered-packs cache (for tests).
     */
    public static function clear_registered_packs_cache(): void {
        self::$registered_packs_cache = null;
    }

    /**
     * Path to the JSON Schema file for template.json (for authors and tooling).
     */
    public static function get_schema_path(): string {
        return LEM_PLUGIN_DIR . 'docs/template-pack.schema.json';
    }

    /**
     * Normalize and validate manifest fields from template.json.
     *
     * @param array<string, mixed> $meta Raw decoded JSON.
     * @return array|WP_Error
     */
    public static function normalize_manifest(array $meta) {
        foreach (array( 'name', 'slug', 'version' ) as $key) {
            if (empty($meta[ $key ]) || ! is_string($meta[ $key ])) {
                return new WP_Error('invalid_manifest', sprintf('template.json is missing or has invalid "%s".', $key));
            }
        }

        $format = isset($meta['lem_format']) ? (int) $meta['lem_format'] : self::LEM_FORMAT_VERSION;
        if ($format > self::LEM_FORMAT_VERSION) {
            return new WP_Error(
                'lem_format',
                sprintf(
                    'This template pack uses manifest format %d; this plugin supports up to %d.',
                    $format,
                    self::LEM_FORMAT_VERSION
                )
            );
        }

        $meta['lem_format'] = $format;
        $meta['slug']       = sanitize_key($meta['slug']);

        $string_fields = array( 'description', 'author', 'author_url', 'license', 'requires_lem', 'preview_url', 'screenshot', 'support_url', 'docs_url', 'update_url', 'update_id' );
        foreach ($string_fields as $field) {
            if (isset($meta[ $field ]) && ! is_string($meta[ $field ])) {
                unset($meta[ $field ]);
            }
        }

        if (! empty($meta['preview_url']) && ! self::is_https_url($meta['preview_url'])) {
            unset($meta['preview_url']);
        }
        if (! empty($meta['screenshot']) && ! self::is_https_url($meta['screenshot'])) {
            unset($meta['screenshot']);
        }
        foreach (array( 'support_url', 'docs_url', 'author_url', 'update_url' ) as $url_field) {
            if (! empty($meta[ $url_field ]) && ! wp_http_validate_url($meta[ $url_field ])) {
                unset($meta[ $url_field ]);
            }
        }

        if (isset($meta['type']) && ! is_array($meta['type'])) {
            unset($meta['type']);
        }

        if (isset($meta['files']) && ! is_array($meta['files'])) {
            unset($meta['files']);
        }

        $requires_check = self::validate_requires_lem($meta['requires_lem'] ?? '');
        if (is_wp_error($requires_check)) {
            return $requires_check;
        }

        return $meta;
    }

    /**
     * @param string $constraint e.g. ">=1.2.0" or "1.2.0"
     * @return true|WP_Error
     */
    public static function validate_requires_lem(string $constraint) {
        if ($constraint === '') {
            return true;
        }
        $operator = '>=';
        $version  = $constraint;
        if (preg_match('/^([><=!]+)\s*(.+)$/', $constraint, $m)) {
            $operator = $m[1];
            $version  = trim($m[2]);
        }
        if (! version_compare(LEM_VERSION, $version, $operator)) {
            return new WP_Error(
                'requires_lem',
                sprintf(
                    /* translators: 1: operator, 2: required version, 3: current LEM version */
                    __('This template pack requires Live Event Manager %1$s %2$s (current: %3$s).', 'live-event-manager'),
                    $operator,
                    $version,
                    LEM_VERSION
                )
            );
        }
        return true;
    }

    /**
     * Preview image URL for admin UI (preview_url preferred over legacy screenshot).
     *
     * @param array<string, mixed> $meta
     */
    public static function get_preview_url(array $meta): string {
        if (! empty($meta['preview_url']) && self::is_https_url($meta['preview_url'])) {
            return $meta['preview_url'];
        }
        if (! empty($meta['screenshot']) && self::is_https_url($meta['screenshot'])) {
            return $meta['screenshot'];
        }
        return '';
    }

    /**
     * Check for updates (reserved). Core does not call remote URLs; use filter for marketplace plugins.
     *
     * @return null|array{version?: string, package_url?: string, changelog?: string}
     */
    public static function check_pack_update(string $slug, array $meta): ?array {
        $response = apply_filters('lem_template_pack_update_response', null, $slug, $meta);
        return is_array($response) ? $response : null;
    }

    /**
     * Show admin notice when the active pack is incompatible with the current LEM version.
     */
    public static function maybe_render_incompatibility_notice(): void {
        if (! is_admin() || ! current_user_can('manage_options')) {
            return;
        }
        $slug = self::get_active_slug();
        if ($slug === self::DEFAULT_SLUG) {
            return;
        }
        $meta = self::get_pack_meta($slug);
        if (empty($meta['requires_lem'])) {
            return;
        }
        $check = self::validate_requires_lem((string) $meta['requires_lem']);
        if (! is_wp_error($check)) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Live Event Manager', 'live-event-manager'),
            esc_html($check->get_error_message())
        );
    }

    /**
     * @param string $path Absolute filesystem path.
     * @param string $filename Template basename.
     */
    private static function apply_filtered_template_path(string $path, string $filename): string {
        $filtered = (string) apply_filters('lem_resolve_template_file', $path, $filename);
        if (defined('WP_DEBUG') && WP_DEBUG && $filtered !== '' && ! is_readable($filtered)) {
            _doing_it_wrong(
                __FUNCTION__,
                sprintf(
                    'lem_resolve_template_file must return a readable path; "%s" was returned for %s.',
                    $filtered,
                    $filename
                ),
                '1.2.0'
            );
        }
        return $filtered;
    }

    private static function is_https_url(string $url): bool {
        return (bool) wp_http_validate_url($url) && stripos($url, 'https://') === 0;
    }

    /**
     * Returns packs registered via register_pack_source(), keyed by slug (later sources overwrite).
     * Callbacks are isolated: exceptions are caught so bad add-ons cannot break front-end resolution.
     * Result is cached per-request; call register_pack_source() to bust the cache.
     *
     * @return array<string, array{path: string, meta: array}>
     */
    private static function collect_registered_packs(): array {
        if (self::$registered_packs_cache !== null) {
            return self::$registered_packs_cache;
        }

        $by_slug = array();
        foreach (self::$pack_sources as $cb) {
            try {
                $items = call_user_func($cb);
            } catch ( \Throwable $e ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( sprintf( '[LEM] template pack source error: %s', $e->getMessage() ) );
                }
                do_action( 'lem_template_pack_source_error', $e, $cb );
                continue;
            }
            if ( ! is_array( $items ) ) {
                continue;
            }
            foreach ( $items as $item ) {
                $row = self::filter_valid_registered_pack_item( $item );
                if ( $row === null ) {
                    continue;
                }
                $by_slug[ $row['slug'] ] = array(
                    'path' => $row['path'],
                    'meta' => $row['meta'],
                );
            }
        }

        self::$registered_packs_cache = $by_slug;
        return $by_slug;
    }

    /**
     * Returns all packs bundled with the plugin (under template-packs/), keyed by slug.
     * Uses the same discovery logic as get_installed_templates() but scoped to in-plugin packs only.
     *
     * @return array<string, array>  Slug → manifest array (includes 'path' key).
     */
    public static function get_bundled_template_packs(): array {
        $bundled = array();
        $base    = LEM_PLUGIN_DIR . 'template-packs/';

        if (!is_dir($base)) {
            return $bundled;
        }

        foreach ((array) glob($base . '*', GLOB_ONLYDIR) as $dir) {
            $json_path = trailingslashit($dir) . 'template.json';
            if (!file_exists($json_path)) {
                continue;
            }
            $raw  = file_get_contents($json_path);
            $meta = json_decode($raw, true);
            if (!is_array($meta) || empty($meta['slug']) || empty($meta['name'])) {
                continue;
            }
            $normalized = self::normalize_manifest($meta);
            if (is_wp_error($normalized)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[LEM] Invalid bundled template.json in ' . $dir . ': ' . $normalized->get_error_message());
                }
                continue;
            }
            $slug                    = $normalized['slug'];
            $normalized['path']      = trailingslashit($dir);
            $normalized['built_in']  = true;
            $normalized['preview_url'] = self::get_preview_url($normalized);
            $bundled[ $slug ]        = $normalized;
        }

        return $bundled;
    }

    /**
     * @param mixed $item Entry returned by a pack source callback.
     * @return null|array{slug: string, path: string, meta: array}
     */
    private static function filter_valid_registered_pack_item( $item ) {
        if ( ! is_array( $item ) ) {
            return null;
        }
        if ( empty( $item['slug'] ) || ! is_string( $item['slug'] ) || empty( $item['path'] ) || ! is_string( $item['path'] ) ) {
            return null;
        }
        $slug = sanitize_key( $item['slug'] );
        if ( $slug === '' || $slug === self::DEFAULT_SLUG ) {
            return null;
        }
        if ( ! empty( $item['url'] ) && ! is_string( $item['url'] ) ) {
            return null;
        }
        $path = wp_normalize_path( trailingslashit( $item['path'] ) );
        if ( $path === '' || ! is_dir( $path ) || ! file_exists( $path . 'template.json' ) ) {
            return null;
        }
        return array(
            'slug' => $slug,
            'path' => $path,
            'meta' => $item,
        );
    }

    /**
     * Whether the slug exists as a user-installed directory, bundled pack, or registered source pack.
     */
    public static function is_pack_available(string $slug): bool {
        $slug = sanitize_key($slug);
        if ($slug === self::DEFAULT_SLUG) {
            return true;
        }
        $dir = self::get_template_dir($slug);
        if (is_dir($dir) && file_exists($dir . 'template.json')) {
            return true;
        }
        $reg = self::collect_registered_packs();
        return isset($reg[ $slug ]);
    }

    /**
     * Returns the slug of the currently active template pack.
     * Falls back to 'default' if nothing is set.
     */
    public static function get_active_slug(): string {
        $settings = get_option('lem_settings', array());
        $slug     = sanitize_key($settings['active_event_template'] ?? '');
        return ($slug !== '') ? $slug : self::DEFAULT_SLUG;
    }

    /**
     * Filesystem path for a pack install directory (with trailing slash).
     *
     * For slug "default", returns the plugin core templates/ directory.
     * For other slugs, returns wp-content/lem-templates/{slug}/ only — not bundled
     * template-packs/ or paths from register_pack_source().
     */
    public static function get_template_dir(string $slug): string {
        if ($slug === self::DEFAULT_SLUG) {
            return LEM_PLUGIN_DIR . 'templates/';
        }
        return self::TEMPLATES_BASE_DIR . trailingslashit(sanitize_file_name($slug));
    }

    /**
     * Public URL for user-installed packs under wp-content/lem-templates/{slug}/.
     *
     * Default slug uses plugin templates/ URL. Does not cover registered or bundled roots.
     */
    public static function get_template_url(string $slug): string {
        if ($slug === self::DEFAULT_SLUG) {
            return LEM_PLUGIN_URL . 'templates/';
        }
        return self::TEMPLATES_BASE_URL . trailingslashit(sanitize_file_name($slug));
    }

    /**
     * Core path resolver.
     *
     * Checks the active template pack for $filename first; falls back to the
     * plugin's built-in template. This means a pack only needs to ship the
     * files it actually overrides.
     *
     * Order for non-default packs: wp-content install > registered pack > bundled template-packs > core templates.
     *
     * @param  string $filename  e.g. 'single-event.php' or 'event-ticket-block.php'
     * @return string            Absolute path to the template file to load
     */
    public static function resolve_template_file(string $filename): string {
        $filename = ltrim(str_replace('\\', '/', $filename), '/');
        $slug     = self::get_active_slug();

        $fallback = LEM_PLUGIN_DIR . 'templates/' . $filename;

        if ($slug !== self::DEFAULT_SLUG) {
            $installed_path = self::get_template_dir($slug) . $filename;
            if (file_exists($installed_path)) {
                return self::apply_filtered_template_path($installed_path, $filename);
            }

            $registered = self::collect_registered_packs();
            if (!empty($registered[ $slug ]['path'])) {
                $reg_path = $registered[ $slug ]['path'] . $filename;
                if (file_exists($reg_path)) {
                    return self::apply_filtered_template_path($reg_path, $filename);
                }
            }

            $bundled_path = LEM_PLUGIN_DIR . 'template-packs/'
                . sanitize_file_name($slug) . '/' . $filename;
            if (file_exists($bundled_path)) {
                return self::apply_filtered_template_path($bundled_path, $filename);
            }
        }

        return self::apply_filtered_template_path($fallback, $filename);
    }

    /**
     * Returns the URL for a custom template asset (e.g. assets/style.css).
     * Returns an empty string if the active template is default, or the asset
     * file doesn't exist in the active pack.
     *
     * @param  string $asset  e.g. 'assets/style.css'
     * @return string         URL or empty string
     */
    public static function get_active_asset_url(string $asset): string {
        $asset = ltrim(str_replace('\\', '/', $asset), '/');
        $slug  = self::get_active_slug();
        if ($slug === self::DEFAULT_SLUG) {
            return '';
        }

        $installed_dir = self::TEMPLATES_BASE_DIR . trailingslashit(sanitize_file_name($slug));
        if (file_exists($installed_dir . $asset)) {
            return self::TEMPLATES_BASE_URL . trailingslashit(sanitize_file_name($slug)) . $asset;
        }

        $registered = self::collect_registered_packs();
        if (!empty($registered[ $slug ]['path']) && file_exists($registered[ $slug ]['path'] . $asset)) {
            $meta = $registered[ $slug ]['meta'];
            if (!empty($meta['url'])) {
                return trailingslashit($meta['url']) . $asset;
            }
            $pack_path = $registered[ $slug ]['path'];
            $public    = self::guess_public_base_url_for_path($pack_path);
            if ($public !== '') {
                return trailingslashit($public) . $asset;
            }
        }

        $bundled_dir = LEM_PLUGIN_DIR . 'template-packs/' . sanitize_file_name($slug) . '/';
        if (file_exists($bundled_dir . $asset)) {
            return LEM_PLUGIN_URL . 'template-packs/' . sanitize_file_name($slug) . '/' . $asset;
        }

        return '';
    }

    /**
     * Best-effort URL for a directory under wp-content; empty if unknown.
     */
    private static function guess_public_base_url_for_path(string $absolute_dir): string {
        $absolute_dir = trailingslashit(wp_normalize_path($absolute_dir));
        $content      = trailingslashit(wp_normalize_path(WP_CONTENT_DIR));
        if (strpos($absolute_dir, $content) === 0) {
            $rel = ltrim(substr($absolute_dir, strlen($content)), '/');
            return trailingslashit(content_url($rel));
        }
        return '';
    }

    /**
     * Returns an array of all installed template packs.
     * The built-in 'default' entry is always the first item.
     *
     * @return array[]  Each item: ['slug', 'name', 'version', 'description', 'author', 'author_url', 'type', 'built_in']
     */
    public static function get_installed_templates(): array {
        $templates = array(
            array(
                'slug'        => self::DEFAULT_SLUG,
                'name'        => 'Default',
                'description' => 'Built-in plugin template.',
                'author'      => 'LEM',
                'author_url'  => '',
                'version'     => LEM_VERSION,
                'type'        => array( 'event_page', 'paywall' ),
                'built_in'    => true,
            ),
        );

        $seen = array( self::DEFAULT_SLUG => true );

        $base = self::TEMPLATES_BASE_DIR;
        if (is_dir($base)) {
            foreach ((array) glob($base . '*', GLOB_ONLYDIR) as $dir) {
                $json_path = trailingslashit($dir) . 'template.json';
                if (!file_exists($json_path)) {
                    continue;
                }
                $raw  = file_get_contents($json_path);
                $meta = json_decode($raw, true);
                if (!is_array($meta) || empty($meta['slug']) || empty($meta['name'])) {
                    continue;
                }
                $normalized = self::normalize_manifest($meta);
                if (is_wp_error($normalized)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[LEM] Invalid template.json in ' . $dir . ': ' . $normalized->get_error_message());
                    }
                    continue;
                }
                $normalized['built_in']    = false;
                $normalized['preview_url'] = self::get_preview_url($normalized);
                $templates[]               = $normalized;
                $seen[ $normalized['slug'] ] = true;
            }
        }

        foreach (self::collect_registered_packs() as $slug => $info) {
            if (!empty($seen[ $slug ])) {
                continue;
            }
            $m = $info['meta'];
            if (!is_array($m)) {
                continue;
            }
            $m['slug']                = $slug;
            $m['built_in']            = false;
            $m['register_source']     = true;
            $templates[]              = $m;
            $seen[ $slug ]            = true;
        }

        return apply_filters('lem_installed_template_packs', $templates);
    }

    /**
     * Activates a template pack by slug.
     * Validates that the slug is either 'default' or an installed pack.
     *
     * @param  string $slug
     * @return true|WP_Error
     */
    public static function activate_template(string $slug) {
        $slug = sanitize_key($slug);

        if ($slug !== self::DEFAULT_SLUG && !self::is_pack_available($slug)) {
            return new WP_Error('not_found', 'Template pack not found.');
        }

        if ($slug !== self::DEFAULT_SLUG) {
            $meta = self::get_pack_meta($slug);
            if (!empty($meta['requires_lem'])) {
                $check = self::validate_requires_lem((string) $meta['requires_lem']);
                if (is_wp_error($check)) {
                    return $check;
                }
            }
        }

        $settings                          = get_option('lem_settings', array());
        $previous_slug                     = $settings['active_event_template'] ?? self::DEFAULT_SLUG;
        $settings['active_event_template'] = $slug;
        update_option('lem_settings', $settings);

        $meta = self::get_pack_meta($slug);
        do_action('lem_template_pack_activated', $slug, $meta, $previous_slug);

        return true;
    }

    /**
     * Deletes an installed template pack.
     * Refuses to delete 'default'. If the deleted pack is currently active,
     * the active template is reverted to 'default' first.
     *
     * @param  string $slug
     * @return true|WP_Error
     */
    public static function delete_template(string $slug) {
        $slug = sanitize_key($slug);

        if ($slug === self::DEFAULT_SLUG) {
            return new WP_Error('protected', 'The built-in default template cannot be deleted.');
        }

        $reg = self::collect_registered_packs();
        if (!empty($reg[ $slug ]) && !is_dir(self::get_template_dir($slug))) {
            return new WP_Error('protected', 'This template is supplied by another plugin and cannot be deleted here.');
        }

        $dir = self::get_template_dir($slug);

        $real_base = realpath(self::TEMPLATES_BASE_DIR);
        $real_dir  = realpath($dir);
        if ($real_base === false || $real_dir === false || strpos($real_dir, $real_base) !== 0) {
            return new WP_Error('bad_path', 'Resolved path is outside the templates directory.');
        }

        if (!is_dir($real_dir)) {
            return new WP_Error('not_found', 'Template pack not found.');
        }

        if (self::get_active_slug() === $slug) {
            self::activate_template(self::DEFAULT_SLUG);
        }

        $meta   = self::get_pack_meta($slug);
        $result = self::recursive_rmdir($real_dir);
        if (!$result) {
            return new WP_Error('delete_failed', 'Could not remove template directory.');
        }

        do_action('lem_template_pack_deleted', $slug, $meta);

        return true;
    }

    /**
     * Return the parsed template.json for a slug, or an empty array if unavailable.
     *
     * @param  string $slug
     * @return array
     */
    private static function get_pack_meta(string $slug): array {
        $slug = sanitize_key($slug);

        if ($slug === self::DEFAULT_SLUG) {
            return array('slug' => self::DEFAULT_SLUG, 'name' => 'Default', 'version' => LEM_VERSION);
        }

        $json_path = self::get_template_dir($slug) . 'template.json';
        if (!file_exists($json_path)) {
            $bundled = self::get_bundled_template_packs();
            return $bundled[$slug] ?? array();
        }

        $raw  = file_get_contents($json_path);
        $meta = json_decode($raw, true);
        if (!is_array($meta)) {
            return array();
        }
        $normalized = self::normalize_manifest($meta);
        return is_wp_error($normalized) ? $meta : $normalized;
    }

    /**
     * Validates and installs a template pack from an uploaded ZIP file.
     *
     * Security enforced:
     *  - 5 MB size cap
     *  - Path traversal check on every ZIP entry
     *  - Allowlist built from default files + template.json "files" array
     *  - slug must match the top-level folder name
     *  - 'default' slug rejected
     *
     * @param  string $tmp_path  Path to the uploaded tmp file ($_FILES[...]['tmp_name'])
     * @return array|WP_Error    Parsed template.json metadata on success
     */
    public static function install_from_zip(string $tmp_path) {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('no_ziparchive', 'The ZipArchive PHP extension is required to install template packs.');
        }
        if (!file_exists($tmp_path) || filesize($tmp_path) === 0) {
            return new WP_Error('empty_file', 'The uploaded file appears to be empty.');
        }
        if (filesize($tmp_path) > self::MAX_ZIP_SIZE) {
            return new WP_Error('too_large', 'The ZIP file exceeds the 5 MB size limit.');
        }

        $zip = new ZipArchive();
        $res = $zip->open($tmp_path);
        if ($res !== true) {
            return new WP_Error('bad_zip', 'Could not open the ZIP file (error code: ' . $res . ').');
        }

        $folder_name = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (strpos($name, '..') !== false || strpos($name, './') !== false) {
                $zip->close();
                return new WP_Error('path_traversal', 'The ZIP contains unsafe file paths.');
            }

            if ($folder_name === '') {
                $parts = explode('/', $name, 2);
                if (!empty($parts[0])) {
                    $folder_name = $parts[0];
                }
            }
        }

        $candidate_slug = sanitize_key($folder_name);
        if ($candidate_slug === '') {
            $zip->close();
            return new WP_Error('no_folder', 'The ZIP must contain a single top-level folder named after the template slug.');
        }
        if ($candidate_slug === self::DEFAULT_SLUG) {
            $zip->close();
            return new WP_Error('reserved_slug', '"default" is a reserved slug and cannot be used for a template pack.');
        }

        $json_content = $zip->getFromName($folder_name . '/template.json');
        if ($json_content === false) {
            $zip->close();
            return new WP_Error('missing_manifest', 'template.json was not found in the ZIP.');
        }

        $meta = json_decode($json_content, true);
        if (!is_array($meta)) {
            $zip->close();
            return new WP_Error('invalid_manifest', 'template.json is missing required fields (name, slug, version).');
        }

        $meta = self::normalize_manifest($meta);
        if (is_wp_error($meta)) {
            $zip->close();
            return $meta;
        }

        if ($meta['slug'] !== $candidate_slug) {
            $zip->close();
            return new WP_Error('slug_mismatch', 'The "slug" in template.json must match the ZIP\'s top-level folder name.');
        }

        $meta = apply_filters('lem_template_pack_install_metadata', $meta, $candidate_slug);
        if (!is_array($meta)) {
            $zip->close();
            return new WP_Error('invalid_manifest', 'lem_template_pack_install_metadata must return an array.');
        }

        $meta = apply_filters('lem_before_template_pack_install', $meta, $candidate_slug, $tmp_path);
        if (is_wp_error($meta)) {
            $zip->close();
            return $meta;
        }

        $allowed = self::build_zip_allowlist($folder_name, is_array($meta) ? $meta : array());

        $dest_dir = self::TEMPLATES_BASE_DIR . $candidate_slug . '/';
        wp_mkdir_p($dest_dir);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);

            if (!in_array($entry, $allowed, true)) {
                continue;
            }
            if (substr($entry, -1) === '/') {
                continue;
            }

            $relative = substr($entry, strlen($folder_name) + 1);
            $out_path = $dest_dir . $relative;

            $real_dest = realpath($dest_dir);
            wp_mkdir_p(dirname($out_path));
            $out_real = realpath(dirname($out_path));
            if ($real_dest !== false && $out_real !== false && strpos($out_real, $real_dest) !== 0) {
                $zip->close();
                return new WP_Error('path_traversal', 'Extraction path escapes the template directory.');
            }

            file_put_contents($out_path, $zip->getFromIndex($i));
        }

        $zip->close();

        do_action('lem_template_pack_installed', $candidate_slug, $meta);

        return $meta;
    }

    /**
     * Build exact ZIP entry paths allowed for extraction.
     *
     * @param string               $folder_name Top-level folder name in the archive.
     * @param array<string, mixed> $meta        Parsed template.json.
     * @return string[]
     */
    private static function build_zip_allowlist(string $folder_name, array $meta): array {
        $allowed = array();

        foreach (self::$default_pack_file_relatives as $rel) {
            $allowed[] = $folder_name . '/' . $rel;
        }

        if (!empty($meta['files']) && is_array($meta['files'])) {
            foreach ($meta['files'] as $extra) {
                $clean = self::sanitize_pack_relative_path((string) $extra);
                if ($clean === '') {
                    continue;
                }
                $allowed[] = $folder_name . '/' . $clean;
            }
        }

        $allowed[] = $folder_name . '/';
        $allowed[] = $folder_name . '/assets/';

        $allowed = array_unique($allowed);
        return array_values($allowed);
    }

    /**
     * Sanitize a relative path inside a template pack (no leading slash, no "..").
     */
    private static function sanitize_pack_relative_path(string $rel): string {
        $rel = str_replace('\\', '/', $rel);
        $rel = ltrim($rel, '/');
        if ($rel === '' || strpos($rel, '..') !== false) {
            return '';
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.\/-]*$/', $rel)) {
            return '';
        }
        return $rel;
    }

    /**
     * Recursively removes a directory and all its contents.
     */
    private static function recursive_rmdir(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        return rmdir($dir);
    }
}
