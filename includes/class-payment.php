<?php
defined( 'ABSPATH' ) || exit;

class WC_Pagbank_Payment {

	/**
	 * @param array<string,mixed> $result
	 */
	public static function remember( WC_Order $order, array $result, string $method ): void {
		$charge_id = (string) ( $result['charge_id'] ?? '' );
		if ( '' === $charge_id ) {
			throw new WC_Pagbank_Exception( __( 'O PagBank não retornou o identificador da cobrança.', 'wooflow-pagbank' ) );
		}

		$order->update_meta_data( '_pagbank_charge_id', sanitize_text_field( $charge_id ) );
		$order->update_meta_data( '_pagbank_order_id', sanitize_text_field( (string) ( $result['order_id'] ?? '' ) ) );
		$order->update_meta_data( '_pagbank_method', $method );
		$order->update_meta_data( '_pagbank_charge_status', sanitize_text_field( (string) ( $result['status'] ?? '' ) ) );
		$order->set_transaction_id( sanitize_text_field( $charge_id ) );
		$order->save();
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array{result:string,redirect:string}
	 */
	public static function finish( WC_Order $order, array $result, string $hold_note, string $declined_message ): array {
		if ( WC_Pagbank_API::is_declined_status( (string) ( $result['status'] ?? '' ) ) ) {
			$attempt = max( 1, (int) $order->get_meta( '_pagbank_attempt' ) );
			$order->update_meta_data( '_pagbank_attempt', $attempt + 1 );
			$order->update_status( 'failed', __( 'PagBank recusou a cobrança.', 'wooflow-pagbank' ) );
			throw new WC_Pagbank_Exception( $declined_message, false, true );
		}

		if ( 'PAID' === ( $result['status'] ?? '' ) ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete( (string) $result['charge_id'] );
			}
		} elseif ( ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold', $hold_note );
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_order_received_url(),
		);
	}

	/**
	 * @return array{result:string}
	 */
	public static function failure( Exception $exception ): array {
		$safe = $exception instanceof WC_Pagbank_Exception && ( $exception->is_safe_for_customer() || $exception->is_declined() );
		if ( ! $safe ) {
			WC_Pagbank_Logger::log( $exception->getMessage(), 'error' );
		} else {
			WC_Pagbank_Logger::log( $exception->getMessage(), 'info' );
		}

		wc_add_notice(
			$safe
				? $exception->getMessage()
				: __( 'Não foi possível processar o pagamento. Tente novamente ou escolha outro método.', 'wooflow-pagbank' ),
			'error'
		);

		return array( 'result' => 'fail' );
	}
}
