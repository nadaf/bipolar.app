<?php
/**
 * V5.2 — Google Analytics 4 com privacidade (DESLIGADO por padrão).
 *
 * Origem do ID (G-XXXX), nesta ordem:
 *  1. 'analytics' => ['ga4_id' => 'G-...'] no config.php (qualquer instalação; '' ou ausente = desligado);
 *  2. arquivo app/site-main.php, que só existe no site principal (não vai para o repositório público) e só vale
 *     quando o endereço acessado é um dos 'hosts' listados nele (bipolar.app.br). Instalações de profissionais
 *     (nome.bipolar.app.br) e quem hospeda por conta própria nunca herdam o ID, mesmo copiando o arquivo.
 * 'analytics' => ['ga4_id' => 'off'] desliga também no site principal.
 *
 * O GA só é carregado DEPOIS que a pessoa aceita no aviso (Consent Mode v2, tudo negado por padrão), nunca em páginas
 * com dados de paciente ou tokens na URL, e os eventos nunca levam valores de registro (ver assets/js/analytics.js).
 */

const GA4_ID_RE = '/^G-[A-Z0-9]{4,16}$/';
/** Páginas onde o GA nunca carrega: visão do profissional, relatório impresso, links com token. */
const GA4_BLOCKED_SCRIPTS = ['/paciente.php', '/relatorio.php', '/convite-profissional.php', '/confirmar.php',
    '/conta/verificar.php', '/conta/confirmar-email.php', '/conta/redefinir.php', '/conta/mfa.php', '/auth/callback.php'];

function ga4_request_host(): string
{
    $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return (string)preg_replace('/:\d+$/', '', $h);
}

/** ID de medição ativo para esta requisição, ou '' (desligado). */
function ga4_id(): string
{
    $cfg = cfg('analytics.ga4_id', '');
    $cfg = is_string($cfg) ? trim($cfg) : '';
    if ($cfg === 'off') return '';
    if ($cfg !== '') return preg_match(GA4_ID_RE, $cfg) ? $cfg : '';
    $f = APP_ROOT . '/site-main.php';
    if (!is_file($f)) return '';
    $site = require $f;
    if (!is_array($site) || !is_string($site['ga4_id'] ?? null) || !preg_match(GA4_ID_RE, $site['ga4_id'])) return '';
    $hosts = array_map('strtolower', array_map('strval', (array)($site['hosts'] ?? [])));
    return in_array(ga4_request_host(), $hosts, true) ? $site['ga4_id'] : '';
}

/** O GA pode ser oferecido nesta página? (ID ativo e página sem dados de paciente/token na URL) */
function ga4_page_allowed(): bool
{
    if (!empty($GLOBALS['bp_no_analytics'])) return false;
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($script, GA4_BLOCKED_SCRIPTS, true)) return false;
    foreach (array_keys($_GET) as $k) {
        if (!in_array((string)$k, ['aba'], true)) return false; // qualquer parâmetro desconhecido pode ser token/ID
    }
    return true;
}

function ga4_active(): bool
{
    return ga4_id() !== '' && ga4_page_allowed();
}

/** Fontes extras da CSP quando o GA está ativo nesta página (somente domínios do GA). */
/** @return array{script:string,connect:string,img:string} */
function ga4_csp_sources(): array
{
    if (!ga4_active()) return ['script' => '', 'connect' => '', 'img' => ''];
    return [
        'script' => ' https://www.googletagmanager.com',
        'connect' => ' https://*.google-analytics.com https://*.analytics.google.com https://www.googletagmanager.com',
        'img' => ' https://*.google-analytics.com https://www.googletagmanager.com',
    ];
}

/** Título genérico (nunca nome, data ou dado de saúde). */
function ga4_generic_title(string $title): string
{
    return preg_replace('/[^\p{L}\p{N} \-]/u', '', mb_substr($title, 0, 40)) ?? '';
}

/** Evento de funil pendente (sign_up / login), consumido uma vez. */
function ga4_flash(string $event): void
{
    if (in_array($event, ['sign_up', 'login'], true)) $_SESSION['ga_flash'] = $event;
}
function ga4_take_flash(): string
{
    $e = (string)($_SESSION['ga_flash'] ?? '');
    unset($_SESSION['ga_flash']);
    return in_array($e, ['sign_up', 'login'], true) ? $e : '';
}
