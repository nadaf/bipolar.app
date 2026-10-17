<?php
/**
 * V4.7 — Ponto de extensão genérico (DESLIGADO no app público).
 *
 * O app público (repositório aberto) é só o diário e funciona completo sem nada aqui. Um módulo opcional pode ser
 * instalado como a pasta app/ext/ com um arquivo app/ext/module.php. Sem essa pasta, todas as funções abaixo
 * respondem "não há extensão" e as páginas mostram a versão pública.
 *
 * O módulo pode:
 *  - declarar recursos: return ['features' => ['recurso' => true], ...] (consultados com ext_has())
 *  - fornecer páginas: app/ext/pages/<nome>.php (usadas por ext_page() em páginas públicas que aceitam substituição)
 *  - fornecer trechos: app/ext/partials/<nome>.php (devolvidos por ext_partial(), ex.: seção extra na Política de Privacidade)
 *
 * Para desligar um módulo instalado sem apagar arquivos: 'extensions' => ['enabled' => false] no config.php.
 */

/** Pasta do módulo instalado, ou null (app público puro / desligado no config). */
function ext_dir(): ?string
{
    if (cfg('extensions.enabled', true) === false) return null;
    $d = APP_ROOT . '/ext';
    return is_file($d . '/module.php') ? $d : null;
}

/** @return array<string,mixed> manifesto do módulo (vazio sem módulo) */
function ext_manifest(): array
{
    static $m = null;
    if ($m !== null) return $m;
    $d = ext_dir();
    $r = $d !== null ? require $d . '/module.php' : [];
    $m = is_array($r) ? $r : [];
    return $m;
}

/** O módulo instalado oferece este recurso? */
function ext_has(string $feature): bool
{
    return !empty(ext_manifest()['features'][$feature]);
}

/** Nome seguro de página/trecho (só letras minúsculas, números e hífen). */
function ext_name_ok(string $name): bool
{
    return (bool)preg_match('/^[a-z0-9-]{1,40}$/', $name);
}

/**
 * Se o módulo fornece esta página, executa-a e devolve true (a página pública deve parar). Senão devolve false.
 * A página do módulo roda no mesmo escopo de funções (o _boot.php já foi carregado).
 */
function ext_page(string $name): bool
{
    $d = ext_dir();
    if ($d === null || !ext_name_ok($name)) return false;
    $f = $d . '/pages/' . $name . '.php';
    if (!is_file($f)) return false;
    ext_manifest();
    (static function (string $__f): void { require $__f; })($f);
    return true;
}

/**
 * Trecho HTML fornecido pelo módulo (string vazia sem módulo).
 * @param array<string,mixed> $vars
 */
function ext_partial(string $name, array $vars = []): string
{
    $d = ext_dir();
    if ($d === null || !ext_name_ok($name)) return '';
    $f = $d . '/partials/' . $name . '.php';
    if (!is_file($f)) return '';
    ext_manifest();
    ob_start();
    (static function (string $__f, array $__v): void { extract($__v, EXTR_SKIP); require $__f; })($f, $vars);
    return (string)ob_get_clean();
}
