/* global wooflow_pagbank_admin */
( function ( $ ) {
    'use strict';

    $( '#wooflow-pagbank-diagnostics' ).on( 'click', function () {
        var $out = $( '#wooflow-pagbank-diagnostics-result' );
        $out.text( 'Consultando o PagBank...' );
        $.post( wooflow_pagbank_admin.ajax_url, {
            action: 'wooflow_pagbank_run_diagnostics',
            nonce: wooflow_pagbank_admin.nonce
        } ).done( function ( response ) {
            render( $out, response && response.success ? response.data.lines : [ 'Falha ao executar o diagnóstico.' ] );
        } ).fail( function () {
            $out.text( 'Falha ao executar o diagnóstico.' );
        } );
    } );

    $( '#wooflow-pagbank-public-key' ).on( 'click', function () {
        var $out = $( '#wooflow-pagbank-diagnostics-result' );
        $out.text( 'Gerando a chave pública...' );
        $.post( wooflow_pagbank_admin.ajax_url, {
            action: 'wooflow_pagbank_create_public_key',
            nonce: wooflow_pagbank_admin.nonce
        } ).done( function ( response ) {
            if ( ! response || ! response.success ) {
                $out.text( ( response && response.data && response.data.message ) || 'Não foi possível gerar a chave.' );
                return;
            }
            $( '#woocommerce_dw_pagbank_public_key' ).val( response.data.public_key );
            $out.text( response.data.message );
        } ).fail( function ( xhr ) {
            var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
            $out.text( message || 'Não foi possível gerar a chave.' );
        } );
    } );

    function render( $out, lines ) {
        var $list = $( '<ul/>' );
        ( lines || [] ).forEach( function ( line ) {
            $list.append( $( '<li/>' ).text( line ) );
        } );
        $out.empty().append( $list );
    }
}( jQuery ) );
