<?php

namespace App\Domain\Legacy;

use App\Support\DataKeys;
use Illuminate\Support\Facades\DB;

/**
 * "Consertar dados copiados": rele so as linhas trazidas da 0.5 (legacy_links) e garante que
 * todo campo cifrado abre com a APP_KEY atual:
 * - abre com a atual: nada a fazer;
 * - abre com OLD_APP_KEY: recifra com a atual;
 * - nao abre: limpa o campo (anotacao vira vazia; marcador do dia ilegivel e removido).
 * Idempotente. Em lotes com limite de tempo. Nunca registra conteudo, so contagens.
 */
final class LegacyRepair
{
    private const BATCH = 300;

    /** @return array{done: bool, ok: int, fixed: int, cleared: int} */
    public function run(float $seconds = 20.0): array
    {
        $deadline = microtime(true) + $seconds;
        $first = true;
        foreach (['fix_notes', 'fix_blocks'] as $step) {
            $p = $this->progress($step);
            while (! $p->done && ($first || microtime(true) < $deadline)) {
                $first = false;
                $c = ['ok' => 0, 'fixed' => 0, 'cleared' => 0];
                [$last, $more] = $step === 'fix_notes' ? $this->notes((int) $p->last_id, $c) : $this->blocks((int) $p->last_id, $c);
                $n = (array) json_decode((string) $p->notes, true);
                foreach ($c as $k => $v) {
                    $n[$k] = ($n[$k] ?? 0) + $v;
                }
                DB::table('legacy_import_progress')->where('step', $step)->update(['last_id' => $last, 'done' => ! $more, 'notes' => json_encode($n), 'updated_at' => now()]);
                $p = $this->progress($step);
            }
            if (! $p->done) {
                break;
            }
        }

        return $this->report();
    }

    /** Recomeca a verificacao (se a anterior ja terminou). */
    public function restartIfDone(): void
    {
        if ($this->report()['done']) {
            DB::table('legacy_import_progress')->whereIn('step', ['fix_notes', 'fix_blocks'])
                ->update(['last_id' => 0, 'done' => false, 'notes' => null, 'updated_at' => now()]);
        }
    }

    /** @return array{done: bool, ok: int, fixed: int, cleared: int} */
    public function report(): array
    {
        $r = ['done' => true, 'ok' => 0, 'fixed' => 0, 'cleared' => 0];
        foreach (['fix_notes', 'fix_blocks'] as $step) {
            $p = DB::table('legacy_import_progress')->where('step', $step)->first();
            $r['done'] = $r['done'] && (bool) ($p->done ?? false);
            foreach ((array) json_decode((string) ($p->notes ?? ''), true) as $k => $v) {
                $r[$k] = ($r[$k] ?? 0) + (int) $v;
            }
        }

        return $r;
    }

    /** @return string|null|false texto aberto, null se ilegivel; false = ja esta ok */
    private function open(string $payload): string|null|false
    {
        if (DataKeys::current($payload) !== null) {
            return false;
        }

        return DataKeys::withOld($payload);
    }

    /** @param array<string, int> $c @return array{0: int, 1: bool} */
    private function notes(int $after, array &$c): array
    {
        $rows = DB::table('mood_entries')->join('legacy_links', fn ($j) => $j->on('legacy_links.new_id', '=', 'mood_entries.id')->where('legacy_links.kind', 'entries'))
            ->where('mood_entries.id', '>', $after)->orderBy('mood_entries.id')->limit(self::BATCH)
            ->get(['mood_entries.id', 'mood_entries.note']);
        foreach ($rows as $r) {
            $after = (int) $r->id;
            if ($r->note === null) {
                continue;
            }
            $plain = $this->open((string) $r->note);
            if ($plain === false) {
                $c['ok']++;
            } elseif ($plain === null) {
                DB::table('mood_entries')->where('id', $r->id)->update(['note' => null]);
                $c['cleared']++;
            } else {
                DB::table('mood_entries')->where('id', $r->id)->update(['note' => DataKeys::seal($plain)]);
                $c['fixed']++;
            }
        }

        return [$after, count($rows) === self::BATCH];
    }

    /** @param array<string, int> $c @return array{0: int, 1: bool} */
    private function blocks(int $after, array &$c): array
    {
        $rows = DB::table('day_blocks')->whereIn('patient_id', DB::table('legacy_links')->where('kind', 'users')->whereNotNull('new_id')->select('new_id'))
            ->where('id', '>', $after)->orderBy('id')->limit(self::BATCH)->get(['id', 'data']);
        foreach ($rows as $r) {
            $after = (int) $r->id;
            $plain = $this->open((string) $r->data);
            if ($plain === false) {
                $c['ok']++;
            } elseif ($plain === null || ! is_array(json_decode($plain, true))) {
                DB::table('day_blocks')->where('id', $r->id)->delete();
                $c['cleared']++;
            } else {
                DB::table('day_blocks')->where('id', $r->id)->update(['data' => DataKeys::seal($plain)]);
                $c['fixed']++;
            }
        }

        return [$after, count($rows) === self::BATCH];
    }

    private function progress(string $step): object
    {
        DB::table('legacy_import_progress')->insertOrIgnore(['step' => $step, 'last_id' => 0, 'done' => false, 'updated_at' => now()]);

        return DB::table('legacy_import_progress')->where('step', $step)->first();
    }
}
