<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\CareGrant;
use App\Models\MoodEntry;

/** Nenhuma tela pode ter script inline, style inline nem on*= (CSP estrita + Alpine CSP). */
function assertStrictPage($r): void
{
    $r->assertOk()->assertHeader('Content-Security-Policy', SecurityHeaders::CSP);
    $html = $r->getContent();
    expect($html)->not->toMatch('/<script(?![^>]*\bsrc=)[^>]*>/i')
        ->and($html)->not->toMatch('/\sstyle\s*=/i')
        ->and($html)->not->toMatch('/\son[a-z]+\s*=/i')
        ->and($html)->not->toMatch('/javascript:/i')
        ->and($html)->not->toMatch('/\bG-[A-Z0-9]{6,}\b/')
        ->and($html)->not->toContain('googletagmanager')
        ->and($html)->toContain('lang="pt-BR"');
}

dataset('telas_paciente', ['/registrar', '/registrar?periodo=manha', '/historico', '/relatorios', '/relatorios?periodo=7', '/relatorios?periodo=personalizado&de=2026-01-01&ate=2026-02-01',
    '/alertas', '/conta', '/compartilhar', '/cuidado']);

it('tela do paciente segue a CSP estrita', function (string $url) {
    $p = patient();
    asTenant(tenantOf($p), fn () => MoodEntry::create(['patient_id' => $p->id] + entryPayload(['mood' => -2, 'energy' => 1])));
    assertStrictPage($this->actingAs($p)->get($url));
})->with('telas_paciente');

it('dia do historico e detalhe de compartilhamento seguem a CSP', function () {
    $p = patient();
    asTenant(tenantOf($p), fn () => MoodEntry::create(['patient_id' => $p->id] + entryPayload()));
    assertStrictPage($this->actingAs($p)->get('/historico/'.entryPayload()['entry_date']));
    $this->actingAs($p)->post('/compartilhar', ['grantee_kind' => 'psicologo', 'scope' => ['humor', 'sono']])->assertRedirect();
    $g = asTenant(tenantOf($p), fn () => CareGrant::first());
    $page = $this->actingAs($p)->get('/compartilhar/'.$g->id);
    assertStrictPage($page);
    $page->assertSee('Convite criado');
});

it('telas publicas, de profissional e de clinica seguem a CSP', function () {
    assertStrictPage($this->get('/entrar'));
    assertStrictPage($this->get('/criar-conta'));
    $c = clinic('clinica-csp');
    $pro = professional($c);
    $pat = patient();
    grantTo($pat, $pro);
    assertStrictPage($this->actingAs($pro)->get(onHost('clinica-csp', '/cuidado')));
    assertStrictPage($this->actingAs($pro)->get(onHost('clinica-csp', '/cuidado/'.$pat->id)));
    assertStrictPage($this->actingAs(clinicAdmin($c))->get(onHost('clinica-csp', '/clinica')));
});

it('convite: paciente cria, outra pessoa aceita e passa a ver o relatorio', function () {
    $p = patient();
    $fam = patient();
    $this->actingAs($p)->post('/compartilhar', ['grantee_kind' => 'familiar', 'scope' => ['humor']])->assertRedirect();
    $link = session('convite');
    expect($link)->toContain('/convite/');
    $path = parse_url($link, PHP_URL_PATH);
    assertStrictPage($this->actingAs($fam)->get($path));
    $this->actingAs($fam)->post($path)->assertRedirect(route('care.index'));
    $this->actingAs($fam)->get('/cuidado/'.$p->id)->assertOk()->assertSee('Somente leitura');
    $this->actingAs($fam)->post($path)->assertNotFound(); // uso unico
});

it('categoria sensivel no convite exige desbloqueio', function () {
    $p = patient();
    $this->actingAs($p)->post('/compartilhar', ['grantee_kind' => 'psiquiatra', 'scope' => ['humor', 'pensamentos_morte']])
        ->assertSessionHasErrors('scope');
});

it('lembretes: grade salva e volta marcada', function () {
    $p = patient();
    $this->actingAs($p)->post('/alertas/lembretes', ['cel' => ['1-manha' => '1', '3-noite' => '1'], 'hora' => ['manha' => '08:30'], 'canal' => 'push'])
        ->assertRedirect('/alertas');
    $this->actingAs($p)->get('/alertas')->assertSee('name="cel[1-manha]" value="1" checked', false)->assertSee('08:30');
});

it('tema persiste na conta', function () {
    $p = patient();
    $this->actingAs($p)->postJson('/tema', ['theme' => 'escuro'])->assertNoContent();
    expect($p->fresh()->theme)->toBe('escuro');
    $this->actingAs($p->fresh())->get('/registrar')->assertSee('data-tema="escuro"', false);
});

it('exportar com sensiveis exige desbloqueio; sem eles baixa JSON', function () {
    $p = patient();
    $this->actingAs($p)->post('/conta/exportar', ['sensiveis' => '1'])->assertRedirect();
    $this->actingAs($p)->post('/conta/exportar')->assertOk()->assertHeader('Content-Disposition');
});

it('excluir conta exige palavra e senha', function () {
    $p = patient();
    $this->actingAs($p)->post('/conta/excluir', ['palavra' => 'nao', 'password' => 'senha-muito-boa-1'])->assertSessionHasErrors('palavra');
    $this->actingAs($p)->post('/conta/excluir', ['palavra' => 'EXCLUIR', 'password' => 'senha-muito-boa-1'])->assertRedirect('/entrar');
    expect($p->fresh())->toBeNull();
});
