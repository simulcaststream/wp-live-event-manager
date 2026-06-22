<?php
/**
 * Normalized payment status returned by LEM_Payment_Provider_Interface methods.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LEM_Payment_Status {

    /**
     * Validate and normalize provider status arrays for core reconciliation.
     *
     * @param array|WP_Error $status          Result from get_payment_status() or finalize_checkout().
     * @param string         $reference_id    Checkout reference passed to the provider (session/order id).
     * @return array|WP_Error
     */
    public static function normalize($status, string $reference_id) {
        if (is_wp_error($status)) {
            return $status;
        }

        if (! is_array($status)) {
            return new WP_Error('invalid_payment_status', 'Payment provider must return an array or WP_Error.');
        }

        $normalized = array(
            'paid'        => ! empty($status['paid']),
            'email'       => isset($status['email']) ? (string) $status['email'] : '',
            'event_id'    => isset($status['event_id']) ? (string) $status['event_id'] : '',
            'payment_id'  => isset($status['payment_id']) && (string) $status['payment_id'] !== ''
                ? (string) $status['payment_id']
                : $reference_id,
            'order_id'    => isset($status['order_id']) ? (string) $status['order_id'] : '',
        );

        if ($normalized['paid'] && ($normalized['event_id'] === '' || $normalized['email'] === '')) {
            return new WP_Error(
                'incomplete_payment_status',
                'Paid status must include event_id and email for access reconciliation.'
            );
        }

        if ($normalized['email'] !== '') {
            $normalized['email'] = sanitize_email($normalized['email']);
        }

        return $normalized;
    }

    /**
     * Resolve status for API reconciliation: finalize_checkout() when supported, else get_payment_status().
     *
     * @param LEM_Payment_Provider_Interface $provider
     * @param string                         $reference_id
     * @param array<string, mixed>           $context Optional hints (e.g. event_id).
     * @return array|WP_Error Normalized status array.
     */
    public static function resolve_for_reconciliation(LEM_Payment_Provider_Interface $provider, string $reference_id, array $context = array()) {
        $finalized = $provider->finalize_checkout($reference_id, $context);

        if (is_wp_error($finalized)) {
            if ($finalized->get_error_code() !== 'not_applicable') {
                return $finalized;
            }
            $raw = $provider->get_payment_status($reference_id);
        } else {
            $raw = $finalized;
        }

        return self::normalize($raw, $reference_id);
    }
}
