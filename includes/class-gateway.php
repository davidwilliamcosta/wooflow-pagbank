<?php
defined( 'ABSPATH' ) || exit;

class WC_Pagbank_Gateway extends WC_Payment_Gateway {

	private string $token;
	private string $public_key;
	private string $environment;
	private string $soft_descriptor;
	private int $max_installments;
	private int $interest_free_installments;
	private float $min_installment_amount;

	public function __construct() {
		$this->id                 = 'dw_pagbank';
		$this->method_title       = __( 'PagBank — Cartão de crédito', 'wooflow-pagbank' );
		$this->method_description = __( 'Cartão pela API de pedidos do PagBank. Os dados do cartão são criptografados no navegador.', 'wooflow-pagbank' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );
		$this->icon               = WOOFLOW_PAGBANK_URL . 'assets/img/icon/icon-card.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled                = $this->get_option( 'enabled' );
		$this->title                  = $this->get_option( 'title', __( 'Cartão de crédito', 'wooflow-pagbank' ) );
		$this->description            = $this->get_option( 'description' );
		$this->token                  = (string) $this->get_option( 'token' );
		$this->public_key             = trim( (string) $this->get_option( 'public_key' ) );
		$this->environment            = 'production' === $this->get_option( 'environment' ) ? 'production' : 'sandbox';
		$this->soft_descriptor        = WC_Pagbank_API::sanitize_soft_descriptor( (string) $this->get_option( 'soft_descriptor', '' ) );
		$this->max_installments           = max( 1, min( 12, (int) $this->get_option( 'max_installments', 12 ) ) );
		$this->interest_free_installments = max( 0, min( $this->max_installments, (int) $this->get_option( 'interest_free_installments', 12 ) ) );
		$this->min_installment_amount     = max( 0, (float) $this->get_option( 'min_installment_amount', 5 ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'                 => array(
				'title'   => __( 'Ativar', 'wooflow-pagbank' ),
				'type'    => 'checkbox',
				'label'   => __( 'Ativar cartão de crédito PagBank', 'wooflow-pagbank' ),
				'default' => 'no',
			),
			'environment'             => array(
				'title'       => __( 'Ambiente', 'wooflow-pagbank' ),
				'type'        => 'select',
				'description' => __( 'Sandbox e produção usam tokens diferentes. Este ambiente vale também para PIX e boleto.', 'wooflow-pagbank' ),
				'default'     => 'sandbox',
				'options'     => array(
					'sandbox'    => __( 'Sandbox', 'wooflow-pagbank' ),
					'production' => __( 'Produção', 'wooflow-pagbank' ),
				),
			),
			'title'                   => array(
				'title'   => __( 'Título', 'wooflow-pagbank' ),
				'type'    => 'text',
				'default' => __( 'Cartão de crédito', 'wooflow-pagbank' ),
			),
			'description'             => array(
				'title'   => __( 'Descrição', 'wooflow-pagbank' ),
				'type'    => 'textarea',
				'default' => __( 'Pague com cartão de crédito de forma segura.', 'wooflow-pagbank' ),
			),
			'token'                   => array(
				'title'       => __( 'Token', 'wooflow-pagbank' ),
				'type'        => 'password',
				'description' => __( 'Token de autenticação criado em PagBank → Integrações. Fica só no servidor. PIX e boleto usam este token quando o deles estiver vazio.', 'wooflow-pagbank' ),
			),
			'public_key'              => array(
				'title'       => __( 'Chave pública do cartão', 'wooflow-pagbank' ),
				'type'        => 'textarea',
				'description' => __( 'Usada pelo SDK do PagBank para criptografar o cartão no navegador. Cole a chave ou gere uma nova no botão abaixo.', 'wooflow-pagbank' ),
				'css'         => 'height: 90px;',
			),
			'soft_descriptor'         => array(
				'title'       => __( 'Identificação na fatura', 'wooflow-pagbank' ),
				'type'        => 'text',
				'description' => __( 'Texto na fatura do cartão. O plugin limita a 17 caracteres, sem acentos.', 'wooflow-pagbank' ),
				'desc_tip'    => true,
			),
			'webhook_token'           => array(
				'title'       => __( 'Token do webhook', 'wooflow-pagbank' ),
				'type'        => 'text',
				'description' => __( 'Senha da URL de notificação. Se ficar vazio, o plugin gera uma ao salvar. O PagBank ainda assina o corpo com x-payload-signature.', 'wooflow-pagbank' ),
			),
			'webhook_url'             => array(
				'title' => __( 'URL de notificação', 'wooflow-pagbank' ),
				'type'  => 'webhook_url',
			),
			'max_installments'        => array(
				'title'             => __( 'Máximo de parcelas', 'wooflow-pagbank' ),
				'type'              => 'number',
				'default'           => '12',
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '12',
					'step' => '1',
				),
			),
			'interest_free_installments' => array(
				'title'             => __( 'Parcelas sem juros', 'wooflow-pagbank' ),
				'type'              => 'number',
				'description'       => __( 'Até essa quantidade, a loja assume a taxa e o cliente paga o valor do pedido dividido. Acima disso, o plugin consulta a taxa do PagBank e soma o juros ao valor do cliente. Para deixar tudo sem juros, use o mesmo número do máximo de parcelas.', 'wooflow-pagbank' ),
				'default'           => '12',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '12',
					'step' => '1',
				),
			),
			'min_installment_amount'  => array(
				'title'             => __( 'Valor mínimo da parcela (R$)', 'wooflow-pagbank' ),
				'type'              => 'number',
				'default'           => '5',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
			),
			'debug'                   => array(
				'title'   => __( 'Log de depuração', 'wooflow-pagbank' ),
				'type'    => 'checkbox',
				'label'   => __( 'Registrar detalhes em WooCommerce → Status → Logs (wooflow-pagbank).', 'wooflow-pagbank' ),
				'default' => 'no',
			),
			'diagnostics'             => array(
				'title'       => __( 'Diagnóstico', 'wooflow-pagbank' ),
				'type'        => 'diagnostics_button',
				'description' => __( 'Testa o token, a chave pública e a URL do webhook. A geração da chave pública já grava o valor.', 'wooflow-pagbank' ),
			),
		);
	}

	public function process_admin_options(): bool {
		$saved = parent::process_admin_options();
		$key   = $this->get_option_key();
		$settings = get_option( $key, array() );
		if ( ! is_array( $settings ) ) {
			return $saved;
		}

		$changed = false;
		if ( '' === trim( (string) ( $settings['webhook_token'] ?? '' ) ) ) {
			$settings['webhook_token'] = wp_generate_password( 32, false, false );
			$changed                   = true;
		}
		if ( ! in_array( $settings['environment'] ?? '', array( 'sandbox', 'production' ), true ) ) {
			$settings['environment'] = 'sandbox';
			$changed                 = true;
		}
		if ( $changed ) {
			update_option( $key, $settings );
		}

		return $saved;
	}

	public function generate_webhook_url_html( $key, $data ): string {
		$settings = get_option( $this->get_option_key(), array() );
		$token    = is_array( $settings ) ? (string) ( $settings['webhook_token'] ?? '' ) : '';
		$url      = '' !== $token
			? add_query_arg(
				array(
					'wc-api' => 'dw_pagbank_webhook',
					'token'  => $token,
				),
				home_url( '/' )
			)
			: __( 'Salve as configurações para gerar a URL.', 'wooflow-pagbank' );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ?? '' ); ?></th>
			<td class="forminp">
				<input type="text" readonly class="large-text" value="<?php echo esc_attr( $url ); ?>" onclick="this.select()" />
				<p class="description"><?php esc_html_e( 'Cadastre esta URL no pedido pelo campo notification_urls. O plugin envia a URL automaticamente quando o site usa HTTPS.', 'wooflow-pagbank' ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	public function generate_diagnostics_button_html( $key, $data ): string {
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ?? '' ); ?></th>
			<td class="forminp">
				<button type="button" class="button" id="wooflow-pagbank-diagnostics"><?php esc_html_e( 'Executar diagnóstico', 'wooflow-pagbank' ); ?></button>
				<button type="button" class="button" id="wooflow-pagbank-public-key"><?php esc_html_e( 'Gerar chave pública', 'wooflow-pagbank' ); ?></button>
				<div id="wooflow-pagbank-diagnostics-result" style="margin-top:12px;"></div>
				<p class="description"><?php echo esc_html( $data['description'] ?? '' ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	public static function enqueue_scripts(): void {
		$checkout = function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page();
		$account  = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' );
		$thanks   = function_exists( 'is_order_received_page' ) && is_order_received_page();

		if ( $checkout || $account || $thanks ) {
			wp_enqueue_style( 'wooflow-pagbank', WOOFLOW_PAGBANK_URL . 'assets/css/pagbank.css', array(), WOOFLOW_PAGBANK_VERSION );
		}

		if ( ! $checkout ) {
			return;
		}

		$settings = get_option( 'woocommerce_dw_pagbank_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$pix      = get_option( 'woocommerce_dw_pagbank_pix_settings', array() );
		$boleto   = get_option( 'woocommerce_dw_pagbank_boleto_settings', array() );
		$card_on  = 'yes' === ( $settings['enabled'] ?? 'no' );
		$any_on   = $card_on
			|| ( is_array( $pix ) && 'yes' === ( $pix['enabled'] ?? 'no' ) )
			|| ( is_array( $boleto ) && 'yes' === ( $boleto['enabled'] ?? 'no' ) );
		if ( ! $any_on ) {
			return;
		}

		$deps = array( 'jquery' );
		if ( $card_on ) {
			wp_enqueue_script( 'pagbank-sdk', 'https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js', array(), null, true );
			$deps[] = 'pagbank-sdk';
		}
		wp_enqueue_script( 'wooflow-pagbank-checkout', WOOFLOW_PAGBANK_URL . 'assets/js/pagbank-checkout.js', $deps, WOOFLOW_PAGBANK_VERSION, true );
		wp_localize_script(
			'wooflow-pagbank-checkout',
			'wooflow_pagbank_params',
			array(
				'public_key'                 => (string) ( $settings['public_key'] ?? '' ),
				'missing_public_key_message' => __( 'Configure a chave pública do PagBank para pagar com cartão.', 'wooflow-pagbank' ),
				'sdk_unavailable_message'    => __( 'Não foi possível carregar o SDK do PagBank. Desative o bloqueador de conteúdo e tente novamente.', 'wooflow-pagbank' ),
				'card_brand_icons'           => array(
					'visa'       => WOOFLOW_PAGBANK_URL . 'assets/img/cards/visa.svg',
					'mastercard' => WOOFLOW_PAGBANK_URL . 'assets/img/cards/mastercard.svg',
					'elo'        => WOOFLOW_PAGBANK_URL . 'assets/img/cards/elo.svg',
					'amex'       => WOOFLOW_PAGBANK_URL . 'assets/img/cards/amex.svg',
				),
				'card_brand_names'           => array(
					'generic'    => __( 'detectando...', 'wooflow-pagbank' ),
					'visa'       => 'Visa',
					'mastercard' => 'Mastercard',
					'elo'        => 'Elo',
					'amex'       => 'Amex',
				),
				'ajax_url'                   => admin_url( 'admin-ajax.php' ),
				'threeds_nonce'              => wp_create_nonce( 'wooflow_pagbank_3ds' ),
				'installments_nonce'         => wp_create_nonce( 'wooflow_pagbank_installments' ),
				'max_installments'           => max( 1, min( 12, (int) ( $settings['max_installments'] ?? 12 ) ) ),
				'interest_free_installments' => max( 0, min( 12, (int) ( $settings['interest_free_installments'] ?? 12 ) ) ),
				'sdk_env'                    => 'production' === ( $settings['environment'] ?? '' ) ? 'PROD' : 'SANDBOX',
			)
		);
	}

	public static function enqueue_admin_assets( string $hook ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		if ( 'dw_pagbank' !== $section ) {
			return;
		}

		wp_enqueue_script( 'wooflow-pagbank-admin', WOOFLOW_PAGBANK_URL . 'assets/js/pagbank-admin.js', array( 'jquery' ), WOOFLOW_PAGBANK_VERSION, true );
		wp_localize_script(
			'wooflow-pagbank-admin',
			'wooflow_pagbank_admin',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wooflow_pagbank_admin' ),
			)
		);
	}

	public static function ajax_run_diagnostics(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'wooflow_pagbank_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$settings = get_option( 'woocommerce_dw_pagbank_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$token    = trim( (string) ( $settings['token'] ?? '' ) );
		$api      = WC_Pagbank_API::account();
		$ping     = $api->ping();
		$lines    = array();

		$lines[] = 'production' === WC_Pagbank_API::environment()
			? __( 'Ambiente: produção.', 'wooflow-pagbank' )
			: __( 'Ambiente: sandbox.', 'wooflow-pagbank' );

		if ( '' === $token ) {
			$lines[] = __( 'Token: ausente.', 'wooflow-pagbank' );
		} else {
			$lines[] = sprintf( __( 'Token: presente, termina em %s.', 'wooflow-pagbank' ), substr( $token, -4 ) );
		}

		if ( 404 === $ping['http_code'] || 200 === $ping['http_code'] ) {
			$lines[] = __( 'API: o token foi aceito.', 'wooflow-pagbank' );
		} elseif ( in_array( $ping['http_code'], array( 401, 403 ), true ) ) {
			$lines[] = __( 'API: o token foi recusado. Confira o ambiente e o valor colado.', 'wooflow-pagbank' );
		} else {
			$lines[] = sprintf( __( 'API: sem resposta útil (HTTP %1$d). %2$s', 'wooflow-pagbank' ), (int) $ping['http_code'], (string) $ping['error'] );
		}

		$lines[] = '' !== trim( (string) ( $settings['public_key'] ?? '' ) )
			? __( 'Chave pública do cartão: configurada.', 'wooflow-pagbank' )
			: __( 'Chave pública do cartão: ausente.', 'wooflow-pagbank' );

		$lines[] = function_exists( 'openssl_verify' )
			? __( 'OpenSSL: disponível para validar o webhook.', 'wooflow-pagbank' )
			: __( 'OpenSSL: ausente. Os webhooks serão recusados.', 'wooflow-pagbank' );

		$webhook = WC_Pagbank_API::webhook_token();
		if ( '' === $webhook ) {
			$lines[] = __( 'Webhook: salve as configurações para gerar o token da URL.', 'wooflow-pagbank' );
		} else {
			$url     = add_query_arg(
				array(
					'wc-api' => 'dw_pagbank_webhook',
					'token'  => $webhook,
				),
				home_url( '/' )
			);
			$lines[] = str_starts_with( $url, 'https://' )
				? __( 'Webhook: a URL HTTPS será enviada em cada pedido.', 'wooflow-pagbank' )
				: __( 'Webhook: o site não está em HTTPS, então a URL não será enviada ao PagBank. Cartão ainda conclui na hora; PIX depende da consulta na página de obrigado.', 'wooflow-pagbank' );
		}

		wp_send_json_success( array( 'lines' => $lines ) );
	}

	public static function ajax_create_public_key(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'wooflow_pagbank_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		try {
			$key = WC_Pagbank_API::account()->create_card_public_key();
		} catch ( Exception $e ) {
			WC_Pagbank_Logger::log( 'gerar chave pública: ' . $e->getMessage(), 'error' );
			wp_send_json_error(
				array( 'message' => __( 'Não foi possível gerar a chave. Confira o token e o ambiente no log do WooCommerce.', 'wooflow-pagbank' ) ),
				400
			);
		}

		$settings = get_option( 'woocommerce_dw_pagbank_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['public_key'] = $key;
		update_option( 'woocommerce_dw_pagbank_settings', $settings );

		wp_send_json_success(
			array(
				'public_key' => $key,
				'message'    => __( 'Chave pública gerada e salva.', 'wooflow-pagbank' ),
			)
		);
	}

	public static function ajax_threeds_session(): void {
		if ( ! check_ajax_referer( 'wooflow_pagbank_3ds', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'A página expirou. Recarregue o checkout e tente novamente.', 'wooflow-pagbank' ) ), 403 );
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'message' => __( 'O carrinho está vazio.', 'wooflow-pagbank' ) ), 400 );
		}

		try {
			$api = WC_Pagbank_API::account();
			wp_send_json_success(
				array(
					'session' => $api->create_threeds_session(),
					'env'     => $api->sdk_environment(),
					'amount'  => WC_Pagbank_API::to_cents( WC()->cart->get_total( 'edit' ) ),
				)
			);
		} catch ( Exception $e ) {
			$safe = $e instanceof WC_Pagbank_Exception && $e->is_safe_for_customer();
			wp_send_json_error(
				array(
					'message' => $safe
						? $e->getMessage()
						: __( 'Não foi possível iniciar a autenticação do cartão.', 'wooflow-pagbank' ),
				),
				400
			);
		}
	}

	public static function ajax_installments(): void {
		check_ajax_referer( 'wooflow_pagbank_installments', 'nonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'message' => __( 'O carrinho está vazio.', 'wooflow-pagbank' ) ), 400 );
		}

		$bin = isset( $_POST['bin'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['bin'] ) ) : '';
		$bin = substr( (string) $bin, 0, 6 );
		if ( ! preg_match( '/^\d{6}$/', $bin ) ) {
			wp_send_json_error( array( 'message' => __( 'Informe os 6 primeiros dígitos do cartão.', 'wooflow-pagbank' ) ), 400 );
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways['dw_pagbank'] ?? null;
		if ( ! $gateway instanceof self || '' === $gateway->token ) {
			wp_send_json_error( array( 'message' => __( 'O cartão PagBank não está disponível.', 'wooflow-pagbank' ) ), 400 );
		}

		try {
			$api   = new WC_Pagbank_API( $gateway->token, $gateway->environment, $gateway->soft_descriptor );
			$plans = $api->installment_plans(
				WC_Pagbank_API::to_cents( WC()->cart->get_total( 'edit' ) ),
				$gateway->max_installments,
				$gateway->interest_free_installments,
				$bin
			);
			$min = WC_Pagbank_API::to_cents( $gateway->min_installment_amount );
			$out = array();
			foreach ( $plans as $plan ) {
				if ( $min > 0 && (int) $plan['installments'] > 1 && (int) $plan['installment_value'] < $min ) {
					continue;
				}
				$out[] = array(
					'installments'          => (int) $plan['installments'],
					'total'                 => (int) $plan['total'],
					'interest_total'        => (int) $plan['interest_total'],
					'interest_installments' => (int) $plan['interest_installments'],
					'label'                 => self::plan_label( $plan ),
				);
			}
			if ( ! $out ) {
				wp_send_json_error( array( 'message' => __( 'O PagBank não retornou parcelas para este cartão.', 'wooflow-pagbank' ) ), 400 );
			}
			wp_send_json_success( array( 'plans' => $out ) );
		} catch ( Exception $e ) {
			$safe = $e instanceof WC_Pagbank_Exception && $e->is_safe_for_customer();
			wp_send_json_error(
				array(
					'message' => $safe ? $e->getMessage() : __( 'Não foi possível consultar as parcelas.', 'wooflow-pagbank' ),
				),
				400
			);
		}
	}

	/**
	 * @param array{installments:int,installment_value:int,total:int,interest_free:bool,interest_total:int,interest_installments:int} $plan
	 */
	private static function plan_label( array $plan ): string {
		$count = (int) $plan['installments'];
		$each  = ( (int) $plan['installment_value'] ) / 100;
		$total = ( (int) $plan['total'] ) / 100;
		if ( 1 === $count ) {
			return sprintf(
				/* translators: %s: order total */
				__( 'À vista (%s)', 'wooflow-pagbank' ),
				wp_strip_all_tags( wc_price( $total ) )
			);
		}
		if ( ! empty( $plan['interest_free'] ) || (int) $plan['interest_total'] < 1 ) {
			return sprintf(
				/* translators: 1: installments, 2: installment amount */
				__( '%1$dx de %2$s sem juros', 'wooflow-pagbank' ),
				$count,
				wp_strip_all_tags( wc_price( $each ) )
			);
		}
		return sprintf(
			/* translators: 1: installments, 2: installment amount, 3: total with interest */
			__( '%1$dx de %2$s com juros (total %3$s)', 'wooflow-pagbank' ),
			$count,
			wp_strip_all_tags( wc_price( $each ) ),
			wp_strip_all_tags( wc_price( $total ) )
		);
	}

	public function payment_fields(): void {
		if ( $this->description ) {
			echo '<p class="wooflow-pagbank-card-description">' . wp_kses_post( $this->description ) . '</p>';
		}

		$cart_total            = WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0.0;
		$public_key_configured = '' !== $this->public_key;
		$is_sandbox            = 'sandbox' === $this->environment;
		$shown_max             = $this->interest_free_installments < $this->max_installments
			? max( 1, $this->interest_free_installments )
			: $this->max_installments;
		$installment_options   = self::installment_options( $cart_total, $shown_max, $this->min_installment_amount );
		include WOOFLOW_PAGBANK_PATH . 'templates/payment-fields-credit-card.php';
	}

	/**
	 * @return array<int,array{value:int,label:string}>
	 */
	public static function installment_options( float $total, int $max, float $min_amount ): array {
		$max = max( 1, min( 12, $max ) );
		if ( $min_amount > 0 && $total > 0 ) {
			$allowed = intdiv( WC_Pagbank_API::to_cents( $total ), WC_Pagbank_API::to_cents( $min_amount ) );
			$max     = max( 1, min( $max, max( 1, $allowed ) ) );
		}

		$options = array();
		for ( $i = 1; $i <= $max; $i++ ) {
			$each      = $total > 0 ? $total / $i : 0;
			$options[] = array(
				'value' => $i,
				'label' => 1 === $i
					? sprintf(
						/* translators: %s: order total */
						__( 'À vista (%s)', 'wooflow-pagbank' ),
						wp_strip_all_tags( wc_price( $total ) )
					)
					: sprintf(
						/* translators: 1: installments, 2: installment amount */
						__( '%1$dx de %2$s', 'wooflow-pagbank' ),
						$i,
						wp_strip_all_tags( wc_price( $each ) )
					),
			);
		}

		return $options;
	}

	public function validate_fields(): bool {
		if ( '' === $this->token || '' === $this->public_key ) {
			wc_add_notice( __( 'O cartão PagBank ainda não está configurado nesta loja.', 'wooflow-pagbank' ), 'error' );
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
			$tax_id = WC_Pagbank_Customer::tax_id_from_order( $order );
			$holder = isset( $_POST['wooflow_pagbank_holder'] ) ? sanitize_text_field( wp_unslash( $_POST['wooflow_pagbank_holder'] ) ) : '';
			if ( ! WC_Pagbank_Customer::is_full_name( $holder ) ) {
				$holder = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			}

			$encrypted = isset( $_POST['wooflow_pagbank_encrypted'] ) ? trim( (string) wp_unslash( $_POST['wooflow_pagbank_encrypted'] ) ) : '';
			if ( ! preg_match( '/^[A-Za-z0-9+\/=._\-]+$/', $encrypted ) || strlen( $encrypted ) < 20 || strlen( $encrypted ) > 20000 ) {
				throw new WC_Pagbank_Exception( __( 'Não foi possível criptografar o cartão. Recarregue a página e tente novamente.', 'wooflow-pagbank' ), true );
			}

			$installments = absint( $_POST['wooflow_pagbank_installments'] ?? 1 );
			$installments = max( 1, min( $this->max_installments, $installments ) );
			$threeds_id   = isset( $_POST['wooflow_pagbank_3ds_id'] ) ? sanitize_text_field( wp_unslash( $_POST['wooflow_pagbank_3ds_id'] ) ) : '';
			$threeds_skip = isset( $_POST['wooflow_pagbank_3ds_status'] ) && 'unsupported' === sanitize_key( wp_unslash( $_POST['wooflow_pagbank_3ds_status'] ) );
			if ( $threeds_skip ) {
				$threeds_id = '';
			} elseif ( ! preg_match( '/^3DS_[A-Za-z0-9\-]+$/', $threeds_id ) ) {
				throw new WC_Pagbank_Exception( __( 'Não foi possível autenticar o cartão. Tente novamente.', 'wooflow-pagbank' ), true );
			}

			$api                    = new WC_Pagbank_API( $this->token, $this->environment, $this->soft_descriptor );
			$amount_cents           = 0;
			$interest_total         = 0;
			$interest_installments  = 0;
			if ( $installments > $this->interest_free_installments ) {
				$bin = isset( $_POST['wooflow_pagbank_card_bin'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['wooflow_pagbank_card_bin'] ) ) : '';
				$bin = substr( (string) $bin, 0, 6 );
				if ( ! preg_match( '/^\d{6}$/', $bin ) ) {
					throw new WC_Pagbank_Exception( __( 'Informe o número do cartão para calcular o parcelamento com juros.', 'wooflow-pagbank' ), true );
				}
				$this->remove_interest_fee( $order );
				$plan = null;
				foreach ( $api->installment_plans( WC_Pagbank_API::to_cents( $order->get_total() ), $this->max_installments, $this->interest_free_installments, $bin ) as $candidate ) {
					if ( (int) $candidate['installments'] === $installments ) {
						$plan = $candidate;
						break;
					}
				}
				if ( ! is_array( $plan ) || (int) $plan['total'] < 100 || (int) $plan['interest_total'] < 1 || (int) $plan['interest_installments'] < 1 ) {
					throw new WC_Pagbank_Exception( __( 'Essa parcela não está disponível para este cartão.', 'wooflow-pagbank' ), true );
				}
				$min_cents = WC_Pagbank_API::to_cents( $this->min_installment_amount );
				if ( $min_cents > 0 && (int) $plan['installment_value'] < $min_cents ) {
					throw new WC_Pagbank_Exception( __( 'Essa parcela fica abaixo do valor mínimo configurado.', 'wooflow-pagbank' ), true );
				}
				$amount_cents          = (int) $plan['total'];
				$interest_total        = (int) $plan['interest_total'];
				$interest_installments = (int) $plan['interest_installments'];
				$this->add_interest_fee( $order, $interest_total );
			}
			$result = $api->charge_card( $order, $encrypted, $installments, $holder, $tax_id, $threeds_id, $amount_cents, $interest_total, $interest_installments );

			WC_Pagbank_Payment::remember( $order, $result, 'credit_card' );
			$order->update_meta_data( '_pagbank_installments', $installments );
			if ( $interest_total > 0 ) {
				$order->update_meta_data( '_pagbank_interest_cents', $interest_total );
			}
			if ( '' !== $threeds_id ) {
				$order->update_meta_data( '_pagbank_3ds_id', $threeds_id );
			}
			if ( '' !== ( $result['authentication_status'] ?? '' ) ) {
				$order->update_meta_data( '_pagbank_3ds_status', sanitize_text_field( (string) $result['authentication_status'] ) );
			}
			$order->update_meta_data( '_pagbank_card_brand', sanitize_text_field( (string) $result['brand'] ) );
			$order->update_meta_data( '_pagbank_card_last', (string) $result['last_digits'] );
			$order->save();

			return WC_Pagbank_Payment::finish(
				$order,
				$result,
				__( 'Pagamento com cartão em análise no PagBank.', 'wooflow-pagbank' ),
				__( 'O PagBank recusou este cartão. Confira os dados ou tente outro cartão.', 'wooflow-pagbank' )
			);
		} catch ( Exception $e ) {
			return WC_Pagbank_Payment::failure( $e );
		}
	}

	private function remove_interest_fee( WC_Order $order ): void {
		$name    = __( 'Juros do parcelamento', 'wooflow-pagbank' );
		$removed = false;
		foreach ( $order->get_items( 'fee' ) as $item_id => $item ) {
			if ( $item instanceof WC_Order_Item_Fee && $name === $item->get_name() ) {
				$order->remove_item( $item_id );
				$removed = true;
			}
		}
		if ( $removed ) {
			$order->calculate_totals( false );
			$order->save();
		}
	}

	private function add_interest_fee( WC_Order $order, int $interest_cents ): void {
		$this->remove_interest_fee( $order );
		$amount = $interest_cents / 100;
		$fee    = new WC_Order_Item_Fee();
		$fee->set_name( __( 'Juros do parcelamento', 'wooflow-pagbank' ) );
		$fee->set_amount( $amount );
		$fee->set_total( $amount );
		$fee->set_tax_status( 'none' );
		$order->add_item( $fee );
		$order->calculate_totals( false );
		$order->update_meta_data( '_pagbank_interest_cents', $interest_cents );
		$order->save();
	}

	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'pagbank_refund', __( 'Pedido não encontrado.', 'wooflow-pagbank' ) );
		}
		return WC_Pagbank_API::refund_order( $order, $amount );
	}
}
