<?php
/**
 * Shared AJAX helpers for core and adaptor plugins.
 *
 * Adaptor plugins should call these helpers at the top of every wp_ajax_*
 * handler so permission checks stay consistent across the ecosystem.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_Ajax_Helpers {

    /**
     * Verify an admin AJAX request: nonce + manage_options capability.
     * On failure, sends a JSON error response and exits.
     */
    public static function verify_admin_request(string $nonce_action, string $nonce_field = 'nonce'): void {
        if (!current_user_can('manage_options')) {
            self::json_error('Insufficient permissions', 403);
        }
        $nonce = $_REQUEST[$nonce_field] ?? '';
        if (!is_string($nonce) || !wp_verify_nonce($nonce, $nonce_action)) {
            self::json_error('Invalid nonce', 403);
        }
    }

    /**
     * Verify a public AJAX request: nonce only (no capability check).
     */
    public static function verify_public_request(string $nonce_action, string $nonce_field = 'nonce'): void {
        $nonce = $_REQUEST[$nonce_field] ?? '';
        if (!is_string($nonce) || !wp_verify_nonce($nonce, $nonce_action)) {
            self::json_error('Invalid nonce', 403);
        }
    }

    /**
     * Send a JSON error response and exit. Mirrors wp_send_json_error but with status code.
     *
     * @return never
     */
    public static function json_error(string $message, int $http_status = 400): void {
        status_header($http_status);
        wp_send_json_error(array('message' => $message), $http_status);
    }

    /**
     * Send a JSON success response and exit.
     *
     * @return never
     */
    public static function json_success($data = null): void {
        wp_send_json_success($data);
    }
}
