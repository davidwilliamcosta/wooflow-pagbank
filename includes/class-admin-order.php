<?php
defined( 'ABSPATH' ) || exit;

class WC_Pagbank_Admin_Order {

	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'register_box' ) );
		add_action( 'admin_post_wooflow_pagbank_sync', array( self::class, 'sync' ) );
	}

	public static function register_box(): void {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box(
				'wooflow-pagbank-order',
				__( 'PagBank', 'wooflow-pagbank' ),
				array( self::class, 'render' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $order->get_payment_method(), array( 'dw_pagbank', 'dw_pagbank_pix', 'dw_pagbank_boleto' ), true ) ) {
			echo '<p>' . esc_html__( 'Este pedido não usa PagBank.', 'wooflow-pagbank' ) . '</p>';
			return;
		}

		$rows = array(
			__( 'Método', 'wooflow-pagbank' )  => (string) $order->get_meta( '_pagbank_method' ),
			__( 'Cobrança', 'wooflow-pagbank' ) => (string) $order->get_meta( '_pagbank_charge_id' ),
			__( 'Pedido PagBank', 'wooflow-pagbank' ) => (string) $order->get_meta( '_pagbank_order_id' ),
			__( 'Status', 'wooflow-pagbank' ) => (string) $order->get_meta( '_pagbank_charge_status' ),
		);

		echo '<ul style="margin:0;">';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</li>';
		}
		echo '</ul>';

		$pdf = (string) $order->get_meta( '_pagbank_boleto_pdf' );
		if ( '' !== $pdf ) {
			echo '<p><a href="' . esc_url( $pdf ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Abrir boleto', 'wooflow-pagbank' ) . '</a></p>';
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wooflow_pagbank_sync&order_id=' . $order->get_id() ),
			'wooflow_pagbank_sync_' . $order->get_id()
		);
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Consultar PagBank', 'wooflow-pagbank' ) . '</a></p>';
	}

	public static function sync(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'wooflow-pagbank' ) );
		}

		$order_id = absint( $_GET['order_id'] ?? 0 );
		check_admin_referer( 'wooflow_pagbank_sync_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Pedido não encontrado.', 'wooflow-pagbank' ) );
		}

		$charge_id = (string) $order->get_meta( '_pagbank_charge_id' );
		if ( '' === $charge_id ) {
			$order->add_order_note( __( 'Consulta PagBank ignorada: o pedido não tem cobrança.', 'wooflow-pagbank' ) );
		} else {
			try {
				$charge = WC_Pagbank_API::for_order( $order )->lookup_charge( $order );
				WC_Pagbank_Webhook::apply_charge( $order, $charge );
				$order->add_order_note(
					sprintf(
						/* translators: %s: PagBank charge status */
						__( 'Consulta PagBank: status %s.', 'wooflow-pagbank' ),
						strtoupper( (string) ( $charge['status'] ?? '' ) )
					)
				);
			} catch ( Exception $e ) {
				WC_Pagbank_Logger::log( 'consulta admin order=' . $order_id . ' ' . $e->getMessage(), 'error' );
				$order->add_order_note( __( 'Não foi possível consultar a cobrança no PagBank. O detalhe está no log.', 'wooflow-pagbank' ) );
			}
		}

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}
}
