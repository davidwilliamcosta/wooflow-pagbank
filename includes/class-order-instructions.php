<?php
defined( 'ABSPATH' ) || exit;

class WC_Pagbank_Order_Instructions {

	/** @var array<int,bool> */
	private static array $rendered_pix = array();

	/** @var array<int,bool> */
	private static array $rendered_card = array();

	/** @var array<int,bool> */
	private static array $rendered_boleto = array();

	private static bool $countdown_printed = false;

	private static bool $poll_printed = false;

	public static function init(): void {
		add_action( 'woocommerce_thankyou', array( self::class, 'render_thankyou' ), 8 );
		add_action( 'woocommerce_thankyou_dw_pagbank', array( self::class, 'render_card' ) );
		add_action( 'woocommerce_thankyou_dw_pagbank_pix', array( self::class, 'render_pix' ) );
		add_action( 'woocommerce_thankyou_dw_pagbank_boleto', array( self::class, 'render_boleto' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( self::class, 'render_account' ) );
		add_action( 'woocommerce_email_before_order_table', array( self::class, 'render_email' ), 10, 4 );
		add_action( 'wp_ajax_wooflow_pagbank_poll_pix', array( self::class, 'ajax_poll' ) );
		add_action( 'wp_ajax_nopriv_wooflow_pagbank_poll_pix', array( self::class, 'ajax_poll' ) );
	}

	public static function render_thankyou( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( 'dw_pagbank' === $order->get_payment_method() ) {
			self::render_card( $order_id );
		} elseif ( 'dw_pagbank_pix' === $order->get_payment_method() ) {
			self::render_pix( $order_id );
		} elseif ( 'dw_pagbank_boleto' === $order->get_payment_method() ) {
			self::render_boleto( $order_id );
		}
	}

	public static function render_account( WC_Order $order ): void {
		self::render_thankyou( $order->get_id() );
	}

	public static function render_pix( int $order_id ): void {
		if ( isset( self::$rendered_pix[ $order_id ] ) ) {
			return;
		}
		self::$rendered_pix[ $order_id ] = true;

		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::can_view( $order ) ) {
			return;
		}

		self::sync_from_api( $order );
		$dead = in_array( $order->get_status(), array( 'failed', 'cancelled', 'refunded' ), true );

		echo '<section class="woocommerce-order-details wooflow-pagbank-payment-details wooflow-pagbank-payment-details--pix">';
		echo '<h2>' . esc_html__( 'Pagamento PIX', 'wooflow-pagbank' ) . '</h2>';

		if ( $dead ) {
			echo '<p class="wooflow-pagbank-warning"><strong>' . esc_html__( 'Este pedido não está mais ativo.', 'wooflow-pagbank' ) . '</strong> '
				. esc_html__( 'Não pague um QR Code exibido antes. Faça um novo pedido ou fale com a loja.', 'wooflow-pagbank' ) . '</p>';
			echo '</section>';
			return;
		}

		if ( $order->is_paid() ) {
			echo '<p>✅ <strong>' . esc_html__( 'Pagamento PIX confirmado!', 'wooflow-pagbank' ) . '</strong> '
				. esc_html__( 'Recebemos a confirmação do seu pagamento. Seu pedido está sendo processado.', 'wooflow-pagbank' ) . '</p>';
			self::paid_at( $order );
		} else {
			$png    = (string) $order->get_meta( '_pagbank_pix_qr_png' );
			$url    = (string) $order->get_meta( '_pagbank_pix_qr_url' );
			$code   = (string) $order->get_meta( '_pagbank_pix_qr_code' );
			$expires = (string) $order->get_meta( '_pagbank_pix_expires' );

			if ( '' !== $png ) {
				echo '<p><img src="data:image/png;base64,' . esc_attr( $png ) . '" alt="' . esc_attr__( 'QR Code PIX', 'wooflow-pagbank' ) . '" style="max-width:260px;height:auto;"></p>';
			} elseif ( '' !== $url ) {
				echo '<p><img src="' . esc_url( $url ) . '" alt="' . esc_attr__( 'QR Code PIX', 'wooflow-pagbank' ) . '" style="max-width:260px;height:auto;"></p>';
			}

			if ( '' !== $code ) {
				echo '<p><strong>' . esc_html__( 'Código copia e cola:', 'wooflow-pagbank' ) . '</strong></p>';
				echo '<p><textarea id="wooflow-pagbank-pix-code" readonly rows="3" style="width:100%;max-width:680px;">' . esc_textarea( $code ) . '</textarea></p>';
				echo '<p><button type="button" class="button wooflow-pagbank-copy" data-copy-target="wooflow-pagbank-pix-code">' . esc_html__( 'Copiar código', 'wooflow-pagbank' ) . '</button></p>';
				self::print_copy_script();
			}

			if ( '' !== $expires ) {
				$expires_ts = WC_Pagbank_Customer::timestamp_from_api_datetime( $expires );
				$formatted  = $expires_ts ? wp_date( 'd/m/Y H:i:s', $expires_ts ) : $expires;
				echo '<p><strong>' . esc_html__( 'Expira em:', 'wooflow-pagbank' ) . '</strong> ';
				echo '<span class="wooflow-pagbank-pix-expire-countdown" data-expires="' . esc_attr( (string) $expires_ts ) . '" data-fallback="' . esc_attr( $formatted ) . '">--:--</span>';
				echo '<br><small style="color:#666;">' . esc_html( $formatted ) . '</small></p>';
				self::print_countdown_script();
			}

			if ( '' === $png && '' === $url && '' === $code ) {
				echo '<p>' . esc_html__( 'Ainda estamos aguardando os dados do PIX. Atualize a página em instantes.', 'wooflow-pagbank' ) . '</p>';
			}

			self::print_poll_script( $order );
		}

		self::charge_line( $order );
		echo '</section>';
	}

	public static function render_card( int $order_id ): void {
		if ( isset( self::$rendered_card[ $order_id ] ) ) {
			return;
		}
		self::$rendered_card[ $order_id ] = true;

		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::can_view( $order ) ) {
			return;
		}

		self::sync_from_api( $order );

		echo '<section class="woocommerce-order-details wooflow-pagbank-payment-details wooflow-pagbank-payment-details--card">';
		echo '<h2>' . esc_html__( 'Pagamento no cartão', 'wooflow-pagbank' ) . '</h2>';

		if ( $order->is_paid() ) {
			echo '<p>✅ <strong>' . esc_html__( 'Pagamento confirmado!', 'wooflow-pagbank' ) . '</strong> '
				. esc_html__( 'Seu pedido foi aprovado e está sendo processado.', 'wooflow-pagbank' ) . '</p>';
			self::paid_at( $order );
		} elseif ( in_array( $order->get_status(), array( 'failed', 'cancelled', 'refunded' ), true ) ) {
			echo '<p>' . esc_html__( 'Este pedido não foi concluído. Nenhum valor adicional será cobrado por esta tentativa.', 'wooflow-pagbank' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Seu pagamento está em análise no PagBank.', 'wooflow-pagbank' ) . '</p>';
		}

		$brand        = (string) $order->get_meta( '_pagbank_card_brand' );
		$last         = (string) $order->get_meta( '_pagbank_card_last' );
		$installments = (int) $order->get_meta( '_pagbank_installments' );
		if ( '' !== $brand || '' !== $last ) {
			echo '<p><strong>' . esc_html__( 'Cartão:', 'wooflow-pagbank' ) . '</strong> ' . esc_html( trim( ucfirst( $brand ) . ( '' !== $last ? ' •••• ' . $last : '' ) ) ) . '</p>';
		}
		if ( $installments > 0 ) {
			$interest = (int) $order->get_meta( '_pagbank_interest_cents' );
			$parcel   = $installments . 'x';
			if ( $interest > 0 ) {
				$parcel .= ' ' . sprintf(
					/* translators: %s: interest amount */
					__( 'com juros (%s)', 'wooflow-pagbank' ),
					wp_strip_all_tags( wc_price( $interest / 100 ) )
				);
			}
			echo '<p><strong>' . esc_html__( 'Parcelamento:', 'wooflow-pagbank' ) . '</strong> ' . esc_html( $parcel ) . '</p>';
		}

		self::charge_line( $order );
		echo '</section>';
	}

	public static function render_boleto( int $order_id ): void {
		if ( isset( self::$rendered_boleto[ $order_id ] ) ) {
			return;
		}
		self::$rendered_boleto[ $order_id ] = true;

		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::can_view( $order ) ) {
			return;
		}

		self::sync_from_api( $order );
		$pdf  = (string) $order->get_meta( '_pagbank_boleto_pdf' );
		$line = (string) $order->get_meta( '_pagbank_boleto_line' );
		$due  = (string) $order->get_meta( '_pagbank_boleto_due' );

		echo '<section class="woocommerce-order-details wooflow-pagbank-payment-details wooflow-pagbank-payment-details--boleto">';
		echo '<h2>' . esc_html__( 'Pagamento por boleto', 'wooflow-pagbank' ) . '</h2>';

		if ( $order->is_paid() ) {
			echo '<p>✅ <strong>' . esc_html__( 'Pagamento do boleto confirmado!', 'wooflow-pagbank' ) . '</strong></p>';
			self::paid_at( $order );
		} elseif ( in_array( $order->get_status(), array( 'failed', 'cancelled', 'refunded' ), true ) ) {
			echo '<p class="wooflow-pagbank-warning">' . esc_html__( 'Este pedido não está mais ativo. Não pague o boleto se ele ainda estiver em mãos.', 'wooflow-pagbank' ) . '</p>';
		} else {
			if ( '' !== $pdf ) {
				echo '<p><a class="button" href="' . esc_url( $pdf ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Abrir boleto', 'wooflow-pagbank' ) . '</a></p>';
			}
			if ( '' !== $line ) {
				echo '<p><strong>' . esc_html__( 'Linha digitável:', 'wooflow-pagbank' ) . '</strong></p>';
				echo '<p><textarea id="wooflow-pagbank-boleto-line" readonly rows="2" style="width:100%;max-width:680px;">' . esc_textarea( $line ) . '</textarea></p>';
				echo '<p><button type="button" class="button wooflow-pagbank-copy" data-copy-target="wooflow-pagbank-boleto-line">' . esc_html__( 'Copiar linha digitável', 'wooflow-pagbank' ) . '</button></p>';
				self::print_copy_script();
			}
			if ( '' !== $due ) {
				$due_ts = strtotime( $due . ' 12:00:00' );
				echo '<p><strong>' . esc_html__( 'Vencimento:', 'wooflow-pagbank' ) . '</strong> ' . esc_html( $due_ts ? wp_date( 'd/m/Y', $due_ts ) : $due ) . '</p>';
			}
			if ( '' === $pdf && '' === $line ) {
				echo '<p>' . esc_html__( 'Ainda estamos aguardando os dados do boleto. Atualize a página em instantes.', 'wooflow-pagbank' ) . '</p>';
			}
		}

		self::charge_line( $order );
		echo '</section>';
	}

	/**
	 * @param WC_Order $order
	 * @param bool     $sent_to_admin
	 * @param bool     $plain_text
	 * @param WC_Email $email
	 */
	public static function render_email( $order, $sent_to_admin, $plain_text, $email ): void {
		if ( $sent_to_admin || ! $order instanceof WC_Order || $order->is_paid() ) {
			return;
		}

		$method = $order->get_payment_method();
		if ( 'dw_pagbank_pix' === $method ) {
			$code = (string) $order->get_meta( '_pagbank_pix_qr_code' );
			if ( '' === $code ) {
				return;
			}
			if ( $plain_text ) {
				echo "\n" . esc_html__( 'PIX copia e cola:', 'wooflow-pagbank' ) . "\n" . esc_html( $code ) . "\n";
				return;
			}
			echo '<p><strong>' . esc_html__( 'PIX copia e cola', 'wooflow-pagbank' ) . '</strong><br>' . esc_html( $code ) . '</p>';
		}

		if ( 'dw_pagbank_boleto' === $method ) {
			$line = (string) $order->get_meta( '_pagbank_boleto_line' );
			$pdf  = (string) $order->get_meta( '_pagbank_boleto_pdf' );
			if ( '' === $line && '' === $pdf ) {
				return;
			}
			if ( $plain_text ) {
				echo "\n" . esc_html__( 'Linha digitável:', 'wooflow-pagbank' ) . "\n" . esc_html( $line ) . "\n" . esc_url( $pdf ) . "\n";
				return;
			}
			echo '<p><strong>' . esc_html__( 'Boleto', 'wooflow-pagbank' ) . '</strong><br>' . esc_html( $line );
			if ( '' !== $pdf ) {
				echo '<br><a href="' . esc_url( $pdf ) . '">' . esc_html__( 'Abrir boleto', 'wooflow-pagbank' ) . '</a>';
			}
			echo '</p>';
		}
	}

	public static function ajax_poll(): void {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0';
		$key = 'dw_pbk_poll_' . md5( $ip );
		if ( get_transient( $key ) ) {
			wp_send_json_error( array( 'message' => 'rate' ), 429 );
		}
		set_transient( $key, 1, 4 );

		$order_id  = absint( $_POST['order_id'] ?? 0 );
		$order_key = isset( $_POST['order_key'] ) ? wc_clean( wp_unslash( $_POST['order_key'] ) ) : '';
		$order     = wc_get_order( $order_id );
		if ( ! $order || '' === $order_key || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		if ( 'dw_pagbank_pix' !== $order->get_payment_method() ) {
			wp_send_json_error( array( 'message' => 'invalid' ), 400 );
		}

		if ( ! $order->is_paid() && ! $order->has_status( array( 'failed', 'cancelled', 'refunded' ) ) ) {
			self::sync_from_api( $order );
		}

		wp_send_json_success(
			array(
				'paid' => $order->is_paid(),
				'dead' => $order->has_status( array( 'failed', 'cancelled', 'refunded' ) ),
			)
		);
	}

	private static function sync_from_api( WC_Order $order ): void {
		if ( $order->is_paid() ) {
			return;
		}
		$charge_id = (string) $order->get_meta( '_pagbank_charge_id' );
		if ( '' === $charge_id ) {
			return;
		}

		try {
			$charge = WC_Pagbank_API::for_order( $order )->lookup_charge( $order );
			WC_Pagbank_Webhook::apply_charge( $order, $charge );
		} catch ( Exception $e ) {
			WC_Pagbank_Logger::log( 'consulta do pedido ' . $order->get_id() . ' falhou.', 'warning' );
		}
	}

	private static function can_view( WC_Order $order ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		$user_id = get_current_user_id();
		if ( $user_id > 0 && (int) $order->get_user_id() === $user_id ) {
			return true;
		}
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		return '' !== $key && hash_equals( $order->get_order_key(), $key );
	}

	private static function paid_at( WC_Order $order ): void {
		$paid = $order->get_date_paid();
		if ( $paid ) {
			echo '<p><strong>' . esc_html__( 'Pago em:', 'wooflow-pagbank' ) . '</strong> '
				. esc_html( wp_date( 'd/m/Y \à\s H:i', $paid->getTimestamp() ) ) . '</p>';
		}
	}

	private static function charge_line( WC_Order $order ): void {
		$charge_id = (string) $order->get_meta( '_pagbank_charge_id' );
		if ( '' !== $charge_id ) {
			echo '<p><small>' . esc_html__( 'Cobrança PagBank:', 'wooflow-pagbank' ) . ' ' . esc_html( $charge_id ) . '</small></p>';
		}
	}

	private static function print_countdown_script(): void {
		if ( self::$countdown_printed ) {
			return;
		}
		self::$countdown_printed = true;
		?>
		<script>
		(function () {
			function tick() {
				document.querySelectorAll('.wooflow-pagbank-pix-expire-countdown').forEach(function (el) {
					var expires = parseInt(el.getAttribute('data-expires') || '0', 10) * 1000;
					var left = expires - Date.now();
					if (!expires || left <= 0) {
						el.textContent = el.getAttribute('data-fallback') || '00:00';
						return;
					}
					var total = Math.floor(left / 1000);
					var hours = Math.floor(total / 3600);
					var minutes = Math.floor((total % 3600) / 60);
					var seconds = total % 60;
					var pad = function (n) { return (n < 10 ? '0' : '') + n; };
					el.textContent = (hours > 0 ? hours + ':' : '') + pad(minutes) + ':' + pad(seconds);
				});
			}
			tick();
			setInterval(tick, 1000);
		}());
		</script>
		<?php
	}

	private static function print_poll_script( WC_Order $order ): void {
		if ( self::$poll_printed || $order->is_paid() ) {
			return;
		}
		self::$poll_printed = true;
		?>
		<script>
		(function () {
			var runs = 0;
			var timer = setInterval(function () {
				runs++;
				if (runs > 120) {
					clearInterval(timer);
					return;
				}
				var body = new URLSearchParams();
				body.set('action', 'wooflow_pagbank_poll_pix');
				body.set('order_id', '<?php echo esc_js( (string) $order->get_id() ); ?>');
				body.set('order_key', '<?php echo esc_js( $order->get_order_key() ); ?>');
				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				}).then(function (response) { return response.json(); }).then(function (payload) {
					if (payload && payload.success && payload.data && (payload.data.paid || payload.data.dead)) {
						clearInterval(timer);
						window.location.reload();
					}
				}).catch(function () {});
			}, 5000);
		}());
		</script>
		<?php
	}

	private static function print_copy_script(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
		document.addEventListener('click', function (event) {
			var button = event.target.closest('.wooflow-pagbank-copy');
			if (!button) return;
			var field = document.getElementById(button.getAttribute('data-copy-target'));
			if (!field) return;
			field.select();
			var done = function () {
				button.textContent = '<?php echo esc_js( __( 'Copiado', 'wooflow-pagbank' ) ); ?>';
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(field.value).then(done).catch(function () {
					document.execCommand('copy');
					done();
				});
			} else {
				document.execCommand('copy');
				done();
			}
		});
		</script>
		<?php
	}
}
