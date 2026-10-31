#!/usr/bin/env bash
# Falha se codigo/segredo PRIVADO (SaaS) vazar para o repositorio PUBLICO.
# Roda no CI publico e localmente: scripts/check-public-tree.sh
set -euo pipefail
cd "$(dirname "$0")/.."
fail=0
files() { git ls-files 2>/dev/null || find . -type f -not -path './vendor/*' -not -path './node_modules/*' -not -path './.git/*'; }

# 1) caminhos proibidos
if files | grep -v '^storage/' | grep -iE '(^|/)(private|saas|billing|provisioner|sales)(/|$)|(^|/)\.env$|(^|/)auth\.json$|\.pem$|\.key$' ; then
  echo "ERRO: arquivo/pasta privada ou segredo no repositorio publico"; fail=1
fi

# 2) referencias ao pacote privado (o publico nunca depende dele)
if grep -nE 'nadaf/bipolar-saas|BipolarSaas\\' composer.json composer.lock package.json 2>/dev/null; then
  echo "ERRO: dependencia do pacote privado no publico"; fail=1
fi
if files | grep -vE '^(scripts/check-public-tree\.sh|AGENTS\.md|docs/|\.github/)' | xargs -r grep -lE 'namespace BipolarSaas|use BipolarSaas\\' 2>/dev/null; then
  echo "ERRO: codigo do namespace privado BipolarSaas no publico"; fail=1
fi

# 3) segredos e IDs de analytics do site principal
PAT='(VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{20,}|G-[A-Z0-9]{8,}|UA-[0-9]{4,}-[0-9]+|GTM-[A-Z0-9]{5,}|sk_live_[A-Za-z0-9]{10,}|pk_live_[A-Za-z0-9]{10,}|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{30,}|xox[baprs]-[A-Za-z0-9-]{10,}|-----BEGIN [A-Z ]*PRIVATE KEY-----|^APP_KEY=base64:.{20,})'
if files | grep -vE '^scripts/check-public-tree\.sh$' | xargs -r grep -nIE "$PAT" 2>/dev/null; then
  echo "ERRO: possivel segredo ou ID de analytics no publico"; fail=1
fi

# 3b) storage/ so pode ter .gitignore versionado
if files | grep '^storage/' | grep -v '\.gitignore$'; then echo "ERRO: arquivos gerados em storage/ versionados"; fail=1; fi

# 3c) termos comerciais proibidos no publico (codigo, docs, OpenAPI). Isso vive so no privado.
SALES='R\$ ?[0-9]|\bplanos?\b|assinatura|\bvend(a|as|er|emos)\b|revenda|checkout|billing|cobran[cç]a|pricing|\bpre[cç]os?\b|mensalidade|pagamento|stripe|mercado ?pago|pagseguro|asaas|pagar\.me|\bsales\b'
if files | grep -vE '^(vendor/|node_modules/|composer\.lock$|package-lock\.json$|scripts/check-public-tree\.sh$)' \
  | xargs -r grep -nIiE "$SALES" 2>/dev/null | grep -v 'actions/checkout@'; then
  echo "ERRO: termo comercial no repositorio publico"; fail=1
fi

# 4) config real do app antigo nunca entra
if files | grep -E '(^|/)app/config\.php$'; then echo "ERRO: config.php do app 0.5"; fail=1; fi

[ $fail -eq 0 ] && echo "OK: arvore publica limpa"
exit $fail
