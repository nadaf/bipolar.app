# Skill: new-api-endpoint

Criar um endpoint REST em `/api/v1` seguro, multi-tenant, com teste e contrato OpenAPI.

## Passos
1. **Contrato primeiro**: adicione o path em `openapi/v1.yaml` (request, respostas 200/201/401/404/409/422, `security: bearerAuth`).
2. **Domínio**: crie/atualize serviço em `app/Domain/<Contexto>/` com a regra. Recebe o `User` autenticado, nunca ids de tenant/paciente do cliente.
3. **Validação**: `app/Http/Requests/<Nome>Request.php`; método `data()` devolve só campos de domínio.
4. **Resource**: `app/Http/Resources/<Nome>Resource.php` (nunca devolva o model cru; nada de `tenant_id`).
5. **Controller fino** em `app/Http/Controllers/Api/V1/`. Buscas por id: `Model::query()->ownedBy($user)->findOrFail($id)` → 404 automático.
6. **Rota** em `routes/api.php` dentro de `prefix('v1')`, grupo `auth:sanctum` + `tenant` (+ `patient` se for dado do paciente), `->whereNumber('id')`, nome `api.v1.<recurso>.<acao>`.
7. **Testes** (`tests/Feature/<Recurso>ApiTest.php`): sucesso, 422, 401, e use a skill `tenant-isolation-test`.
8. Rode `vendor/bin/pint && vendor/bin/phpstan analyse && vendor/bin/pest`.
9. Se houver tela: mesma regra de domínio, controller web, Blade sem inline JS/CSS.

## Checklist
- [ ] OpenAPI atualizado  - [ ] 404 para fora do alcance  - [ ] sem `DB::` no controller
- [ ] auditoria (`AuditLog`) em escrita de dado clínico  - [ ] rate limit adequado
