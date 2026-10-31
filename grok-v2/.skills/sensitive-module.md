# Skill: sensitive-module

Adicionar ou alterar um módulo sensível (sexualidade, substâncias, pensamentos/morte, religiosidade, ou novo).

## Passos
1. **Bit de categoria**: reserve um bit novo no `scope_mask` (doc `docs/SECURITY-MULTITENANT.md`). Padrão **desligado**.
2. **Dados**: tabela própria `private_<modulo>` com `tenant_id`, `patient_id` (skill `new-migration-mysql57`), texto livre `encrypted`.
3. **Ativação**: o paciente liga o módulo em Conta → Módulos; desligado = endpoints respondem 404 e nada aparece em relatórios.
4. **Step-up**: endpoints exigem desbloqueio recente (PIN do módulo/passkey/TOTP, janela curta). Sem desbloqueio → 423 com instrução; registre em `audit_logs`.
5. **Compartilhamento**: só com opt-in explícito por concessão (bit ligado), aviso claro e confirmação; nunca herdado de "compartilhar tudo".
6. **Saídas**: nunca em push, e-mail, prévias de notificação, exportações automáticas, IA ou relatórios sem o bit/opt-in.
7. **Morte/suicídio**: proibido notificar qualquer pessoa automaticamente; sempre exibir **CVV 188** e **SAMU 192**; linguagem acolhedora, sem julgamento.
8. **Testes**: isolamento (skill `tenant-isolation-test`), step-up (sem desbloqueio → 423), concessão sem o bit → campo ausente, nenhuma notificação disparada (`Notification::fake()` + `assertNothingSent()`).
9. **OpenAPI**: marque com `x-sensitive: true` e `x-step-up: true`.
10. Revise textos com o dono do produto (e, idealmente, um profissional de saúde mental).
