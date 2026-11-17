<?php
defined( 'ABSPATH' ) || exit;

class WC_Pagbank_Gateway_Boleto extends WC_Payment_Gateway {

	private string $token;

	public function __construct() {
		$this->id                 = 'dw_pagbank_boleto';
		$this->method_title       = __( 'PagBank — Boleto', 'wooflow-pagbank' );
		$this->method_description = __( 'Boleto pela API de pedidos do PagBank.', 'wooflow-pagbank' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );
		$this->icon               = WOOFLOW_PAGBANK_URL . 'assets/img/icon/icon-boleto.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title', __( 'Boleto', 'wooflow-pagbank' ) );
		$this->description = $this->get_option( 'description' );
		$this->token       = $this->resolve_token();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Ativar', 'wooflow-pagbank' ),
				'type'    => 'checkbox',
				'label'   => __( 'Ativar boleto PagBank', 'wooflow-pagbank' ),
				'default' => 'no',
			),
			'title'           => array(
				'title'   => __( 'Título', 'wooflow-pagbank' ),
				'type'    => 'text',
				'default' => __( 'Boleto', 'wooflow-pagbank' ),
			),
			'description'     => array(
				'title'   => __( 'Descrição', 'wooflow-pagbank' ),
				'type'    => 'textarea',
				'default' => __( 'O boleto é gerado depois da confirmação do pedido.', 'wooflow-pagbank' ),
			),
			'token'           => array(
				'title'       => __( 'Token', 'wooflow-pagbank' ),
				'type'        => 'password',
				'description' => __( 'Opcional. Se vazio, usa o token do gateway de cartão.', 'wooflow-pagbank' ),
			),
			'boleto_due_days' => array(
				'title'             => __( 'Vencimento (dias)', 'wooflow-pagbank' ),
				'type'              => 'number',
				'default'           => '3',
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '60',
					'step' => '1',
				),
			),
			'debug'           => array(
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
		$days = max( 1, (int) $this->get_option( 'boleto_due_days', 3 ) );
		echo '<p class="wooflow-pagbank-info">' . esc_html(
			sprintf(
				/* translators: %d: days until boleto due date */
				__( 'O boleto vence em %d dias. A linha digitável e o PDF aparecem na página do pedido.', 'wooflow-pagbank' ),
				$days
			)
		) . '</p>';
		WC_Pagbank_Customer::render_tax_field( 'wooflow_pagbank_boleto_tax_id' );
		echo '</div>';
	}

	public function validate_fields(): bool {
		if ( '' === $this->token ) {
			wc_add_notice( __( 'O boleto PagBank ainda não está configurado nesta loja.', 'wooflow-pagbank' ), 'error' );
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
			$result = $api->charge_boleto( $order, (int) $this->get_option( 'boleto_due_days', 3 ) );

			if ( '' === ( $result['barcode'] ?? '' ) && '' === ( $result['boleto_pdf'] ?? '' ) && ! WC_Pagbank_API::is_declined_status( (string) $result['status'] ) ) {
				throw new WC_Pagbank_Exception( __( 'O PagBank não retornou a linha digitável do boleto.', 'wooflow-pagbank' ) );
			}

			WC_Pagbank_Payment::remember( $order, $result, 'boleto' );
			$order->update_meta_data( '_pagbank_boleto_pdf', esc_url_raw( (string) $result['boleto_pdf'] ) );
			$order->update_meta_data( '_pagbank_boleto_line', (string) $result['barcode'] );
			$order->update_meta_data( '_pagbank_boleto_due', (string) $result['due_date'] );
			$order->save();

			return WC_Pagbank_Payment::finish(
				$order,
				$result,
				__( 'Aguardando o pagamento do boleto.', 'wooflow-pagbank' ),
				__( 'O PagBank não autorizou este boleto. Tente outro método.', 'wooflow-pagbank' )
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
