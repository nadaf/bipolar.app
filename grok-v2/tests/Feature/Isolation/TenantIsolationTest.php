<?php

use App\Models\MoodEntry;
use App\Models\User;
use App\Tenancy\Exceptions\MissingTenantContext;
use App\Tenancy\Exceptions\TenantMismatch;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;

/** Cria um registro do paciente e devolve o id. */
function entryFor(User $p): int
{
    return asTenant(tenantOf($p), fn () => MoodEntry::create(['patient_id' => $p->id] + entryPayload())->id);
}

it('paciente de outro tenant: leitura por id da 404 (IDOR)', function () {
    $vitima = patient();
    $id = entryFor($vitima);
    Sanctum::actingAs(patient());
    $this->getJson("/api/v1/entries/{$id}")->assertNotFound();
    $this->deleteJson("/api/v1/entries/{$id}")->assertNotFound();
    $this->getJson('/api/v1/entries')->assertOk()->assertJsonCount(0, 'data');
});

it('mesmo tenant de clinica, outro paciente: 404 e listagem vazia', function () {
    $c = clinic();
    $a = patient($c->id);
    $b = patient($c->id);
    $id = entryFor($a);
    Sanctum::actingAs($b);
    $this->getJson("/api/v1/entries/{$id}")->assertNotFound();
    $this->deleteJson("/api/v1/entries/{$id}")->assertNotFound();
    $this->getJson('/api/v1/entries')->assertJsonCount(0, 'data');
});

it('profissional da clinica NAO le dados do paciente sem concessao', function () {
    $c = clinic();
    $p = patient($c->id);
    $id = entryFor($p);
    Sanctum::actingAs(professional($c));
    $this->withHeader('X-Tenant-Id', (string) $c->id)->getJson("/api/v1/entries/{$id}")->assertNotFound();
    $this->withHeader('X-Tenant-Id', (string) $c->id)->getJson('/api/v1/entries')->assertNotFound();
});

it('cabecalho X-Tenant-Id de tenant alheio da 404', function () {
    $outro = patient();
    Sanctum::actingAs(patient());
    $this->withHeader('X-Tenant-Id', (string) tenantOf($outro))->getJson('/api/v1/entries')->assertNotFound();
    $this->withHeader('X-Tenant-Id', 'abc')->getJson('/api/v1/entries')->assertNotFound();
});

it('escrita cruzada: upsert nunca altera registro de outro paciente', function () {
    $vitima = patient();
    $id = entryFor($vitima);
    Sanctum::actingAs(patient());
    $this->putJson('/api/v1/entries', entryPayload(['mood' => 3, 'version' => 1]))->assertCreated();
    expect(asTenant(tenantOf($vitima), fn () => MoodEntry::find($id)->mood))->toBe(0);
});

it('sem contexto de tenant, consulta a model lanca excecao (falha fechada)', function () {
    expect(fn () => MoodEntry::count())->toThrow(MissingTenantContext::class);
    expect(fn () => MoodEntry::create(['patient_id' => 1] + entryPayload()))->toThrow(MissingTenantContext::class);
});

it('nao cria registro com tenant_id diferente do contexto', function () {
    $a = patient();
    $b = patient();
    asTenant(tenantOf($a), function () use ($a, $b) {
        $e = new MoodEntry(['patient_id' => $a->id] + entryPayload());
        $e->tenant_id = tenantOf($b);
        expect(fn () => $e->save())->toThrow(TenantMismatch::class);
    });
});

it('nao altera tenant_id de um registro existente', function () {
    $a = patient();
    $b = patient();
    $id = entryFor($a);
    asTenant(tenantOf($a), function () use ($id, $b) {
        $e = MoodEntry::find($id);
        $e->tenant_id = tenantOf($b);
        expect(fn () => $e->save())->toThrow(TenantMismatch::class);
    });
});

it('FK composta impede paciente que nao e membro do tenant', function () {
    $a = patient();
    $b = patient();
    asTenant(tenantOf($a), function () use ($b) {
        expect(fn () => MoodEntry::create(['patient_id' => $b->id] + entryPayload()))
            ->toThrow(QueryException::class);
    });
});

it('tela web: outro paciente nao ve registro alheio', function () {
    $vitima = patient();
    asTenant(tenantOf($vitima), fn () => MoodEntry::create(['patient_id' => $vitima->id] + entryPayload(['note' => 'nota-da-vitima'])));
    $this->actingAs(patient())->get('/registrar?data='.entryPayload()['entry_date'].'&periodo=tarde')
        ->assertOk()->assertDontSee('nota-da-vitima');
});
