<?php

use App\Domain\Legacy\LegacyImporter;
use App\Domain\Legacy\LegacyReader;
use App\Domain\Legacy\LegacyRepair;
use App\Domain\Mood\Scales;
use App\Models\CareGrant;
use App\Models\DayBlock;
use App\Models\Membership;
use App\Models\MoodEntry;
use App\Models\User;
use App\Support\DataKeys;
use App\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * Banco "legado" FICTICIO (estrutura real da 0.5.0, dados inventados) num MySQL separado:
 * - legado_seed: usuario com escrita, so para montar o cenario;
 * - legado: usuario SO COM SELECT (como o Ronaldo vai criar no cPanel), usado pelo importador.
 * Variaveis: LEGACY_TEST_DB (bipolar_legacy_test), LEGACY_TEST_USER/PASS (bp_ro), DB_USERNAME/DB_PASSWORD para o seed.
 */
const LEGACY_TOKEN = 'token-de-teste-com-mais-de-32-caracteres-123';

function legacyConn(string $user, string $pass): array
{
    return ['driver' => 'mysql', 'host' => env('DB_HOST', '127.0.0.1'), 'port' => env('DB_PORT', '3306'),
        'database' => env('LEGACY_TEST_DB', 'bipolar_legacy_test'), 'username' => $user, 'password' => $pass,
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null];
}

/** Soma de controle de todas as linhas das tabelas antigas (para provar que nada mudou). */
function legacyChecksum(): string
{
    $out = '';
    foreach (['users', 'entries', 'daily_markers', 'period_markers', 'sexuality_log', 'reminders', 'care_shares'] as $t) {
        $out .= $t.json_encode(DB::connection('legado_seed')->select("SELECT * FROM `{$t}` ORDER BY id"));
    }

    return hash('sha256', $out);
}

beforeEach(function () {
    config(['database.connections.legado_seed' => legacyConn((string) env('DB_USERNAME', 'bp'), (string) env('DB_PASSWORD', 'bp')),
        'database.connections.legado' => legacyConn((string) env('LEGACY_TEST_USER', 'bp_ro'), (string) env('LEGACY_TEST_PASS', 'bp_ro')),
        'bipolar.installer_token' => LEGACY_TOKEN]);
    DB::purge('legado');
    $seed = DB::connection('legado_seed');
    $seed->statement('SET FOREIGN_KEY_CHECKS=0');
    foreach ($seed->select('SHOW TABLES') as $row) {
        $seed->statement('DROP TABLE `'.array_values((array) $row)[0].'`');
    }
    $seed->statement('SET FOREIGN_KEY_CHECKS=1');
    $sql = (string) file_get_contents(base_path('tests/fixtures/legacy/schema-0.5.0.sql'));
    foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) {
        $seed->statement($stmt);
    }
    $argon = defined('PASSWORD_ARGON2ID') ? password_hash('senha-antiga-argon', PASSWORD_ARGON2ID) : password_hash('senha-antiga-argon', PASSWORD_BCRYPT);
    $seed->table('users')->insert([
        ['id' => 10, 'name' => 'Ana Ficticia', 'email' => 'ana@exemplo.test', 'password_hash' => password_hash('senha-antiga-1', PASSWORD_BCRYPT, ['cost' => 4]),
            'timezone' => 'America/Cuiaba', 'prefs' => json_encode(['modules' => ['substancias' => true, 'sexualidade' => true]]), 'is_professional' => 0, 'mfa_enabled_at' => null],
        ['id' => 11, 'name' => 'Beto Ficticio', 'email' => 'beto@exemplo.test', 'password_hash' => $argon,
            'timezone' => 'America/Sao_Paulo', 'prefs' => null, 'is_professional' => 0, 'mfa_enabled_at' => '2026-10-01 10:00:00'],
        ['id' => 12, 'name' => 'Dra. Carla Ficticia', 'email' => 'carla@exemplo.test', 'password_hash' => password_hash('senha-pro-1', PASSWORD_BCRYPT, ['cost' => 4]),
            'timezone' => 'America/Sao_Paulo', 'prefs' => null, 'is_professional' => 1, 'mfa_enabled_at' => null],
        ['id' => 13, 'name' => 'So Google', 'email' => null, 'password_hash' => null, 'timezone' => 'America/Sao_Paulo', 'prefs' => null, 'is_professional' => 0, 'mfa_enabled_at' => null],
        ['id' => 14, 'name' => 'Sem senha', 'email' => 'semsenha@exemplo.test', 'password_hash' => null, 'timezone' => 'Lua/Base', 'prefs' => null, 'is_professional' => 0, 'mfa_enabled_at' => null],
    ]);
    $seed->table('users')->where('id', 12)->update(['professional_kind' => 'psiquiatra']);
    foreach ([0, 1, 2, 3] as $i => $energy) {
        $seed->table('entries')->insert(['id' => 100 + $i, 'user_id' => 10, 'entry_date' => '2026-09-0'.($i + 1), 'period' => 'manha',
            'mood' => $i - 1, 'energy' => $energy, 'anxiety' => 1, 'irritability' => 2, 'sleep_hours' => 7.5, 'medication_taken' => 1,
            'note' => 'anotacao ficticia '.$i, 'tags' => $i === 0 ? 'trabalho' : null]);
    }
    $seed->table('entries')->insert(['id' => 200, 'user_id' => 13, 'entry_date' => '2026-09-01', 'period' => 'noite', 'mood' => 0]);
    $seed->table('daily_markers')->insert(['id' => 1, 'user_id' => 10, 'entry_date' => '2026-09-01', 'bed_time' => '23:00', 'wake_time' => '07:00',
        'sleep_quality' => 2, 'caffeine_cups' => 3, 'cycle_phase' => 'pre_menstrual', 'nicotine_other' => 'vape_pod']);
    $seed->table('period_markers')->insert(['id' => 1, 'user_id' => 10, 'entry_date' => '2026-09-01', 'period' => 'tarde', 'stress_level' => 2, 'th_racing' => 3, 'th_death' => 1]);
    $seed->table('sexuality_log')->insert(['id' => 1, 'user_id' => 10, 'entry_date' => '2026-09-01', 'libido' => 2, 'different_from_usual' => 1]);
    $seed->table('reminders')->insert([['id' => 1, 'user_id' => 10, 'time_local' => '08:30', 'days' => '1,2,3', 'channel' => 'push', 'active' => 1],
        ['id' => 2, 'user_id' => 10, 'time_local' => '20:00', 'days' => '0', 'channel' => 'email', 'active' => 0]]);
    $seed->table('care_shares')->insert([
        ['id' => 1, 'patient_id' => 10, 'professional_id' => 12, 'status' => 'ativo', 'scope_mask' => 7, 'accepted_at' => '2026-09-02 10:00:00'],
        ['id' => 2, 'patient_id' => 11, 'professional_id' => null, 'status' => 'pendente', 'scope_mask' => 3, 'accepted_at' => null],
    ]);
});

afterEach(fn () => DB::purge('legado'));

function runImport(): array
{
    return app(LegacyImporter::class)->run(60);
}

it('copia contas, registros e vinculos e mostra so contagens', function () {
    $r = runImport();
    expect($r['done'])->toBeTrue();
    $by = collect($r['steps'])->keyBy('step');
    expect($by['users']['copied'])->toBe(4)->and($by['users']['skipped'])->toBe(1)
        ->and($by['entries']['copied'])->toBe(4)->and($by['entries']['skipped'])->toBe(1)
        ->and($by['shares']['copied'])->toBe(1)->and($by['reminders']['copied'])->toBe(1);
    $ana = User::where('email', 'ana@exemplo.test')->firstOrFail();
    expect($ana->timezone)->toBe('America/Cuiaba')->and($ana->moduleOn('sexualidade'))->toBeTrue();
    expect(User::where('email', 'semsenha@exemplo.test')->value('timezone'))->toBe('America/Sao_Paulo');
    $tid = (int) DB::table('memberships')->where('user_id', $ana->id)->where('role', 'patient')->value('tenant_id');
    app(TenantContext::class)->run($tid, function () use ($ana) {
        $e = MoodEntry::where('patient_id', $ana->id)->orderBy('entry_date')->get();
        expect($e)->toHaveCount(4)->and($e[0]->note)->toBe('anotacao ficticia 0')->and($e[0]->medication_taken)->toBeTrue();
        expect(DayBlock::where('patient_id', $ana->id)->pluck('data', 'block')->all())->toMatchArray([
            'sono' => ['deitou' => '23:00', 'acordou' => '07:00', 'qualidade' => '2'],
            'meudia' => ['ciclo' => 'lutea'],
            'substancias' => ['cafe' => 3, 'nicotina' => 'vape'],
            'estresse' => ['nivel' => 2],
            'pensamentos' => ['acelerados' => 3],
            'morte' => ['intensidade' => '1'],
            'sexualidade' => ['desejo' => 3, 'diferente' => true],
        ]);
        $g = CareGrant::firstOrFail();
        expect($g->grantee_kind)->toBe('psiquiatra')->and((int) $g->scope_mask)->toBe(7);
    });
    $rem = json_decode((string) DB::table('alert_settings')->where('patient_id', $ana->id)->value('reminders'), true);
    expect($rem['days'][1]['slots'][0])->toBe(['time' => '08:30', 'period' => 'manha', 'channel' => 'push'])
        ->and($rem['days'][0]['slots'])->toBe([]);
    $carla = User::where('email', 'carla@exemplo.test')->firstOrFail();
    expect(DB::table('memberships')->where('user_id', $carla->id)->pluck('role')->sort()->values()->all())->toBe(['patient', 'professional']);
});

it('converte a energia 0..3 para -3..+3 e guarda o valor antigo', function () {
    runImport();
    $rows = DB::table('mood_entries')->orderBy('entry_date')->get(['energy', 'energy_legacy']);
    expect($rows->map(fn ($r) => [(int) $r->energy_legacy, (int) $r->energy])->all())->toBe([[0, -2], [1, 0], [2, 1], [3, 2]]);
    expect(Scales::energyFromLegacy(null))->toBeNull()->and(Scales::energyFromLegacy(9))->toBeNull();
});

it('e idempotente: rodar de novo nao duplica nada', function () {
    runImport();
    $before = collect(['users', 'tenants', 'memberships', 'mood_entries', 'day_blocks', 'care_grants', 'alert_settings', 'legacy_links'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    DB::table('legacy_import_progress')->update(['last_id' => 0, 'done' => false]); // forca reler tudo
    runImport();
    runImport();
    foreach ($before as $t => $n) {
        expect(DB::table($t)->count())->toBe($n, $t);
    }
});

it('continua de onde parou quando o tempo acaba', function () {
    $partial = app(LegacyImporter::class)->run(0.0);
    expect(DB::table('legacy_import_progress')->count())->toBeGreaterThan(0);
    expect($partial['done'])->toBeFalse();
    $guard = 0;
    do {
        $r = app(LegacyImporter::class)->run(0.0001);
    } while (! $r['done'] && ++$guard < 50);
    expect($r['done'])->toBeTrue()->and(DB::table('mood_entries')->count())->toBe(4);
});

it('senha antiga (bcrypt e argon2id) continua entrando e vira bcrypt; sem senha precisa redefinir', function () {
    runImport();
    $this->post('/entrar', ['email' => 'ana@exemplo.test', 'password' => 'senha-antiga-1'])->assertRedirect('/');
    $this->post('/logout');
    auth()->logout();
    $this->post('/entrar', ['email' => 'beto@exemplo.test', 'password' => 'senha-antiga-argon'])->assertRedirect('/');
    expect((string) DB::table('users')->where('email', 'beto@exemplo.test')->value('password'))->toStartWith('$2y$');
    auth()->logout();
    $this->post('/entrar', ['email' => 'beto@exemplo.test', 'password' => 'errada-123456'])->assertSessionHasErrors('email');
    $this->post('/entrar', ['email' => 'semsenha@exemplo.test', 'password' => ''])->assertSessionHasErrors();
    $notes = collect(runImport()['steps'])->firstWhere('step', 'users')['notes'];
    expect($notes)->toHaveKey('sem senha utilizável: precisa usar "Esqueci minha senha"')
        ->and($notes)->toHaveKey('verificação em duas etapas desligada (ligar de novo em Conta)');
});

it('nunca escreve no banco legado: so SELECT e nada muda', function () {
    $sum = legacyChecksum();
    $sqls = [];
    DB::listen(function (QueryExecuted $q) use (&$sqls) {
        if ($q->connectionName === 'legado') {
            $sqls[] = $q->sql;
        }
    });
    runImport();
    app(LegacyImporter::class)->notCopied();
    expect($sqls)->not->toBeEmpty();
    foreach ($sqls as $s) {
        expect(preg_match('/^\s*(SELECT\s|SET SESSION TRANSACTION READ ONLY$)/i', $s))->toBe(1, $s);
        expect(stripos($s, 'information_schema'))->toBeFalse();
    }
    expect(legacyChecksum())->toBe($sum);
    expect(fn () => app(LegacyReader::class)->select('DELETE FROM users'))->toThrow(LogicException::class);
});

it('pagina /migrar-dados: sem OLD_DB mostra aviso; token errado nao roda; certo roda e mostra contagens', function () {
    config(['database.connections.legado.database' => '']);
    $this->get('/migrar-dados?token='.LEGACY_TOKEN)->assertOk()->assertSee('Faltam as linhas OLD_DB_');
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN])->assertStatus(409)->assertSee('Faltam as linhas OLD_DB_');
    expect(DB::table('users')->count())->toBe(0);

    config(['database.connections.legado' => legacyConn((string) env('LEGACY_TEST_USER', 'bp_ro'), (string) env('LEGACY_TEST_PASS', 'bp_ro'))]);
    DB::purge('legado');
    $this->post('/migrar-dados', ['token' => 'errado'])->assertStatus(403);
    expect(DB::table('users')->count())->toBe(0);
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN])->assertOk()
        ->assertSee('Pronto! Tudo foi copiado.')->assertSee('4 copiados')->assertDontSee('anotacao ficticia');
});

it('pagina desligada sem INSTALLER_TOKEN e a instalacao nao importa nada sozinha', function () {
    config(['bipolar.installer_token' => '']);
    $this->get('/migrar-dados')->assertOk()->assertSee('A página está desligada');
    $this->post('/migrar-dados', ['token' => ''])->assertOk();
    expect(DB::table('users')->count())->toBe(0);
});

/** Simula o bug do 2.0.4: dados copiados cifrados com OUTRA APP_KEY. Devolve a chave "antiga". */
function scrambleWithOtherKey(): string
{
    $key = 'base64:'.base64_encode(random_bytes(32));
    $other = new Encrypter(base64_decode(substr($key, 7)), config('app.cipher'));
    foreach (DB::table('day_blocks')->get(['id', 'data']) as $b) {
        DB::table('day_blocks')->where('id', $b->id)->update(['data' => $other->encryptString(Crypt::decryptString($b->data))]);
    }
    foreach (DB::table('mood_entries')->whereNotNull('note')->get(['id', 'note']) as $e) {
        DB::table('mood_entries')->where('id', $e->id)->update(['note' => $other->encryptString(Crypt::decryptString($e->note))]);
    }

    return $key;
}

it('bug 2.0.4: dado cifrado com outra chave nao derruba a tela (sem 500) e vira vazio', function () {
    app(LegacyImporter::class)->run(60);
    scrambleWithOtherKey();
    $uid = (int) DB::table('legacy_links')->where('kind', 'users')->where('legacy_id', 10)->value('new_id');
    $tid = (int) DB::table('day_blocks')->where('patient_id', $uid)->value('tenant_id');
    app(TenantContext::class)->run($tid, function () use ($uid) {
        expect(DayBlock::where('patient_id', $uid)->first()->data)->toBe([]);
        expect(MoodEntry::where('patient_id', $uid)->first()->note)->toBeNull();
    });
    $u = User::find($uid);
    $this->actingAs($u);
    $res = $this->get('/registrar?data=2026-09-01');
    $res->assertOk();
});

it('consertar dados copiados: com OLD_APP_KEY recifra; sem ela limpa e conta; idempotente', function () {
    app(LegacyImporter::class)->run(60);
    $key = scrambleWithOtherKey();
    $blocks = DB::table('day_blocks')->count();

    config(['bipolar.old_app_key' => 'base64:'.base64_encode(random_bytes(32)).','.$key]);
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'consertar'])->assertOk()
        ->assertSee('consertados com a chave antiga')->assertDontSee('anotacao ficticia');
    $uid = (int) DB::table('legacy_links')->where('kind', 'users')->where('legacy_id', 10)->value('new_id');
    $tid = (int) DB::table('day_blocks')->where('patient_id', $uid)->value('tenant_id');
    app(TenantContext::class)->run($tid, function () use ($uid) {
        expect(MoodEntry::where('patient_id', $uid)->whereNotNull('note')->count())->toBe(4);
        expect(MoodEntry::where('patient_id', $uid)->orderBy('id')->first()->note)->toBe('anotacao ficticia 0');
        expect(DayBlock::where('patient_id', $uid)->get()->every(fn ($b) => $b->data !== []))->toBeTrue();
    });
    $r = app(LegacyRepair::class)->report();
    expect($r['fixed'])->toBe(4 + $blocks)->and($r['cleared'])->toBe(0);

    // sem a chave: limpa o que nao abre e conta
    scrambleWithOtherKey();
    config(['bipolar.old_app_key' => null]);
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'consertar'])->assertOk()
        ->assertSee('não puderam ser lidos sem a chave antiga');
    expect(DB::table('mood_entries')->whereNotNull('note')->count())->toBe(0);
    expect(DB::table('day_blocks')->count())->toBe(0);
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'consertar'])->assertOk();
    expect(app(LegacyRepair::class)->report()['cleared'])->toBe(0);
});

it('copiar de novo tambem roda o conserto', function () {
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN])->assertOk()->assertSee('Pronto!');
    scrambleWithOtherKey();
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN])->assertOk()->assertSee('não puderam ser lidos sem a chave antiga');
    expect(DB::table('mood_entries')->whereNotNull('note')->count())->toBe(0);
});

it('OLD_APP_KEY invalida mostra aviso amigavel sem mostrar a chave e nao faz nada', function (string $bad, string $see) {
    app(LegacyImporter::class)->run(60);
    scrambleWithOtherKey();
    config(['bipolar.old_app_key' => $bad === '__APP__' ? config('app.key') : $bad]);
    $this->get('/migrar-dados?token='.LEGACY_TOKEN)->assertOk()->assertSee($see);
    $res = $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'consertar'])->assertStatus(409)->assertSee($see)->assertSee('Nada foi feito');
    expect($res->getContent())->not->toContain($bad);
    expect(DB::table('mood_entries')->whereNotNull('note')->count())->toBe(4); // nada limpo
})->with([
    'secret_key da 0.5 (hex 64)' => [str_repeat('a1b2c3d4', 8), 'OLD_APP_KEY parece incompleta'],
    'curta' => ['abc123', 'OLD_APP_KEY parece incompleta'],
    'base64 cortada' => ['base64:QUJDREVGR0hJSktM', 'OLD_APP_KEY parece incompleta'],
    'igual a APP_KEY' => ['__APP__', 'igual à APP_KEY atual'],
]);

it('OLD_APP_KEY com aspas e espacos funciona', function () {
    app(LegacyImporter::class)->run(60);
    $key = scrambleWithOtherKey();
    config(['bipolar.old_app_key' => ' "'.$key.'" ']);
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'consertar'])->assertOk();
    expect(app(LegacyRepair::class)->report()['fixed'])->toBeGreaterThan(0);
});

it('registros nativos do 2.0 ilegiveis: tenta OLD_APP_KEY, senao so conta e oferece limpar', function () {
    $u = User::factory()->create();
    $tid = (int) DB::table('tenants')->insertGetId(['kind' => 'personal', 'name' => 'P', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('memberships')->insert(['tenant_id' => $tid, 'user_id' => $u->id, 'role' => Membership::PATIENT, 'created_at' => now(), 'updated_at' => now()]);
    $key = 'base64:'.base64_encode(random_bytes(32));
    $other = new Encrypter(base64_decode(substr($key, 7)), config('app.cipher'));
    foreach (['sono', 'meu_dia', 'substancias'] as $i => $b) {
        DB::table('day_blocks')->insert(['tenant_id' => $tid, 'patient_id' => $u->id, 'entry_date' => '2026-10-0'.($i + 1), 'period' => 'dia', 'block' => $b,
            'data' => $other->encryptString('{"x":1}'), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    // chave valida mas errada: so conta, nao apaga
    config(['bipolar.old_app_key' => 'base64:'.base64_encode(random_bytes(32))]);
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'consertar'])->assertOk()->assertSee('3 anotações/marcadores criados no 2.0')->assertSee('Limpar os 3');
    expect(DB::table('day_blocks')->count())->toBe(3);
    // chave certa: recupera
    config(['bipolar.old_app_key' => $key]);
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'consertar'])->assertOk();
    expect(app(LegacyRepair::class)->report()['fixed'])->toBe(3);
    // sem chave e pedindo limpar
    DB::table('day_blocks')->update(['data' => $other->encryptString('{"x":1}')]);
    config(['bipolar.old_app_key' => null]);
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'limpar'])->assertOk();
    expect(DB::table('day_blocks')->count())->toBe(0);
});

it('qualquer erro na pagina vira mensagem com codigo e vai para o log (sem segredos)', function () {
    app()->instance(LegacyRepair::class, new class extends LegacyRepair
    {
        public function run(float $seconds = 20.0, bool $clean = false): array
        {
            throw new RuntimeException('falhou com '.config('bipolar.installer_token'));
        }
    });
    Log::spy();
    $res = $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'consertar'])->assertStatus(500)->assertSee('código');
    expect($res->getContent())->not->toContain(LEGACY_TOKEN);
    Log::shouldHaveReceived('error')->withArgs(fn ($m, $ctx) => $ctx['type'] === RuntimeException::class
        && ! str_contains($ctx['message'], LEGACY_TOKEN) && strlen($ctx['code']) === 6)->once();
});

it('detecta APP_KEY malformada sem trocar', function () {
    $orig = config('app.key');
    expect(DataKeys::appKeyProblem())->toBeNull();
    config(['app.key' => str_repeat('x', 32)]);
    expect(DataKeys::appKeyProblem())->toBeNull();
    config(['app.key' => 'chave-sem-prefixo-de-40-caracteres-xxxxx']);
    expect(DataKeys::appKeyProblem())->toContain('APP_KEY= do .env parece errada')->not->toContain('chave-sem');
    config(['app.key' => $orig]);
});

it('APP_KEY malformada: /migrar-dados explica e nao da 500', function () {
    config(['app.key' => 'chave-sem-prefixo-de-40-caracteres-xxxxx']);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
    $this->get('/migrar-dados?token='.LEGACY_TOKEN)->assertOk()->assertSee('APP_KEY= do .env parece errada')->assertDontSee('chave-sem-prefixo');
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'consertar'])->assertStatus(409)->assertSee('Nada foi feito');
});

it('copiar tudo de novo: sem chave nenhuma, traz de volta anotacoes e marcadores que nao abrem; idempotente', function () {
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN])->assertOk()->assertSee('Pronto!');
    $blocks = DB::table('day_blocks')->count();
    scrambleWithOtherKey();
    config(['bipolar.old_app_key' => null]);
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'consertar'])->assertOk(); // limpa (situacao do Ronaldo)
    expect(DB::table('mood_entries')->whereNotNull('note')->count())->toBe(0);
    $before = legacyChecksum();
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'recopiar'])->assertOk()->assertSee('Pronto!')->assertDontSee('anotacao ficticia');
    expect(legacyChecksum())->toBe($before);
    $uid = (int) DB::table('legacy_links')->where('kind', 'users')->where('legacy_id', 10)->value('new_id');
    $tid = (int) DB::table('day_blocks')->where('patient_id', $uid)->value('tenant_id');
    app(TenantContext::class)->run($tid, function () use ($uid) {
        expect(MoodEntry::where('patient_id', $uid)->orderBy('id')->first()->note)->toBe('anotacao ficticia 0');
        expect(DayBlock::where('patient_id', $uid)->get()->every(fn ($b) => $b->data !== []))->toBeTrue();
    });
    expect(DB::table('day_blocks')->count())->toBe($blocks)->and(DB::table('mood_entries')->count())->toBe(4);
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'recopiar'])->assertOk();
    expect(DB::table('mood_entries')->count())->toBe(4)->and(DB::table('day_blocks')->count())->toBe($blocks);
});

it('conta do 2.0 com o mesmo e-mail: so junta se o e-mail estiver em OLD_JUNTAR_CONTAS', function () {
    $ana = User::factory()->create(['email' => 'ana@exemplo.test']);
    runImport();
    expect(DB::table('mood_entries')->where('patient_id', $ana->id)->count())->toBe(0);
    config(['bipolar.old_merge_emails' => " 'Ana@Exemplo.test' "]);
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'recopiar'])->assertOk()->assertSee('Pronto!');
    expect(DB::table('mood_entries')->where('patient_id', $ana->id)->count())->toBe(4)
        ->and(DB::table('day_blocks')->where('patient_id', $ana->id)->count())->toBeGreaterThan(0);
});

it('OLD_APP_KEY colada sem base64: tambem funciona', function () {
    app(LegacyImporter::class)->run(60);
    $key = scrambleWithOtherKey();
    config(['bipolar.old_app_key' => substr($key, 7)]);
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN, 'acao' => 'consertar'])->assertOk()->assertSee('consertados com a chave antiga');
    expect(DB::table('mood_entries')->whereNotNull('note')->count())->toBe(4);
});
