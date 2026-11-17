<?php
defined( 'ABSPATH' ) || exit;

class WC_Pagbank_Gateway_Pix extends WC_Payment_Gateway {

	private string $token;

	public function __construct() {
		$this->id                 = 'dw_pagbank_pix';
		$this->method_title       = __( 'PagBank — PIX', 'wooflow-pagbank' );
		$this->method_description = __( 'PIX com QR Code e confirmação pelo webhook do PagBank.', 'wooflow-pagbank' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );
		$this->icon               = WOOFLOW_PAGBANK_URL . 'assets/img/icon/icon-pix.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title', __( 'PIX', 'wooflow-pagbank' ) );
		$this->description = $this->get_option( 'description' );
		$this->token       = $this->resolve_token();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Ativar', 'wooflow-pagbank' ),
				'type'    => 'checkbox',
				'label'   => __( 'Ativar PIX PagBank', 'wooflow-pagbank' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'   => __( 'Título', 'wooflow-pagbank' ),
				'type'    => 'text',
				'default' => __( 'PIX', 'wooflow-pagbank' ),
			),
			'description'    => array(
				'title'   => __( 'Descrição', 'wooflow-pagbank' ),
				'type'    => 'textarea',
				'default' => __( 'O QR Code aparece depois que você confirmar o pedido.', 'wooflow-pagbank' ),
			),
			'token'          => array(
				'title'       => __( 'Token', 'wooflow-pagbank' ),
				'type'        => 'password',
				'description' => __( 'Opcional. Se vazio, usa o token do gateway de cartão. A conta precisa ter uma chave PIX.', 'wooflow-pagbank' ),
			),
			'pix_expires_in' => array(
				'title'             => __( 'Expiração do PIX (segundos)', 'wooflow-pagbank' ),
				'type'              => 'number',
				'default'           => '3600',
				'custom_attributes' => array(
					'min'  => '120',
					'step' => '1',
				),
			),
			'debug'          => array(
				'title'   => __( 'Log de depuração', 'wooflow-pagbank' ),
				'type'    => 'checkbox',
				'label'   => __( 'Registrar detalhes em WooCommerce → Status → Logs.', 'wooflow-pagbank' ),
				'default' => 'no',
			),
		);
	}

	public function payment_fields(): void {
		echo '<div class="wooflow-pagbank-wrapper">';
		if ( 'sandbox' === WC_Pagbank_API::environment() ) {
			echo '<p class="wooflow-pagbank-warning">' . esc_html__( 'Sandbox: este pagamento é de teste e não tem valor real.', 'wooflow-pagbank' ) . '</p>';
		}
		if ( $this->description ) {
			echo '<p class="wooflow-pagbank-card-description">' . wp_kses_post( $this->description ) . '</p>';
		}
		echo '<p class="wooflow-pagbank-info">' . esc_html__( 'Ao finalizar o pedido, geramos um QR Code e o código copia e cola.', 'wooflow-pagbank' ) . '</p>';
		WC_Pagbank_Customer::render_tax_field( 'wooflow_pagbank_pix_tax_id' );
		echo '</div>';
	}

	public function validate_fields(): bool {
		if ( '' === $this->token ) {
			wc_add_notice( __( 'O PIX PagBank ainda não está configurado nesta loja.', 'wooflow-pagbank' ), 'error' );
			return false;
		}
		return true;
	}

	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Pedido não encontrado.', 'wooflow-pagbank' ), 'error' );
			return array( 'result' => 'fail' );
		}

		try {
			WC_Pagbank_Customer::sync_checkout_fields( $order );
			$api    = new WC_Pagbank_API( $this->token, WC_Pagbank_API::environment() );
			$result = $api->charge_pix( $order, (int) $this->get_option( 'pix_expires_in', 3600 ) );

			if ( '' === ( $result['qr_code'] ?? '' ) && '' === ( $result['qr_png'] ?? '' ) && ! WC_Pagbank_API::is_declined_status( (string) $result['status'] ) ) {
				throw new WC_Pagbank_Exception( __( 'O PagBank não retornou o QR Code do PIX.', 'wooflow-pagbank' ) );
			}

			WC_Pagbank_Payment::remember( $order, $result, 'pix' );
			$order->update_meta_data( '_pagbank_pix_qr_code', (string) $result['qr_code'] );
			$order->update_meta_data( '_pagbank_pix_qr_url', esc_url_raw( (string) $result['qr_png'] ) );
			$order->update_meta_data( '_pagbank_pix_qr_png', (string) $result['qr_png_base64'] );
			$order->update_meta_data( '_pagbank_pix_expires', (string) $result['expires'] );
			$order->save();

			if ( ! WC_Pagbank_API::is_declined_status( (string) $result['status'] ) && 'PAID' !== $result['status'] ) {
				do_action( 'dw_pagbank_pix_created', $order->get_id(), (string) $result['expires'] );
			}

			return WC_Pagbank_Payment::finish(
				$order,
				$result,
				__( 'Aguardando o pagamento do PIX.', 'wooflow-pagbank' ),
				__( 'O PagBank não autorizou este PIX. Tente outro método.', 'wooflow-pagbank' )
			);
		} catch ( Exception $e ) {
			return WC_Pagbank_Payment::failure( $e );
		}
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'pagbank_refund', __( 'Pedido não encontrado.', 'wooflow-pagbank' ) );
		}
		return WC_Pagbank_API::refund_order( $order, $amount );
	}

	private function resolve_token(): string {
		$own = trim( (string) $this->get_option( 'token' ) );
		return '' !== $own ? $own : WC_Pagbank_API::account_token();
	}
}
