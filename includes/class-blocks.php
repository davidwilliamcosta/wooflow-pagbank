<?php
defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WC_Pagbank_Blocks_Assets {

	/**
	 * @return array<int,string>
	 */
	public static function handles(): array {
		if ( ! wp_script_is( 'pagbank-sdk', 'registered' ) ) {
			wp_register_script( 'pagbank-sdk', 'https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js', array(), null, true );
		}

		if ( ! wp_script_is( 'wooflow-pagbank-blocks', 'registered' ) ) {
			wp_register_script(
				'wooflow-pagbank-blocks',
				WOOFLOW_PAGBANK_URL . 'assets/js/pagbank-blocks.js',
				array( 'pagbank-sdk', 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-data' ),
				WOOFLOW_PAGBANK_VERSION,
				true
			);
		}

		wp_enqueue_style( 'wooflow-pagbank', WOOFLOW_PAGBANK_URL . 'assets/css/pagbank.css', array(), WOOFLOW_PAGBANK_VERSION );
		return array( 'wooflow-pagbank-blocks' );
	}
}

class WC_Pagbank_Blocks_Card extends AbstractPaymentMethodType {

	protected $name = 'dw_pagbank';

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_dw_pagbank_settings', array() );
		if ( ! is_array( $this->settings ) ) {
			$this->settings = array();
		}
	}

	public function is_active(): bool {
		return filter_var( $this->settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN );
	}

	public function get_payment_method_script_handles(): array {
		return WC_Pagbank_Blocks_Assets::handles();
	}

	public function get_payment_method_data(): array {
		return array(
			'title'         => $this->settings['title'] ?? __( 'Cartão de crédito', 'wooflow-pagbank' ),
			'description'   => wp_strip_all_tags( (string) ( $this->settings['description'] ?? '' ) ),
			'publicKey'     => (string) ( $this->settings['public_key'] ?? '' ),
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'threedsNonce'  => wp_create_nonce( 'wooflow_pagbank_3ds' ),
			'installmentsNonce' => wp_create_nonce( 'wooflow_pagbank_installments' ),
			'sdkEnv'        => 'production' === ( $this->settings['environment'] ?? '' ) ? 'PROD' : 'SANDBOX',
			'sandbox'       => 'production' !== ( $this->settings['environment'] ?? '' ),
			'maxInstallments' => max( 1, min( 12, (int) ( $this->settings['max_installments'] ?? 12 ) ) ),
			'interestFreeInstallments' => max( 0, min( 12, (int) ( $this->settings['interest_free_installments'] ?? 12 ) ) ),
			'minInstallment'  => max( 0, (float) ( $this->settings['min_installment_amount'] ?? 5 ) ),
			'icons'         => array(
				'visa'       => WOOFLOW_PAGBANK_URL . 'assets/img/cards/visa.svg',
				'mastercard' => WOOFLOW_PAGBANK_URL . 'assets/img/cards/mastercard.svg',
				'elo'        => WOOFLOW_PAGBANK_URL . 'assets/img/cards/elo.svg',
				'amex'       => WOOFLOW_PAGBANK_URL . 'assets/img/cards/amex.svg',
			),
			'supports'      => array( 'products' ),
		);
	}
}

class WC_Pagbank_Blocks_Pix extends AbstractPaymentMethodType {

	protected $name = 'dw_pagbank_pix';

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_dw_pagbank_pix_settings', array() );
		if ( ! is_array( $this->settings ) ) {
			$this->settings = array();
		}
	}

	public function is_active(): bool {
		return filter_var( $this->settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN );
	}

	public function get_payment_method_script_handles(): array {
		return WC_Pagbank_Blocks_Assets::handles();
	}

	public function get_payment_method_data(): array {
		return array(
			'title'       => $this->settings['title'] ?? __( 'PIX', 'wooflow-pagbank' ),
			'description' => wp_strip_all_tags( (string) ( $this->settings['description'] ?? '' ) ),
			'sandbox'     => 'production' !== WC_Pagbank_API::environment(),
			'supports'    => array( 'products' ),
		);
	}
}

class WC_Pagbank_Blocks_Boleto extends AbstractPaymentMethodType {

	protected $name = 'dw_pagbank_boleto';

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_dw_pagbank_boleto_settings', array() );
		if ( ! is_array( $this->settings ) ) {
			$this->settings = array();
		}
	}

	public function is_active(): bool {
		return filter_var( $this->settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN );
	}

	public function get_payment_method_script_handles(): array {
		return WC_Pagbank_Blocks_Assets::handles();
	}

	public function get_payment_method_data(): array {
		return array(
			'title'       => $this->settings['title'] ?? __( 'Boleto', 'wooflow-pagbank' ),
			'description' => wp_strip_all_tags( (string) ( $this->settings['description'] ?? '' ) ),
			'sandbox'     => 'production' !== WC_Pagbank_API::environment(),
			'supports'    => array( 'products' ),
		);
	}
}
