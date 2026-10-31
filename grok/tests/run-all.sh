#!/usr/bin/env bash
# Executa toda a verificação. Pré-requisitos: MySQL 5.7 de teste (ver RELATORIO-TESTES.md),
# composer install (nesta pasta) e npm install (em tests/js). Google Chrome para a11y/Lighthouse.
set -u
cd "$(dirname "$0")"
status=0
step() { echo; echo "=== $1"; shift; "$@" || status=1; }
# V5.1: preflight — mostra versões e para com erro se faltar algo obrigatório.
# Testes de navegador (ESLint/regressões/axe/Lighthouse) podem ser pulados de forma explícita com SKIP_BROWSER=1.
preflight() {
  local miss=0
  echo "=== preflight"
  if command -v php >/dev/null; then php -r 'echo "PHP ", PHP_VERSION, "\n";'; else echo "FALTA: php"; miss=1; fi
  for ext in pdo_mysql dom xmlwriter mbstring zip; do php -m 2>/dev/null | grep -qi "^$ext$" || { echo "FALTA: extensão PHP $ext"; miss=1; }; done
  [ -x vendor/bin/phpunit ] || { echo "FALTA: composer install (vendor/bin/phpunit)"; miss=1; }
  if php -r 'exit(@fsockopen(getenv("TEST_DB_HOST") ?: "127.0.0.1", (int)(getenv("TEST_DB_PORT") ?: 3307)) ? 0 : 1);'; then echo "MySQL de teste: ok (porta ${TEST_DB_PORT:-3307})"; else echo "FALTA: MySQL de teste em ${TEST_DB_HOST:-127.0.0.1}:${TEST_DB_PORT:-3307}"; miss=1; fi
  if [ "${SKIP_BROWSER:-0}" = "1" ]; then echo "Testes de navegador: PULADOS (SKIP_BROWSER=1)"; else
    command -v node >/dev/null && echo "Node $(node --version)" || { echo "FALTA: node (ou use SKIP_BROWSER=1)"; miss=1; }
    [ -d js/node_modules ] || { echo "FALTA: npm install em tests/js (ou use SKIP_BROWSER=1)"; miss=1; }
  fi
  [ $miss -eq 0 ] || { echo "Pré-requisitos faltando. Nada foi executado."; exit 2; }
}
preflight
for v in 8.1 8.2 8.3 8.4; do
  command -v php$v >/dev/null && step "PHPUnit em PHP $v" php$v vendor/bin/phpunit --colors=never
done
step "php -l (sintaxe) em todos os arquivos" bash -c 'for f in $(find ../app ../public_html ../cron ../tools -name "*.php"); do php -l "$f" >/dev/null || { echo "erro: $f"; exit 1; }; done; echo ok'
step "PHPStan nível 6" vendor/bin/phpstan analyse -c phpstan.neon --no-progress
step "Varredura de padrões inseguros" tools/security_scan.sh
[ "${SKIP_BROWSER:-0}" = "1" ] || step "ESLint" bash -c 'cd js && npm run -s lint && echo ok'
[ "${SKIP_BROWSER:-0}" = "1" ] || step "Regressão V4.1 (Editar por id)" bash -c 'cd js && node regress-edit.mjs'
[ "${SKIP_BROWSER:-0}" = "1" ] || step "Regressão V4.1 (calendário, 404, páginas)" bash -c 'cd js && node regress-v41.mjs'
[ "${SKIP_BROWSER:-0}" = "1" ] || step "Regressão V4.4 (verificação em duas etapas, CSP + axe)" bash -c 'cd js && node regress-v44.mjs'
[ "${SKIP_BROWSER:-0}" = "1" ] || [ ! -f js/regress-v45.mjs ] || step "Regressão V4.5 (páginas públicas, CSP + axe + capturas)" bash -c 'cd js && node regress-v45.mjs'
[ "${SKIP_BROWSER:-0}" = "1" ] || step "Regressão V4.7 (toast + estresse/alimentação por período, 412x915)" bash -c 'cd js && node regress-v47.mjs'
[ "${SKIP_BROWSER:-0}" = "1" ] || step "Regressão V4.7.2 (Meu dia segue a data escolhida, 412x915)" bash -c 'cd js && node regress-v472.mjs'
[ "${SKIP_BROWSER:-0}" = "1" ] || step "Regressão V4.8 (pensamentos, nicotina, painel de apoio offline, compartilhamento com profissional, 412x915 + axe)" bash -c 'cd js && node regress-v48.mjs'
[ "${SKIP_BROWSER:-0}" = "1" ] || step "Regressão V5.1 (período travado, Salvar e continuar/tudo, religiosidade, tema, grade de lembretes, 412x915 + axe)" bash -c 'cd js && node regress-v51.mjs'
[ "${SKIP_BROWSER:-0}" = "1" ] || step "Regressão V5.2 (GA4 com consentimento: nada antes do aceite, eventos sem dados sensíveis, CSP + axe)" bash -c 'cd js && node regress-v52.mjs'
[ "${SKIP_BROWSER:-0}" = "1" ] || step "Regressão V5.2.1 (aviso de consentimento some após Aceitar: cadastro, sem localStorage, HTML antigo, navegação inferior)" bash -c 'cd js && node regress-v53.mjs'
[ "${SKIP_BROWSER:-0}" = "1" ] || step "Acessibilidade (axe-core)" bash -c 'cd js && node a11y.mjs | tail -5'
[ "${SKIP_BROWSER:-0}" = "1" ] || step "Lighthouse + PWA" bash -c 'cd js && node lighthouse.mjs'
echo; [ $status -eq 0 ] && echo "TUDO VERDE ✅" || echo "HÁ FALHAS ❌"
exit $status
