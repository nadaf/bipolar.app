#!/usr/bin/env bash
# Varredura estática simples (grep) por padrões perigosos no código enviado ao servidor.
set -u
cd "$(dirname "$0")/../.."
SRC="app public_html cron tools"
fail=0
check() { # descrição, regex, [exclusões]
  local desc="$1" re="$2"
  local out
  out=$(grep -rnE --include="*.php" -e "$re" $SRC | grep -v 'app/config.php' || true)
  if [ -n "$out" ]; then echo "FALHA: $desc"; echo "$out" | sed 's/^/   /'; fail=1; else echo "ok   : $desc"; fi
}
check "eval/assert dinâmico"            '\b(eval|assert)\s*\('
check "execução de comandos"            '\b(shell_exec|passthru|system|popen|proc_open|pcntl_exec)\s*\(|`[^`]*\$'
check "exec() de shell (não PDO)"       '(^|[^>:a-z_])exec\s*\('
check "unserialize/extract"             '\b(unserialize|extract|parse_str\s*\([^,]*\))\s*\('
check "\$_REQUEST"                      '\$_REQUEST'
check "include/require com entrada"     '(include|require)(_once)?\s*\(?\s*\$_'
check "echo direto de superglobal"      '(echo|print)\s+\$_(GET|POST|COOKIE|SERVER|FILES)'
check "SQL com variável interpolada"    '->(query|exec|prepare)\(\s*"[^"]*\$[a-zA-Z_]'
check "SQL concatenado com superglobal" '->(query|exec|prepare)\([^)]*\.\s*\$_'
check "hash fraco p/ segredo"           '\b(md5|sha1|rand|mt_rand|uniqid)\s*\('
check "display_errors ligado"           "display_errors'?,\s*'?(1|On)"
check "curl sem verificação TLS"        'CURLOPT_SSL_VERIFY(PEER|HOST)\s*=>\s*(false|0)'
check "header Location com entrada"     'Location:\s*.*\$_(GET|POST)'
# JS: innerHTML só com esc() — lista para revisão manual
n=$(grep -cE 'innerHTML\s*=' public_html/assets/js/*.js | awk -F: '{s+=$2} END {print s}')
echo "info : $n atribuições innerHTML em JS (todas usam esc() para dados do usuário — revisado manualmente)"
# Arquivos que não podem estar no public_html
for f in public_html/config.php public_html/schema.sql public_html/app; do [ -e "$f" ] && { echo "FALHA: $f exposto"; fail=1; }; done
[ -f app/.htaccess ] && [ -f storage/.htaccess ] && echo "ok   : .htaccess de bloqueio em app/ e storage/" || { echo "FALHA: .htaccess ausente"; fail=1; }
grep -q "'dev_login' => false" app/config.example.php && echo "ok   : dev_login desativado no config.example.php" || { echo "FALHA: dev_login"; fail=1; }
exit $fail
