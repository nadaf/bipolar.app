<?php

namespace App\Domain\Legacy;

use App\Domain\Mood\Scales;
use App\Models\Membership;
use App\Models\Tenant;
use App\Support\DataKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Copia os dados do Bipolar 0.5 para o 2.0. Regras:
 * - Banco antigo: SO leitura (LegacyReader). Nada e alterado la.
 * - Idempotente: cada linha antiga copiada fica em legacy_links (tipo + id antigo). Repetir nao duplica.
 * - Em lotes, com limite de tempo por chamada; legacy_import_progress guarda onde parou.
 * - Nunca registra conteudo (anotacoes, valores): so contagens e motivos.
 */
final class LegacyImporter
{
    public const STEPS = [
        'users' => 'Contas',
        'entries' => 'Registros de humor, energia, sono, medicação e anotações',
        'daily' => 'Marcadores do dia (sono, meu dia, substâncias)',
        'period' => 'Marcadores por período (estresse, alimentação, pensamentos)',
        'sexuality' => 'Sexualidade',
        'reminders' => 'Lembretes e configurações de alerta',
        'shares' => 'Compartilhamentos com profissionais',
    ];

    private const BATCH = 200;

    /** Modulos opcionais 0.5 -> 2.0 */
    private const MODULES = ['substancias' => 'substancias', 'sexualidade' => 'sexualidade', 'pensamentos_morte' => 'morte', 'religiosidade' => 'religiosidade'];

    private const CYCLE = ['menstruacao' => 'menstruacao', 'folicular' => 'folicular', 'ovulacao' => 'ovulacao', 'pre_menstrual' => 'lutea'];

    private const NICOTINE = ['nenhuma' => 'nenhuma', 'vape_pod' => 'vape', 'narguile' => 'narguile', 'adesivo_chiclete' => 'adesivo', 'palha' => 'outra', 'charuto_cachimbo' => 'outra'];

    /** Desejo 0.5 (-2..2) -> 2.0 (-3..3), extremos para os extremos. */
    private const LIBIDO = [-2 => -3, -1 => -1, 0 => 0, 1 => 1, 2 => 3];

    /** @var array<string, int> */
    private array $notes = [];

    private int $copied = 0;

    private int $skipped = 0;

    public function __construct(private LegacyReader $old) {}

    /** Tabelas de controle existem no banco 2.0 (migration rodada pelo atualizador)? */
    public static function ready(): bool
    {
        return Schema::hasTable('legacy_links') && Schema::hasTable('legacy_import_progress');
    }

    /**
     * Roda ate terminar ou ate $seconds. Retorna o resumo de todas as etapas.
     *
     * @return array{done: bool, steps: list<array{step: string, label: string, done: bool, copied: int, skipped: int, notes: array<string, int>}>}
     */
    public function run(float $seconds = 20.0): array
    {
        $deadline = microtime(true) + $seconds;
        $first = true; // cada chamada anda pelo menos um lote (sempre progride)
        foreach (array_keys(self::STEPS) as $step) {
            $p = $this->progress($step);
            while (! $p->done && ($first || microtime(true) < $deadline)) {
                $first = false;
                $this->notes = (array) json_decode((string) $p->notes, true);
                $this->copied = 0;
                $this->skipped = 0;
                [$lastId, $more] = $this->batch($step, (int) $p->last_id);
                DB::table('legacy_import_progress')->where('step', $step)->update([
                    'last_id' => $lastId, 'done' => ! $more,
                    'copied' => (int) $p->copied + $this->copied, 'skipped' => (int) $p->skipped + $this->skipped,
                    'notes' => json_encode($this->notes), 'updated_at' => now(),
                ]);
                $p = $this->progress($step);
            }
            if (! $p->done) {
                break;
            }
        }

        return $this->report();
    }

    /** @return array{done: bool, steps: list<array{step: string, label: string, done: bool, copied: int, skipped: int, notes: array<string, int>}>} */
    public function report(): array
    {
        $steps = [];
        $all = true;
        foreach (self::STEPS as $step => $label) {
            $p = DB::table('legacy_import_progress')->where('step', $step)->first();
            $done = (bool) ($p->done ?? false);
            $all = $all && $done;
            $steps[] = ['step' => $step, 'label' => $label, 'done' => $done, 'copied' => (int) ($p->copied ?? 0),
                'skipped' => (int) ($p->skipped ?? 0), 'notes' => (array) json_decode((string) ($p->notes ?? ''), true)];
        }

        return ['done' => $all, 'steps' => $steps];
    }

    private function progress(string $step): object
    {
        DB::table('legacy_import_progress')->insertOrIgnore(['step' => $step, 'last_id' => 0, 'done' => false, 'updated_at' => now()]);

        return DB::table('legacy_import_progress')->where('step', $step)->first();
    }

    private function note(string $reason, int $n = 1): void
    {
        $this->notes[$reason] = ($this->notes[$reason] ?? 0) + $n;
    }

    private function skip(string $reason): void
    {
        $this->skipped++;
        $this->note($reason);
    }

    /** @return array{0: int, 1: bool} [ultimo id lido, ha mais] */
    private function batch(string $step, int $after): array
    {
        $table = ['users' => 'users', 'entries' => 'entries', 'daily' => 'daily_markers', 'period' => 'period_markers',
            'sexuality' => 'sexuality_log', 'reminders' => 'reminders', 'shares' => 'care_shares'][$step];
        if (! $this->old->hasTable($table)) {
            return [$after, false];
        }
        $rows = $this->old->select("SELECT * FROM `{$table}` WHERE id > ? ORDER BY id LIMIT ".self::BATCH, [$after]);
        foreach ($rows as $r) {
            $after = (int) $r->id;
            if ($this->linked($step, $after) !== false) {
                continue; // ja copiado numa rodada anterior
            }
            DB::transaction(fn () => $this->{'import'.ucfirst($step)}($r));
        }

        return [$after, count($rows) === self::BATCH];
    }

    /** id novo, null (vinculado sem destino) ou false (nao vinculado) */
    private function linked(string $kind, int $legacyId): int|null|false
    {
        $l = DB::table('legacy_links')->where('kind', $kind)->where('legacy_id', $legacyId)->first();

        return $l === null ? false : ($l->new_id === null ? null : (int) $l->new_id);
    }

    private function link(string $kind, int $legacyId, ?int $newId): void
    {
        DB::table('legacy_links')->insert(['kind' => $kind, 'legacy_id' => $legacyId, 'new_id' => $newId, 'created_at' => now()]);
    }

    /** @return array{0: int, 1: int}|null [user_id, tenant_id pessoal] */
    private function owner(int $legacyUserId): ?array
    {
        $uid = $this->linked('users', $legacyUserId);
        if (! is_int($uid)) {
            return null;
        }
        $m = DB::table('memberships')->join('tenants', 'tenants.id', '=', 'memberships.tenant_id')
            ->where('memberships.user_id', $uid)->where('memberships.role', Membership::PATIENT)
            ->where('tenants.kind', Tenant::PERSONAL)->orderBy('memberships.id')->first(['memberships.tenant_id']);

        return $m === null ? null : [$uid, (int) $m->tenant_id];
    }

    private function int(mixed $v, int $min, int $max): ?int
    {
        if ($v === null || ! is_numeric($v)) {
            return null;
        }
        $i = (int) $v;

        return $i < $min || $i > $max ? null : $i;
    }

    // ---------------------------------------------------------------- etapas

    private function importUsers(object $r): void
    {
        $email = strtolower(trim((string) $r->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->link('users', (int) $r->id, null);
            $this->skip('conta sem e-mail (entrava só com Google/Apple): não copiada');

            return;
        }
        if (DB::table('users')->where('email', $email)->exists()) {
            $this->link('users', (int) $r->id, null);
            $this->skip('e-mail já tinha conta no 2.0: conta e dados não copiados');

            return;
        }
        $hash = (string) $r->password_hash;
        $info = $hash === '' ? ['algoName' => 'unknown'] : password_get_info($hash);
        if (in_array($info['algoName'], ['bcrypt', 'argon2i', 'argon2id'], true)) {
            $this->note('senha antiga mantida (entra com a mesma senha)');
        } else {
            $hash = password_hash(Str::random(40), PASSWORD_BCRYPT); // ninguem sabe: usar "Esqueci minha senha"
            $this->note('sem senha utilizável: precisa usar "Esqueci minha senha"');
        }
        if (! empty($r->mfa_enabled_at)) {
            $this->note('verificação em duas etapas desligada (ligar de novo em Conta)');
        }
        $tz = in_array((string) $r->timezone, timezone_identifiers_list(), true) ? (string) $r->timezone : 'America/Sao_Paulo';
        $prefs = json_decode((string) $r->prefs, true);
        $modules = null;
        if (is_array($prefs) && is_array($prefs['modules'] ?? null)) {
            $on = [];
            foreach (self::MODULES as $old => $new) {
                if (! empty($prefs['modules'][$old])) {
                    $on[] = $new;
                }
            }
            $modules = implode(',', $on);
        }
        $now = now();
        $created = $r->created_at ?: $now;
        $uid = DB::table('users')->insertGetId([
            'name' => mb_substr(trim((string) $r->name) ?: 'Sem nome', 0, 150), 'email' => $email,
            'email_verified_at' => $r->email_verified_at, 'password' => $hash, 'timezone' => $tz,
            'modules' => $modules, 'legacy_id' => (int) $r->id, 'created_at' => $created, 'updated_at' => $now,
        ]);
        $tid = DB::table('tenants')->insertGetId(['kind' => Tenant::PERSONAL, 'name' => 'Pessoal', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('memberships')->insert(['tenant_id' => $tid, 'user_id' => $uid, 'role' => Membership::PATIENT, 'created_at' => $now, 'updated_at' => $now]);
        if ((int) $r->is_professional === 1) {
            $sid = DB::table('tenants')->insertGetId(['kind' => Tenant::SOLO, 'name' => mb_substr(trim((string) $r->name) ?: 'Profissional', 0, 150), 'created_at' => $now, 'updated_at' => $now]);
            DB::table('memberships')->insert(['tenant_id' => $sid, 'user_id' => $uid, 'role' => Membership::PROFESSIONAL, 'created_at' => $now, 'updated_at' => $now]);
            $this->note('contas de profissional (também ganham um espaço de profissional)');
        }
        DB::table('alert_settings')->insertOrIgnore(['tenant_id' => $tid, 'patient_id' => $uid, 'mood_low' => -2, 'mood_high' => 2,
            'energy_low' => -2, 'energy_high' => 2, 'created_at' => $now, 'updated_at' => $now]);
        $this->link('users', (int) $r->id, $uid);
        $this->copied++;
    }

    private function importEntries(object $r): void
    {
        $o = $this->owner((int) $r->user_id);
        if ($o === null) {
            $this->link('entries', (int) $r->id, null);
            $this->skip('dono do registro não foi copiado');

            return;
        }
        [$uid, $tid] = $o;
        $mood = $this->int($r->mood, -3, 3);
        $legacyEnergy = $this->int($r->energy, 0, 3);
        if ($r->energy !== null && $legacyEnergy === null) {
            $this->note('energia fora da escala antiga: deixada em branco');
        }
        if (! empty($r->tags)) {
            $this->note('etiquetas (tags) não existem no 2.0: não copiadas');
        }
        $uuid = $r->client_uuid !== null && Str::isUuid((string) $r->client_uuid) ? (string) $r->client_uuid : null;
        $note = trim((string) $r->note);
        $id = DB::table('mood_entries')->insertGetId([
            'tenant_id' => $tid, 'patient_id' => $uid, 'version' => max(1, (int) $r->version),
            'entry_date' => $r->entry_date, 'period' => (string) $r->period, 'mood' => $mood,
            'energy' => Scales::energyFromLegacy($legacyEnergy), 'energy_legacy' => $legacyEnergy,
            'anxiety' => $this->int($r->anxiety, 0, 3), 'irritability' => $this->int($r->irritability, 0, 3),
            'sleep_hours' => is_numeric($r->sleep_hours) && $r->sleep_hours >= 0 && $r->sleep_hours <= 24 ? $r->sleep_hours : null,
            'medication_taken' => $r->medication_taken === null ? null : (int) ((bool) $r->medication_taken),
            'note' => $note === '' ? null : DataKeys::seal(mb_substr($note, 0, 5000)),
            'client_uuid' => $uuid, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
        ]);
        $this->link('entries', (int) $r->id, $id);
        $this->copied++;
    }

    /** Grava blocos do dia (so os que tem algum valor). @param array<string, array<string, mixed>> $blocks */
    private function blocks(int $tid, int $uid, string $date, string $period, array $blocks, mixed $updated): int
    {
        $n = 0;
        foreach ($blocks as $block => $data) {
            $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
            if ($data === []) {
                continue;
            }
            $n += DB::table('day_blocks')->insertOrIgnore([
                'tenant_id' => $tid, 'patient_id' => $uid, 'entry_date' => $date, 'period' => $period, 'block' => $block,
                'data' => DataKeys::seal((string) json_encode($data)), 'version' => 1,
                'created_at' => $updated ?: now(), 'updated_at' => $updated ?: now(),
            ]);
        }

        return $n;
    }

    private function dayRow(string $kind, object $r, callable $build): void
    {
        $o = $this->owner((int) $r->user_id);
        if ($o === null) {
            $this->link($kind, (int) $r->id, null);
            $this->skip('dono do registro não foi copiado');

            return;
        }
        [$uid, $tid] = $o;
        [$period, $blocks] = $build($r);
        $n = $this->blocks($tid, $uid, (string) $r->entry_date, $period, $blocks, $r->updated_at ?? null);
        $this->link($kind, (int) $r->id, null);
        if ($n === 0) {
            $this->skip('linha sem nenhum valor preenchido');
        } else {
            $this->copied++;
        }
    }

    private function importDaily(object $r): void
    {
        $this->dayRow('daily', $r, function (object $r) {
            $hhmm = fn ($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
            $q = $this->int($r->sleep_quality, 0, 3);
            $min = fn ($v) => $v === null ? null : min(720, max(0, (int) $v));

            return ['dia', [
                'sono' => ['deitou' => $hhmm($r->bed_time), 'acordou' => $hhmm($r->wake_time), 'qualidade' => $q === null ? null : (string) $q],
                'meudia' => ['sol' => $min($r->sun_minutes), 'exercicio' => $min($r->exercise_minutes),
                    'gastos' => $this->int($r->impulsive_spending, 0, 3), 'fala' => $this->int($r->fast_speech, 0, 3),
                    'ciclo' => self::CYCLE[(string) $r->cycle_phase] ?? null],
                'substancias' => ['cafe' => $this->int($r->caffeine_cups, 0, 30), 'cigarros' => $this->int($r->cigarettes, 0, 200),
                    'alcool' => $this->int($r->alcohol_units, 0, 50), 'nicotina' => self::NICOTINE[(string) ($r->nicotine_other ?? '')] ?? null,
                    'outras' => $r->substances === null ? null : mb_substr((string) $r->substances, 0, 200)],
            ]];
        });
    }

    private function importPeriod(object $r): void
    {
        $this->dayRow('period', $r, function (object $r) {
            $i = fn ($v) => $this->int($v, 0, 3);
            $death = $i($r->th_death);
            $rel = property_exists($r, 'religiosity') ? $i($r->religiosity) : null;

            return [in_array($r->period, ['manha', 'tarde', 'noite'], true) ? (string) $r->period : 'manha', [
                'estresse' => ['nivel' => $i($r->stress_level), 'eventos' => $r->stress_note === null ? null : mb_substr((string) $r->stress_note, 0, 500)],
                'alimentacao' => ['doces' => $i($r->food_sweets), 'ultraprocessados' => $i($r->food_processed), 'compulsao' => $i($r->food_binge)],
                'pensamentos' => ['positivos' => $i($r->th_positive), 'negativos' => $i($r->th_negative), 'acelerados' => $i($r->th_racing ?? null),
                    'grandiosos' => $i($r->th_grandiose), 'ruminacao' => $i($r->th_rumination)],
                'religiosidade' => ['intensidade' => $rel],
                'morte' => ['intensidade' => $death === null ? null : (string) $death],
            ]];
        });
    }

    private function importSexuality(object $r): void
    {
        $this->dayRow('sexuality', $r, function (object $r) {
            $lib = $this->int($r->libido, -2, 2);
            if (! empty($r->activities) || $r->frequency !== null || $r->online_minutes !== null) {
                $this->note('sexualidade: atividades/frequência sem campo igual no 2.0, não copiadas');
            }

            return ['dia', ['sexualidade' => [
                'desejo' => $lib === null ? null : self::LIBIDO[$lib],
                'diferente' => $r->different_from_usual === null ? null : (bool) $r->different_from_usual,
                'nota' => $r->note === null ? null : mb_substr((string) $r->note, 0, 1000),
            ]]];
        });
    }

    private function importReminders(object $r): void
    {
        $o = $this->owner((int) $r->user_id);
        $time = (string) $r->time_local;
        if ($o === null || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $this->link('reminders', (int) $r->id, null);
            $this->skip($o === null ? 'dono do registro não foi copiado' : 'lembrete com horário inválido');

            return;
        }
        [$uid, $tid] = $o;
        $this->link('reminders', (int) $r->id, null);
        if (! (int) $r->active) {
            $this->skip('lembrete estava desligado');

            return;
        }
        $s = DB::table('alert_settings')->where('tenant_id', $tid)->where('patient_id', $uid)->first();
        $grid = json_decode((string) ($s->reminders ?? ''), true);
        $days = is_array($grid['days'] ?? null) && count($grid['days']) === 7 ? $grid['days'] : array_map(fn ($w) => ['weekday' => $w, 'slots' => []], range(0, 6));
        $h = (int) substr($time, 0, 2);
        $slot = ['time' => $time, 'period' => $h < 12 ? 'manha' : ($h < 18 ? 'tarde' : 'noite'),
            'channel' => in_array($r->channel, ['push', 'email', 'ambos'], true) ? (string) $r->channel : 'push'];
        foreach (array_unique(array_map('intval', explode(',', (string) $r->days))) as $w) {
            if ($w >= 0 && $w <= 6 && count($days[$w]['slots']) < 6) {
                $days[$w]['slots'][] = $slot;
            }
        }
        DB::table('alert_settings')->updateOrInsert(['tenant_id' => $tid, 'patient_id' => $uid],
            ['reminders' => json_encode(['days' => $days]), 'updated_at' => now()]);
        $this->copied++;
    }

    private function importShares(object $r): void
    {
        $this->link('shares', (int) $r->id, null);
        $o = $this->owner((int) $r->patient_id);
        $pro = $r->professional_id === null ? false : $this->linked('users', (int) $r->professional_id);
        if ($r->status !== 'ativo' || $r->ended_at !== null) {
            $this->skip('convite pendente ou compartilhamento encerrado: não copiado (convidar de novo)');

            return;
        }
        if ($r->expires_on !== null && (string) $r->expires_on < now()->toDateString()) {
            $this->skip('compartilhamento vencido');

            return;
        }
        if ($o === null || ! is_int($pro)) {
            $this->skip('paciente ou profissional não foi copiado');

            return;
        }
        $kind = DB::table('users')->where('id', $pro)->value('legacy_id');
        $legacyKind = $this->old->select('SELECT professional_kind FROM `users` WHERE id = ?', [(int) $kind])[0]->professional_kind ?? null;
        $now = now();
        $id = DB::table('care_grants')->insertGetId([
            'tenant_id' => $o[1], 'patient_id' => $o[0], 'grantee_user_id' => $pro,
            'grantee_kind' => in_array($legacyKind, ['psicologo', 'psiquiatra'], true) ? $legacyKind : 'outro',
            'scope_mask' => (int) $r->scope_mask, 'data_from' => $r->data_from,
            'expires_at' => $r->expires_on === null ? null : $r->expires_on.' 23:59:59',
            'accepted_at' => $r->accepted_at ?? $now, 'created_at' => $r->created_at ?? $now, 'updated_at' => $now,
        ]);
        DB::table('legacy_links')->where('kind', 'shares')->where('legacy_id', (int) $r->id)->update(['new_id' => $id]);
        $this->copied++;
    }

    /** O que NAO e copiado, com quantidade no banco antigo. @return array<string, int> */
    public function notCopied(): array
    {
        $out = [];
        foreach (['substance_log' => 'Detalhes de substâncias por dose (o total do dia é copiado)', 'alert_rules' => 'Alertas personalizados',
            'alerts' => 'Histórico de alertas', 'recipients' => 'Relatórios por e-mail (cadastrar de novo)', 'media' => 'Áudios, vídeos e imagens',
            'push_subscriptions' => 'Notificações no aparelho (ativar de novo)', 'webauthn_credentials' => 'Chaves de acesso (passkeys)'] as $t => $label) {
            if ($this->old->hasTable($t)) {
                $n = $this->old->count($t);
                if ($n > 0) {
                    $out[$label] = $n;
                }
            }
        }

        return $out;
    }
}
