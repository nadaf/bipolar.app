# Migração de dados 0.5.3-beta → 2.0

Status: **rascunho** (o importador ainda não foi escrito). Nada é apagado do banco antigo.

## Estratégia
1. Banco 2.0 **novo e separado**; o banco 0.5 fica só leitura durante a migração.
2. Importador como comando `php artisan bipolar:import-legacy` (rodável também por página protegida
   igual ao instalador, já que não há SSH), em lotes por usuário, **idempotente** (usa `legacy_id`).
3. Cada usuário 0.5 vira: `users` + `tenants(kind=personal)` + `memberships(role=patient)`.
   Profissionais (`is_professional=1`) viram usuários com vínculo `professional` em um tenant de clínica
   (a definir com o dono: uma clínica por profissional ou agrupamento manual).
4. Todas as linhas importadas recebem o `tenant_id` do paciente dono.
5. Verificação: contagens por usuário/tabela antes e depois, amostragem, e teste de isolamento rodado
   no banco migrado (staging).
6. Janela: modo manutenção na 0.5 → importação → conferência → troca de DNS/pasta.

## Mapeamento de tabelas

| 0.5 | 2.0 | Observações |
|---|---|---|
| users | users + tenants + memberships | senha (`password_hash` bcrypt) reaproveitada; `session_epoch` descartado; segredo TOTP recriptografado com a nova APP_KEY |
| oauth_identities | social_identities (futuro) | |
| entries | mood_entries | `period` igual (manha/tarde/noite); `mood` -3..3 igual; `note` recriptografada; `tags` → tabela futura; `version` e `client_uuid` preservados |
| entries.energy (0..3) | mood_entries.energy (-3..3) + `energy_legacy` | ver abaixo — **decisão pendente** |
| media | media (futuro) | arquivos copiados para `storage/app/private/{tenant}/{patient}` |
| daily_markers | day_markers | campos sensíveis (`th_death`, `religiosity`, substâncias) vão para módulos privados |
| period_markers | period_markers | idem |
| sexuality_log | private_sexuality | módulo sensível |
| substance_log | private_substances | módulo sensível |
| recipients (relatórios por e-mail) | care_grants + report_recipients | e-mails sem conta viram convites pendentes; nada é enviado automaticamente na migração |
| care_shares / care_codes / care_access_log | care_grants / invites / audit_logs | `scope_mask` compatível bit a bit |
| reminders | reminders (grade semanal) | `days` "0,1,…" → bitmask de dias |
| push_subscriptions | push_subscriptions | endpoints mantidos; chaves VAPID precisam ser as mesmas |
| alerts / alert_rules | alerts / alert_rules | regras revisadas para a nova escala de energia |
| webauthn_credentials | webauthn_credentials | RP ID igual (mesmo domínio) |
| mfa_recovery_codes | mfa_recovery_codes | hashes mantidos |
| auth_tokens, rate_limits, outbox, entry_client_ids, ai_usage | não migrar | temporários; `ai_*` fora do escopo da 2.0 inicial |

## Energia: conversão 0..3 → -3..+3
Na 0.5 a energia ia de 0 (baixa — os alertas tratam 0 como sinal depressivo) a 3 (intensa — sinal de mania).
Proposta: `0 → -2 (Baixa)`, `1 → 0 (Normal)`, `2 → +1 (Um pouco alta)`, `3 → +2 (Alta)`.
O valor original fica em `mood_entries.energy_legacy` para auditoria e reversão. A interface pode marcar
registros convertidos. **Precisa de confirmação do dono do produto.**

## Riscos
- Re-criptografia: a 0.5 usa chave própria; o importador precisa dela **só em memória** (variável de ambiente
  temporária), nunca copiando o `config.php`.
- Fuso: datas da 0.5 já são locais do usuário (`entry_date`); manter.
- Duplicidade de e-mail entre conta e destinatário: tratar como convite, não como conta.
