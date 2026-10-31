# Skill: tenant-isolation-test

Garantir que um recurso não vaza entre tenants, pacientes ou rede de apoio.

## Passos
1. Crie `tests/Feature/Isolation/<Recurso>IsolationTest.php`.
2. Use os helpers de `tests/Pest.php`: `patient()`, `patient($clinic->id)`, `clinic()`, `professional($clinic)`, `tenantOf($u)`, `asTenant($tenantId, fn)`.
3. Crie o dado da vítima dentro de `asTenant(tenantOf($vitima), …)`.
4. Casos obrigatórios (todos esperam **404**, nunca 403/200):
   - outro tenant: GET/PUT/PATCH/DELETE por id;
   - mesmo tenant de clínica, outro paciente;
   - profissional da clínica sem concessão (com e sem `X-Tenant-Id`);
   - `X-Tenant-Id` de tenant alheio ou inválido;
   - concessão revogada/expirada; categoria fora do `scope_mask`;
   - listagem não inclui itens alheios (`assertJsonCount(0, 'data')`).
5. Escrita forjada: envie `tenant_id`/`patient_id` de outro no corpo e confirme que nada mudou do lado da vítima.
6. Falha fechada: sem contexto, `Model::count()` lança `MissingTenantContext`.
7. Tela web: `assertDontSee('<texto-da-vitima>')`.
8. Rode `vendor/bin/pest tests/Feature/Isolation tests/Arch`.

## Host / subdomínio (obrigatório para rotas novas)
- Use `tokenFor($user, $tenantId)` (token amarrado) e URLs completas `http://<slug>.bipolar.app.br/...`.
- Cubra: token do tenant A no host do tenant B → 404; `X-Tenant-Id` divergente → 404; sessão aberta num host
  levada a outro → 404. Modelo: `tests/Feature/Isolation/HostTenancyTest.php`.
