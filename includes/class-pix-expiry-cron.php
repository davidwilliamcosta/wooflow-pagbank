<?php
defined( 'ABSPATH' ) || exit;

class WC_Pagbank_Pix_Expiry {

	public static function init(): void {
		add_action( 'dw_pagbank_pix_created', array( self::class, 'schedule' ), 10, 2 );
		add_action( WOOFLOW_PAGBANK_PIX_EXPIRY_EVENT, array( self::class, 'expire' ) );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'unschedule' ) );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'unschedule' ) );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'unschedule' ) );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'unschedule' ) );
		add_action( 'woocommerce_order_status_failed', array( self::class, 'unschedule' ) );
	}

	public static function schedule( int $order_id, string $expires_at ): void {
		$timestamp = WC_Pagbank_Customer::timestamp_from_api_datetime( $expires_at );
		if ( $timestamp < time() + 30 ) {
			$timestamp = time() + 60;
		}

		wp_clear_scheduled_hook( WOOFLOW_PAGBANK_PIX_EXPIRY_EVENT, array( $order_id ) );
		wp_schedule_single_event( $timestamp, WOOFLOW_PAGBANK_PIX_EXPIRY_EVENT, array( $order_id ) );
	}

	public static function unschedule( $order_id ): void {
		wp_clear_scheduled_hook( WOOFLOW_PAGBANK_PIX_EXPIRY_EVENT, array( (int) $order_id ) );
	}

	public static function expire( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'dw_pagbank_pix' !== $order->get_payment_method() ) {
			return;
		}
		if ( $order->is_paid() || $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) {
			return;
		}

		$expires_at = (string) $order->get_meta( '_pagbank_pix_expires' );
		$timestamp  = WC_Pagbank_Customer::timestamp_from_api_datetime( $expires_at );
		if ( $timestamp > time() + 30 ) {
			wp_schedule_single_event( $timestamp, WOOFLOW_PAGBANK_PIX_EXPIRY_EVENT, array( $order_id ) );
			return;
		}

		$charge_id = (string) $order->get_meta( '_pagbank_charge_id' );
		if ( '' === $charge_id ) {
			return;
		}

		try {
			$api    = WC_Pagbank_API::for_order( $order );
			$charge = $api->lookup_charge( $order );
		} catch ( Exception $e ) {
			WC_Pagbank_Logger::log( 'expiração do PIX sem resposta da API. order=' . $order_id, 'warning' );
			wp_schedule_single_event( time() + 600, WOOFLOW_PAGBANK_PIX_EXPIRY_EVENT, array( $order_id ) );
			return;
		}

		$status = strtoupper( (string) ( $charge['status'] ?? '' ) );
		if ( in_array( $status, array( 'PAID', 'DECLINED', 'CANCELED', 'CANCELLED' ), true ) ) {
			WC_Pagbank_Webhook::apply_charge( $order, $charge );
			return;
		}

		try {
			$api->cancel_charge( $charge_id, WC_Pagbank_API::to_cents( $order->get_total() ) );
			$order->add_order_note( __( 'PIX expirado. A cobrança foi cancelada no PagBank.', 'wooflow-pagbank' ) );
		} catch ( Exception $e ) {
			WC_Pagbank_Logger::log( 'cancelamento do PIX expirado falhou. order=' . $order_id . ' ' . $e->getMessage(), 'warning' );
			$order->add_order_note( __( 'PIX expirado. O PagBank não confirmou o cancelamento; o QR Code pode continuar pagável até a própria expiração.', 'wooflow-pagbank' ) );
		}

		if ( ! $order->is_paid() ) {
			$order->update_status( 'cancelled', __( 'PIX expirado sem pagamento.', 'wooflow-pagbank' ) );
		}
	}
}
