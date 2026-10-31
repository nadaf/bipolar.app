# AGENTS.md — Bipolar 2.x (bipolar.app.br)

Instruções para QUALQUER agente de IA (Cursor, Claude, Copilot, Aider, Continue com Ollama local)
e para pessoas. Leia inteiro antes de mudar código. Em dúvida, pare e pergunte; nunca "chute" em
segurança ou isolamento de dados.

## 1. O que é

Diário de humor em pt-BR (PWA) para pessoas com transtorno bipolar, com rede de apoio
(psicólogos, psiquiatras, família) que só vê o que o paciente autorizar.
Versão 2.0 = reescrita API-first em Laravel. A 0.5.x (PHP puro) é a referência de domínio.
Apps nativos futuros consumirão a mesma API (`/api/v1`, Sanctum, `openapi/v1.yaml`).

## 2. Layout do projeto (público x privado)

```
bipolar.app/
├── public-app/   → repositório PÚBLICO nadaf/bipolar.app (open source). ESTE diretório.
│   ├── app/ … (Laravel), AGENTS.md, docs/, openapi/, .skills/, .github/workflows/ci-release.yml
└── private/      → repositório PRIVADO nadaf/bipolar-saas (operação do serviço hospedado)
    └── pacote Composer nadaf/bipolar-saas (namespace BipolarSaas\), opcional em tempo de execução
```

Regras:
- O público **compila, roda e passa nos testes sem o privado**. Nunca referencie `BipolarSaas\` ou
  `nadaf/bipolar-saas` no público.
- Provisionamento e onboarding de clínicas, contas de organização e ID de analytics do site principal
  ficam **só** no privado. O privado entra via composer path repo + auto-discovery
  (`private/scripts/build-saas.sh`).
- Pontos de extensão do público para o privado: `config('bipolar.edition')`, service providers,
  rotas próprias. O privado **não** lê dados clínicos.
- `scripts/check-public-tree.sh` (roda no CI) falha se algo privado ou segredo aparecer aqui.
- Cada pasta é um repositório git separado. **Não faça push/deploy sem pedido explícito.**

## 3. Stack

| Camada | Tecnologia |
|---|---|
| Backend | Laravel 13 (PHP 8.3), Sanctum (tokens), Pest 4 + PHPUnit 12, Larastan, Pint |
| Banco | MySQL 5.7 (produção HostGator). Testes locais: MariaDB/MySQL; CI: `mysql:5.7` |
| Front | Blade + HTMX + Alpine.js (**build CSP** `@alpinejs/csp`) + Tailwind CSS 4 (Vite) |
| PWA | Workbox (`workbox.config.cjs`) → `public/sw.js` (só estáticos; nunca API/HTML privado) |
| Hospedagem | HostGator cPanel compartilhado: Apache, PHP 8.3, **sem SSH**, cron permitido |

## 4. Comandos

```bash
composer install && npm ci
cp .env.example .env && php artisan key:generate   # local
npm run build                                     # Vite + Tailwind + Workbox
vendor/bin/pest                                   # todos os testes (precisa MySQL/MariaDB: ver phpunit.xml)
vendor/bin/pest tests/Feature/Isolation tests/Arch# isolamento + arquitetura
vendor/bin/pint                                   # formatação
vendor/bin/phpstan analyse --memory-limit=1G      # análise estática (nível 5)
php artisan bipolar:export-sql                    # regenera database/sql/install-mysql57.sql (banco VAZIO)
scripts/check-public-tree.sh                      # nada privado/segredo no público
scripts/build-release.sh /caminho/saida.zip       # zip para cPanel
```

Banco de teste padrão (phpunit.xml, sobrescrevível por env): `127.0.0.1:3306`, db `bipolar_test`, user/senha `bp`.

## 5. Padrões de código

- `declare` tipos em tudo; PSR-12 via Pint; PHPStan nível 5 sem baseline e sem `@phpstan-ignore`.
- Controllers finos. Regras de negócio em `app/Domain/<Contexto>/` (serviço único usado por API **e** tela).
- Validação em FormRequest. Resposta da API em JsonResource. Nunca devolver o model cru.
- Nunca aceitar `tenant_id`, `patient_id`, `user_id` do cliente: vêm do usuário autenticado/contexto.
- Controllers não usam `DB::` (teste de arquitetura). Consultas sempre via models com escopo.
- Textos de interface em pt-BR simples; código/identificadores em inglês; comentários podem ser pt-BR.
- Rotas novas: API em `routes/api.php` dentro de `v1`, e o **mesmo** contrato em `openapi/v1.yaml`.
- Escalas clínicas centralizadas em `App\Domain\Mood\Scales` (não duplique rótulos).

### Escalas
- **Humor** e **Energia**: bipolares **-3..+3**, 0 = normal/estável. Negativo = sinal depressivo,
  positivo = sinal de (hipo)mania. Energia: Muito baixa, Baixa, Um pouco baixa, Normal, Um pouco alta, Alta, Muito alta.
- Ansiedade e irritabilidade: 0..3 (Nenhuma..Intensa).
- `SignalClassifier`: |v| ≥ 2 vira sinal; humor e energia em sentidos opostos = **misto**.
- UI: cor **nunca** é o único indicador (rótulo + símbolo ▼/●/▲ + número), radios nativos em `fieldset/legend`.

### Front-end e CSP
- CSP estrita (`App\Http\Middleware\SecurityHeaders`): **sem** `<script>` inline, sem `on*=`,
  sem `style=`, sem `eval`, sem CDN. Alpine só com `Alpine.data(...)` em `resources/js/app.js`.
- HTMX configurado por `<meta name="htmx-config">` (sem estilos inline, sem eval).
- WCAG 2.2 AA: alvo de toque ≥ 44px, foco visível, `aria-live` para status, rótulos em todo campo,
  contraste ≥ 4.5:1, funciona só com teclado. Mobile-first.

### Nada comercial no público
Conteúdo comercial do serviço hospedado **não** entra neste repositório (código, docs, OpenAPI).
`scripts/check-public-tree.sh` falha se termos comerciais aparecerem (lista no script). Isso vive só no repositório privado.

## 6. Segurança, privacidade e LGPD (OBRIGATÓRIO)

- **Nenhum segredo no git**: `.env`, chaves, tokens, `auth.json`, `config.php` da 0.5. Use `.env.example`.
  Nunca leia, imprima, copie ou empacote `/workspace/bipolar-app/app/config.php` (segredos reais da 0.5).
- **Sem analytics/telemetria/rastreadores em telas privadas** (tudo depois do login e a API).
  Analytics só no site de marketing (repositório privado).
- Dados de saúde são **dados sensíveis (LGPD art. 11)**. Anotações livres criptografadas (cast `encrypted`).
  Logs nunca contêm conteúdo clínico, notas, e-mails completos ou tokens.
- Respostas autenticadas: `Cache-Control: no-store`. Service worker não cacheia API nem HTML.
- Cookies: `Secure`, `HttpOnly`, `SameSite=Lax`, sessão criptografada.
- Ver `docs/SECURITY-MULTITENANT.md` para o modelo completo.

### Isolamento multi-tenant (prioridade máxima)
1. Banco único, toda tabela de dado de tenant tem `tenant_id` + índices/FKs compostos começando por `tenant_id`.
2. Todo model com `tenant_id` usa `App\Tenancy\BelongsToTenant` (teste de arquitetura verifica).
   Exceções documentadas: `Membership` (resolve o tenant) e `PersonalAccessToken` (guarda o tenant de
   emissão). Único código que cruza tenants: `App\Domain\Care\GrantDirectory` (por concessão ativa).
3. Sem contexto de tenant, qualquer consulta a esses models **lança exceção** (falha fechada).
4. O tenant vem do usuário autenticado (`ResolveTenant`), nunca de parâmetro livre. Token e sessão
   ficam amarrados ao tenant de emissão; o subdomínio (`ResolveHost`) só restringe, nunca concede.
   Cookie de sessão é por host (`session.domain` sempre `null`). Ver docs/SECURITY-MULTITENANT.md.
5. Dentro do tenant, dado de paciente só pelo **dono** (`ownedBy`) ou por **concessão** (CareGrant) ativa.
6. Acesso negado a recurso de outro tenant/paciente responde **404** (nunca 403: não revelar existência).
7. Rede de apoio: só leitura, por concessão do paciente (bitmask de categorias), com validade,
   revogável e auditada (`audit_logs`).
8. Módulos sensíveis (sexualidade, substâncias, pensamentos/morte, religiosidade) começam
   **desligados** no compartilhamento e exigem desbloqueio extra (step-up).
9. Módulo de pensamentos de morte/suicídio **nunca notifica ninguém automaticamente**; sempre mostra
   **CVV 188** e **SAMU 192**.

## 7. Testes (obrigatório em todo PR)

- Toda rota nova: teste de sucesso, validação, não autenticado (401) e **isolamento**:
  outro tenant (404), mesmo tenant outro paciente (404), profissional sem concessão (404),
  IDOR por id em leitura/escrita/exclusão (404), e corpo com `tenant_id/patient_id` forjados ignorado.
- Use os helpers de `tests/Pest.php` (`patient()`, `clinic()`, `professional()`, `asTenant()`).
- Migrations devem rodar no MySQL 5.7 (CI usa `mysql:5.7`).
- Telas: teste que não há script inline e que a CSP está presente.

## 8. Banco e migrations (MySQL 5.7)

- Sem `json` com default, sem `CHECK`, sem colunas geradas complexas, sem funções de janela/CTE em migration.
- Índices: no máximo 3072 bytes (utf8mb4 = 4 bytes/char → `string(190)` em índices únicos simples).
- Nomeie FKs/índices explicitamente (≤ 64 caracteres).
- Não use `information_schema` em SQL de instalação; não coloque `;` em comentários SQL.
- Depois de mudar migrations: `php artisan bipolar:export-sql` (o CI compara o arquivo).

## 9. Implantação (cPanel, sem SSH)

- Entregável: zip de `scripts/build-release.sh` com `bipolar/` (app + `vendor/` de
  `composer install --no-dev -o`) e `public_html/` (conteúdo de `public/`, assets prontos).
- Banco: instalador web `/instalar` (só com `INSTALLER_TOKEN` ≥ 32 caracteres no `.env`) ou
  importar `database/sql/install-mysql57.sql` no phpMyAdmin.
- Cron (cPanel): `cd ~/bipolar && /usr/local/bin/php artisan schedule:run` a cada minuto.
  Filas: driver `database`, processadas pelo agendador (`queue:work --stop-when-empty`).
- Nunca use `config:cache` no build (o `.env` é editado no servidor). Guia: `INSTALL-CPANEL.md`.

## 10. Definition of Done

- [ ] `pint --test`, `phpstan`, `pest` verdes localmente; CI verde.
- [ ] Testes de isolamento para cada recurso novo; arquitetura ok.
- [ ] `openapi/v1.yaml` atualizado e coerente com as rotas.
- [ ] Sem segredo, sem analytics em tela privada; `scripts/check-public-tree.sh` ok.
- [ ] Migrations compatíveis com MySQL 5.7 e `install-mysql57.sql` regenerado.
- [ ] Tela acessível (teclado, leitor de tela, contraste, sem cor-única) e sem inline JS/CSS.
- [ ] Textos em pt-BR revisados; CHANGELOG atualizado.
- [ ] Nada de push/deploy/e-mail externo sem pedido explícito.

## 11. Skills (passo a passo reutilizável)

Veja `.skills/`: `new-api-endpoint`, `new-migration-mysql57`, `tenant-isolation-test`,
`cpanel-zip-release`, `sensitive-module`.
