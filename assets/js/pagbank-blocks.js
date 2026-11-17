( function () {
    if ( window.wooflowPagbankBlocksReady ) {
        return;
    }
    window.wooflowPagbankBlocksReady = true;

    var registry = window.wc && window.wc.wcBlocksRegistry;
    var settingsApi = window.wc && window.wc.wcSettings;
    var element = window.wp && window.wp.element;
    var dataApi = window.wp && window.wp.data;
    if ( ! registry || ! settingsApi || ! element || ! dataApi ) {
        return;
    }

    var createElement = element.createElement;
    var useEffect = element.useEffect;
    var useState = element.useState;
    var useSelect = dataApi.useSelect;
    var decode = window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities
        ? window.wp.htmlEntities.decodeEntities
        : function ( value ) { return value; };

    function money( value ) {
        return Number( value || 0 ).toLocaleString( 'pt-BR', { style: 'currency', currency: 'BRL' } );
    }

    function cartTotal() {
        return useSelect( function ( select ) {
            var store = select( 'wc/store/cart' );
            if ( ! store || ! store.getCartTotals ) {
                return 0;
            }
            var totals = store.getCartTotals() || {};
            var minor = parseInt( totals.currency_minor_unit, 10 );
            var amount = parseInt( totals.total_price, 10 );
            if ( ! amount ) {
                return 0;
            }
            return amount / Math.pow( 10, isNaN( minor ) ? 2 : minor );
        }, [] );
    }

    function detectBrand( number ) {
        if ( /^(4011(78|79)|431274|438935|451416|457393|45763[12]|504175|506(699|7[0-6]\d)|509\d{3}|627780|636297|636368|650(0[3-9]|[1-9]\d)|6516\d{2}|6550\d{2})/.test( number ) ) {
            return 'elo';
        }
        if ( /^3[47]/.test( number ) ) {
            return 'amex';
        }
        if ( /^(5[1-5]|2(?:2[2-9]|[3-6]\d|7[01]|720))/.test( number ) ) {
            return 'mastercard';
        }
        if ( /^4/.test( number ) ) {
            return 'visa';
        }
        return '';
    }

    function Label( settings ) {
        return function () {
            return createElement( 'span', null, decode( settings.title || 'PagBank' ) );
        };
    }

    function DocumentField( id, value, setValue ) {
        return createElement( 'p', { className: 'form-row form-row-wide' },
            createElement( 'label', { htmlFor: id }, 'CPF ou CNPJ ', createElement( 'span', { className: 'required' }, '*' ) ),
            createElement( 'input', {
                id: id,
                type: 'text',
                inputMode: 'numeric',
                autoComplete: 'off',
                maxLength: 18,
                placeholder: '000.000.000-00',
                value: value,
                onChange: function ( event ) { setValue( event.target.value ); }
            } )
        );
    }

    function CardContent( props ) {
        var settings = settingsApi.getSetting( 'dw_pagbank_data', {} );
        var onPaymentSetup = props.eventRegistration && props.eventRegistration.onPaymentSetup;
        var emitResponse = props.emitResponse;
        var total = cartTotal();
        var numberState = useState( '' );
        var holderState = useState( '' );
        var expiryState = useState( '' );
        var cvvState = useState( '' );
        var taxState = useState( '' );
        var installmentsState = useState( '1' );
        var number = numberState[ 0 ];
        var setNumber = numberState[ 1 ];
        var holder = holderState[ 0 ];
        var setHolder = holderState[ 1 ];
        var expiry = expiryState[ 0 ];
        var setExpiry = expiryState[ 1 ];
        var cvv = cvvState[ 0 ];
        var setCvv = cvvState[ 1 ];
        var taxId = taxState[ 0 ];
        var setTaxId = taxState[ 1 ];
        var installments = installmentsState[ 0 ];
        var setInstallments = installmentsState[ 1 ];
        var plansState = useState( [] );
        var plans = plansState[ 0 ];
        var setPlans = plansState[ 1 ];
        var brand = detectBrand( number.replace( /\D/g, '' ) );

        useEffect( function () {
            var digits = number.replace( /\D/g, '' );
            var max = parseInt( settings.maxInstallments, 10 ) || 12;
            var free = parseInt( settings.interestFreeInstallments, 10 );
            if ( isNaN( free ) ) {
                free = max;
            }
            if ( free >= max || digits.length < 6 || ! settings.ajaxUrl ) {
                setPlans( [] );
                return undefined;
            }
            var payload = new window.FormData();
            payload.append( 'action', 'wooflow_pagbank_installments' );
            payload.append( 'nonce', settings.installmentsNonce || '' );
            payload.append( 'bin', digits.slice( 0, 6 ) );
            var cancelled = false;
            window.fetch( settings.ajaxUrl, { method: 'POST', body: payload, credentials: 'same-origin' } )
                .then( function ( response ) { return response.json(); } )
                .then( function ( response ) {
                    if ( ! cancelled && response && response.success && response.data && response.data.plans ) {
                        setPlans( response.data.plans );
                    }
                } )
                .catch( function () {} );
            return function () { cancelled = true; };
        }, [ number, settings.ajaxUrl, settings.installmentsNonce, settings.maxInstallments, settings.interestFreeInstallments ] );

        useEffect( function () {
            if ( ! onPaymentSetup || ! emitResponse ) {
                return undefined;
            }
            var unsubscribe = onPaymentSetup( function () {
                return new Promise( function ( resolve ) {
                    var error = function ( message ) {
                        resolve( { type: emitResponse.responseTypes.ERROR, message: message } );
                    };
                    if ( ! settings.publicKey ) {
                        error( 'Configure a chave pública do PagBank.' );
                        return;
                    }
                    if ( ! window.PagSeguro || typeof window.PagSeguro.encryptCard !== 'function' || typeof window.PagSeguro.authenticate3DS !== 'function' ) {
                        error( 'Não foi possível carregar o SDK do PagBank.' );
                        return;
                    }
                    var parts = ( expiry || '' ).split( '/' );
                    var month = ( parts[ 0 ] || '' ).replace( /\D/g, '' );
                    var year = ( parts[ 1 ] || '' ).replace( /\D/g, '' );
                    var expYear = year.length === 2 ? '20' + year : year;
                    var digits = number.replace( /\D/g, '' );
                    var card = window.PagSeguro.encryptCard( {
                        publicKey: settings.publicKey,
                        holder: holder,
                        number: digits,
                        expMonth: String( parseInt( month, 10 ) ),
                        expYear: expYear,
                        securityCode: cvv
                    } );
                    if ( ! card || card.hasErrors || ! card.encryptedCard ) {
                        error( 'Confira os dados do cartão e tente novamente.' );
                        return;
                    }
                    var payload = new window.FormData();
                    payload.append( 'action', 'wooflow_pagbank_3ds_session' );
                    payload.append( 'nonce', settings.threedsNonce || '' );
                    window.fetch( settings.ajaxUrl, { method: 'POST', body: payload, credentials: 'same-origin' } )
                        .then( function ( response ) { return response.json(); } )
                        .then( function ( response ) {
                            if ( ! response || ! response.success || ! response.data || ! response.data.session ) {
                                throw new Error( ( response && response.data && response.data.message ) || 'Não foi possível iniciar a autenticação do cartão.' );
                            }
                            window.PagSeguro.setUp( { session: response.data.session, env: response.data.env || settings.sdkEnv || 'SANDBOX' } );
                            var select = window.wp.data.select;
                            var billing = {};
                            try {
                                var checkout = select( 'wc/store/checkout' );
                                billing = ( checkout && checkout.getBillingAddress && checkout.getBillingAddress() ) || {};
                            } catch ( ignored ) {
                                billing = {};
                            }
                            var buyer = ( ( billing.first_name || '' ) + ' ' + ( billing.last_name || '' ) ).replace( /\s+/g, ' ' ).trim();
                            var phoneDigits = String( billing.phone || '' ).replace( /\D/g, '' );
                            if ( phoneDigits.indexOf( '55' ) === 0 && phoneDigits.length > 11 ) {
                                phoneDigits = phoneDigits.slice( 2 );
                            }
                            var phoneNumber = phoneDigits.slice( 2 );
                            return window.PagSeguro.authenticate3DS( {
                                data: {
                                    customer: {
                                        name: buyer,
                                        email: billing.email || '',
                                        phones: [ {
                                            country: '55',
                                            area: phoneDigits.slice( 0, 2 ),
                                            number: phoneNumber,
                                            type: phoneNumber.length >= 9 ? 'MOBILE' : 'HOME'
                                        } ]
                                    },
                                    paymentMethod: {
                                        type: 'CREDIT_CARD',
                                        installments: Math.max( 1, parseInt( installments, 10 ) || 1 ),
                                        card: {
                                            number: digits,
                                            expMonth: String( parseInt( month, 10 ) ).padStart( 2, '0' ),
                                            expYear: expYear,
                                            holder: { name: holder || buyer }
                                        }
                                    },
                                    amount: { value: ( function () {
                                        var chosen = 0;
                                        ( plans || [] ).forEach( function ( plan ) {
                                            if ( String( plan.installments ) === String( installments ) ) {
                                                chosen = parseInt( plan.total, 10 ) || 0;
                                            }
                                        } );
                                        return chosen || parseInt( response.data.amount, 10 ) || 0;
                                    }() ), currency: 'BRL' },
                                    billingAddress: {
                                        street: billing.address_1 || '',
                                        number: 'S/N',
                                        city: billing.city || '',
                                        regionCode: String( billing.state || '' ).slice( 0, 2 ).toUpperCase(),
                                        country: 'BRA',
                                        postalCode: String( billing.postcode || '' ).replace( /\D/g, '' )
                                    }
                                },
                                dataOnly: false
                            } ).then( function ( result ) {
                                var authStatus = result && result.status;
                                var authId = '';
                                var posted = '';
                                if ( authStatus === 'AUTH_NOT_SUPPORTED' ) {
                                    posted = 'unsupported';
                                } else if ( authStatus === 'AUTH_FLOW_COMPLETED' && result.id ) {
                                    authId = result.id;
                                    posted = 'completed';
                                } else {
                                    throw new Error( authStatus === 'CHANGE_PAYMENT_METHOD'
                                        ? 'O banco não autenticou este cartão. Escolha outro cartão ou outra forma de pagamento.'
                                        : 'Não foi possível autenticar o cartão. Tente novamente.' );
                                }
                                resolve( {
                                    type: emitResponse.responseTypes.SUCCESS,
                                    meta: {
                                        paymentMethodData: {
                                            wooflow_pagbank_encrypted: card.encryptedCard,
                                            wooflow_pagbank_installments: String( installments ),
                                            wooflow_pagbank_holder: holder,
                                            wooflow_pagbank_tax_id: taxId,
                                            wooflow_pagbank_3ds_id: authId,
                                            wooflow_pagbank_3ds_status: posted,
                                            wooflow_pagbank_card_bin: digits.slice( 0, 6 )
                                        }
                                    }
                                } );
                            } );
                        } )
                        .catch( function ( reason ) {
                            var detail = reason && reason.detail && reason.detail.message;
                            error( detail || ( reason && reason.message ) || 'Não foi possível autenticar o cartão. Tente novamente.' );
                        } );
                } );
            } );
            return function () { unsubscribe(); };
        }, [ onPaymentSetup, emitResponse, number, holder, expiry, cvv, taxId, installments, plans, settings.publicKey ] );

        var max = Math.max( 1, Math.min( 12, parseInt( settings.maxInstallments, 10 ) || 12 ) );
        var free = parseInt( settings.interestFreeInstallments, 10 );
        if ( isNaN( free ) ) {
            free = max;
        }
        var min = Number( settings.minInstallment || 0 );
        if ( min > 0 && total > 0 ) {
            max = Math.max( 1, Math.min( max, Math.max( 1, Math.floor( ( total * 100 ) / ( min * 100 ) ) ) ) );
        }
        var options = [];
        if ( plans && plans.length ) {
            plans.forEach( function ( plan ) {
                options.push( createElement( 'option', { key: String( plan.installments ), value: String( plan.installments ) }, plan.label ) );
            } );
        } else {
            if ( free < max ) {
                max = Math.max( 1, free );
            }
            for ( var i = 1; i <= max; i++ ) {
                options.push( createElement( 'option', { key: String( i ), value: String( i ) },
                    i === 1 ? 'À vista (' + money( total ) + ')' : i + 'x de ' + money( total / i ) + ( free < ( parseInt( settings.maxInstallments, 10 ) || 12 ) ? ' sem juros' : '' )
                ) );
            }
        }

        var icons = settings.icons || {};
        var brands = [ 'mastercard', 'visa', 'elo', 'amex' ].map( function ( name ) {
            if ( ! icons[ name ] ) {
                return null;
            }
            return createElement( 'span', {
                key: name,
                className: 'wooflow-pagbank-brand-item' + ( brand === name ? ' is-active' : '' ),
                style: { backgroundImage: 'url(' + icons[ name ] + ')' },
                'aria-hidden': 'true'
            } );
        } );

        return createElement( 'div', { className: 'wooflow-pagbank-wrapper' },
            settings.sandbox ? createElement( 'p', { className: 'wooflow-pagbank-warning' }, 'Sandbox: este pagamento é de teste e não tem valor real. Use o cartão Mastercard 5555 6666 7777 8884, uma validade futura e o CVV 123.' ) : null,
            settings.description ? createElement( 'p', { className: 'wooflow-pagbank-card-description' }, decode( settings.description ) ) : null,
            createElement( 'div', { className: 'wooflow-pagbank-accepted-brands' }, brands ),
            DocumentField( 'wooflow_pagbank_blocks_tax', taxId, setTaxId ),
            createElement( 'p', { className: 'form-row form-row-wide' },
                createElement( 'label', { htmlFor: 'wooflow_pagbank_blocks_number' }, 'Número do cartão' ),
                createElement( 'span', { className: 'wooflow-pagbank-card-input-wrapper' },
                    createElement( 'input', {
                        id: 'wooflow_pagbank_blocks_number',
                        className: 'wooflow-pagbank-blocks-number',
                        type: 'tel',
                        inputMode: 'numeric',
                        autoComplete: 'cc-number',
                        maxLength: 19,
                        placeholder: '0000 0000 0000 0000',
                        value: number,
                        onChange: function ( event ) {
                            var digits = event.target.value.replace( /\D/g, '' ).slice( 0, 19 );
                            setNumber( digits.replace( /(.{4})/g, '$1 ' ).trim() );
                        }
                    } ),
                    createElement( 'span', {
                        className: 'wooflow-pagbank-card-brand-icon' + ( brand ? ' is-visible' : '' ),
                        style: brand && icons[ brand ] ? { backgroundImage: 'url(' + icons[ brand ] + ')' } : null
                    } )
                )
            ),
            createElement( 'p', { className: 'form-row form-row-wide' },
                createElement( 'label', { htmlFor: 'wooflow_pagbank_blocks_name' }, 'Nome impresso no cartão' ),
                createElement( 'input', {
                    id: 'wooflow_pagbank_blocks_name',
                    type: 'text',
                    autoComplete: 'cc-name',
                    value: holder,
                    onChange: function ( event ) { setHolder( event.target.value ); }
                } )
            ),
            createElement( 'div', { className: 'wooflow-pagbank-inline-fields' },
                createElement( 'p', { className: 'wooflow-pagbank-field' },
                    createElement( 'label', { htmlFor: 'wooflow_pagbank_blocks_expiry' }, 'Vencimento' ),
                    createElement( 'input', {
                        id: 'wooflow_pagbank_blocks_expiry',
                        type: 'tel',
                        inputMode: 'numeric',
                        autoComplete: 'cc-exp',
                        maxLength: 5,
                        placeholder: 'MM/AA',
                        value: expiry,
                        onChange: function ( event ) {
                            var digits = event.target.value.replace( /\D/g, '' ).slice( 0, 4 );
                            setExpiry( digits.length >= 2 ? digits.slice( 0, 2 ) + '/' + digits.slice( 2 ) : digits );
                        }
                    } )
                ),
                createElement( 'p', { className: 'wooflow-pagbank-field' },
                    createElement( 'label', { htmlFor: 'wooflow_pagbank_blocks_cvv' }, 'CVV' ),
                    createElement( 'input', {
                        id: 'wooflow_pagbank_blocks_cvv',
                        type: 'tel',
                        inputMode: 'numeric',
                        autoComplete: 'cc-csc',
                        maxLength: 4,
                        placeholder: '123',
                        value: cvv,
                        onChange: function ( event ) { setCvv( event.target.value.replace( /\D/g, '' ).slice( 0, 4 ) ); }
                    } )
                )
            ),
            createElement( 'p', { className: 'form-row form-row-wide' },
                createElement( 'label', { htmlFor: 'wooflow_pagbank_blocks_installments' }, 'Parcelas' ),
                createElement( 'select', {
                    id: 'wooflow_pagbank_blocks_installments',
                    value: installments,
                    onChange: function ( event ) { setInstallments( event.target.value ); }
                }, options )
            )
        );
    }

    function SimpleContent( settingName, fieldName, info ) {
        return function ( props ) {
            var settings = settingsApi.getSetting( settingName, {} );
            var onPaymentSetup = props.eventRegistration && props.eventRegistration.onPaymentSetup;
            var emitResponse = props.emitResponse;
            var taxState = useState( '' );
            var taxId = taxState[ 0 ];
            var setTaxId = taxState[ 1 ];

            useEffect( function () {
                if ( ! onPaymentSetup || ! emitResponse ) {
                    return undefined;
                }
                var unsubscribe = onPaymentSetup( function () {
                    var payload = { type: emitResponse.responseTypes.SUCCESS, meta: { paymentMethodData: {} } };
                    payload.meta.paymentMethodData[ fieldName ] = taxId;
                    return payload;
                } );
                return function () { unsubscribe(); };
            }, [ onPaymentSetup, emitResponse, taxId ] );

            return createElement( 'div', { className: 'wooflow-pagbank-wrapper' },
                settings.sandbox ? createElement( 'p', { className: 'wooflow-pagbank-warning' }, 'Sandbox: este pagamento é de teste e não tem valor real.' ) : null,
                settings.description ? createElement( 'p', { className: 'wooflow-pagbank-card-description' }, decode( settings.description ) ) : null,
                createElement( 'p', { className: 'wooflow-pagbank-info' }, info ),
                DocumentField( fieldName + '_blocks', taxId, setTaxId )
            );
        };
    }

    registry.registerPaymentMethod( {
        name: 'dw_pagbank',
        label: createElement( Label( settingsApi.getSetting( 'dw_pagbank_data', {} ) ) ),
        content: createElement( CardContent ),
        edit: createElement( 'p', null, 'Cartão PagBank' ),
        canMakePayment: function () { return true; },
        ariaLabel: 'PagBank cartão',
        supports: { features: [ 'products' ] }
    } );

    registry.registerPaymentMethod( {
        name: 'dw_pagbank_pix',
        label: createElement( Label( settingsApi.getSetting( 'dw_pagbank_pix_data', {} ) ) ),
        content: createElement( SimpleContent( 'dw_pagbank_pix_data', 'wooflow_pagbank_pix_tax_id', 'Ao finalizar o pedido, geramos um QR Code e o código copia e cola.' ) ),
        edit: createElement( 'p', null, 'PIX PagBank' ),
        canMakePayment: function () { return true; },
        ariaLabel: 'PagBank PIX',
        supports: { features: [ 'products' ] }
    } );

    registry.registerPaymentMethod( {
        name: 'dw_pagbank_boleto',
        label: createElement( Label( settingsApi.getSetting( 'dw_pagbank_boleto_data', {} ) ) ),
        content: createElement( SimpleContent( 'dw_pagbank_boleto_data', 'wooflow_pagbank_boleto_tax_id', 'O boleto e a linha digitável aparecem na página do pedido.' ) ),
        edit: createElement( 'p', null, 'Boleto PagBank' ),
        canMakePayment: function () { return true; },
        ariaLabel: 'PagBank boleto',
        supports: { features: [ 'products' ] }
    } );
}() );
