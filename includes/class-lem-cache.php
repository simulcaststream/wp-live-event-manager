<?php
/**
 * Upstash Redis HTTP cache layer for Live Event Manager.
 *
 * Uses the Upstash REST API so no phpredis extension is required.
 * Upstash is mandatory — if not configured the plugin will display an admin notice
 * and all cache operations will be no-ops that return false/null.
 *
 * Usage (instance, compatible with existing $redis->method() call sites):
 *   $cache = LEM_Cache::instance();
 *   if ($cache) { $cache->setex('key', 3600, 'value'); }
 *
 * Usage (static shorthand):
 *   LEM_Cache::set('key', 'value', 3600);
 *   LEM_Cache::get('key');
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_Cache {

    /** @var LEM_Cache|null */
    private static $instance = null;

    /** @var array|null Cached config so we don't call get_option on every operation */
    private static $config = null;

    /** @var array Per-request in-memory cache (replaces the old $memory_cache static array) */
    private static $memory = [];

    private string $url;
    private string $token;

    private function __construct(string $url, string $token) {
        $this->url   = rtrim($url, '/');
        $this->token = $token;
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    private static function load_config(array $override = []): array {
        if (self::$config === null || !empty($override)) {
            $settings    = get_option('lem_settings', []);
            self::$config = [
                'url'   => $settings['upstash_redis_url']   ?? '',
                'token' => $settings['upstash_redis_token'] ?? '',
            ];
        }
        return array_merge(self::$config, $override);
    }

    /**
     * Returns true if Upstash URL and token are both set.
     *
     * @param array $override Optional settings override (used by connection test).
     */
    public static function is_configured(array $override = []): bool {
        $cfg = self::load_config($override);
        return !empty($cfg['url']) && !empty($cfg['token']);
    }

    /**
     * Returns the singleton cache instance, or false if Upstash is not configured.
     *
     * @param array $override Optional settings override.
     * @return self|false
     */
    public static function instance(array $override = []) {
        $cfg = self::load_config($override);

        if (empty($cfg['url']) || empty($cfg['token'])) {
            return false;
        }

        // If an override is passed we build a fresh instance so tests use the right credentials.
        if (!empty($override)) {
            return new self($cfg['url'], $cfg['token']);
        }

        if (self::$instance === null) {
            self::$instance = new self($cfg['url'], $cfg['token']);
        }

        return self::$instance;
    }

    /** Call after saving settings so the next request picks up new credentials. */
    public static function reset(): void {
        self::$instance = null;
        self::$config   = null;
        self::$memory   = [];
    }

    // -------------------------------------------------------------------------
    // HTTP transport
    // -------------------------------------------------------------------------

    /**
     * Execute a single Redis command via the Upstash REST API.
     *
     * @param array $command e.g. ['SET', 'key', 'value', 'EX', 3600]
     * @return mixed The 'result' field from the response, or null on error.
     */
    /** @var bool True when the last request() failed due to a transport/HTTP error (not a Redis-level miss). */
    private bool $last_request_failed = false;

    /** Whether the last Redis operation was a transport failure (vs a key-miss). */
    public function last_request_failed(): bool {
        return $this->last_request_failed;
    }

    private function request(array $command) {
        $this->last_request_failed = false;

        $response = wp_remote_post($this->url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($command),
            'timeout' => 3,
        ]);

        if (is_wp_error($response)) {
            $this->last_request_failed = true;
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 500 || $status === 0) {
            $this->last_request_failed = true;
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            $this->last_request_failed = true;
            return null;
        }

        return $body['result'] ?? null;
    }

    /**
     * Execute multiple commands in a single HTTP round-trip via Upstash pipeline.
     *
     * @param array[] $commands Array of command arrays.
     * @return array Array of result values (same order as commands), empty on error.
     */
    public function execute_pipeline(array $commands): array {
        if (empty($commands)) {
            return [];
        }

        $response = wp_remote_post($this->url . '/pipeline', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($commands),
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            return [];
        }

        return array_map(fn($r) => $r['result'] ?? null, $body);
    }

    // -------------------------------------------------------------------------
    // Core Redis operations (instance methods — compatible with $redis->method())
    // -------------------------------------------------------------------------

    /**
     * Get a value. Returns null (not false) when the key does not exist so callers
     * can distinguish "not found" from "false stored as value".
     * We also accept the old convention of returning false for not-found since
     * existing code checks `if (!$result)`.
     */
    public function get(string $key) {
        // Per-request memory cache
        if (isset(self::$memory[$key])) {
            $entry = self::$memory[$key];
            if ($entry['exp'] === 0 || $entry['exp'] > time()) {
                return $entry['val'];
            }
            unset(self::$memory[$key]);
        }

        $result = $this->request(['GET', $key]);

        if ($result !== null) {
            self::$memory[$key] = ['val' => $result, 'exp' => time() + 10];
        }

        return $result;
    }

    /**
     * Set a value with optional TTL (seconds). TTL 0 = no expiry.
     */
    public function set(string $key, $value, int $ttl = 0): bool {
        $cmd = ['SET', $key, $value];
        if ($ttl > 0) {
            $cmd[] = 'EX';
            $cmd[] = $ttl;
        }
        return $this->request($cmd) === 'OK';
    }

    /**
     * Set a value with a TTL (SETEX). Argument order matches phpredis: key, ttl, value.
     */
    public function setex(string $key, int $ttl, $value): bool {
        return $this->set($key, $value, $ttl);
    }

    /**
     * SET if Not eXists. Returns true if the key was set, false if it already existed.
     */
    public function setnx(string $key, $value): bool {
        $result = $this->request(['SETNX', $key, $value]);
        return (int) $result === 1;
    }

    /**
     * Atomic SET key value NX EX ttl.
     *
     * Unlike setnx() + setex() (two round-trips), this single command guarantees
     * that the key is either created with a TTL or not created at all — no window
     * where the key could exist without an expiry if the process crashes between
     * the two separate calls.
     *
     * Returns true if the key was set (i.e. did not already exist), false otherwise.
     */
    public function set_nx_ex(string $key, $value, int $ttl): bool {
        $result = $this->request(['SET', $key, $value, 'NX', 'EX', $ttl]);
        return $result === 'OK';
    }

    /**
     * Delete one or more keys. Returns the number of keys deleted.
     *
     * @param string|string[] $key
     */
    public function del($key): int {
        $keys = is_array($key) ? $key : [$key];
        if (empty($keys)) {
            return 0;
        }
        $cmd    = array_merge(['DEL'], $keys);
        $result = $this->request($cmd);

        // Also clear from memory cache
        foreach ($keys as $k) {
            unset(self::$memory[$k]);
        }

        return (int) $result;
    }

    /**
     * Check whether a key exists. Returns true/false (not 0/1) for simpler conditionals.
     */
    public function exists(string $key): bool {
        return (int) $this->request(['EXISTS', $key]) > 0;
    }

    /**
     * Pattern match keys. Note: KEYS is O(N) — avoid on large keyspaces.
     * Returns an array of matching key names.
     */
    public function keys(string $pattern): array {
        $result = $this->request(['KEYS', $pattern]);
        return is_array($result) ? $result : [];
    }

    /**
     * Returns a pipeline builder. Call ->execute() to flush all buffered commands.
     */
    public function pipeline(): LEM_Cache_Pipeline {
        return new LEM_Cache_Pipeline($this);
    }

    // -------------------------------------------------------------------------
    // Compatibility shims for code that calls info(), ping(), getDBNum()
    // -------------------------------------------------------------------------

    public function ping(): bool {
        return $this->request(['PING']) === 'PONG';
    }

    public function info(): array {
        return [
            'redis_mode'  => 'upstash',
            'driver'      => 'http-rest',
            'upstash_url' => preg_replace('/^(https?:\/\/[^.]+).*/', '$1…', $this->url),
        ];
    }

    public function getDBNum(): int {
        return 0; // Upstash doesn't support SELECT; always DB 0.
    }

    // -------------------------------------------------------------------------
    // Per-request in-memory cache helpers
    // -------------------------------------------------------------------------

    /**
     * Store a value in the per-request memory cache (never hits Upstash).
     * Used for hot-path reads within a single PHP request lifecycle.
     *
     * @param string $key
     * @param mixed  $value
     * @param int    $ttl   Seconds. 0 = valid for the whole request.
     */
    public static function remember_in_memory(string $key, $value, int $ttl = 0): void {
        self::$memory[$key] = [
            'val' => $value,
            'exp' => $ttl > 0 ? time() + $ttl : 0,
        ];
    }

    /** Retrieve a value from per-request memory, or null if missing/expired. */
    public static function from_memory(string $key) {
        if (!isset(self::$memory[$key])) {
            return null;
        }
        $entry = self::$memory[$key];
        if ($entry['exp'] !== 0 && $entry['exp'] <= time()) {
            unset(self::$memory[$key]);
            return null;
        }
        return $entry['val'];
    }

    /** Remove a key from per-request memory. */
    public static function forget_memory(string $key): void {
        unset(self::$memory[$key]);
    }

    /** Flush the entire per-request memory cache. */
    public static function flush_memory(): void {
        self::$memory = [];
    }

    // -------------------------------------------------------------------------
    // Admin notice
    // -------------------------------------------------------------------------

    /**
     * Registers an admin notice if Upstash is not configured.
     * Hook this onto 'admin_notices'.
     */
    public static function maybe_show_config_notice(): void {
        if (self::is_configured()) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings_url = admin_url('edit.php?post_type=lem_event&page=live-event-manager-settings');
        echo '<div class="notice notice-error"><p>';
        printf(
            '<strong>Live Event Manager:</strong> Upstash Redis is not configured. '
            . 'The plugin requires Upstash for JWT sessions and caching. '
            . '<a href="%s">Configure Upstash in Settings</a>.',
            esc_url($settings_url)
        );
        echo '</p></div>';
    }

}


/**
 * Pipeline builder — buffers commands and flushes them in one HTTP call.
 *
 * Usage:
 *   $pipe = $cache->pipeline();
 *   $pipe->setex('key1', 3600, 'val1');
 *   $pipe->setex('key2', 3600, 'val2');
 *   $results = $pipe->execute(); // [ 'OK', 'OK' ]
 */
class LEM_Cache_Pipeline {

    private LEM_Cache $cache;
    /** @var array[] */
    private array $commands = [];

    public function __construct(LEM_Cache $cache) {
        $this->cache = $cache;
    }

    public function get(string $key): self {
        $this->commands[] = ['GET', $key];
        return $this;
    }

    public function set(string $key, $value, int $ttl = 0): self {
        $cmd = ['SET', $key, $value];
        if ($ttl > 0) {
            $cmd[] = 'EX';
            $cmd[] = $ttl;
        }
        $this->commands[] = $cmd;
        return $this;
    }

    /** Argument order: key, ttl, value (matches phpredis SETEX). */
    public function setex(string $key, int $ttl, $value): self {
        $this->commands[] = ['SETEX', $key, $ttl, $value];
        return $this;
    }

    /** Atomic SET key value NX EX ttl — see LEM_Cache::set_nx_ex(). */
    public function set_nx_ex(string $key, $value, int $ttl): self {
        $this->commands[] = ['SET', $key, $value, 'NX', 'EX', $ttl];
        return $this;
    }

    /** @param string|string[] $key */
    public function del($key): self {
        $keys             = is_array($key) ? $key : [$key];
        $this->commands[] = array_merge(['DEL'], $keys);
        return $this;
    }

    public function exists(string $key): self {
        $this->commands[] = ['EXISTS', $key];
        return $this;
    }

    /**
     * Flush all buffered commands to Upstash in a single HTTP request.
     *
     * @return array Results array (same order as commands).
     */
    public function execute(): array {
        $results       = $this->cache->execute_pipeline($this->commands);
        $this->commands = [];
        return $results;
    }
}
