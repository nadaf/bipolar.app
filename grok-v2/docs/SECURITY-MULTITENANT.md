# Segurança e isolamento multi-tenant

## Ameaças principais
1. Paciente A lê/edita dados do paciente B (IDOR), no mesmo tenant ou em outro.
2. Profissional/familiar vê mais do que o paciente concedeu, ou depois de revogado/expirado.
3. Código novo esquece o filtro de tenant (consulta "crua").
4. Vazamento por logs, cache, service worker, analytics, e-mails ou exportações.
5. Exposição de dados sensíveis (sexualidade, substâncias, pensamentos de morte, religiosidade).

## Camadas de defesa

| Camada | Mecanismo | Onde |
|---|---|---|
| Identidade | Sanctum (token por aparelho) / sessão web; MFA TOTP (próxima etapa) | `AuthController`, `SessionController` |
| Host | `ResolveHost`: host principal ou `<slug>.bipolar.app.br`; slug inexistente/reservado/inválido ou host estranho → 404. O host **só restringe**, nunca concede | `app/Http/Middleware/ResolveHost.php` |
| Contexto | `ResolveTenant`: token (API) ou sessão (web) guardam o tenant de emissão; host, token/sessão e `X-Tenant-Id` precisam concordar e o usuário precisa ter vínculo; senão 404 | `app/Http/Middleware/ResolveTenant.php` |
| Cookie | sessão **por host** (`session.domain = null`, `__Host-` em HTTPS): nada de cookie em `.bipolar.app.br` | `config/session.php` |
| Escopo global | `BelongsToTenant` + `TenantScope`: sem contexto → `MissingTenantContext` | `app/Tenancy` |
| Escrita | `creating` força `tenant_id` do contexto; mudar `tenant_id` → `TenantMismatch` | `BelongsToTenant` |
| Dono | `ownedBy($user)` em toda consulta de dados do paciente | models/controllers |
| Banco | FKs compostas `(tenant_id, patient_id)` → `memberships`; índices começam por `tenant_id` | migrations |
| Resposta | 404 para tudo fora do alcance (nunca 403) | controllers, handler |
| Arquitetura | teste: todo model com `tenant_id` usa o trait; controllers sem `DB::` | `tests/Arch` |
| Testes | suíte `tests/Feature/Isolation` (IDOR, cross-tenant, mesmo tenant, profissional sem concessão) | CI |

## Rede de apoio (care grants)
- Emitida **pelo paciente**: `care_grants(tenant_id do paciente, patient_id, grantee_user_id, scope_mask, expires_at, revoked_at)`.
- Somente leitura. Válida se `accepted_at` preenchido, `revoked_at` nulo e não expirada (`CareGrant::scopeActive`).
- Convite por token de uso único (hash SHA-256 no banco, validade 14 dias).
- Toda visualização/impressão/alteração gera `audit_logs` visível ao paciente.
- Leitura por profissional: `/api/v1/care/patients[/{id}/entries]` (papel `professional`).
  `App\Domain\Care\GrantDirectory` é o **único** código que cruza tenants: acha concessões ativas onde
  `grantee_user_id` = o próprio usuário, **troca** o contexto para o tenant do paciente só depois disso,
  filtra por `scope_mask` (anotação livre só com bit 8) e grava `care.read_mood` em `audit_logs` do paciente.
- Admin da clínica **não** lê dados clínicos por ser admin: só gerencia vínculos de profissionais.

### Bitmask de categorias (compatível com a 0.5)
| bit | categoria | padrão | sensível |
|---|---|---|---|
| 1 | humor, energia e marcadores gerais | ligado | não |
| 2 | sono | ligado | não |
| 4 | estresse e alimentação | ligado | não |
| 8 | pensamentos | desligado | sim |
| 16 | substâncias, nicotina, cafeína, álcool | desligado | sim |
| 32 | sexualidade | desligado | sim |
| 64 | pensamentos de morte/suicídio | desligado | sim |
| 128 | religiosidade | desligado | sim |

## Módulos sensíveis
- Desligados por padrão (no registro e no compartilhamento).
- Ver/editar exige **step-up**: PIN do módulo ou passkey/TOTP recente (janela curta, ex.: 10 min), registrado em auditoria.
- Nunca entram em notificações push/e-mail, prévias, resumos automáticos ou IA sem opt-in próprio.
- **Pensamentos de morte/suicídio**: nunca notificam ninguém automaticamente (nem rede de apoio); a tela
  sempre mostra **CVV 188** (24h) e **SAMU 192** e orienta procurar ajuda.

## LGPD
- Base legal: consentimento específico e destacado (art. 11, I) para dados de saúde; registro do aceite.
- Direitos do titular: exportação completa (JSON/CSV/PDF), correção, exclusão da conta e de módulos.
- Minimização: só campos necessários; anotações criptografadas; IPs só como hash em auditoria.
- Sem analytics/rastreadores em telas privadas e API. Sem CDN de terceiros.
- Retenção: tokens expirados podados diariamente; logs de aplicação sem conteúdo clínico.

## Cabeçalhos
CSP estrita sem `unsafe-inline`/`unsafe-eval`, `frame-ancestors 'none'`, `Referrer-Policy: no-referrer`,
`X-Content-Type-Options: nosniff`, `Permissions-Policy` restritiva, `Cache-Control: no-store` quando autenticado.

## Instalador web
Desligado sem `INSTALLER_TOKEN` ≥ 32 caracteres (404). Comparação em tempo constante, atraso aleatório em erro,
fora do grupo de sessão. Depois de instalar, o usuário apaga o token.

## Subdomínios e contas

| Tipo de tenant | `kind` | Quem administra | Subdomínio (opcional) |
|---|---|---|---|
| Clínica | `clinic` | papel `admin` (convida/remove profissionais em `/api/v1/clinic/professionals`) | `<clinica>.bipolar.app.br` |
| Profissional solo | `solo` | o próprio profissional (único vínculo) | `<nome>.bipolar.app.br` |
| Pessoal (paciente) | `personal` | o próprio paciente | `<usuario>.bipolar.app.br` |

- Slug: 3–30 caracteres, `a-z 0-9 -`, sem hífen no início/fim nem `--`; lista reservada em
  `App\Tenancy\TenantSlug::RESERVED` (www, api, blog, clinica, demo, admin, mail, cpanel, cvv, samu…).
  O pacote privado pode somar nomes em `bipolar.reserved_slugs_extra`.
- Resolução: `ResolveHost` (grupos web e api). Host principal = `BIPOLAR_BASE_DOMAIN`, `www.` dele, host
  do `APP_URL` e `BIPOLAR_MAIN_HOSTS`. Qualquer outro host → 404.
- Amarração: login por API grava `personal_access_tokens.tenant_id`; login web grava `tenant_id` na
  sessão. No subdomínio, login sem vínculo com aquele tenant falha com a mesma mensagem de senha errada.
  Token da clínica A usado no host da clínica B → 404, mesmo que a pessoa seja membro das duas.
- Cookies: cada host tem a própria sessão. **Nunca** defina `SESSION_DOMAIN` (o config ignora).
- Cadastro aberto (`/auth/register`) só no host principal.
- Testes: `tests/Feature/Isolation/HostTenancyTest.php`, `tests/Feature/ClinicAndCareTest.php`.

## Alertas personalizados
- `alert_settings` (tenant-owned, 1 por paciente): limiares baixo (−3..−1) e alto (1..3) para humor e
  energia; padrão −2/+2. `SignalClassifier` usa esses limiares para "depressivo", "maníaco" e "misto".
- Alertas **nunca** avisam terceiros automaticamente; pensamentos de morte seguem a regra acima.
