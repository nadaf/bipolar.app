# Arquitetura — Bipolar 2.x

## Visão geral

API-first: toda regra mora em `app/Domain`, exposta por `/api/v1` (JSON, Sanctum) e reutilizada pelas
telas Blade/HTMX (sessão). Apps nativos futuros usam a mesma API e o contrato `openapi/v1.yaml`.

```mermaid
flowchart LR
  subgraph Cliente
    PWA[PWA Blade + HTMX + Alpine CSP<br/>Tailwind + Workbox]
    APP[App nativo futuro]
  end
  subgraph cPanel["HostGator cPanel (Apache, PHP 8.3)"]
    PH[public_html/index.php]
    subgraph Laravel["~/bipolar (fora da raiz web)"]
      MW[Middlewares:<br/>SecurityHeaders, auth, ResolveTenant, EnsurePatient]
      WEB[Web controllers]
      API[API v1 controllers]
      DOM[Domain services<br/>MoodEntryService, SignalClassifier]
      M[Models + BelongsToTenant]
    end
    CRON[cron: schedule:run<br/>queue:work --stop-when-empty]
  end
  DB[(MySQL 5.7<br/>banco único)]
  PWA -- sessão/CSRF --> PH
  APP -- Bearer token --> PH
  PH --> MW --> WEB & API --> DOM --> M --> DB
  CRON --> DOM
```

## Layout público x privado

```mermaid
flowchart TB
  root[bipolar.app/]
  root --> pub[public-app/ → github nadaf/bipolar.app PÚBLICO]
  root --> priv[private/ → github nadaf/bipolar-saas PRIVADO]
  pub --> l1[Laravel app, AGENTS.md, docs, openapi, .skills, CI público]
  priv --> l2[pacote composer nadaf/bipolar-saas<br/>provisionamento, onboarding de clínicas, analytics do site]
  priv -. composer path repo + auto-discovery .-> pub
```

- O público não depende do privado (edição *community*). `private/scripts/build-saas.sh` copia o público,
  instala o pacote e gera o zip SaaS.
- CI público: `.github/workflows/ci-release.yml`. CI privado: `private/.github/workflows/private-ci.yml`
  (clona o público e roda tudo junto).
- `scripts/check-public-tree.sh` bloqueia vazamento de código privado/segredos no público.

## Modelo de dados (núcleo)

```mermaid
erDiagram
  tenants ||--o{ memberships : tem
  users ||--o{ memberships : participa
  memberships ||--o{ mood_entries : "(tenant_id, patient_id)"
  memberships ||--o{ care_grants : "paciente concede"
  users ||--o{ care_grants : "recebe (grantee)"
  tenants ||--o{ audit_logs : registra
  memberships ||--o| alert_settings : "limiares do paciente"
  tenants { bigint id string kind "personal|clinic|solo" string slug "subdominio opcional" }
  users { bigint id string email "identidade global" }
  memberships { bigint tenant_id bigint user_id string role "patient|professional|admin" }
  mood_entries { bigint tenant_id bigint patient_id date entry_date string period int mood "-3..3" int energy "-3..3" text note "criptografado" int version }
  care_grants { bigint tenant_id bigint patient_id bigint grantee_user_id int scope_mask timestamp expires_at timestamp revoked_at }
```

- **Tenant** = clínica (admin + vários profissionais e pacientes), **profissional solo** (tenant com um
  único profissional) ou **pessoal** (paciente independente). Cada um pode ter subdomínio opcional.
- **User** é identidade global; o vínculo com tenants é por `memberships` (um paciente tem exatamente
  um vínculo `patient`). Profissional pode ter vínculos em várias clínicas.
- FKs compostas `(tenant_id, patient_id) → memberships(tenant_id, user_id)` garantem no banco que o
  paciente pertence ao tenant da linha.

## Resolução de tenant por host

```mermaid
flowchart TD
  R[Requisição] --> H{Host}
  H -->|bipolar.app.br / www / APP_URL| M[sem tenant de host]
  H -->|slug.bipolar.app.br| S{slug válido, não reservado e existente?}
  H -->|outro| X[404]
  S -->|não| X
  S -->|sim| T[tenant do host]
  M --> A[auth: token ou sessão]
  T --> A
  A --> C{host, token/sessão e X-Tenant-Id concordam e há vínculo?}
  C -->|não| X
  C -->|sim| OK[TenantContext definido]
```

## Fluxo de requisição (registro de humor)

```mermaid
sequenceDiagram
  participant C as Cliente (PWA/App)
  participant M as Middlewares
  participant S as MoodEntryService
  participant DB as MySQL
  C->>M: PUT /api/v1/entries {entry_date, period, mood, energy, version}
  M->>M: auth:sanctum → user
  M->>M: ResolveTenant (membership do user) → TenantContext
  M->>M: EnsurePatient (role=patient)
  M->>S: dados validados (sem tenant_id/patient_id do cliente)
  S->>S: trava de período (não futuro; passado sem limite)
  S->>DB: SELECT … WHERE tenant_id=? AND patient_id=? FOR UPDATE
  alt versão diferente
    S-->>C: 409 Conflict
  else
    S->>DB: INSERT/UPDATE (version+1) + audit_logs
    S-->>C: 201/200 + signal (depressivo|maniaco|misto|neutro)
  end
```

## Trava de período (Manhã/Tarde/Noite)

O cliente envia **explicitamente** `entry_date` + `period` escolhidos quando o registro começou; o servidor
nunca deduz pelo relógio. A tela mostra "Registrando: Tarde de 30/10" fixo e avisa se o relógio mudou.
Servidor recusa período que ainda não começou (fuso do paciente; manhã 00h, tarde 12h, noite 18h). Dias
passados não têm limite. Edição concorrente usa `version` (409 em conflito).

## Front-end

- Blade renderiza no servidor; HTMX envia formulários e troca fragmentos; Alpine (build CSP) só para
  pequenos estados (rótulo selecionado, aviso de período).
- Sem inline JS/CSS → CSP `script-src 'self'; style-src 'self'`.
- Workbox: precache só de `build/assets/*`, manifest e `offline.html`; navegação e `/api` = NetworkOnly.

## Tarefas assíncronas

Sem processo residente no cPanel: cron por minuto → `schedule:run` → `queue:work --stop-when-empty --max-time=50`.
Fila no banco (`jobs`). Lembretes/relatórios entram como jobs com tenant explícito (`TenantContext::run`).

## Próximos módulos (contrato já no OpenAPI)

Marcadores do dia, módulos privados (step-up), grade semanal de lembretes, relatórios, concessões/convites,
exportação (LGPD art. 18), conta (MFA TOTP, exclusão).
