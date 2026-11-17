<?php
/**
 * Plugin Name:       WooFlow PagBank for WooCommerce
 * Plugin URI:        https://github.com/davidwilliamcosta/wooflow-pagbank
 * Description:       Cartão, PIX e boleto no WooCommerce pela API de pedidos do PagBank. O cartão é criptografado no navegador.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            David William da Costa
 * Author URI:        https://davidwilliam.studio/
 * License:           GPL-2.0-or-later
 * Text Domain:       wooflow-pagbank
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'WOOFLOW_PAGBANK_VERSION', '1.0.0' );
define( 'WOOFLOW_PAGBANK_FILE', __FILE__ );
define( 'WOOFLOW_PAGBANK_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOOFLOW_PAGBANK_URL', plugin_dir_url( __FILE__ ) );
define( 'WOOFLOW_PAGBANK_PIX_EXPIRY_EVENT', 'dw_pagbank_pix_expire_order' );

/**
 * Remove eventos agendados mesmo quando o WP guarda argumentos.
 */
function wooflow_pagbank_clear_scheduled_hook( string $hook ): void {
	wp_clear_scheduled_hook( $hook );

	$crons = _get_cron_array();
	if ( ! is_array( $crons ) ) {
		return;
	}

	foreach ( $crons as $timestamp => $hooks ) {
		if ( empty( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
			continue;
		}

		foreach ( $hooks[ $hook ] as $event ) {
			wp_unschedule_event( (int) $timestamp, $hook, $event['args'] ?? array() );
		}
	}
}

function wooflow_pagbank_deactivate(): void {
	wooflow_pagbank_clear_scheduled_hook( WOOFLOW_PAGBANK_PIX_EXPIRY_EVENT );
}

register_deactivation_hook( WOOFLOW_PAGBANK_FILE, 'wooflow_pagbank_deactivate' );

add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'wooflow-pagbank', false, dirname( plugin_basename( WOOFLOW_PAGBANK_FILE ) ) . '/languages' );
	}
);

add_action( 'plugins_loaded', 'wooflow_pagbank_init', 11 );

function wooflow_pagbank_init(): void {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p>'
					. esc_html__( 'WooFlow PagBank requer o WooCommerce ativo.', 'wooflow-pagbank' )
					. '</p></div>';
			}
		);
		return;
	}

	require_once WOOFLOW_PAGBANK_PATH . 'includes/class-logger.php';
	require_once WOOFLOW_PAGBANK_PATH . 'includes/class-customer.php';
	require_once WOOFLOW_PAGBANK_PATH . 'includes/class-api.php';
	require_once WOOFLOW_PAGBANK_PATH . 'includes/class-payment.php';
	require_once WOOFLOW_PAGBANK_PATH . 'includes/class-webhook.php';
	require_once WOOFLOW_PAGBANK_PATH . 'includes/class-order-instructions.php';
	require_once WOOFLOW_PAGBANK_PATH . 'includes/class-admin-order.php';
	require_once WOOFLOW_PAGBANK_PATH . 'includes/class-pix-expiry-cron.php';
	require_once WOOFLOW_PAGBANK_PATH . 'includes/class-gateway.php';
	require_once WOOFLOW_PAGBANK_PATH . 'includes/class-gateway-pix.php';
	require_once WOOFLOW_PAGBANK_PATH . 'includes/class-gateway-boleto.php';

	WC_Pagbank_Pix_Expiry::init();
	WC_Pagbank_Order_Instructions::init();
	WC_Pagbank_Admin_Order::init();

	if ( ! function_exists( 'openssl_verify' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p>'
					. esc_html__( 'WooFlow PagBank precisa da extensão OpenSSL do PHP para validar os webhooks.', 'wooflow-pagbank' )
					. '</p></div>';
			}
		);
	}

	add_action( 'woocommerce_after_checkout_validation', array( 'WC_Pagbank_Customer', 'after_checkout_validation' ), 10, 2 );
	add_action( 'woocommerce_api_dw_pagbank_webhook', array( 'WC_Pagbank_Webhook', 'receive' ) );
	add_action( 'wp_enqueue_scripts', array( 'WC_Pagbank_Gateway', 'enqueue_scripts' ) );
	add_action( 'admin_enqueue_scripts', array( 'WC_Pagbank_Gateway', 'enqueue_admin_assets' ) );
	add_action( 'wp_ajax_wooflow_pagbank_run_diagnostics', array( 'WC_Pagbank_Gateway', 'ajax_run_diagnostics' ) );
	add_action( 'wp_ajax_wooflow_pagbank_create_public_key', array( 'WC_Pagbank_Gateway', 'ajax_create_public_key' ) );
	add_action( 'wp_ajax_wooflow_pagbank_3ds_session', array( 'WC_Pagbank_Gateway', 'ajax_threeds_session' ) );
	add_action( 'wp_ajax_nopriv_wooflow_pagbank_3ds_session', array( 'WC_Pagbank_Gateway', 'ajax_threeds_session' ) );
	add_action( 'wp_ajax_wooflow_pagbank_installments', array( 'WC_Pagbank_Gateway', 'ajax_installments' ) );
	add_action( 'wp_ajax_nopriv_wooflow_pagbank_installments', array( 'WC_Pagbank_Gateway', 'ajax_installments' ) );

	add_filter(
		'woocommerce_payment_gateways',
		static function ( array $gateways ): array {
			$gateways[] = 'WC_Pagbank_Gateway';
			$gateways[] = 'WC_Pagbank_Gateway_Pix';
			$gateways[] = 'WC_Pagbank_Gateway_Boleto';
			return $gateways;
		}
	);

	add_action( 'woocommerce_blocks_loaded', 'wooflow_pagbank_register_blocks' );
}

function wooflow_pagbank_register_blocks(): void {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once WOOFLOW_PAGBANK_PATH . 'includes/class-blocks.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		static function ( $registry ): void {
			$registry->register( new WC_Pagbank_Blocks_Card() );
			$registry->register( new WC_Pagbank_Blocks_Pix() );
			$registry->register( new WC_Pagbank_Blocks_Boleto() );
		}
	);
}
