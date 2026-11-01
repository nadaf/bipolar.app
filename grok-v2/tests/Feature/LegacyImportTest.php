<?php

use App\Domain\Legacy\LegacyImporter;
use App\Domain\Legacy\LegacyReader;
use App\Domain\Mood\Scales;
use App\Models\CareGrant;
use App\Models\DayBlock;
use App\Models\MoodEntry;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

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
    $this->post('/migrar-dados', ['token' => LEGACY_TOKEN])->assertOk()->assertSee('Faltam as linhas OLD_DB_');
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
