# Atualização 0.5.3-beta (V5.2.1) — aviso de estatísticas que "não sumia"

## O que estava errado
O aviso "Estatísticas de uso" usava a classe CSS `.consent`. Essa classe **já existia** no checkbox
"Li a Política de Privacidade… (LGPD)" da página **Criar conta** (`/conta/cadastro.php`). O CSS da 0.5.2
deixou esse checkbox fixo no rodapé da tela, com a mesma borda do aviso. Ao clicar em **Aceitar**, o aviso
de verdade sumia, mas o checkbox continuava flutuando ali — parecendo que o aviso não tinha fechado.

Nas outras páginas o aviso já fechava e a escolha era lembrada (conferido no site no ar, celular e computador).

## O que mudou
- Aviso com estilo próprio (`#consent-banner` / `.ga-consent`); o checkbox do cadastro voltou ao formulário.
- Fecha por dois caminhos (`hidden` + classe `is-hidden` com `!important`), mesmo se o Google estiver bloqueado.
- Se o navegador bloquear o armazenamento local, a escolha fica no cookie `bp_consent` e o aviso não volta.
- No celular, dentro do app, o aviso fica acima da barra de abas. Em telas largas, no canto inferior direito.
- Service worker `bp-v18`, `app.css?v=16`, `analytics.js?v=2`. **Sem migração de banco.**

## Como instalar
1. Faça backup dos arquivos atuais.
2. Envie os arquivos deste pacote **por cima**, mantendo as pastas (`app/…` e `public_html/…`).
   **Não** mexa no `app/config.php`.
3. Envie **todos** os arquivos juntos, sem abrir o site no meio do envio.

## Como conferir
1. Abra https://bipolar.app.br numa **janela anônima** (celular e computador).
2. O aviso aparece. Clique em **Aceitar**: ele some. Recarregue: não volta.
3. Abra **Criar conta**: o checkbox LGPD fica dentro do formulário, não flutuando embaixo.
4. No celular onde o problema apareceu: Configurações do Chrome › Configurações do site › bipolar.app.br ›
   **Limpar e redefinir** (ou feche e abra o app instalado duas vezes), para pegar o service worker `bp-v18`.
5. Google Analytics › Relatórios › Tempo real: a visita aparece só depois do Aceitar.
