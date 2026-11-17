<?php
defined( 'ABSPATH' ) || exit;

class WC_Pagbank_Logger {

	private static ?WC_Logger $logger = null;

	public static function log( string $message, string $level = 'info' ): void {
		if ( ! self::should_log( $level ) ) {
			return;
		}

		if ( ! self::$logger && function_exists( 'wc_get_logger' ) ) {
			self::$logger = wc_get_logger();
		}

		if ( ! self::$logger ) {
			return;
		}

		self::$logger->log(
			$level,
			self::redact( $message ),
			array( 'source' => 'wooflow-pagbank' )
		);
	}

	private static function should_log( string $level ): bool {
		if ( in_array( $level, array( 'error', 'warning' ), true ) ) {
			return true;
		}

		foreach ( array(
			'woocommerce_dw_pagbank_settings',
			'woocommerce_dw_pagbank_pix_settings',
			'woocommerce_dw_pagbank_boleto_settings',
		) as $option_name ) {
			$settings = get_option( $option_name, array() );
			if ( is_array( $settings ) && 'yes' === ( $settings['debug'] ?? 'no' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function redact( string $message ): string {
		$patterns = array(
			'/Bearer\s+[A-Za-z0-9\-._~+\/=]+/i'                                      => 'Bearer [redacted]',
			'/"?encrypted"?\s*[:=]\s*"?[^",\s]+/i'                                   => '"encrypted":"[redacted]"',
			'/("?(?:cvv|cvc|security_code|securityCode)"?\s*[:=]\s*"?)[^",\s]+/i'    => '$1[redacted]',
			'/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/'                      => '[redacted_email]',
			'/\b\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}\b/'                                   => '[redacted_cnpj]',
			'/(?<!\d)\d{14}(?!\d)/'                                                    => '[redacted_cnpj]',
			'/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b/'                                          => '[redacted_cpf]',
			'/(?<!\d)\d{11}(?!\d)/'                                                    => '[redacted_cpf]',
			'/\b(?:\d[ -]?){13,19}\b/'                                                 => '[redacted_pan]',
		);

		return (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $message );
	}
}
