<?php

use App\Models\Membership;
use App\Models\MoodEntry;
use App\Models\PersonalAccessToken;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Models com tenant_id que NAO usam o trait (documentados no AGENTS.md). */
// Excecoes documentadas: Membership resolve o tenant; o token so GUARDA o tenant de emissao
// (consultado pelo hash do token antes de existir contexto).
const TENANT_TRAIT_EXCEPTIONS = [Membership::class, PersonalAccessToken::class];

it('todo model Eloquent com tenant_id usa BelongsToTenant', function () {
    $missing = [];
    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        if (! is_subclass_of($class, Model::class) || in_array($class, TENANT_TRAIT_EXCEPTIONS, true)) {
            continue;
        }
        $table = (new $class)->getTable();
        $mig = implode("\n", array_map('file_get_contents', glob(database_path('migrations/*.php'))));
        $tableHasTenant = (bool) preg_match("/Schema::create\\('{$table}'.*?\\}\\);/s", $mig, $m) && str_contains($m[0], "'tenant_id'");
        if ($tableHasTenant && ! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            $missing[] = $class;
        }
    }
    expect($missing)->toBe([]);
    // sanidade: o teste realmente enxerga as tabelas com tenant_id
    expect(class_uses_recursive(MoodEntry::class))->toContain(BelongsToTenant::class);
});

arch('controllers nao acessam o banco cru')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB']);

arch('sem funcoes de debug')
    ->expect(['dd', 'dump', 'var_dump', 'ray', 'print_r'])
    ->not->toBeUsed();

arch('modelos tenant usam o trait')
    ->expect(['App\Models\MoodEntry', 'App\Models\CareGrant', 'App\Models\AuditLog', 'App\Models\AlertSetting', 'App\Models\DayBlock'])
    ->toUseTrait(BelongsToTenant::class);

it('toda tabela real com tenant_id tem model com BelongsToTenant', function () {
    $this->artisan('migrate:fresh');
    $byTable = [];
    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        if (is_subclass_of($class, Model::class)) {
            $byTable[(new $class)->getTable()] = $class;
        }
    }
    $bad = [];
    foreach (Schema::getTableListing(schemaQualified: false) as $t) {
        if (! Schema::hasColumn($t, 'tenant_id')) {
            continue;
        }
        $class = $byTable[$t] ?? null;
        if ($class === null || (! in_array($class, TENANT_TRAIT_EXCEPTIONS, true) && ! in_array(BelongsToTenant::class, class_uses_recursive($class), true))) {
            $bad[] = $t;
        }
    }
    expect($bad)->toBe([]);
});
