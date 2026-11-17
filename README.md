# WooFlow PagBank for WooCommerce

Cartão de crédito, PIX e boleto no WooCommerce pela [API de pedidos do PagBank](https://developer.pagbank.com.br/). O checkout usa o mesmo desenho do WooFlow Stone: bandeiras, campos lado a lado, QR Code, linha digitável e a linha “Pagamento 100% seguro”.

O número do cartão é criptografado no navegador com `PagSeguro.encryptCard()` e a chave pública da conta. O servidor recebe só o campo `encrypted`.

## Requisitos

- WordPress 6.4+
- WooCommerce 8+
- PHP 8.1+ com OpenSSL
- Conta PagBank com token de integração e, para PIX, uma chave PIX ativa

## Gerar o token

Sandbox e produção usam tokens diferentes. No plugin, o ambiente precisa ser o mesmo do token colado. O token fica só no servidor. A documentação do PagBank está em [Token de autenticação](https://developer.pagbank.com.br/docs/token-de-autenticacao).

### Sandbox

1. Acesse o [Portal do Desenvolvedor](https://portaldev.pagbank.com.br/tokens).
2. Abra a aba **Tokens**.
3. Copie o token e cole no plugin, com o ambiente em sandbox.

### Produção

1. Acesse a conta PagBank no computador.
2. No menu lateral, abra **Vendas → Plataformas e Checkout → Integrações**. Em algumas contas o caminho aparece como **Vendas → Integrações**.
3. Clique em **Gerar token**. Se o token já existir, use **Enviar por e-mail**. Ele chega no e-mail cadastrado na conta.
4. Cole o token no plugin, com o ambiente em produção.

Gerar um token novo invalida o anterior. Quem ainda usa o token antigo deixa de autenticar. Trocar o token ou o ambiente exige gerar de novo a chave pública do cartão na tela do plugin. O PIX também precisa de uma chave PIX cadastrada na conta PagBank.

## Configuração

1. Ative o plugin.
2. Em **WooCommerce → Configurações → Pagamentos**, abra **PagBank — Cartão de crédito**.
3. Escolha sandbox ou produção. O ambiente vale também para PIX e boleto.
4. Cole o token gerado no passo acima.
5. Gere a chave pública no botão da própria tela, ou cole uma chave criada nesse mesmo ambiente.
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
