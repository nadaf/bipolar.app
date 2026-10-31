# Atualização 0.5.0-beta → 0.5.1-beta (V5.1)

Tempo estimado: 15 minutos. **Não mexa no `config.php`.** O pacote não traz `config.php` e não precisa de nenhuma configuração nova.

## O que muda para você
- **Mais segurança**: relatório e tela do profissional respeitam o bloqueio do app. Trocar o e-mail agora pede sua senha/PIN e um clique no link enviado ao e-mail novo (as outras sessões são encerradas). Exportar os dados pede confirmação de identidade.
- **Sem internet**: o humor fica guardado no aparelho e é enviado depois, sem duplicar. Seções íntimas (sexualidade, substâncias, pensamentos de morte), áudio e vídeo **não** ficam guardados no aparelho: o aviso "não enviado" aparece e o formulário continua na tela.
- **Ao sair**, o app apaga do aparelho o cache e a fila de envios.
- **Lembretes** com novas tentativas automáticas se o envio falhar.
- **Período travado**: ao registrar, a data e o período (Manhã/Tarde/Noite) não mudam sozinhos. No topo aparece "Registrando: Manhã de hoje (22/10)". Se o relógio virar de período, o app pergunta se você quer continuar ou trocar.
- **Botões**: cada bloco tem "Salvar e continuar". No fim tem "Salvar tudo", que mostra o que foi salvo e o que falhou.
- **Tema claro/escuro** voltou a funcionar e fica salvo. Sem escolha, segue o tema do celular.
- **Lembretes em grade**: dias da semana (Dom a Sáb) nas colunas e horários nas linhas. Toque no dia para ligar ou desligar.
- **Nova categoria "Religiosidade"** (por período, opcional): Nenhuma, Normal, Sensível ao tema, Muito sensível ao tema. Aparece no Histórico, no relatório junto do humor e na exportação. Para o profissional, só se você marcar essa categoria no compartilhamento (vem desmarcada). Não vai para a IA.
- Pensamentos deixam de vir marcados ao compartilhar com profissional novo. Compartilhamentos que já existem **não mudam**.

## Passo 1 — Backup
1. cPanel → phpMyAdmin → banco do Bipolar → **Exportar** → **Executar**. Guarde o arquivo.
2. Gerenciador de Arquivos → selecione `app`, `cron` e a pasta do site → **Comprimir**. Baixe o zip.

## Passo 2 — Banco de dados (antes dos arquivos)
1. phpMyAdmin → banco do Bipolar → aba **SQL**.
2. Abra `migrations/2026-11-17-v5-1-seguranca.sql` (está dentro do zip), copie tudo, cole e clique **Executar**.
3. Se aparecer **#1060 (Duplicate column name)** ou **#1061 (Duplicate key name)**, essa linha já foi aplicada: apague do campo as linhas até ela (inclusive) e execute o restante.
4. No fim aparecem as tabelas `outbox` e `entry_client_ids`.
5. Nada é apagado e nenhum dado de saúde é alterado. Pode rodar com o site no ar.

A migração antiga `2026-10-19-v4-1-destinatarios-unicos.sql` também veio revisada (só para instalações novas). **Não precisa rodar de novo.**

## Passo 3 — Arquivos
1. Gerenciador de Arquivos → pasta **acima** do site (onde ficam `app/`, `cron/` e `VERSION`).
2. **Carregar** → `bipolar-update-v5-1.zip`.
3. Botão direito no zip → **Extrair** → mesma pasta → confirme **substituir**.
4. Se o seu site fica em `bipolar.app.br/` (e não em `public_html/`), mova o **conteúdo** da pasta `public_html` extraída para dentro de `bipolar.app.br/`, substituindo.
5. Apague o zip.

Arquivos novos: `app/lib/outbox.php`, `app/lib/outbox_jobs.php`, `app/lib/storage_cleanup.php`, `public_html/conta/confirmar-email.php` e a migração acima.

## Passo 4 — Tarefas agendadas (cron)
Não muda nada. Os crons continuam com `/usr/local/bin/php`, por exemplo:
`/usr/local/bin/php /home/SEU_USUARIO/cron/send_reminders.php`

## Configuração (opcional, pode ignorar)
- `trusted_proxies`: **só** se o site estiver atrás de um proxy/CDN que você controla. Lista de IPs, por exemplo `'trusted_proxies' => ['10.0.0.5'],`. Sem essa chave (padrão), o app não confia no cabeçalho de proxy — é o seguro na hospedagem comum.
- Nenhuma chave existente muda. A `ai.secret_key` continua a mesma (não troque).

## Passo 5 — Conferir
- `VERSION` contém `0.5.1-beta`.
- `https://bipolar.app.br/sw.js` mostra `const VERSION = 'bp-v16';`.
- No celular, feche e abra o app (se precisar, abra e feche duas vezes para o app novo assumir).
- Em **Registrar** aparece "Registrando: …" no topo e "Salvar tudo" no fim.
- O botão ◐ do topo troca o tema e a escolha continua depois de recarregar.
- Em **Lembretes** aparece a grade da semana.
