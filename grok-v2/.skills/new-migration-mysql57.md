# Skill: new-migration-mysql57

Criar migration compatível com MySQL 5.7 (HostGator) e com o instalador sem SSH.

## Passos
1. `php artisan make:migration create_<tabela>_table` (nome em inglês, plural).
2. Dado de paciente/clínica? Adicione:
   ```php
   $table->unsignedBigInteger('tenant_id');
   $table->unsignedBigInteger('patient_id');
   $table->foreign('tenant_id', 'fk_<abrev>_tenant')->references('id')->on('tenants')->cascadeOnDelete();
   $table->foreign(['tenant_id', 'patient_id'], 'fk_<abrev>_membership')->references(['tenant_id', 'user_id'])->on('memberships')->cascadeOnDelete();
   $table->index(['tenant_id', 'patient_id', '<coluna_de_busca>']);
   ```
3. Unicidades sempre começando por `tenant_id`.
4. Proibido no MySQL 5.7: default em `json`/`text`, `CHECK`, CTE/janela, índice > 3072 bytes, nomes > 64 chars.
   Strings indexadas: `string(190)` no máximo em utf8mb4.
5. Não use `information_schema`, nem `;` dentro de comentários SQL.
6. Texto livre sensível → coluna `text` + cast `encrypted` no model.
7. Crie o model com `use BelongsToTenant;` (o teste de arquitetura falha sem ele).
8. Regere o SQL de instalação num banco **vazio**: `php artisan bipolar:export-sql`.
9. `vendor/bin/pest` (o CI roda em `mysql:5.7` e compara o SQL gerado).
10. Documente em `docs/MIGRATION-FROM-0.5.md` se a tabela recebe dados da 0.5.
