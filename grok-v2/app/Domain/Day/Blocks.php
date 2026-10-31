<?php

namespace App\Domain\Day;

use App\Domain\Care\CareScope;
use App\Domain\Mood\Scales;

/**
 * Catalogo UNICO dos blocos do Registrar (fora humor/energia/ansiedade, que ficam em mood_entries).
 * Usado pela API, pela tela, pelos relatorios e pelo compartilhamento.
 *
 * - per: 'periodo' (Manha/Tarde/Noite) ou 'dia' (um por dia)
 * - scope: bit de CareScope (o que o paciente pode compartilhar)
 * - step_up: exige desbloqueio extra (PIN ou senha) para ver e gravar
 * - intimate: nunca fica guardado no aparelho (sem fila offline)
 * - module: modulo opcional (null = sempre ligado); default_on = ligado para quem nunca mexeu
 */
final class Blocks
{
    public const SPEC = [
        'sono' => ['title' => 'Sono', 'per' => 'dia', 'scope' => CareScope::SLEEP, 'fields' => [
            'horas' => ['type' => 'decimal', 'label' => 'Horas dormidas', 'min' => 0, 'max' => 24, 'step' => 0.5],
            'deitou' => ['type' => 'time', 'label' => 'Hora em que deitou'],
            'acordou' => ['type' => 'time', 'label' => 'Hora em que acordou'],
            'qualidade' => ['type' => 'choice', 'label' => 'Qualidade do sono', 'options' => ['0' => 'Muito ruim', '1' => 'Ruim', '2' => 'Boa', '3' => 'Muito boa']],
        ]],
        'estresse' => ['title' => 'Estresse e eventos', 'per' => 'periodo', 'scope' => CareScope::STRESS_FOOD, 'fields' => [
            'nivel' => ['type' => 'intensity', 'label' => 'Estresse neste período'],
            'eventos' => ['type' => 'text', 'label' => 'O que aconteceu? (opcional)', 'max' => 500],
        ]],
        'alimentacao' => ['title' => 'Alimentação', 'per' => 'periodo', 'scope' => CareScope::STRESS_FOOD, 'fields' => [
            'doces' => ['type' => 'intensity', 'label' => 'Doces'],
            'ultraprocessados' => ['type' => 'intensity', 'label' => 'Ultraprocessados'],
            'compulsao' => ['type' => 'intensity', 'label' => 'Compulsão (comer sem conseguir parar)'],
        ]],
        'medicacao' => ['title' => 'Medicação', 'per' => 'dia', 'scope' => CareScope::MOOD, 'fields' => [
            'tomou' => ['type' => 'choice', 'label' => 'Remédios de hoje', 'options' => ['sim' => 'Tomei todos', 'parcial' => 'Tomei parte', 'nao' => 'Não tomei', 'sem' => 'Não uso remédio']],
            'obs' => ['type' => 'text', 'label' => 'Observação (opcional)', 'max' => 300],
        ]],
        'substancias' => ['title' => 'Substâncias', 'per' => 'dia', 'scope' => CareScope::SUBSTANCES, 'intimate' => true, 'module' => 'substancias', 'default_on' => true, 'fields' => [
            'cafe' => ['type' => 'int', 'label' => 'Café (xícaras)', 'min' => 0, 'max' => 30],
            'cigarros' => ['type' => 'int', 'label' => 'Cigarros', 'min' => 0, 'max' => 200],
            'nicotina' => ['type' => 'choice', 'label' => 'Outra forma de nicotina', 'options' => ['nenhuma' => 'Nenhuma', 'vape' => 'Vape / pod', 'narguile' => 'Narguilé', 'adesivo' => 'Adesivo ou goma', 'outra' => 'Outra']],
            'alcool' => ['type' => 'int', 'label' => 'Álcool (doses)', 'min' => 0, 'max' => 50],
            'outras' => ['type' => 'text', 'label' => 'Outras substâncias (opcional)', 'max' => 200],
            'acima' => ['type' => 'bool', 'label' => 'Mais que o meu habitual'],
        ]],
        'pensamentos' => ['title' => 'Pensamentos', 'per' => 'periodo', 'scope' => CareScope::THOUGHTS, 'fields' => [
            'positivos' => ['type' => 'intensity', 'label' => 'Pensamentos positivos'],
            'negativos' => ['type' => 'intensity', 'label' => 'Pensamentos negativos'],
            'acelerados' => ['type' => 'intensity', 'label' => 'Pensamentos acelerados'],
            'grandiosos' => ['type' => 'intensity', 'label' => 'Ideias de grandeza'],
            'ruminacao' => ['type' => 'intensity', 'label' => 'Ruminação (pensar na mesma coisa)'],
        ]],
        'religiosidade' => ['title' => 'Religiosidade', 'per' => 'periodo', 'scope' => CareScope::RELIGION, 'module' => 'religiosidade', 'default_on' => true, 'fields' => [
            'intensidade' => ['type' => 'intensity', 'label' => 'Envolvimento religioso ou espiritual acima do seu habitual'],
        ]],
        'sexualidade' => ['title' => 'Sexualidade', 'per' => 'dia', 'scope' => CareScope::SEXUALITY, 'step_up' => true, 'intimate' => true, 'module' => 'sexualidade', 'fields' => [
            'desejo' => ['type' => 'bipolar', 'label' => 'Desejo sexual comparado ao seu normal', 'labels' => [
                -3 => 'Muito abaixo', -2 => 'Abaixo', -1 => 'Um pouco abaixo', 0 => 'Normal', 1 => 'Um pouco acima', 2 => 'Acima', 3 => 'Muito acima']],
            'atividades' => ['type' => 'multi', 'label' => 'O que aconteceu hoje (opcional)', 'options' => [
                'relacao' => 'Relação sexual', 'masturbacao' => 'Masturbação', 'online' => 'Conteúdo ou conversa online', 'risco' => 'Situação de risco', 'nenhuma' => 'Nada']],
            'diferente' => ['type' => 'bool', 'label' => 'Isso é diferente do meu habitual'],
            'nota' => ['type' => 'text', 'label' => 'Anotação (opcional)', 'max' => 1000],
        ]],
        'morte' => ['title' => 'Pensamentos de morte', 'per' => 'periodo', 'scope' => CareScope::DEATH, 'step_up' => true, 'intimate' => true, 'module' => 'morte', 'fields' => [
            'intensidade' => ['type' => 'choice', 'label' => 'Pensamentos de morte neste período', 'options' => [
                '0' => 'Nenhum', '1' => 'Passaram pela cabeça', '2' => 'Frequentes', '3' => 'Intensos']],
        ]],
        'anotacao' => ['title' => 'Anotação', 'per' => 'dia', 'scope' => CareScope::THOUGHTS, 'intimate' => true, 'fields' => [
            'texto' => ['type' => 'text', 'label' => 'Anotação livre (privada)', 'max' => 5000, 'rows' => 5],
        ]],
        'meudia' => ['title' => 'Meu dia', 'per' => 'dia', 'scope' => CareScope::MOOD, 'fields' => [
            'sol' => ['type' => 'int', 'label' => 'Sol (minutos)', 'min' => 0, 'max' => 720],
            'exercicio' => ['type' => 'int', 'label' => 'Exercício (minutos)', 'min' => 0, 'max' => 720],
            'gastos' => ['type' => 'intensity', 'label' => 'Gastos por impulso'],
            'fala' => ['type' => 'intensity', 'label' => 'Fala acelerada'],
            'ciclo' => ['type' => 'choice', 'label' => 'Ciclo menstrual (opcional)', 'options' => [
                'na' => 'Não se aplica', 'menstruacao' => 'Menstruação', 'folicular' => 'Fase folicular', 'ovulacao' => 'Ovulação', 'lutea' => 'Fase lútea (TPM)']],
        ]],
    ];

    /** Modulos que a pessoa liga/desliga em Conta. Sensiveis comecam desligados. */
    public const MODULES = [
        'substancias' => 'Substâncias (café, nicotina, álcool e outras)',
        'religiosidade' => 'Religiosidade',
        'sexualidade' => 'Sexualidade (privado, pede desbloqueio)',
        'morte' => 'Pensamentos de morte (privado, pede desbloqueio, nunca avisa ninguém)',
    ];

    public const CRISIS = ['cvv' => '188', 'samu' => '192',
        'message' => 'Você não está sozinho(a). CVV 188 (24h, gratuito, também por chat em cvv.org.br) · SAMU 192 em emergência. Este app nunca avisa ninguém automaticamente.'];

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        return self::SPEC[$key] ?? throw new \InvalidArgumentException('bloco desconhecido');
    }

    public static function exists(string $key): bool
    {
        return isset(self::SPEC[$key]);
    }

    public static function stepUp(string $key): bool
    {
        return (bool) (self::SPEC[$key]['step_up'] ?? false);
    }

    public static function intimate(string $key): bool
    {
        return (bool) (self::SPEC[$key]['intimate'] ?? false);
    }

    public static function perPeriod(string $key): bool
    {
        return self::SPEC[$key]['per'] === 'periodo';
    }

    /** Bloco ligado para o usuario (modulo opcional respeitado). */
    public static function enabledFor(string $key, ?string $modules): bool
    {
        $m = self::SPEC[$key]['module'] ?? null;
        if ($m === null) {
            return true;
        }
        if ($modules === null) {
            return (bool) (self::SPEC[$key]['default_on'] ?? false);
        }

        return in_array($m, explode(',', $modules), true);
    }

    /** @return list<string> */
    public static function enabledKeys(?string $modules): array
    {
        return array_values(array_filter(array_keys(self::SPEC), fn ($k) => self::enabledFor($k, $modules)));
    }

    /** Regras de validacao do bloco (chaves desconhecidas sao descartadas). @return array<string, list<mixed>> */
    public static function rules(string $key): array
    {
        $r = [];
        foreach (self::get($key)['fields'] as $name => $f) {
            $base = ['nullable'];
            $r["data.$name"] = match ($f['type']) {
                'intensity' => [...$base, 'integer', 'between:0,3'],
                'bipolar' => [...$base, 'integer', 'between:-3,3'],
                'int' => [...$base, 'integer', 'between:'.$f['min'].','.$f['max']],
                'decimal' => [...$base, 'numeric', 'between:'.$f['min'].','.$f['max']],
                'time' => [...$base, 'date_format:H:i'],
                'choice' => [...$base, 'string', 'in:'.implode(',', array_map('strval', array_keys($f['options'])))],
                'multi' => [...$base, 'array', 'max:'.count($f['options'])],
                'bool' => [...$base, 'boolean'],
                'text' => [...$base, 'string', 'max:'.$f['max']],
                default => throw new \LogicException('tipo'),
            };
            if ($f['type'] === 'multi') {
                $r["data.$name.*"] = ['string', 'in:'.implode(',', array_keys($f['options']))];
            }
        }

        return $r;
    }

    /** Normaliza tipos e remove vazios. @param array<string, mixed> $data @return array<string, mixed> */
    public static function normalize(string $key, array $data): array
    {
        $out = [];
        foreach (self::get($key)['fields'] as $name => $f) {
            $v = $data[$name] ?? null;
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            $out[$name] = match ($f['type']) {
                'intensity', 'bipolar', 'int' => (int) $v,
                'decimal' => (float) $v,
                'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                'multi' => array_values(array_unique(array_map('strval', (array) $v))),
                default => (string) $v,
            };
        }

        return $out;
    }

    /** Texto curto legivel do bloco (historico, relatorio, resumo de "Salvar tudo"). @param array<string, mixed> $data */
    public static function summary(string $key, array $data): string
    {
        $parts = [];
        foreach (self::get($key)['fields'] as $name => $f) {
            if (! array_key_exists($name, $data)) {
                continue;
            }
            $v = $data[$name];
            $txt = match ($f['type']) {
                'intensity' => Scales::INTENSITY[(int) $v] ?? (string) $v,
                'bipolar' => $f['labels'][(int) $v] ?? (string) $v,
                'choice' => $f['options'][(string) $v] ?? (string) $v,
                'multi' => implode(', ', array_map(fn ($o) => $f['options'][$o] ?? $o, (array) $v)),
                'bool' => $v ? 'sim' : 'não',
                'text' => mb_strimwidth((string) $v, 0, 60, '…'),
                default => (string) $v,
            };
            $parts[] = $f['label'].': '.$txt;
        }

        return implode(' · ', $parts);
    }
}
