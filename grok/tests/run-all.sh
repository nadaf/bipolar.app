#!/usr/bin/env bash
# Executa toda a verificação. Pré-requisitos: MySQL 5.7 de teste (ver RELATORIO-TESTES.md),
# composer install (nesta pasta) e npm install (em tests/js). Google Chrome para a11y/Lighthouse.
set -u
cd "$(dirname "$0")"
status=0
step() { echo; echo "=== $1"; shift; "$@" || status=1; }
for v in 8.1 8.2 8.3 8.4; do
  command -v php$v >/dev/null && step "PHPUnit em PHP $v" php$v vendor/bin/phpunit --colors=never
done
step "php -l (sintaxe) em todos os arquivos" bash -c 'for f in $(find ../app ../public_html ../cron ../tools -name "*.php"); do php -l "$f" >/dev/null || { echo "erro: $f"; exit 1; }; done; echo ok'
step "PHPStan nível 6" vendor/bin/phpstan analyse -c phpstan.neon --no-progress
step "Varredura de padrões inseguros" tools/security_scan.sh
step "ESLint" bash -c 'cd js && npm run -s lint && echo ok'
step "Regressão V4.1 (Editar por id)" bash -c 'cd js && node regress-edit.mjs'
step "Regressão V4.1 (calendário, 404, páginas)" bash -c 'cd js && node regress-v41.mjs'
step "Regressão V4.4 (verificação em duas etapas, CSP + axe)" bash -c 'cd js && node regress-v44.mjs'
step "Acessibilidade (axe-core)" bash -c 'cd js && node a11y.mjs | tail -5'
step "Lighthouse + PWA" bash -c 'cd js && node lighthouse.mjs'
echo; [ $status -eq 0 ] && echo "TUDO VERDE ✅" || echo "HÁ FALHAS ❌"
exit $status
