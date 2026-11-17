<?php
/**
 * Limpeza na desinstalação. Sem Freemius, o uninstall.php é o caminho certo.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'woocommerce_dw_pagbank_settings' );
delete_option( 'woocommerce_dw_pagbank_pix_settings' );
delete_option( 'woocommerce_dw_pagbank_boleto_settings' );

global $wpdb;

$meta_like = $wpdb->esc_like( '_pagbank_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $meta_like ) );

$orders_meta = $wpdb->prefix . 'wc_orders_meta';
$found       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta ) );
if ( $found === $orders_meta ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$orders_meta} WHERE meta_key LIKE %s", $meta_like ) );
}

$transient_like = $wpdb->esc_like( '_transient_dw_pbk_' ) . '%';
$timeout_like   = $wpdb->esc_like( '_transient_timeout_dw_pbk_' ) . '%';
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$transient_like,
		$timeout_like
	)
);

$hook  = 'dw_pagbank_pix_expire_order';
$crons = _get_cron_array();
if ( is_array( $crons ) ) {
	foreach ( $crons as $timestamp => $hooks ) {
		if ( empty( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
			continue;
		}
		foreach ( $hooks[ $hook ] as $event ) {
			wp_unschedule_event( (int) $timestamp, $hook, $event['args'] ?? array() );
		}
	}
}
