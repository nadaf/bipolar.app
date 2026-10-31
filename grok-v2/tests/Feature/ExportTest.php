<?php

use App\Domain\Care\CareScope;
use App\Models\AuditLog;
use App\Models\MoodEntry;
use App\Models\User;

function mood(User $p, string $date, string $period, array $o = []): void
{
    asTenant(tenantOf($p), fn () => MoodEntry::create(array_merge(['patient_id' => $p->id, 'entry_date' => $date, 'period' => $period,
        'mood' => -1, 'energy' => 1, 'sleep_hours' => 7.5, 'medication_taken' => true, 'note' => 'nota secreta'], $o)));
}

function csvRows(string $body): array
{
    return array_map(fn ($l) => str_getcsv($l, ';', '"', ''), array_values(array_filter(explode("\r\n", substr($body, 3)))));
}

it('paciente exporta CSV: BOM, separador ;, datas pt-BR, periodo, nome neutro, auditado', function () {
    $p = patient();
    mood($p, '2026-10-01', 'noite');
    mood($p, '2026-10-01', 'manha', ['mood' => 2, 'note' => '=HYPERLINK("http://x")']);
    mood($p, '2026-09-01', 'tarde'); // fora do intervalo
    $r = $this->actingAs($p)->post('/exportar', ['formato' => 'csv', 'de' => '2026-10-01', 'ate' => '2026-10-31']);
    $r->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private');
    expect($r->headers->get('Content-Disposition'))->toBe('attachment; filename="bipolar-registros-'.now()->format('Y-m-d').'.csv"');
    $body = $r->getContent();
    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF");
    $rows = csvRows($body);
    expect($rows)->toHaveCount(3)
        ->and($rows[0][0])->toBe('Data')->and($rows[0][1])->toBe('Período')
        ->and($rows[1][0])->toBe('01/10/2026')->and($rows[1][1])->toBe('Manhã')->and($rows[2][1])->toBe('Noite')
        ->and($rows[1][10])->toBe("'=HYPERLINK(\"http://x\")") // injecao de formula neutralizada
        ->and($rows[2][8])->toBe('7,5')->and($rows[2][9])->toBe('sim')->and($rows[2][10])->toBe('nota secreta')
        ->and(asTenant(tenantOf($p), fn () => AuditLog::where('action', 'export.csv')->where('patient_id', $p->id)->count()))->toBe(1);
});

it('paciente exporta PDF gerado no servidor', function () {
    $p = patient();
    mood($p, now()->subDay()->toDateString(), 'tarde');
    $r = $this->actingAs($p)->post('/exportar', ['formato' => 'pdf', 'de' => now()->subDays(7)->toDateString(), 'ate' => now()->toDateString()]);
    $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($r->getContent(), 0, 5))->toBe('%PDF-')
        ->and($r->headers->get('Content-Disposition'))->toContain('bipolar-registros-')
        ->and(asTenant(tenantOf($p), fn () => AuditLog::where('action', 'export.pdf')->count()))->toBe(1);
});

it('valida intervalo e formato; tela tem formulario acessivel', function () {
    $p = patient();
    $this->actingAs($p)->get('/exportar')->assertOk()->assertSee('<label for="de"', false)->assertSee('Planilha (CSV)');
    $this->actingAs($p)->post('/exportar', ['formato' => 'xls', 'de' => '2026-10-01', 'ate' => '2026-09-01'])->assertSessionHasErrors(['formato', 'ate']);
});

it('isolamento: exporta so os proprios registros (outro tenant e mesma clinica)', function () {
    $c = clinic();
    $a = patient($c->id);
    $b = patient($c->id);
    $x = patient();
    mood($a, '2026-10-02', 'tarde', ['note' => 'de A']);
    mood($b, '2026-10-02', 'tarde', ['note' => 'de B']);
    mood($x, '2026-10-02', 'tarde', ['note' => 'de X']);
    $body = $this->actingAs($a)->withSession(['tenant_id' => $c->id])->post('/exportar', ['formato' => 'csv', 'de' => '2026-10-01', 'ate' => '2026-10-31'])->getContent();
    expect($body)->toContain('de A')->not->toContain('de B')->not->toContain('de X');
    $api = $this->withToken(tokenFor($x))->get('/api/v1/export/entries?format=csv&from=2026-10-01&to=2026-10-31')->assertOk()->getContent();
    expect($api)->toContain('de X')->not->toContain('de A');
});

it('profissional exporta so paciente vinculado, so categorias concedidas, auditado no tenant do paciente', function () {
    $c = clinic();
    $pro = professional($c);
    $p = patient();
    $other = patient();
    mood($p, '2026-10-05', 'noite');
    mood($other, '2026-10-05', 'noite');
    grantTo($p, $pro, CareScope::MOOD); // sem SONO e sem PENSAMENTOS
    $t = tokenFor($pro, $c->id);
    $body = $this->withToken($t)->get("/api/v1/care/patients/{$p->id}/export?format=csv&from=2026-10-01&to=2026-10-31")->assertOk()->getContent();
    $rows = csvRows($body);
    expect($rows)->toHaveCount(2)->and($rows[1][2])->toBe('-1')
        ->and($rows[1][8])->toBe('')->and($rows[1][10])->toBe('') // sono e anotacao nao concedidos
        ->and($body)->not->toContain('nota secreta');
    $this->withToken($t)->get("/api/v1/care/patients/{$other->id}/export?format=csv&from=2026-10-01&to=2026-10-31")->assertNotFound();
    $log = asTenant(tenantOf($p), fn () => AuditLog::where('action', 'care.exported_csv')->first());
    expect($log->actor_id)->toBe($pro->id)->and($log->patient_id)->toBe($p->id)->and((int) $log->scope_mask)->toBe(CareScope::MOOD);
    // profissional nao usa a exportacao de paciente
    $this->withToken($t)->get('/api/v1/export/entries?format=csv&from=2026-10-01&to=2026-10-31')->assertNotFound();
});

it('profissional: respeita data_from, concessao revogada/vencida da 404, web tambem', function () {
    $c = clinic('clinica-exp');
    $pro = professional($c);
    $p = patient();
    mood($p, '2026-09-10', 'tarde', ['note' => 'antes']);
    mood($p, '2026-10-10', 'tarde', ['note' => 'depois']);
    $g = grantTo($p, $pro, CareScope::MOOD | CareScope::THOUGHTS, ['data_from' => '2026-10-01']);
    $host = 'http://clinica-exp.'.config('bipolar.base_domain');
    $this->actingAs($pro)->withSession(['tenant_id' => $c->id])->get($host."/cuidado/{$p->id}/exportar")->assertOk()->assertSee('Exportar registros de');
    $body = $this->actingAs($pro)->withSession(['tenant_id' => $c->id])
        ->post($host."/cuidado/{$p->id}/exportar", ['formato' => 'csv', 'de' => '2026-09-01', 'ate' => '2026-10-31'])->assertOk()->getContent();
    expect($body)->toContain('depois')->not->toContain('antes');
    asTenant(tenantOf($p), fn () => $g->forceFill(['revoked_at' => now()])->save());
    $this->actingAs($pro)->withSession(['tenant_id' => $c->id])
        ->post($host."/cuidado/{$p->id}/exportar", ['formato' => 'csv', 'de' => '2026-09-01', 'ate' => '2026-10-31'])->assertNotFound();
});
