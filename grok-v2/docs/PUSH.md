# Lembretes por Web Push

## Como funciona
- A pessoa marca dias/períodos e horários em **Alertas e lembretes** (grade salva em `alert_settings.reminders`).
- Em **Notificações neste aparelho** ela ativa o push: o navegador pede permissão, o app guarda a inscrição em
  `push_subscriptions` (endpoint + chaves públicas da inscrição, isolada por tenant; nenhum dado de saúde).
- `php artisan bipolar:send-reminders` (agendado a cada minuto em `routes/console.php`) percorre os tenants,
  calcula no fuso de cada pessoa os horários vencidos nos últimos 10 minutos e envia **uma** notificação por
  aparelho, dia e período (`last_slot`). Inscrições expiradas (404/410) são apagadas.
- Texto **fixo e neutro**: título "Bipolar", corpo "Hora do seu registro", clique abre `/registrar`
  (`App\Domain\Push\PushSender::PAYLOAD` e `public/push-sw.js`, importado pelo service worker do Workbox).
  Nunca coloque humor, energia, nome de remédio, alerta ou qualquer dado de saúde em notificação.

## Configuração (cPanel, sem SSH)
1. `php artisan bipolar:vapid` (no seu computador) mostra `VAPID_PUBLIC_KEY` e `VAPID_PRIVATE_KEY`.
2. Cole no `.env` do servidor com `VAPID_SUBJECT=mailto:contato@seu-dominio`. Nunca versione a chave privada
   (`scripts/check-public-tree.sh` falha se ela aparecer no repositório).
3. Cron, uma vez por minuto:

```
cd /home/seuusuario/bipolar && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Sem as chaves, o comando não envia nada e a tela avisa que o envio não está configurado.

## Limites conhecidos
- iPhone/iPad: só recebe com o app instalado na Tela de Início (iOS 16.4+).
- Lembrete por e-mail ainda não existe.
