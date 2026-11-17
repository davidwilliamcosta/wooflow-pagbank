<?php
defined( 'ABSPATH' ) || exit;

/**
 * Dados do comprador exigidos pela API de pedidos do PagBank.
 */
class WC_Pagbank_Customer {

	public static function after_checkout_validation( $data, $errors ): void {
		if ( ! $errors instanceof WP_Error || ! is_array( $data ) ) {
			return;
		}

		$method = (string) ( $data['payment_method'] ?? '' );
		if ( ! in_array( $method, array( 'dw_pagbank', 'dw_pagbank_pix', 'dw_pagbank_boleto' ), true ) ) {
			return;
		}

		$first = (string) ( $data['billing_first_name'] ?? '' );
		$last  = (string) ( $data['billing_last_name'] ?? '' );
		if ( ! self::is_full_name( trim( $first . ' ' . $last ) ) ) {
			$errors->add( 'pagbank_name', __( 'Informe nome e sobrenome para pagar com PagBank.', 'wooflow-pagbank' ) );
		}

		$email = (string) ( $data['billing_email'] ?? '' );
		if ( ! is_email( $email ) ) {
			$errors->add( 'pagbank_email', __( 'Informe um e-mail válido para pagar com PagBank.', 'wooflow-pagbank' ) );
		}

		$phone = self::parse_phone( (string) ( $data['billing_phone'] ?? '' ) );
		if ( ! $phone ) {
			$phone = self::parse_phone( self::posted_string( 'billing_cellphone' ) );
		}
		if ( ! $phone ) {
			$errors->add( 'pagbank_phone', self::phone_message() );
		}

		if ( ! self::is_valid_tax_id( self::posted_tax_id() ) ) {
			$errors->add( 'pagbank_tax', __( 'Informe um CPF ou CNPJ válido.', 'wooflow-pagbank' ) );
		}

		$postcode = preg_replace( '/\D/', '', (string) ( $data['billing_postcode'] ?? '' ) );
		$state    = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $data['billing_state'] ?? '' ) ) );
		$city     = trim( (string) ( $data['billing_city'] ?? '' ) );
		$street   = trim( (string) ( $data['billing_address_1'] ?? '' ) );
		if ( strlen( $postcode ) !== 8 || strlen( $state ) !== 2 || '' === $city || '' === $street ) {
			$errors->add( 'pagbank_address', __( 'Preencha rua, cidade, estado e CEP para pagar com PagBank.', 'wooflow-pagbank' ) );
		}
	}

	public static function sync_checkout_fields( WC_Order $order ): void {
		$phone = self::parse_phone( (string) $order->get_billing_phone() );
		if ( ! $phone ) {
			$posted = self::parse_phone( self::posted_string( 'billing_cellphone' ) );
			if ( ! $posted ) {
				$posted = self::parse_phone( self::posted_string( 'billing_phone' ) );
			}
			if ( $posted ) {
				$order->set_billing_phone( $posted['area'] . $posted['number'] );
			}
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function payload_from_order( WC_Order $order ): array {
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( ! self::is_full_name( $name ) ) {
			throw new WC_Pagbank_Exception( __( 'Informe nome e sobrenome para pagar com PagBank.', 'wooflow-pagbank' ), true );
		}

		$email = (string) $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			throw new WC_Pagbank_Exception( __( 'Informe um e-mail válido para pagar com PagBank.', 'wooflow-pagbank' ), true );
		}

		$tax_id = self::tax_id_from_order( $order );
		if ( ! self::is_valid_tax_id( $tax_id ) ) {
			throw new WC_Pagbank_Exception( __( 'Informe um CPF ou CNPJ válido.', 'wooflow-pagbank' ), true );
		}

		$phone = self::phone_from_order( $order );
		if ( ! $phone ) {
			throw new WC_Pagbank_Exception( self::phone_message(), true );
		}

		return array(
			'name'   => self::clip( $name, 120 ),
			'email'  => $email,
			'tax_id' => $tax_id,
			'phones' => array( $phone ),
		);
	}

	/**
	 * Endereço de entrega. Sem endereço de entrega, usa o de cobrança.
	 *
	 * @return array<string,string>
	 */
	public static function shipping_address( WC_Order $order ): array {
		if ( $order->has_shipping_address() ) {
			$shipping = self::read_address( $order, 'shipping' );
			if ( strlen( $shipping['postal_code'] ) === 8 && '' !== $shipping['street'] && '' !== $shipping['city'] ) {
				return self::finalize_address( $shipping, 'BRA' );
			}
		}

		return self::finalize_address( self::read_address( $order, 'billing' ), 'BRA' );
	}

	/**
	 * Endereço do sacado do boleto. O exemplo oficial usa country "Brasil".
	 *
	 * @return array<string,string>
	 */
	public static function boleto_address( WC_Order $order ): array {
		$address                 = self::shipping_address( $order );
		$address['region']       = $address['region_code'];
		$address['country']      = 'Brasil';
		unset( $address['complement'] );
		$complement = trim( (string) $order->get_billing_address_2() );
		if ( '' !== $complement ) {
			$address['complement'] = self::clip( $complement, 40 );
		}
		return $address;
	}

	public static function render_tax_field( string $id ): void {
		$value = self::format_tax_id( self::checkout_tax_digits() );
		$known = '' !== $value;
		?>
		<p class="form-row form-row-wide wooflow-pagbank-tax-row" <?php echo $known ? 'hidden' : ''; ?>>
			<label for="<?php echo esc_attr( $id ); ?>">
				<?php esc_html_e( 'CPF ou CNPJ', 'wooflow-pagbank' ); ?> <span class="required">*</span>
			</label>
			<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>"
				inputmode="numeric" autocomplete="off" maxlength="18"
				placeholder="000.000.000-00" value="<?php echo esc_attr( $value ); ?>">
		</p>
		<?php
	}

	/**
	 * CPF/CNPJ já informado no checkout (campo do WooFlow, sessão ou o do gateway).
	 */
	public static function checkout_tax_digits(): string {
		$posted = self::posted_tax_id();
		if ( '' !== $posted ) {
			return $posted;
		}

		if ( function_exists( 'WC' ) && WC()->checkout() ) {
			foreach ( array( 'billing_cpf', 'billing_cnpj', 'billing_cpf_cnpj', 'billing_document' ) as $key ) {
				$digits = preg_replace( '/\D/', '', (string) WC()->checkout()->get_value( $key ) );
				if ( '' !== $digits ) {
					return $digits;
				}
			}
		}

		if ( is_user_logged_in() ) {
			foreach ( array( 'billing_cpf', 'billing_cnpj' ) as $key ) {
				$digits = preg_replace( '/\D/', '', (string) get_user_meta( get_current_user_id(), $key, true ) );
				if ( '' !== $digits ) {
					return $digits;
				}
			}
		}

		return '';
	}

	public static function posted_tax_id(): string {
		$method    = self::request_value( 'payment_method' );
		$method    = sanitize_key( $method );
		$preferred = array(
			'dw_pagbank'         => 'wooflow_pagbank_tax_id',
			'dw_pagbank_pix'     => 'wooflow_pagbank_pix_tax_id',
			'dw_pagbank_boleto'  => 'wooflow_pagbank_boleto_tax_id',
		);
		$keys      = array();
		if ( isset( $preferred[ $method ] ) ) {
			$keys[] = $preferred[ $method ];
		}
		$keys = array_merge(
			$keys,
			array(
				'wooflow_pagbank_tax_id',
				'wooflow_pagbank_pix_tax_id',
				'wooflow_pagbank_boleto_tax_id',
				'billing_cpf',
				'billing_cnpj',
				'billing_cpf_cnpj',
				'billing_document',
			)
		);

		foreach ( $keys as $key ) {
			$digits = preg_replace( '/\D/', '', self::posted_string( $key ) );
			if ( '' !== $digits ) {
				return $digits;
			}
		}

		return '';
	}

	public static function tax_id_from_order( WC_Order $order ): string {
		$posted = self::posted_tax_id();
		if ( '' !== $posted ) {
			return $posted;
		}

		$keys = array(
			'_billing_cpf',
			'_billing_cnpj',
			'billing_cpf',
			'billing_cnpj',
			'_billing_cpf_cnpj',
			'billing_cpf_cnpj',
			'_billing_document',
			'billing_document',
		);

		foreach ( $keys as $key ) {
			$digits = preg_replace( '/\D/', '', (string) $order->get_meta( $key ) );
			if ( '' !== $digits ) {
				return $digits;
			}
		}

		return '';
	}

	/**
	 * @return array{country:string,area:string,number:string,type:string}|null
	 */
	public static function phone_from_order( WC_Order $order ): ?array {
		$phone = self::parse_phone( (string) $order->get_billing_phone() );
		if ( $phone ) {
			return $phone;
		}

		foreach ( array( '_billing_cellphone', 'billing_cellphone' ) as $key ) {
			$phone = self::parse_phone( (string) $order->get_meta( $key ) );
			if ( $phone ) {
				return $phone;
			}
		}

		return self::parse_phone( self::posted_string( 'billing_cellphone' ) );
	}

	/**
	 * @return array{country:string,area:string,number:string,type:string}|null
	 */
	public static function parse_phone( string $raw ): ?array {
		$digits = preg_replace( '/\D/', '', $raw );
		if ( str_starts_with( $digits, '55' ) && strlen( $digits ) > 11 ) {
			$digits = substr( $digits, 2 );
		}

		if ( ! preg_match( '/^[1-9]{2}[1-9]\d{7,8}$/', $digits ) ) {
			return null;
		}

		$number = substr( $digits, 2 );
		return array(
			'country' => '55',
			'area'    => substr( $digits, 0, 2 ),
			'number'  => $number,
			'type'    => strlen( $number ) >= 9 ? 'MOBILE' : 'HOME',
		);
	}

	public static function is_valid_tax_id( string $digits ): bool {
		if ( 11 === strlen( $digits ) ) {
			return self::is_valid_cpf( $digits );
		}
		if ( 14 === strlen( $digits ) ) {
			return self::is_valid_cnpj( $digits );
		}
		return false;
	}

	public static function phone_message(): string {
		return __( 'Informe um celular ou telefone com DDD. Use 10 ou 11 dígitos, sem o zero na frente.', 'wooflow-pagbank' );
	}

	public static function timestamp_from_api_datetime( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}

		try {
			if ( preg_match( '/(?:Z|[+\-]\d{2}:?\d{2})$/', $value ) ) {
				$dt = new DateTimeImmutable( $value );
			} else {
				$dt = new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
			}
			return $dt->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	public static function clip( string $value, int $length ): string {
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}
		return substr( $value, 0, $length );
	}

	public static function is_full_name( string $name ): bool {
		$parts = preg_split( '/\s+/', trim( $name ) ) ?: array();
		return count( $parts ) >= 2 && strlen( $parts[0] ) >= 2 && strlen( $parts[1] ) >= 2;
	}

	private static function is_valid_cpf( string $cpf ): bool {
		if ( preg_match( '/^(\d)\1{10}$/', $cpf ) ) {
			return false;
		}

		for ( $t = 9; $t < 11; $t++ ) {
			$sum = 0;
			for ( $i = 0; $i < $t; $i++ ) {
				$sum += (int) $cpf[ $i ] * ( ( $t + 1 ) - $i );
			}
			$digit = ( ( 10 * $sum ) % 11 ) % 10;
			if ( (int) $cpf[ $t ] !== $digit ) {
				return false;
			}
		}

		return true;
	}

	private static function is_valid_cnpj( string $cnpj ): bool {
		if ( preg_match( '/^(\d)\1{13}$/', $cnpj ) ) {
			return false;
		}

		$weights = array(
			array( 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 ),
			array( 6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2 ),
		);

		foreach ( $weights as $index => $weight ) {
			$sum = 0;
			foreach ( $weight as $i => $multiplier ) {
				$sum += (int) $cnpj[ $i ] * $multiplier;
			}
			$rest  = $sum % 11;
			$digit = $rest < 2 ? 0 : 11 - $rest;
			if ( (int) $cnpj[ 12 + $index ] !== $digit ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array{street:string,number:string,complement:string,locality:string,city:string,region_code:string,postal_code:string}
	 */
	private static function read_address( WC_Order $order, string $type ): array {
		$getter = static function ( string $field ) use ( $order, $type ): string {
			$method = "get_{$type}_{$field}";
			return is_callable( array( $order, $method ) ) ? trim( (string) $order->$method() ) : '';
		};

		$street = $getter( 'address_1' );
		$number = (string) $order->get_meta( "_{$type}_number" );
		if ( '' === $number ) {
			$number = (string) $order->get_meta( "{$type}_number" );
		}
		if ( '' === $number && preg_match( '/,\s*(\d+[A-Za-z0-9\/\-]*)\s*$/', $street, $matches ) ) {
			$number = $matches[1];
			$street = trim( (string) preg_replace( '/,\s*\d+[A-Za-z0-9\/\-]*\s*$/', '', $street ) );
		}

		$locality = (string) $order->get_meta( "_{$type}_neighborhood" );
		if ( '' === $locality ) {
			$locality = (string) $order->get_meta( "{$type}_neighborhood" );
		}
		if ( '' === $locality ) {
			$locality = $getter( 'address_2' );
		}

		return array(
			'street'      => $street,
			'number'      => '' !== $number ? $number : 'S/N',
			'complement'  => 'billing' === $type ? $getter( 'address_2' ) : '',
			'locality'    => '' !== $locality ? $locality : 'Centro',
			'city'        => $getter( 'city' ),
			'region_code' => strtoupper( preg_replace( '/[^A-Za-z]/', '', $getter( 'state' ) ) ),
			'postal_code' => preg_replace( '/\D/', '', $getter( 'postcode' ) ),
		);
	}

	/**
	 * @param array{street:string,number:string,complement:string,locality:string,city:string,region_code:string,postal_code:string} $address
	 * @return array<string,string>
	 */
	private static function finalize_address( array $address, string $country ): array {
		if ( strlen( $address['postal_code'] ) !== 8 || strlen( $address['region_code'] ) !== 2 || '' === $address['city'] || '' === $address['street'] ) {
			throw new WC_Pagbank_Exception( __( 'Preencha rua, cidade, estado e CEP para pagar com PagBank.', 'wooflow-pagbank' ), true );
		}

		$clean = array(
			'street'      => self::clip( $address['street'], 160 ),
			'number'      => self::clip( $address['number'], 20 ),
			'locality'    => self::clip( $address['locality'], 60 ),
			'city'        => self::clip( $address['city'], 90 ),
			'region_code' => $address['region_code'],
			'country'     => $country,
			'postal_code' => $address['postal_code'],
		);

		$complement = self::clip( $address['complement'], 40 );
		if ( '' !== $complement && 'Centro' !== $complement ) {
			$clean['complement'] = $complement;
		}

		return $clean;
	}

	private static function format_tax_id( string $raw ): string {
		$digits = preg_replace( '/\D/', '', $raw );
		if ( 11 === strlen( $digits ) ) {
			return preg_replace( '/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits );
		}
		if ( 14 === strlen( $digits ) ) {
			return preg_replace( '/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digits );
		}
		return '';
	}

	private static function posted_string( string $key ): string {
		return self::request_value( $key );
	}

	/**
	 * O update_order_review manda o formulário em post_data, não em campos soltos.
	 */
	private static function request_value( string $key ): string {
		if ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
			return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}

		$raw = isset( $_POST['post_data'] ) ? wp_unslash( $_POST['post_data'] ) : '';
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		$parsed = array();
		wp_parse_str( $raw, $parsed );
		if ( ! isset( $parsed[ $key ] ) || ! is_scalar( $parsed[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( (string) $parsed[ $key ] );
	}
}
