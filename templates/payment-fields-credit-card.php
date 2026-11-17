<?php
/**
 * Campos de cartão no checkout clássico.
 *
 * O número, a validade e o CVV não têm atributo name: o navegador não os envia.
 * O servidor recebe só o cartão criptografado pelo SDK do PagBank.
 *
 * @var int   $max_installments
 * @var float $min_installment_amount
 * @var float $cart_total
 * @var bool  $is_sandbox
 * @var bool  $public_key_configured
 * @var array $installment_options
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="wooflow-pagbank-fields" class="wooflow-pagbank-wrapper">
	<?php if ( ! empty( $is_sandbox ) ) : ?>
		<p class="wooflow-pagbank-warning">
			<?php esc_html_e( 'Sandbox: este pagamento é de teste e não tem valor real. Use o cartão Mastercard 5555 6666 7777 8884, uma validade futura e o CVV 123.', 'wooflow-pagbank' ); ?>
		</p>
	<?php endif; ?>
	<?php if ( ! $public_key_configured ) : ?>
		<p class="wooflow-pagbank-warning">
			<?php esc_html_e( 'Configure a chave pública do PagBank para habilitar o cartão.', 'wooflow-pagbank' ); ?>
		</p>
	<?php endif; ?>

	<div class="wooflow-pagbank-accepted-brands" id="wooflow_pagbank_accepted_brands" aria-label="<?php esc_attr_e( 'Bandeiras aceitas', 'wooflow-pagbank' ); ?>"></div>

	<input type="hidden" id="wooflow_pagbank_encrypted" name="wooflow_pagbank_encrypted" value="">
	<input type="hidden" id="wooflow_pagbank_card_bin" name="wooflow_pagbank_card_bin" value="">
	<input type="hidden" id="wooflow_pagbank_3ds_id" name="wooflow_pagbank_3ds_id" value="">
	<input type="hidden" id="wooflow_pagbank_3ds_status" name="wooflow_pagbank_3ds_status" value="">

	<?php WC_Pagbank_Customer::render_tax_field( 'wooflow_pagbank_tax_id' ); ?>

	<p class="form-row form-row-wide">
		<label for="wooflow_pagbank_number">
			<?php esc_html_e( 'Número do cartão', 'wooflow-pagbank' ); ?> <span class="required">*</span>
		</label>
		<span class="wooflow-pagbank-card-input-wrapper" id="wooflow_pagbank_card_number_wrapper">
			<input type="tel" id="wooflow_pagbank_number" autocomplete="cc-number" inputmode="numeric" maxlength="19" placeholder="0000 0000 0000 0000">
			<span class="wooflow-pagbank-card-brand-icon" id="wooflow_pagbank_card_brand_icon" aria-hidden="true"></span>
		</span>
		<small class="wooflow-pagbank-card-brand-label" id="wooflow_pagbank_card_brand_label">
			<?php esc_html_e( 'Bandeira: detectando...', 'wooflow-pagbank' ); ?>
		</small>
	</p>

	<p class="form-row form-row-wide">
		<label for="wooflow_pagbank_name">
			<?php esc_html_e( 'Nome impresso no cartão', 'wooflow-pagbank' ); ?> <span class="required">*</span>
		</label>
		<input type="text" id="wooflow_pagbank_name" name="wooflow_pagbank_holder" autocomplete="cc-name" placeholder="<?php esc_attr_e( 'Nome do titular', 'wooflow-pagbank' ); ?>">
	</p>

	<div class="wooflow-pagbank-inline-fields">
		<p class="form-row form-row-first">
			<label for="wooflow_pagbank_expiry">
				<?php esc_html_e( 'Vencimento', 'wooflow-pagbank' ); ?> <span class="required">*</span>
			</label>
			<input type="tel" id="wooflow_pagbank_expiry" autocomplete="cc-exp" inputmode="numeric" maxlength="5" placeholder="MM/AA">
		</p>
		<p class="form-row form-row-last">
			<label for="wooflow_pagbank_cvv">
				<?php esc_html_e( 'CVV', 'wooflow-pagbank' ); ?> <span class="required">*</span>
			</label>
			<input type="tel" id="wooflow_pagbank_cvv" autocomplete="cc-csc" inputmode="numeric" maxlength="4" placeholder="<?php esc_attr_e( 'Ex.: 123', 'wooflow-pagbank' ); ?>">
		</p>
	</div>

	<p class="form-row form-row-wide">
		<label for="wooflow_pagbank_installments"><?php esc_html_e( 'Parcelas', 'wooflow-pagbank' ); ?></label>
		<select id="wooflow_pagbank_installments" name="wooflow_pagbank_installments">
			<?php foreach ( $installment_options as $option ) : ?>
				<option value="<?php echo esc_attr( (string) $option['value'] ); ?>"><?php echo esc_html( $option['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
</div>
