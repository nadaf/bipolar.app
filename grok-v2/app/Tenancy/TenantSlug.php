<?php

namespace App\Tenancy;

/**
 * Regras do subdominio <slug>.bipolar.app.br (mesmas da 0.5: 3-30, a-z0-9 e hifen).
 * Lista reservada: infraestrutura, paginas e termos que confundem.
 * Nomes extras podem ser somados por config('bipolar.reserved_slugs_extra').
 */
final class TenantSlug
{
    public const RESERVED = [
        'www', 'mail', 'email', 'smtp', 'imap', 'pop', 'pop3', 'ftp', 'sftp', 'ssh', 'mx', 'ns', 'ns1', 'ns2', 'ns3', 'dns',
        'webmail', 'cpanel', 'whm', 'webdisk', 'autodiscover', 'autoconfig', 'cpcalendars', 'cpcontacts',
        'api', 'app', 'apps', 'admin', 'administrador', 'root', 'painel', 'dashboard', 'static', 'cdn', 'assets', 'media',
        'img', 'files', 'arquivos', 'backup', 'backups', 'db', 'mysql', 'vpn', 'proxy', 'status', 'monitor',
        'blog', 'docs', 'wiki', 'ajuda', 'help', 'suporte', 'support', 'contato', 'comercial', 'financeiro',
        'login', 'entrar', 'sair', 'conta', 'contas', 'auth', 'sso', 'oauth', 'instalar', 'atualizar',
        'dev', 'teste', 'test', 'testes', 'staging', 'homolog', 'demo', 'beta', 'alpha', 'sandbox', 'local', 'localhost',
        'bipolar', 'grok', 'oficial', 'seguranca', 'security', 'privacidade', 'lgpd', 'dpo', 'termos', 'abuse', 'postmaster',
        'hostmaster', 'noc', 'clinica', 'clinicas', 'profissional', 'profissionais', 'paciente', 'pacientes', 'caps', 'sus',
        'anvisa', 'cfm', 'crm', 'crp', 'cfp', 'governo', 'gov', 'cvv', 'samu',
    ];

    public static function normalize(string $s): string
    {
        return strtolower(trim($s));
    }

    /** Mensagem do problema (pt-BR) ou null se o formato estiver ok. Nao consulta o banco. */
    public static function problem(string $s): ?string
    {
        if (strlen($s) < 3 || strlen($s) > 30) {
            return 'O endereço precisa ter de 3 a 30 caracteres.';
        }
        if (! preg_match('/^[a-z0-9-]+$/', $s)) {
            return 'Use só letras minúsculas sem acento (a–z), números e hífen.';
        }
        if ($s[0] === '-' || str_ends_with($s, '-')) {
            return 'O endereço não pode começar nem terminar com hífen.';
        }
        if (str_contains($s, '--')) {
            return 'O endereço não pode ter dois hífens seguidos.';
        }
        if (self::isReserved($s)) {
            return 'Esse nome é reservado. Escolha outro.';
        }

        return null;
    }

    public static function isReserved(string $s): bool
    {
        $extra = (array) config('bipolar.reserved_slugs_extra', []);

        return in_array($s, self::RESERVED, true) || in_array($s, $extra, true);
    }
}
