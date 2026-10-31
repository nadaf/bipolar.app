<?php

use App\Models\AuditLog;
use App\Models\DayBlock;
use Illuminate\Support\Facades\DB;

function yday(): string
{
    return now('America/Sao_Paulo')->subDay()->toDateString();
}

it('grava bloco diario e por periodo, criptografado em repouso, sem conteudo na auditoria', function () {
    $p = patient();
    $tok = tokenFor($p);
    $this->withToken($tok)->putJson('/api/v1/days/'.yday().'/blocks/sono', ['data' => ['horas' => 7.5, 'qualidade' => '2', 'lixo' => 'x']])
        ->assertCreated()->assertJsonPath('data.period', 'dia')->assertJsonPath('data.data.horas', 7.5)
        ->assertJsonMissingPath('data.data.lixo');
    $this->withToken($tok)->putJson('/api/v1/days/'.yday().'/blocks/estresse', ['period' => 'tarde', 'data' => ['nivel' => 2, 'eventos' => 'briga no trabalho']])
        ->assertCreated()->assertJsonPath('data.summary', 'Estresse neste período: Moderada · O que aconteceu? (opcional): briga no trabalho');
    $raw = DB::table('day_blocks')->where('block', 'estresse')->value('data');
    expect($raw)->not->toContain('briga');
    expect(AuditLog::withoutGlobalScopes()->where('action', 'day_block.created')->count())->toBe(2);

    $this->withToken($tok)->getJson('/api/v1/days/'.yday())->assertOk()->assertJsonCount(2, 'data.blocks');
});

it('valida campos do bloco, periodo futuro e bloco desconhecido', function () {
    $tok = tokenFor(patient());
    $this->withToken($tok)->putJson('/api/v1/days/'.yday().'/blocks/sono', ['data' => ['horas' => 30]])->assertUnprocessable();
    $this->withToken($tok)->putJson('/api/v1/days/'.yday().'/blocks/estresse', ['period' => 'madrugada', 'data' => []])->assertUnprocessable();
    $this->withToken($tok)->putJson('/api/v1/days/'.now()->addDays(3)->toDateString().'/blocks/sono', ['data' => ['horas' => 1]])->assertUnprocessable();
    $this->withToken($tok)->putJson('/api/v1/days/'.yday().'/blocks/inexistente', ['data' => []])->assertNotFound();
    $this->withToken($tok)->getJson('/api/v1/days/2026-02-31')->assertNotFound();
});

it('concorrencia otimista nos blocos: versao velha => 409', function () {
    $tok = tokenFor(patient());
    $url = '/api/v1/days/'.yday().'/blocks/medicacao';
    $this->withToken($tok)->putJson($url, ['data' => ['tomou' => 'sim']])->assertCreated();
    $this->withToken($tok)->putJson($url, ['version' => 1, 'data' => ['tomou' => 'parcial']])->assertOk()->assertJsonPath('data.version', 2);
    $this->withToken($tok)->putJson($url, ['version' => 1, 'data' => ['tomou' => 'nao']])->assertStatus(409);
});

it('modulos sensiveis: desligados por padrao (404), ligados exigem desbloqueio (423), com token gravam e trazem CVV', function () {
    $p = patient();
    $tok = tokenFor($p);
    $url = '/api/v1/days/'.yday().'/blocks/morte';
    $body = ['period' => 'manha', 'data' => ['intensidade' => '1']];
    $this->withToken($tok)->putJson($url, $body)->assertNotFound();
    $this->withToken($tok)->patchJson('/api/v1/account', ['modules' => ['morte', 'sexualidade']])->assertOk()->assertJsonPath('modules', ['morte', 'sexualidade']);
    $this->withToken($tok)->putJson($url, $body)->assertStatus(423);
    $this->withToken($tok)->postJson('/api/v1/private-modules/unlock', ['method' => 'password', 'secret' => 'errada'])->assertUnprocessable();
    $this->withToken($tok)->postJson('/api/v1/private-modules/unlock', ['method' => 'totp', 'secret' => '123456'])->assertUnprocessable();
    $su = $this->withToken($tok)->postJson('/api/v1/private-modules/unlock', ['method' => 'password', 'secret' => 'senha-muito-boa-1'])->assertOk()->json('step_up_token');
    $this->withToken($tok)->withHeader('X-Step-Up', $su)->putJson($url, $body)->assertCreated()
        ->assertJsonPath('crisis_resources.cvv', '188')->assertJsonPath('crisis_resources.samu', '192');
    // sem desbloqueio o dia esconde o bloco e avisa que existe
    $this->withToken($tok)->withHeader('X-Step-Up', '')->getJson('/api/v1/days/'.yday())->assertJsonCount(0, 'data.blocks')->assertJsonPath('data.locked', ['morte']);
    $this->withToken($tok)->withHeader('X-Step-Up', $su)->getJson('/api/v1/days/'.yday())->assertJsonCount(1, 'data.blocks');
    $this->withToken($tok)->getJson('/api/v1/private-modules')->assertOk()->assertJsonPath('data.3.module', 'morte')->assertJsonPath('data.3.enabled', true);
});

it('token de desbloqueio de outra pessoa nao vale', function () {
    $a = patient();
    $b = patient();
    $su = $this->withToken(tokenFor($a))->postJson('/api/v1/private-modules/unlock', ['method' => 'password', 'secret' => 'senha-muito-boa-1'])->json('step_up_token');
    $tb = tokenFor($b);
    $this->withToken($tb)->patchJson('/api/v1/account', ['modules' => ['sexualidade']])->assertOk();
    $this->withToken($tb)->withHeader('X-Step-Up', $su)->putJson('/api/v1/days/'.yday().'/blocks/sexualidade', ['data' => ['desejo' => 1]])->assertStatus(423);
});

it('PIN: define com senha e desbloqueia', function () {
    $p = patient();
    $tok = tokenFor($p);
    $this->withToken($tok)->putJson('/api/v1/account/pin', ['pin' => '4321', 'password' => 'errada'])->assertStatus(422);
    $this->withToken($tok)->putJson('/api/v1/account/pin', ['pin' => '4321', 'password' => 'senha-muito-boa-1'])->assertNoContent();
    $this->withToken($tok)->postJson('/api/v1/private-modules/unlock', ['method' => 'pin', 'secret' => '4321'])->assertOk();
    expect($p->fresh()->pin_hash)->not->toBe('4321');
});

it('cada cartao de humor salva sozinho (humor nao e mais obrigatorio)', function () {
    $tok = tokenFor(patient());
    $this->withToken($tok)->putJson('/api/v1/entries', ['entry_date' => yday(), 'period' => 'noite', 'energy' => 2])->assertCreated()
        ->assertJsonPath('data.mood', null)->assertJsonPath('data.energy', 2);
    $this->withToken($tok)->putJson('/api/v1/entries', ['entry_date' => yday(), 'period' => 'noite', 'mood' => -1, 'version' => 1])->assertOk()
        ->assertJsonPath('data.energy', 2)->assertJsonPath('data.mood', -1);
    $this->withToken($tok)->putJson('/api/v1/entries', ['entry_date' => yday(), 'period' => 'manha'])->assertUnprocessable();
    expect(DayBlock::withoutGlobalScopes()->count())->toBe(0);
});
