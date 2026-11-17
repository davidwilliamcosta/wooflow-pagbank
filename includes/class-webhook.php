<?php
defined( 'ABSPATH' ) || exit;

class WC_Pagbank_Webhook {

	public static function receive(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			status_header( 405 );
			exit;
		}

		$raw = file_get_contents( 'php://input' );
		$raw = is_string( $raw ) ? $raw : '';
		if ( strlen( $raw ) > 1000000 ) {
			status_header( 413 );
			exit;
		}

		$provided = isset( $_GET['token'] ) ? (string) wp_unslash( $_GET['token'] ) : '';
		$expected = WC_Pagbank_API::webhook_token();
		if ( '' === $expected || '' === $provided || ! hash_equals( $expected, $provided ) ) {
			WC_Pagbank_Logger::log( 'webhook rejeitado: token ausente ou inválido.', 'warning' );
			status_header( 401 );
			exit;
		}

		if ( ! self::signature_is_valid( $raw ) ) {
			WC_Pagbank_Logger::log( 'webhook rejeitado: assinatura x-payload-signature inválida.', 'warning' );
			status_header( 401 );
			exit;
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			WC_Pagbank_Logger::log( 'webhook com JSON inválido.', 'warning' );
			status_header( 200 );
			echo wp_json_encode( array( 'received' => true ) );
			exit;
		}

		$charges = self::extract_charges( $payload );
		if ( ! $charges ) {
			WC_Pagbank_Logger::log( 'webhook sem cobrança. chaves=' . implode( ',', array_keys( $payload ) ), 'info' );
			status_header( 200 );
			echo wp_json_encode( array( 'received' => true ) );
			exit;
		}

		foreach ( $charges as $charge ) {
			if ( ! is_array( $charge ) ) {
				continue;
			}
			$order = self::find_order( $charge, $payload );
			if ( ! $order ) {
				WC_Pagbank_Logger::log( 'webhook sem pedido correspondente.', 'info' );
				continue;
			}
			if ( ! self::charge_belongs_to_order( $order, $charge ) ) {
				continue;
			}
			self::apply_charge( $order, $charge );
		}

		status_header( 200 );
		echo wp_json_encode( array( 'received' => true ) );
		exit;
	}

	/**
	 * @param array<string,mixed> $charge
	 */
	public static function apply_charge( WC_Order $order, array $charge ): void {
		$status = strtoupper( (string) ( $charge['status'] ?? '' ) );
		if ( '' === $status ) {
			return;
		}

		$charge_id = (string) ( $charge['id'] ?? '' );
		if ( '' !== $charge_id ) {
			$order->update_meta_data( '_pagbank_charge_id', sanitize_text_field( $charge_id ) );
			$order->set_transaction_id( sanitize_text_field( $charge_id ) );
		}

		$last = (string) $order->get_meta( '_pagbank_charge_status' );
		if ( $last === $status && ( 'PAID' !== $status || $order->is_paid() ) ) {
			$order->save();
			return;
		}

		$order->update_meta_data( '_pagbank_charge_status', $status );
		self::sync_refunded_amount( $order, $charge );

		if ( 'PAID' === $status ) {
			if ( self::is_underpaid( $order, $charge ) ) {
				$order->add_order_note( __( 'PagBank confirmou um valor menor que o total do pedido. O pedido não foi concluído automaticamente.', 'wooflow-pagbank' ) );
				$order->save();
				return;
			}
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $charge_id );
				$order->add_order_note( sprintf( 'PagBank confirmou o pagamento (%s).', $charge_id ) );
			} else {
				$order->save();
			}
			return;
		}

		if ( in_array( $status, array( 'CANCELED', 'CANCELLED' ), true ) ) {
			if ( $order->is_paid() ) {
				$order->save();
				return;
			}
			if ( ! $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) {
				$order->update_status( 'cancelled', __( 'Cobrança cancelada no PagBank sem pagamento.', 'wooflow-pagbank' ) );
			} else {
				$order->save();
			}
			return;
		}

		if ( 'DECLINED' === $status ) {
			if ( ! $order->is_paid() && ! $order->has_status( 'failed' ) ) {
				$order->update_status( 'failed', __( 'PagBank recusou a cobrança.', 'wooflow-pagbank' ) );
			} else {
				$order->save();
			}
			return;
		}

		if ( in_array( $status, array( 'WAITING', 'AUTHORIZED', 'IN_ANALYSIS' ), true ) ) {
			if ( ! $order->is_paid() && ! $order->has_status( array( 'on-hold', 'cancelled', 'failed', 'refunded' ) ) ) {
				$order->update_status( 'on-hold', __( 'Aguardando confirmação do PagBank.', 'wooflow-pagbank' ) );
			} else {
				$order->save();
			}
			return;
		}

		$order->add_order_note( sprintf( 'PagBank enviou o status %s, sem ação automática.', $status ) );
		$order->save();
		WC_Pagbank_Logger::log( 'status desconhecido ' . $status . ' order=' . $order->get_id(), 'warning' );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<int,array<string,mixed>>
	 */
	private static function extract_charges( array $payload ): array {
		if ( isset( $payload['charges'] ) && is_array( $payload['charges'] ) ) {
			return $payload['charges'];
		}

		$id = (string) ( $payload['id'] ?? '' );
		if ( str_starts_with( $id, 'CHAR_' ) ) {
			return array( $payload );
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $charge
	 * @param array<string,mixed> $payload
	 */
	private static function find_order( array $charge, array $payload ): ?WC_Order {
		$reference = (string) ( $charge['reference_id'] ?? '' );
		if ( '' === $reference ) {
			$reference = (string) ( $payload['reference_id'] ?? '' );
		}

		if ( ctype_digit( $reference ) ) {
			$order = wc_get_order( (int) $reference );
			if ( $order instanceof WC_Order && self::is_our_order( $order ) ) {
				return $order;
			}
		}

		$charge_id = (string) ( $charge['id'] ?? '' );
		if ( '' === $charge_id || ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$ids = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_pagbank_charge_id',
				'meta_value' => $charge_id,
				'return'     => 'ids',
			)
		);

		if ( empty( $ids ) ) {
			return null;
		}

		$order = wc_get_order( (int) $ids[0] );
		return $order instanceof WC_Order && self::is_our_order( $order ) ? $order : null;
	}

	/**
	 * Cobrança nova só entra se a API autenticada confirmar o reference_id do pedido.
	 *
	 * @param array<string,mixed> $charge
	 */
	private static function charge_belongs_to_order( WC_Order $order, array $charge ): bool {
		$incoming = (string) ( $charge['id'] ?? '' );
		if ( '' === $incoming ) {
			WC_Pagbank_Logger::log( 'SEGURANÇA webhook sem charge id. order=' . $order->get_id(), 'warning' );
			return false;
		}

		$stored = (string) $order->get_meta( '_pagbank_charge_id' );
		if ( '' !== $stored && hash_equals( $stored, $incoming ) ) {
			return true;
		}

		try {
			$remote = WC_Pagbank_API::for_order( $order )->lookup_charge( $order, $incoming );
		} catch ( Exception $e ) {
			WC_Pagbank_Logger::log( 'SEGURANÇA webhook não confirmou a cobrança. order=' . $order->get_id() . ' ' . $e->getMessage(), 'warning' );
			return false;
		}

		$remote_ref = (string) ( $remote['reference_id'] ?? '' );
		if ( (string) $order->get_id() !== $remote_ref ) {
			WC_Pagbank_Logger::log( 'SEGURANÇA charge de outro pedido. order=' . $order->get_id(), 'warning' );
			return false;
		}

		return true;
	}

	private static function is_our_order( WC_Order $order ): bool {
		return in_array( $order->get_payment_method(), array( 'dw_pagbank', 'dw_pagbank_pix', 'dw_pagbank_boleto' ), true );
	}

	/**
	 * @param array<string,mixed> $charge
	 */
	private static function is_underpaid( WC_Order $order, array $charge ): bool {
		if ( ! isset( $charge['amount']['summary']['paid'] ) ) {
			return false;
		}
		$paid     = (int) $charge['amount']['summary']['paid'];
		$expected = WC_Pagbank_API::to_cents( $order->get_total() );
		return $paid + 1 < $expected;
	}

	/**
	 * @param array<string,mixed> $charge
	 */
	private static function sync_refunded_amount( WC_Order $order, array $charge ): void {
		$refunded = (int) ( $charge['amount']['summary']['refunded'] ?? 0 );
		$already  = (int) $order->get_meta( '_pagbank_refunded_cents' );
		$delta    = $refunded - $already;
		if ( $delta <= 0 || ! function_exists( 'wc_create_refund' ) ) {
			return;
		}

		$amount    = $delta / 100;
		$remaining = (float) $order->get_total() - (float) $order->get_total_refunded();
		if ( $amount - $remaining > 0.01 ) {
			$amount = max( 0, $remaining );
		}
		if ( $amount <= 0.009 ) {
			$order->update_meta_data( '_pagbank_refunded_cents', $refunded );
			return;
		}

		$result = wc_create_refund(
			array(
				'amount'         => wc_format_decimal( $amount ),
				'reason'         => __( 'Estorno confirmado pelo PagBank', 'wooflow-pagbank' ),
				'order_id'       => $order->get_id(),
				'refund_payment' => false,
				'restock_items'  => 0 === $already,
			)
		);

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( __( 'O PagBank informou um estorno, mas o WooCommerce não conseguiu registrá-lo.', 'wooflow-pagbank' ) );
			WC_Pagbank_Logger::log( 'estorno WooCommerce falhou order=' . $order->get_id() . ' ' . $result->get_error_message(), 'error' );
			return;
		}

		$order->update_meta_data( '_pagbank_refunded_cents', $refunded );
	}

	private static function signature_is_valid( string $raw ): bool {
		$signatures = self::header_values( 'x-payload-signature' );
		if ( ! $signatures || ! function_exists( 'openssl_verify' ) ) {
			return false;
		}

		$key = self::webhook_public_key( false );
		if ( '' !== $key && self::verify_payload( $raw, $signatures, $key ) ) {
			return true;
		}

		$key = self::webhook_public_key( true );
		return '' !== $key && self::verify_payload( $raw, $signatures, $key );
	}

	private static function webhook_public_key( bool $refresh ): string {
		$cache_key = 'dw_pbk_wh_pub_' . WC_Pagbank_API::environment();
		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		} else {
			delete_transient( $cache_key );
		}

		try {
			$key = WC_Pagbank_API::account()->get_webhook_public_key();
		} catch ( Exception $e ) {
			WC_Pagbank_Logger::log( 'falha ao buscar chave pública de webhook. ' . $e->getMessage(), 'error' );
			return '';
		}

		if ( '' !== $key ) {
			set_transient( $cache_key, $key, 12 * HOUR_IN_SECONDS );
		}

		return $key;
	}

	/**
	 * @param array<int,string> $signatures
	 */
	private static function verify_payload( string $payload, array $signatures, string $public_key_b64 ): bool {
		$public_key_b64 = trim( $public_key_b64 );
		$pem            = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( $public_key_b64, 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		$key            = openssl_pkey_get_public( $pem );
		if ( ! $key ) {
			return false;
		}

		foreach ( $signatures as $signature ) {
			$decoded = base64_decode( $signature, true );
			if ( ! is_string( $decoded ) || '' === $decoded ) {
				continue;
			}
			if ( 1 === openssl_verify( $payload, $decoded, $key, OPENSSL_ALGO_SHA256 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<int,string>
	 */
	private static function header_values( string $name ): array {
		$values = array();
		$wanted = strtolower( $name );

		if ( function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $header => $value ) {
				if ( strtolower( (string) $header ) !== $wanted ) {
					continue;
				}
				foreach ( explode( ',', (string) $value ) as $part ) {
					$part = trim( $part );
					if ( '' !== $part ) {
						$values[] = $part;
					}
				}
			}
		}

		$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		if ( isset( $_SERVER[ $server_key ] ) ) {
			foreach ( explode( ',', (string) wp_unslash( $_SERVER[ $server_key ] ) ) as $part ) {
				$part = trim( $part );
				if ( '' !== $part && ! in_array( $part, $values, true ) ) {
					$values[] = $part;
				}
			}
		}

		return $values;
	}
}
