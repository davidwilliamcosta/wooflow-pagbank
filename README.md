# WooFlow PagBank for WooCommerce

Cartão de crédito, PIX e boleto no WooCommerce pela [API de pedidos do PagBank](https://developer.pagbank.com.br/). O checkout usa o mesmo desenho do WooFlow Stone: bandeiras, campos lado a lado, QR Code, linha digitável e a linha “Pagamento 100% seguro”.

O número do cartão é criptografado no navegador com `PagSeguro.encryptCard()` e a chave pública da conta. O servidor recebe só o campo `encrypted`.

## Requisitos

- WordPress 6.4+
- WooCommerce 8+
- PHP 8.1+ com OpenSSL
- Conta PagBank com token de integração e, para PIX, uma chave PIX ativa

## Token de produção

O token da loja é o de produção, gerado na conta PagBank do lojista. O token do [Portal do Desenvolvedor](https://portaldev.pagbank.com.br/tokens) serve só para teste e não autentica em produção. A referência do PagBank está em [Token de autenticação](https://developer.pagbank.com.br/docs/token-de-autenticacao).

1. Acesse a conta no computador, em [minhaconta.pagbank.com.br](https://minhaconta.pagbank.com.br/).
2. No menu lateral, abra **Vendas → Plataformas e Checkout → Integrações**.
3. Gere o token de produção. Se ele já existir, use **Enviar por e-mail**. O token chega no e-mail cadastrado na conta.
4. No plugin, deixe o ambiente em **Produção** e cole esse token.

Gerar um token novo invalida o anterior. Quem ainda usa o token antigo deixa de autenticar. Depois de colar o token, gere a chave pública do cartão na mesma tela do plugin. O PIX também precisa de uma chave PIX cadastrada na conta.

## Configuração

1. Ative o plugin.
2. Em **WooCommerce → Configurações → Pagamentos**, abra **PagBank — Cartão de crédito**.
3. Escolha sandbox ou produção. O ambiente vale também para PIX e boleto.
4. Cole o token de produção. O ambiente do plugin fica em **Produção**.
5. Gere a chave pública no botão da própria tela. Ela precisa ser criada com esse token.
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
