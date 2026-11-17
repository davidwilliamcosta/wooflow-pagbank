# WooFlow PagBank for WooCommerce

Cartão de crédito, PIX e boleto no WooCommerce pela [API de pedidos do PagBank](https://developer.pagbank.com.br/). O checkout usa o mesmo desenho do WooFlow Stone: bandeiras, campos lado a lado, QR Code, linha digitável e a linha “Pagamento 100% seguro”.

O número do cartão é criptografado no navegador com `PagSeguro.encryptCard()` e a chave pública da conta. O servidor recebe só o campo `encrypted`.

## Requisitos

- WordPress 6.4+
- WooCommerce 8+
- PHP 8.1+ com OpenSSL
- Conta PagBank com token de integração e, para PIX, uma chave PIX ativa

## Configuração

1. Ative o plugin.
2. Em **WooCommerce → Configurações → Pagamentos**, abra **PagBank — Cartão de crédito**.
3. Escolha sandbox ou produção. O ambiente vale também para PIX e boleto.
4. Cole o token. Ele fica só no servidor.
5. Gere a chave pública no botão da própria tela, ou cole uma chave já criada.
6. Salve. O plugin gera o token do webhook e mostra a URL.
7. Ative os métodos que a loja vai oferecer.

Com o site em HTTPS, cada pedido envia `notification_urls` apontando para `/?wc-api=dw_pagbank_webhook&token=...`. O plugin só aceita a notificação se o token bater e a assinatura `x-payload-signature` for válida com a chave pública de webhook do PagBank.

## O que o comprador vê

- Cartão: CPF/CNPJ, número, nome, validade, CVV e parcelas. O parcelamento divide o total do pedido; juros, se existirem, são os da conta PagBank.
- PIX: QR Code, copia e cola, contagem e atualização automática da página quando o pagamento cai.
- Boleto: PDF e linha digitável. Valor mínimo de R$ 0,20.

Cartão aprovado na hora conclui o pedido. PIX e boleto ficam aguardando até o webhook, a consulta da página ou o botão **Consultar PagBank** no pedido.

## Estorno

O estorno do WooCommerce chama `POST /charges/{id}/cancel` com o valor em centavos. O detalhe de uma falha fica no log `wooflow-pagbank`, em **WooCommerce → Status → Logs**.
