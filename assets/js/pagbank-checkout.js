/* global wooflow_pagbank_params */
( function ( $ ) {
    'use strict';

    $( function () {
        initBrands();
        syncTaxFields();
        rememberInterestFreeOptions();
    } );

    $( document.body ).on( 'updated_checkout', function () {
        initBrands();
        syncTaxFields();
        rememberInterestFreeOptions();
        plansBin = '';
        refreshInstallments( ( $( '#wooflow_pagbank_number' ).val() || '' ).replace( /\D/g, '' ) );
    } );

    $( document.body ).on( 'input change', 'input[name="billing_cpf"], input[name="billing_cnpj"]', syncTaxFields );
    $( document.body ).on( 'click', '.wooflow-person-type__btn', function () {
        window.setTimeout( syncTaxFields, 0 );
    } );

    $( document.body ).on( 'input', '#wooflow_pagbank_number', function () {
        var digits = this.value.replace( /\D/g, '' ).slice( 0, 19 );
        this.value = digits.replace( /(.{4})/g, '$1 ' ).trim();
        $( '#wooflow_pagbank_encrypted' ).val( '' );
        clearThreeDS();
        setBrand( detectBrand( digits ) );
        refreshInstallments( digits );
    } );

    $( document.body ).on( 'input', '#wooflow_pagbank_expiry', function () {
        var digits = this.value.replace( /\D/g, '' ).slice( 0, 4 );
        this.value = digits.length >= 2 ? digits.slice( 0, 2 ) + '/' + digits.slice( 2 ) : digits;
        $( '#wooflow_pagbank_encrypted' ).val( '' );
        clearThreeDS();
    } );

    $( document.body ).on( 'input', '#wooflow_pagbank_cvv, #wooflow_pagbank_name', function () {
        if ( this.id === 'wooflow_pagbank_cvv' ) {
            this.value = this.value.replace( /\D/g, '' ).slice( 0, 4 );
        }
        $( '#wooflow_pagbank_encrypted' ).val( '' );
        clearThreeDS();
    } );

    function onPlaceOrder() {
        try {
            var status = $( '#wooflow_pagbank_3ds_status' ).val();
            if ( ( status === 'completed' || status === 'unsupported' ) && $( '#wooflow_pagbank_encrypted' ).val() ) {
                return true;
            }
            if ( window.wooflowPagbankAuthenticating ) {
                return false;
            }
            window.wooflowPagbankAuthenticating = true;
            authenticateCard().always( function () {
                window.wooflowPagbankAuthenticating = false;
            } );
        } catch ( error ) {
            window.wooflowPagbankAuthenticating = false;
            return fail( 'Não foi possível autenticar o cartão. Tente novamente.' );
        }
        return false;
    }

    var plansBin = '';
    var plansTimer = null;
    var interestFreeOptions = '';

    function rememberInterestFreeOptions() {
        var $select = $( '#wooflow_pagbank_installments' );
        if ( $select.length && ! $select.find( 'option[data-total]' ).length ) {
            interestFreeOptions = $select.html();
        }
    }

    function refreshInstallments( digits ) {
        var params = window.wooflow_pagbank_params || {};
        var max = parseInt( params.max_installments, 10 ) || 12;
        var free = parseInt( params.interest_free_installments, 10 );
        if ( isNaN( free ) ) {
            free = max;
        }
        var bin = String( digits || '' ).replace( /\D/g, '' ).slice( 0, 6 );
        $( '#wooflow_pagbank_card_bin' ).val( bin.length === 6 ? bin : '' );
        if ( free >= max ) {
            return;
        }
        if ( bin.length < 6 ) {
            plansBin = '';
            if ( interestFreeOptions ) {
                $( '#wooflow_pagbank_installments' ).html( interestFreeOptions );
            }
            return;
        }
        if ( bin === plansBin ) {
            return;
        }
        window.clearTimeout( plansTimer );
        plansTimer = window.setTimeout( function () {
            $.post( params.ajax_url, {
                action: 'wooflow_pagbank_installments',
                nonce: params.installments_nonce,
                bin: bin
            } ).done( function ( response ) {
                var plans = response && response.success && response.data ? response.data.plans : null;
                if ( ! plans || ! plans.length ) {
                    return;
                }
                plansBin = bin;
                var $select = $( '#wooflow_pagbank_installments' );
                var current = $select.val();
                $select.empty();
                plans.forEach( function ( plan ) {
                    $( '<option></option>' )
                        .val( String( plan.installments ) )
                        .text( plan.label )
                        .attr( {
                            'data-total': String( plan.total || 0 ),
                            'data-interest-total': String( plan.interest_total || 0 ),
                            'data-interest-installments': String( plan.interest_installments || 0 )
                        } )
                        .appendTo( $select );
                } );
                if ( current && $select.find( 'option[value="' + current + '"]' ).length ) {
                    $select.val( current );
                }
            } );
        }, 250 );
    }

    function bindPlaceOrder() {
        $( 'form.checkout' )
            .off( 'checkout_place_order_dw_pagbank.wooflow' )
            .on( 'checkout_place_order_dw_pagbank.wooflow', onPlaceOrder );
    }

    $( bindPlaceOrder );
    $( document.body ).on( 'updated_checkout', bindPlaceOrder );

    function initBrands() {
        var icons = ( window.wooflow_pagbank_params && wooflow_pagbank_params.card_brand_icons ) || {};
        var $accepted = $( '#wooflow_pagbank_accepted_brands' );
        if ( $accepted.length && ! $accepted.data( 'loaded' ) ) {
            [ 'mastercard', 'visa', 'elo', 'amex' ].forEach( function ( brand ) {
                if ( ! icons[ brand ] ) {
                    return;
                }
                $( '<span class="wooflow-pagbank-brand-item" aria-hidden="true"></span>' )
                    .attr( 'data-brand', brand )
                    .css( 'background-image', 'url(' + icons[ brand ] + ')' )
                    .appendTo( $accepted );
            } );
            $accepted.data( 'loaded', true );
        }

        var current = ( $( '#wooflow_pagbank_number' ).val() || '' ).replace( /\D/g, '' );
        setBrand( detectBrand( current ) );
    }

    function detectBrand( number ) {
        var patterns = [
            { brand: 'elo', regex: /^(4011(78|79)|431274|438935|451416|457393|45763[12]|504175|506(699|7[0-6]\d)|509\d{3}|627780|636297|636368|650(0[3-9]|[1-9]\d)|6516\d{2}|6550\d{2})/ },
            { brand: 'amex', regex: /^3[47]/ },
            { brand: 'mastercard', regex: /^(5[1-5]|2(?:2[2-9]|[3-6]\d|7[01]|720))/ },
            { brand: 'visa', regex: /^4/ }
        ];
        for ( var i = 0; i < patterns.length; i++ ) {
            if ( patterns[ i ].regex.test( number ) ) {
                return patterns[ i ].brand;
            }
        }
        return 'generic';
    }

    function setBrand( brand ) {
        var params = window.wooflow_pagbank_params || {};
        var icons = params.card_brand_icons || {};
        var names = params.card_brand_names || {};
        var detected = brand !== 'generic' && !! icons[ brand ];
        $( '#wooflow_pagbank_card_brand_icon' )
            .css( 'background-image', detected ? 'url(' + icons[ brand ] + ')' : 'none' )
            .toggleClass( 'is-visible', detected );
        $( '#wooflow_pagbank_card_brand_label' ).text( 'Bandeira: ' + ( names[ brand ] || names.generic || 'detectando...' ) );
        $( '#wooflow_pagbank_accepted_brands .wooflow-pagbank-brand-item' ).removeClass( 'is-active' )
            .filter( '[data-brand="' + brand + '"]' ).addClass( 'is-active' );
    }

    function errorMessage( errors ) {
        var code = errors && errors[ 0 ] && errors[ 0 ].code;
        var messages = {
            INVALID_NUMBER: 'Confira o número do cartão.',
            INVALID_SECURITY_CODE: 'Confira o CVV do cartão.',
            INVALID_EXPIRATION_MONTH: 'Confira o mês de vencimento.',
            INVALID_EXPIRATION_YEAR: 'Confira o ano de vencimento.',
            INVALID_PUBLIC_KEY: 'A chave pública do PagBank é inválida.',
            INVALID_HOLDER: 'Informe o nome impresso no cartão.'
        };
        return messages[ code ] || 'Não foi possível criptografar o cartão. Confira os dados e tente novamente.';
    }

    function taxDigits( value ) {
        return String( value || '' ).replace( /\D/g, '' );
    }

    function formatTax( digits ) {
        if ( digits.length === 11 ) {
            return digits.replace( /(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4' );
        }
        if ( digits.length === 14 ) {
            return digits.replace( /(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5' );
        }
        return '';
    }

    function checkoutDocument() {
        var person = $( '.wooflow-person-type__btn.is-active' ).attr( 'data-person-ui' );
        var cpf = taxDigits( $( 'input[name="billing_cpf"]' ).val() );
        var cnpj = taxDigits( $( 'input[name="billing_cnpj"]' ).val() );
        var chosen = person === 'pj' ? cnpj : cpf;
        if ( chosen.length !== 11 && chosen.length !== 14 ) {
            if ( cpf.length === 11 ) {
                return cpf;
            }
            if ( cnpj.length === 14 ) {
                return cnpj;
            }
            return '';
        }
        return chosen;
    }

    function syncTaxFields() {
        var formatted = formatTax( checkoutDocument() );
        $( '.wooflow-pagbank-tax-row' ).each( function () {
            var $row = $( this );
            var $input = $row.find( 'input' ).first();
            if ( ! formatted ) {
                if ( $row.is( '[data-pagbank-tax-locked]' ) ) {
                    $input.val( '' );
                    $row.removeAttr( 'hidden' ).removeAttr( 'data-pagbank-tax-locked' );
                }
                return;
            }
            $input.val( formatted );
            $row.attr( 'hidden', 'hidden' ).attr( 'data-pagbank-tax-locked', '1' );
        } );
    }

    function clearThreeDS() {
        $( '#wooflow_pagbank_3ds_id, #wooflow_pagbank_3ds_status' ).val( '' );
    }

    function fieldValue( name ) {
        var value = $( '[name="' + name + '"]' ).first().val();
        return value ? String( value ).trim() : '';
    }

    function lettersName( value ) {
        return String( value || '' )
            .normalize( 'NFD' )
            .replace( /[\u0300-\u036f]/g, '' )
            .replace( /[^A-Za-z\s]/g, ' ' )
            .replace( /\s+/g, ' ' )
            .trim();
    }

    function checkoutPhone() {
        var digits = ( fieldValue( 'billing_phone' ) || fieldValue( 'billing_cellphone' ) ).replace( /\D/g, '' );
        if ( digits.indexOf( '55' ) === 0 && digits.length > 11 ) {
            digits = digits.slice( 2 );
        }
        if ( ! /^[1-9]{2}[1-9]\d{7,8}$/.test( digits ) ) {
            return null;
        }
        var number = digits.slice( 2 );
        return {
            country: '55',
            area: digits.slice( 0, 2 ),
            number: number,
            type: number.length >= 9 ? 'MOBILE' : 'HOME'
        };
    }

    function checkoutAddress( prefix ) {
        var street = fieldValue( prefix + '_address_1' );
        var number = fieldValue( prefix + '_number' );
        if ( ! number ) {
            var match = street.match( /,\s*(\d+[A-Za-z0-9/-]*)\s*$/ );
            if ( match ) {
                number = match[ 1 ];
                street = street.replace( /,\s*\d+[A-Za-z0-9/-]*\s*$/, '' ).trim();
            }
        }
        var address = {
            street: street,
            number: number || 'S/N',
            city: fieldValue( prefix + '_city' ),
            regionCode: fieldValue( prefix + '_state' ).replace( /[^A-Za-z]/g, '' ).slice( 0, 2 ).toUpperCase(),
            country: 'BRA',
            postalCode: fieldValue( prefix + '_postcode' ).replace( /\D/g, '' )
        };
        var complement = fieldValue( prefix + '_address_2' );
        if ( complement ) {
            address.complement = complement;
        }
        return address;
    }

    function cardFields() {
        var expiry = ( $( '#wooflow_pagbank_expiry' ).val() || '' ).split( '/' );
        var month = ( expiry[ 0 ] || '' ).replace( /\D/g, '' );
        var year = ( expiry[ 1 ] || '' ).replace( /\D/g, '' );
        return {
            holder: $( '#wooflow_pagbank_name' ).val() || '',
            number: ( $( '#wooflow_pagbank_number' ).val() || '' ).replace( /\D/g, '' ),
            expMonth: month ? String( parseInt( month, 10 ) ) : '',
            expMonthPadded: month ? String( parseInt( month, 10 ) ).padStart( 2, '0' ) : '',
            expYear: year.length === 2 ? '20' + year : year,
            securityCode: $( '#wooflow_pagbank_cvv' ).val() || ''
        };
    }

    function encryptCurrentCard( params, card ) {
        if ( ! window.PagSeguro || typeof window.PagSeguro.encryptCard !== 'function' ) {
            return null;
        }
        return window.PagSeguro.encryptCard( {
            publicKey: params.public_key,
            holder: card.holder,
            number: card.number,
            expMonth: card.expMonth,
            expYear: card.expYear,
            securityCode: card.securityCode
        } );
    }

    function authenticateCard() {
        var params = window.wooflow_pagbank_params || {};
        var card = cardFields();
        if ( ! params.public_key ) {
            return $.Deferred().reject().always( function () {
                fail( params.missing_public_key_message || 'Configure a chave pública do PagBank.' );
            } );
        }
        var encrypted = encryptCurrentCard( params, card );
        if ( ! encrypted || encrypted.hasErrors || ! encrypted.encryptedCard ) {
            return $.Deferred().reject().always( function () {
                fail( errorMessage( encrypted && encrypted.errors ) );
            } );
        }
        $( '#wooflow_pagbank_encrypted' ).val( encrypted.encryptedCard );

        return $.post( params.ajax_url, {
            action: 'wooflow_pagbank_3ds_session',
            nonce: params.threeds_nonce
        } ).then( function ( response ) {
            var data = response && response.success ? response.data : null;
            if ( ! data || ! data.session ) {
                return $.Deferred().reject( ( response && response.data && response.data.message ) || 'Não foi possível iniciar a autenticação do cartão.' );
            }
            if ( typeof window.PagSeguro.setUp !== 'function' || typeof window.PagSeguro.authenticate3DS !== 'function' ) {
                return $.Deferred().reject( 'O SDK do PagBank não oferece a autenticação 3DS.' );
            }
            window.PagSeguro.setUp( {
                session: data.session,
                env: data.env || params.sdk_env || 'SANDBOX'
            } );
            var buyer = lettersName( fieldValue( 'billing_first_name' ) + ' ' + fieldValue( 'billing_last_name' ) );
            var phone = checkoutPhone();
            if ( ! buyer || buyer.indexOf( ' ' ) === -1 || ! phone ) {
                return $.Deferred().reject( 'Confira nome, sobrenome e telefone com DDD antes de pagar com cartão.' );
            }
            var billing = checkoutAddress( 'billing' );
            if ( ! billing.street || billing.regionCode.length !== 2 || billing.postalCode.length < 5 ) {
                return $.Deferred().reject( 'Confira o endereço de cobrança antes de pagar com cartão.' );
            }
            var shippingStreet = fieldValue( 'shipping_address_1' );
            var shipping = shippingStreet ? checkoutAddress( 'shipping' ) : billing;
            return window.PagSeguro.authenticate3DS( {
                data: {
                    customer: {
                        name: buyer,
                        email: fieldValue( 'billing_email' ),
                        phones: [ phone ]
                    },
                    paymentMethod: {
                        type: 'CREDIT_CARD',
                        installments: Math.max( 1, parseInt( $( '#wooflow_pagbank_installments' ).val(), 10 ) || 1 ),
                        card: {
                            number: card.number,
                            expMonth: card.expMonthPadded,
                            expYear: card.expYear,
                            holder: { name: lettersName( card.holder ) || buyer }
                        }
                    },
                    amount: {
                        value: parseInt( $( '#wooflow_pagbank_installments option:selected' ).attr( 'data-total' ), 10 ) || parseInt( data.amount, 10 ) || 0,
                        currency: 'BRL'
                    },
                    billingAddress: billing,
                    shippingAddress: shipping
                },
                dataOnly: false
            } );
        } ).then( function ( result ) {
            var status = result && result.status;
            if ( status === 'AUTH_NOT_SUPPORTED' ) {
                $( '#wooflow_pagbank_3ds_id' ).val( '' );
                $( '#wooflow_pagbank_3ds_status' ).val( 'unsupported' );
                $( 'form.checkout' ).trigger( 'submit' );
                return;
            }
            if ( status === 'AUTH_FLOW_COMPLETED' && result.id ) {
                $( '#wooflow_pagbank_3ds_id' ).val( result.id );
                $( '#wooflow_pagbank_3ds_status' ).val( 'completed' );
                $( 'form.checkout' ).trigger( 'submit' );
                return;
            }
            clearThreeDS();
            fail( status === 'CHANGE_PAYMENT_METHOD'
                ? 'O banco não autenticou este cartão. Escolha outro cartão ou outra forma de pagamento.'
                : 'Não foi possível autenticar o cartão. Tente novamente.' );
        } ).fail( function ( error ) {
            clearThreeDS();
            var message = 'Não foi possível autenticar o cartão. Tente novamente.';
            if ( typeof error === 'string' ) {
                message = error;
            } else if ( error && error.detail && error.detail.message ) {
                message = error.detail.message;
            } else if ( error && error.responseJSON && error.responseJSON.data && error.responseJSON.data.message ) {
                message = error.responseJSON.data.message;
            }
            fail( message );
        } );
    }

    function fail( message ) {
        var $form = $( 'form.checkout' );
        $( '.woocommerce-NoticeGroup-checkout' ).remove();
        $form.prepend( '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><ul class="woocommerce-error" role="alert"><li>' + $( '<div>' ).text( message ).html() + '</li></ul></div>' );
        $form.removeClass( 'processing' );
        if ( typeof $form.unblock === 'function' ) {
            $form.unblock();
        }
        if ( $form.offset() ) {
            $( 'html, body' ).animate( { scrollTop: $form.offset().top - 80 }, 250 );
        }
        return false;
    }
}( jQuery ) );
