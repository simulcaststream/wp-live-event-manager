<?php
/**
 * Webhook log writer.
 *
 * Public service used by core and adaptor plugins to record incoming webhook
 * events into {prefix}lem_webhook_log. Keeps only the most recent 200 rows.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_Webhook_Log {

    /**
     * Record one webhook event.
     *
     * @param string $status One of: received, processed, skipped, failed,
     *                       verification_failed, missing_metadata, duplicate,
     *                       already_has_access, jwt_failed.
     * @param array  $data   { provider, source_ip, has_signature, event_type,
     *                        payment_id, event_id, email, message, payload_preview }
     */
    public static function record(string $status, array $data = array()): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lem_webhook_log';

        $payload_preview = $data['payload_preview'] ?? null;
        if (is_array($payload_preview) || is_object($payload_preview)) {
            $payload_preview = wp_json_encode($payload_preview);
        }
        if (is_string($payload_preview) && strlen($payload_preview) > 500) {
            $payload_preview = substr($payload_preview, 0, 500) . '…';
        }

        $wpdb->insert(
            $table,
            array(
                'received_at'     => current_time('mysql'),
                'provider'        => $data['provider']        ?? null,
                'source_ip'       => $data['source_ip']       ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                'has_signature'   => !empty($data['has_signature']) ? 1 : 0,
                'event_type'      => $data['event_type']      ?? null,
                'payment_id'      => $data['payment_id']      ?? null,
                'event_id'        => $data['event_id']        ?? null,
                'email'           => $data['email']           ?? null,
                'status'          => $status,
                'message'         => $data['message']         ?? null,
                'payload_preview' => $payload_preview,
            ),
            array('%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s')
        );

        $wpdb->query("DELETE FROM {$table} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {$table} ORDER BY received_at DESC LIMIT 200) AS keep_rows)");
    }
}
