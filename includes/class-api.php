<?php
defined( 'ABSPATH' ) || exit;

class WC_Pagbank_Exception extends Exception {

	public function __construct(
		string $message,
		private bool $safe_for_customer = false,
		private bool $declined = false
	) {
		parent::__construct( $message );
	}

	public function is_safe_for_customer(): bool {
		return $this->safe_for_customer;
	}

	public function is_declined(): bool {
		return $this->declined;
	}
}

/**
 * Cliente da API de pedidos do PagBank.
 *
 * Produção: https://api.pagseguro.com
 * Sandbox:  https://sandbox.api.pagseguro.com
 */
class WC_Pagbank_API {

	private const URLS = array(
		'production' => 'https://api.pagseguro.com',
		'sandbox'    => 'https://sandbox.api.pagseguro.com',
	);

	private string $token;
	private string $environment;
	private string $soft_descriptor;

	public function __construct( string $token, string $environment, string $soft_descriptor = '' ) {
		$this->token            = trim( $token );
		$this->environment      = 'production' === $environment ? 'production' : 'sandbox';
		$this->soft_descriptor  = self::sanitize_soft_descriptor( $soft_descriptor );
	}

	public static function account(): self {
		$settings = get_option( 'woocommerce_dw_pagbank_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		return new self(
			self::account_token(),
			(string) ( $settings['environment'] ?? 'sandbox' ),
			(string) ( $settings['soft_descriptor'] ?? '' )
		);
	}

	public static function for_order( WC_Order $order ): self {
		$settings = get_option( 'woocommerce_' . $order->get_payment_method() . '_settings', array() );
		$token    = is_array( $settings ) ? trim( (string) ( $settings['token'] ?? '' ) ) : '';
		if ( '' === $token ) {
			$token = self::account_token();
		}

		$card = get_option( 'woocommerce_dw_pagbank_settings', array() );
		$card = is_array( $card ) ? $card : array();

		return new self(
			$token,
			(string) ( $card['environment'] ?? 'sandbox' ),
			(string) ( $card['soft_descriptor'] ?? '' )
		);
	}

	public static function account_token(): string {
		foreach ( array( 'dw_pagbank', 'dw_pagbank_pix', 'dw_pagbank_boleto' ) as $gateway_id ) {
			$settings = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );
			$token    = is_array( $settings ) ? trim( (string) ( $settings['token'] ?? '' ) ) : '';
			if ( '' !== $token ) {
				return $token;
			}
		}
		return '';
	}

	public static function environment(): string {
		$settings = get_option( 'woocommerce_dw_pagbank_settings', array() );
		$environment = is_array( $settings ) ? (string) ( $settings['environment'] ?? 'sandbox' ) : 'sandbox';
		return 'production' === $environment ? 'production' : 'sandbox';
	}

	public static function webhook_token(): string {
		$settings = get_option( 'woocommerce_dw_pagbank_settings', array() );
		return is_array( $settings ) ? (string) ( $settings['webhook_token'] ?? '' ) : '';
	}

	public static function to_cents( $amount ): int {
		return (int) round( (float) wc_format_decimal( $amount ) * 100 );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function charge_card( WC_Order $order, string $encrypted, int $installments, string $holder, string $tax_id, string $threeds_id = '', int $amount_cents = 0, int $interest_total = 0, int $interest_installments = 0 ): array {
		$cents = $amount_cents >= 100 ? $amount_cents : self::to_cents( $order->get_total() );
		if ( $cents < 100 ) {
			throw new WC_Pagbank_Exception( __( 'O valor mínimo para cartão no PagBank é R$ 1,00.', 'wooflow-pagbank' ), true );
		}

		$attempt = max( 1, (int) $order->get_meta( '_pagbank_attempt' ) );
		$charge  = array(
			'reference_id'   => (string) $order->get_id(),
			'description'    => $this->charge_description( $order ),
			'amount'         => array(
				'value'    => $cents,
				'currency' => 'BRL',
			),
			'payment_method' => array(
				'type'            => 'CREDIT_CARD',
				'installments'    => max( 1, min( 12, $installments ) ),
				'capture'         => true,
				'soft_descriptor' => $this->soft_descriptor,
				'card'            => array(
					'encrypted' => $encrypted,
					'store'     => false,
				),
				'holder'          => array(
					'name'   => WC_Pagbank_Customer::clip( $holder, 50 ),
					'tax_id' => $tax_id,
				),
			),
		);

		if ( $interest_total > 0 && $interest_installments > 0 ) {
			$charge['amount']['fees'] = array(
				'buyer' => array(
					'interest' => array(
						'total'        => $interest_total,
						'installments' => $interest_installments,
					),
				),
			);
		}

		if ( preg_match( '/^3DS_[A-Za-z0-9\-]+$/', $threeds_id ) ) {
			$charge['payment_method']['authentication_method'] = array(
				'type' => 'THREEDS',
				'id'   => $threeds_id,
			);
		}

		return $this->normalize_order(
			$this->request(
				'POST',
				'/orders',
				$this->build_order_payload( $order, $charge ),
				array( 'x-idempotency-key' => substr( 'pb' . $order->get_id() . 'c' . $attempt, 0, 64 ) )
			)
		);
	}

	/**
	 * Planos da API de taxas. interest.installments é quantas parcelas o cliente paga juros, não o total de parcelas.
	 *
	 * @return array<int,array{installments:int,installment_value:int,total:int,interest_free:bool,interest_total:int,interest_installments:int}>
	 */
	public function installment_plans( int $cents, int $max_installments, int $interest_free, string $bin ): array {
		$max_installments = max( 1, min( 12, $max_installments ) );
		$interest_free    = max( 0, min( $max_installments, $interest_free ) );
		if ( $cents < 100 || ! preg_match( '/^\d{6}$/', $bin ) ) {
			return array();
		}

		$cache_key = 'wooflow_pb_fees_' . md5( $this->environment . '|' . $cents . '|' . $max_installments . '|' . $interest_free . '|' . $bin );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$path = '/charges/fees/calculate?' . http_build_query(
			array(
				'payment_methods'             => 'CREDIT_CARD',
				'value'                       => $cents,
				'max_installments'            => $max_installments,
				'max_installments_no_interest' => $interest_free,
				'credit_card_bin'             => $bin,
			)
		);
		$body  = $this->request( 'GET', $path );
		$cards = $body['payment_methods']['credit_card'] ?? array();
		$raw   = array();
		if ( is_array( $cards ) ) {
			foreach ( $cards as $brand ) {
				if ( is_array( $brand ) && is_array( $brand['installment_plans'] ?? null ) ) {
					$raw = $brand['installment_plans'];
					break;
				}
			}
		}

		$plans = array();
		foreach ( $raw as $plan ) {
			if ( ! is_array( $plan ) ) {
				continue;
			}
			$count    = (int) ( $plan['installments'] ?? 0 );
			$interest = $plan['amount']['fees']['buyer']['interest'] ?? array();
			$interest = is_array( $interest ) ? $interest : array();
			if ( $count < 1 || $count > $max_installments ) {
				continue;
			}
			$plans[] = array(
				'installments'           => $count,
				'installment_value'      => (int) ( $plan['installment_value'] ?? 0 ),
				'total'                  => (int) ( $plan['amount']['value'] ?? 0 ),
				'interest_free'          => ! empty( $plan['interest_free'] ),
				'interest_total'         => (int) ( $interest['total'] ?? 0 ),
				'interest_installments'  => (int) ( $interest['installments'] ?? 0 ),
			);
		}

		set_transient( $cache_key, $plans, 10 * MINUTE_IN_SECONDS );
		return $plans;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function charge_pix( WC_Order $order, int $expires_in ): array {
		$cents = self::to_cents( $order->get_total() );
		if ( $cents < 1 ) {
			throw new WC_Pagbank_Exception( __( 'O valor do pedido é inválido para PIX.', 'wooflow-pagbank' ), true );
		}

		$seconds = max( 120, min( 86400 * 30, $expires_in ) );
		$expires = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
			->modify( '+' . $seconds . ' seconds' )
			->format( 'Y-m-d\TH:i:s\Z' );

		$charge = array(
			'reference_id'   => (string) $order->get_id(),
			'description'    => $this->charge_description( $order ),
			'amount'         => array(
				'value'    => $cents,
				'currency' => 'BRL',
			),
			'payment_method' => array(
				'type' => 'PIX',
				'pix'  => array(
					'expiration_date' => $expires,
				),
			),
		);

		$attempt = max( 1, (int) $order->get_meta( '_pagbank_attempt' ) );
		$result  = $this->normalize_order(
			$this->request(
				'POST',
				'/orders',
				$this->build_order_payload( $order, $charge ),
				array( 'x-idempotency-key' => substr( 'pb' . $order->get_id() . 'pix' . $attempt, 0, 64 ) )
			)
		);

		if ( '' !== $result['qr_png'] ) {
			$result['qr_png_base64'] = $this->download_qr( $result['qr_png'] );
		}

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function charge_boleto( WC_Order $order, int $due_days ): array {
		$cents = self::to_cents( $order->get_total() );
		if ( $cents < 20 ) {
			throw new WC_Pagbank_Exception( __( 'O boleto PagBank exige um valor mínimo de R$ 0,20.', 'wooflow-pagbank' ), true );
		}

		$days     = max( 1, min( 60, $due_days ) );
		$due_date = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
		$customer = WC_Pagbank_Customer::payload_from_order( $order );
		$line_two = trim( (string) preg_replace( '/[^A-Za-z0-9 ]/', '', remove_accents( (string) get_bloginfo( 'name' ) ) ) );
		$line_two = '' !== $line_two ? WC_Pagbank_Customer::clip( $line_two, 70 ) : 'Pedido da loja';

		$charge = array(
			'reference_id'   => (string) $order->get_id(),
			'description'    => $this->charge_description( $order ),
			'amount'         => array(
				'value'    => $cents,
				'currency' => 'BRL',
			),
			'payment_method' => array(
				'type'   => 'BOLETO',
				'boleto' => array(
					'template'              => 'proposta',
					'due_date'              => $due_date,
					'days_until_expiration' => (string) $days,
					'instruction_lines'     => array(
						'line_1' => 'Pagamento ate a data de vencimento',
						'line_2' => $line_two,
					),
					'holder'                => array(
						'name'    => $customer['name'],
						'tax_id'  => $customer['tax_id'],
						'email'   => $customer['email'],
						'address' => WC_Pagbank_Customer::boleto_address( $order ),
					),
				),
			),
		);

		$attempt = max( 1, (int) $order->get_meta( '_pagbank_attempt' ) );
		return $this->normalize_order(
			$this->request(
				'POST',
				'/orders',
				$this->build_order_payload( $order, $charge, $customer ),
				array( 'x-idempotency-key' => substr( 'pb' . $order->get_id() . 'bol' . $attempt, 0, 64 ) )
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_charge( string $charge_id ): array {
		if ( ! preg_match( '/^CHAR_[A-Za-z0-9\-]+$/', $charge_id ) ) {
			throw new WC_Pagbank_Exception( 'ID de cobrança inválido.' );
		}

		$body = $this->request( 'GET', '/charges/' . rawurlencode( $charge_id ) );
		if ( empty( $body['id'] ) ) {
			throw new WC_Pagbank_Exception( 'O PagBank não retornou a cobrança.' );
		}
		return $body;
	}

	/**
	 * A consulta direta da cobrança responde 406 neste sandbox. O pedido traz a mesma cobrança.
	 *
	 * @return array<string,mixed>
	 */
	public function lookup_charge( WC_Order $order, string $charge_id = '' ): array {
		$charge_id = '' !== $charge_id ? $charge_id : (string) $order->get_meta( '_pagbank_charge_id' );
		$order_id  = (string) $order->get_meta( '_pagbank_order_id' );
		if ( preg_match( '/^ORDE_[A-Za-z0-9\-]+$/', $order_id ) ) {
			$body = $this->request( 'GET', '/orders/' . rawurlencode( $order_id ) );
			foreach ( (array) ( $body['charges'] ?? array() ) as $charge ) {
				if ( ! is_array( $charge ) || empty( $charge['id'] ) ) {
					continue;
				}
				if ( '' === $charge_id || hash_equals( $charge_id, (string) $charge['id'] ) ) {
					return $charge;
				}
			}
		}

		return $this->get_charge( $charge_id );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function cancel_charge( string $charge_id, int $cents ): array {
		if ( ! preg_match( '/^CHAR_[A-Za-z0-9\-]+$/', $charge_id ) ) {
			throw new WC_Pagbank_Exception( 'ID de cobrança inválido.' );
		}
		if ( $cents < 1 ) {
			throw new WC_Pagbank_Exception( 'Valor de estorno inválido.' );
		}

		return $this->request(
			'POST',
			'/charges/' . rawurlencode( $charge_id ) . '/cancel',
			array(
				'amount' => array(
					'value' => $cents,
				),
			)
		);
	}

	public function sdk_environment(): string {
		return 'production' === $this->environment ? 'PROD' : 'SANDBOX';
	}

	/**
	 * Sessão do SDK para o 3DS. Vale 30 minutos e não é o token da API.
	 */
	public function create_threeds_session(): string {
		if ( '' === $this->token ) {
			throw new WC_Pagbank_Exception( __( 'Configure o token do PagBank.', 'wooflow-pagbank' ), true );
		}

		$url      = 'production' === $this->environment
			? 'https://sdk.pagseguro.com/checkout-sdk/sessions'
			: 'https://sandbox.sdk.pagseguro.com/checkout-sdk/sessions';
		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 30,
				'sslverify' => true,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'WooFlow-PagBank/' . WOOFLOW_PAGBANK_VERSION,
				),
				'body'      => '{}',
			)
		);

		if ( is_wp_error( $response ) ) {
			WC_Pagbank_Logger::log( 'sessão 3DS falhou: ' . $response->get_error_message(), 'error' );
			throw new WC_Pagbank_Exception( __( 'Não foi possível iniciar a autenticação do cartão.', 'wooflow-pagbank' ), true );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();
		if ( ( 200 !== $code && 201 !== $code ) || ! is_string( $body['session'] ?? null ) || strlen( $body['session'] ) < 20 ) {
			$summary = self::error_summary( $body );
			WC_Pagbank_Logger::log( 'sessão 3DS HTTP ' . $code . ' ' . $summary, 'error' );
			throw new WC_Pagbank_Exception( self::threeds_customer_message( $code, $summary ), true );
		}

		return $body['session'];
	}

	private static function threeds_customer_message( int $code, string $summary ): string {
		$text = strtoupper( $summary );
		if ( 403 === $code || str_contains( $text, 'WHITELIST' ) || str_contains( $text, 'ACCESS_DENIED' ) ) {
			return __( 'O PagBank ainda não liberou esta conta para a autenticação do cartão. A homologação precisa ser aprovada antes.', 'wooflow-pagbank' );
		}
		if ( 401 === $code || str_contains( $text, 'UNAUTHORIZED' ) ) {
			return __( 'O PagBank não aceitou o token. Confira se ele é do mesmo ambiente escolhido na loja (sandbox ou produção).', 'wooflow-pagbank' );
		}
		return __( 'Não foi possível iniciar a autenticação do cartão.', 'wooflow-pagbank' );
	}

	public function create_card_public_key(): string {
		$body = $this->request( 'POST', '/public-keys', array( 'type' => 'card' ) );
		$key  = (string) ( $body['public_key'] ?? '' );
		if ( '' === $key ) {
			throw new WC_Pagbank_Exception( 'O PagBank não retornou a chave pública.' );
		}
		return $key;
	}

	public function get_webhook_public_key(): string {
		$body = $this->request( 'GET', '/public-keys?type=webhook' );
		return (string) ( $body['public_key'] ?? '' );
	}

	/**
	 * @return array{http_code:int,error:string}
	 */
	public function ping(): array {
		if ( '' === $this->token ) {
			return array(
				'http_code' => 0,
				'error'     => 'token ausente',
			);
		}

		$url      = self::URLS[ $this->environment ] . '/orders/ORDE_WOOFLOW_PING';
		$response = wp_remote_request(
			$url,
			array(
				'method'    => 'GET',
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
					'User-Agent'    => 'WooFlow-PagBank/' . WOOFLOW_PAGBANK_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'http_code' => 0,
				'error'     => $response->get_error_message(),
			);
		}

		return array(
			'http_code' => (int) wp_remote_retrieve_response_code( $response ),
			'error'     => '',
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function refund_order( WC_Order $order, $amount ) {
		$charge_id = (string) $order->get_meta( '_pagbank_charge_id' );
		$cents     = self::to_cents( $amount );
		if ( '' === $charge_id || $cents < 1 ) {
			return new WP_Error( 'pagbank_refund', __( 'Não há cobrança PagBank para estornar neste pedido.', 'wooflow-pagbank' ) );
		}

		try {
			$api    = self::for_order( $order );
			$charge = $api->lookup_charge( $order, $charge_id );
			$status = strtoupper( (string) ( $charge['status'] ?? '' ) );
			if ( 'PAID' !== $status ) {
				return new WP_Error(
					'pagbank_refund',
					sprintf(
						/* translators: %s: PagBank charge status */
						__( 'O PagBank não aceitou o estorno. A cobrança está como %s e só uma cobrança paga pode ser devolvida.', 'wooflow-pagbank' ),
						'' !== $status ? $status : __( 'desconhecido', 'wooflow-pagbank' )
					)
				);
			}
			$api->cancel_charge( $charge_id, $cents );
		} catch ( Exception $e ) {
			WC_Pagbank_Logger::log( 'estorno order=' . $order->get_id() . ' ' . $e->getMessage(), 'error' );
			$message = __( 'Não foi possível estornar no PagBank. O detalhe está no log do WooCommerce.', 'wooflow-pagbank' );
			if ( str_contains( $e->getMessage(), '40007' ) ) {
				$message = __( 'O PagBank não aceitou o estorno: esta cobrança não está em um status que permite devolução.', 'wooflow-pagbank' );
			}
			return new WP_Error( 'pagbank_refund', $message );
		}

		$already = (int) $order->get_meta( '_pagbank_refunded_cents' );
		$order->update_meta_data( '_pagbank_refunded_cents', $already + $cents );
		$order->save();
		return true;
	}

	public static function sanitize_soft_descriptor( string $value ): string {
		$value = strtoupper( remove_accents( $value ) );
		$value = preg_replace( '/[^A-Z0-9 ]/', '', $value );
		$value = trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
		if ( '' === $value ) {
			$value = 'LOJA';
		}
		return substr( $value, 0, 17 );
	}

	public static function is_declined_status( string $status ): bool {
		return in_array( strtoupper( $status ), array( 'DECLINED', 'CANCELED', 'CANCELLED' ), true );
	}

	/**
	 * @param array<string,mixed> $customer
	 * @param array<string,mixed> $charge
	 * @return array<string,mixed>
	 */
	private function build_order_payload( WC_Order $order, array $charge, ?array $customer = null ): array {
		if ( null === $customer ) {
			$customer = WC_Pagbank_Customer::payload_from_order( $order );
		}

		$cents   = (int) ( $charge['amount']['value'] ?? 0 );
		$payload = array(
			'reference_id' => (string) $order->get_id(),
			'customer'     => $customer,
			'items'        => array(
				array(
					'reference_id' => 'pedido-' . $order->get_id(),
					'name'         => WC_Pagbank_Customer::clip( sprintf( 'Pedido %s', $order->get_order_number() ), 100 ),
					'quantity'     => 1,
					'unit_amount'  => $cents,
				),
			),
			'shipping'     => array(
				'address' => WC_Pagbank_Customer::shipping_address( $order ),
			),
			'charges'      => array( $charge ),
		);

		$notification = $this->notification_url();
		if ( '' !== $notification ) {
			$payload['notification_urls'] = array( $notification );
		} else {
			WC_Pagbank_Logger::log( 'pedido ' . $order->get_id() . ' sem notification_urls: token ausente ou site sem HTTPS.', 'warning' );
		}

		return $payload;
	}

	private function notification_url(): string {
		$token = self::webhook_token();
		if ( '' === $token ) {
			return '';
		}

		$url = add_query_arg(
			array(
				'wc-api' => 'dw_pagbank_webhook',
				'token'  => $token,
			),
			home_url( '/' )
		);

		return str_starts_with( $url, 'https://' ) ? $url : '';
	}

	private function charge_description( WC_Order $order ): string {
		return WC_Pagbank_Customer::clip( sprintf( 'Pedido %s', $order->get_order_number() ), 64 );
	}

	/**
	 * @param array<string,string> $extra_headers
	 * @return array<string,mixed>
	 */
	private function request( string $method, string $path, ?array $body = null, array $extra_headers = array() ): array {
		if ( '' === $this->token ) {
			throw new WC_Pagbank_Exception( __( 'Configure o token do PagBank.', 'wooflow-pagbank' ), true );
		}

		$args = array(
			'method'    => $method,
			'timeout'   => 30,
			'sslverify' => true,
			'headers'   => array_merge(
				array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
					'User-Agent'    => 'WooFlow-PagBank/' . WOOFLOW_PAGBANK_VERSION,
				),
				$extra_headers
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::URLS[ $this->environment ] . $path, $args );
		if ( is_wp_error( $response ) ) {
			WC_Pagbank_Logger::log( $method . ' ' . self::redact_bin( $path ) . ' falhou: ' . $response->get_error_message(), 'error' );
			throw new WC_Pagbank_Exception( 'Falha de rede ao falar com o PagBank.' );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $code < 200 || $code >= 300 ) {
			$summary = self::error_summary( $decoded );
			WC_Pagbank_Logger::log( sprintf( 'HTTP %d %s %s %s', $code, $method, self::redact_bin( $path ), $summary ), 'error' );
			$safe    = self::customer_can_act_on_error( $code, $summary );
			throw new WC_Pagbank_Exception( self::customer_error_message( $code, $summary ), $safe );
		}

		return $decoded;
	}

	private static function customer_can_act_on_error( int $code, string $summary ): bool {
		$text = strtoupper( $summary );
		if ( str_contains( $text, 'WHITELIST' ) || str_contains( $text, 'ACCESS_DENIED' ) ) {
			return false;
		}
		if ( 401 === $code || 403 === $code ) {
			return true;
		}
		return str_contains( $text, 'UNAUTHORIZED' );
	}

	private static function customer_error_message( int $code, string $summary ): string {
		$text = strtoupper( $summary );
		if ( str_contains( $text, 'WHITELIST' ) || str_contains( $text, 'ACCESS_DENIED' ) ) {
			return __( 'Não foi possível processar o pagamento. Tente novamente ou escolha outro método.', 'wooflow-pagbank' );
		}
		if ( 401 === $code || 403 === $code || str_contains( $text, 'UNAUTHORIZED' ) ) {
			return __( 'O PagBank não aceitou o token. Confira se ele é do mesmo ambiente escolhido na loja (sandbox ou produção).', 'wooflow-pagbank' );
		}

		return sprintf( 'PagBank HTTP %d %s', $code, $summary );
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private static function redact_bin( string $path ): string {
		return (string) preg_replace( '/credit_card_bin=\d+/', 'credit_card_bin=******', $path );
	}

	private static function error_summary( array $body ): string {
		$parts    = array();
		$messages = $body['error_messages'] ?? $body['errors'] ?? array();
		if ( is_array( $messages ) ) {
			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}
				$parts[] = trim(
					(string) ( $message['code'] ?? '' ) . ' ' .
					(string) ( $message['parameter_name'] ?? '' ) . ' ' .
					(string) ( $message['description'] ?? $message['message'] ?? '' )
				);
			}
		}

		$parts = array_values( array_filter( $parts ) );
		return $parts ? implode( ' | ', $parts ) : 'sem detalhe';
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	private function normalize_order( array $body ): array {
		$charge = $body['charges'][0] ?? array();
		$charge = is_array( $charge ) ? $charge : array();
		$links  = is_array( $charge['links'] ?? null ) ? $charge['links'] : array();

		$qr_png     = '';
		$boleto_pdf = '';
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$rel   = strtoupper( (string) ( $link['rel'] ?? '' ) );
			$media = (string) ( $link['media'] ?? '' );
			$href  = (string) ( $link['href'] ?? '' );
			if ( 'QRCODE.PNG' === $rel ) {
				$qr_png = $href;
			}
			if ( 'application/pdf' === $media && '' !== $href ) {
				$boleto_pdf = $href;
			}
		}

		$boleto = is_array( $charge['payment_method']['boleto'] ?? null ) ? $charge['payment_method']['boleto'] : array();
		$card   = is_array( $charge['payment_method']['card'] ?? null ) ? $charge['payment_method']['card'] : array();
		$qr     = is_array( $charge['qr_code'] ?? null ) ? $charge['qr_code'] : array();

		return array(
			'order_id'      => (string) ( $body['id'] ?? '' ),
			'charge_id'     => (string) ( $charge['id'] ?? '' ),
			'status'        => strtoupper( (string) ( $charge['status'] ?? '' ) ),
			'message'       => (string) ( $charge['payment_response']['message'] ?? '' ),
			'qr_code'       => (string) ( $qr['text'] ?? '' ),
			'qr_png'        => $qr_png,
			'qr_png_base64' => '',
			'expires'       => (string) ( $charge['payment_method']['pix']['expiration_date'] ?? '' ),
			'barcode'       => (string) ( $boleto['formatted_barcode'] ?? $boleto['barcode'] ?? '' ),
			'boleto_pdf'    => $boleto_pdf,
			'due_date'      => (string) ( $boleto['due_date'] ?? '' ),
			'brand'                  => (string) ( $card['brand'] ?? '' ),
			'last_digits'            => preg_replace( '/\D/', '', (string) ( $card['last_digits'] ?? '' ) ),
			'authentication_status'  => strtoupper( (string) ( $charge['payment_method']['authentication_method']['status'] ?? '' ) ),
		);
	}

	private function download_qr( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || ! preg_match( '/(^|\.)pagseguro\.com$/', $host ) ) {
			return '';
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'image/png',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > 200000 || ! str_starts_with( $body, "\x89PNG" ) ) {
			return '';
		}

		return base64_encode( $body );
	}
}
