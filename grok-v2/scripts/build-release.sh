#!/usr/bin/env bash
# Gera o zip para cPanel (sem SSH). Uso: scripts/build-release.sh [saida.zip]
# Estrutura do zip:
#   bipolar/      -> app Laravel (FORA da pasta publica) com vendor/ pronto
#   public_html/  -> conteudo de public/ (raiz do site), index.php apontando para ../bipolar
# NUNCA inclui: .env, auth.json, node_modules, tests, .git, logs, chaves.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
OUT="${1:-$ROOT/dist/bipolar-release.zip}"
case "$OUT" in /*) ;; *) OUT="$ROOT/$OUT" ;; esac
VERSION="$(cat VERSION)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

echo "==> Front-end (Tailwind + Vite + Workbox)"
if [ "${SKIP_NPM:-0}" != "1" ]; then npm ci --no-audit --no-fund >/dev/null; npm run build >/dev/null; fi
test -f public/build/manifest.json && test -f public/sw.js

echo "==> Copiando arquivos"
APP="$STAGE/bipolar"; WEB="$STAGE/public_html"
mkdir -p "$APP" "$WEB"
for p in app bootstrap config database resources routes artisan composer.json composer.lock VERSION .env.example INSTALL-CPANEL.md; do
  cp -R "$p" "$APP/"
done
rm -rf "$APP/resources/js" "$APP/resources/css" "$APP/bootstrap/cache/"*.php
mkdir -p "$APP"/storage/{app/public,framework/{cache/data,sessions,views},logs}
cp -R public/. "$WEB/"
rm -f "$WEB/hot"

echo "==> Composer (--no-dev -o)"
( cd "$APP" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts -q \
  && composer dump-autoload -o --no-dev --no-scripts -q && php artisan package:discover -q )
rm -f "$APP/composer.lock.bak"

echo "==> Ajustando index.php para ../bipolar"
php -r '
$f = $argv[1]; $s = file_get_contents($f);
$s = str_replace("__DIR__.\x27/../", "__DIR__.\x27/../bipolar/", $s);
$s = preg_replace("/\\\$app->handleRequest/", "\$app->usePublicPath(__DIR__);\n\$app->handleRequest", $s, 1);
file_put_contents($f, $s);' "$WEB/index.php"
grep -q "/../bipolar/vendor/autoload.php" "$WEB/index.php"
grep -q "usePublicPath" "$WEB/index.php"

# A pasta do app nunca deve ser servida pelo Apache, mesmo se enviada no lugar errado.
printf 'Require all denied\nDeny from all\n' > "$APP/.htaccess"
# Protege arquivos ocultos na raiz publica
cat >> "$WEB/.htaccess" <<'HT'

# Bipolar: bloquear arquivos ocultos e de configuracao
<FilesMatch "^\.|\.(env|ini|log|sql|lock)$">
    Require all denied
</FilesMatch>
HT

echo "==> Verificando segredos no pacote"
if find "$STAGE" \( -name '.env' -o -name 'auth.json' -o -name '*.key' -o -name 'id_rsa*' -o -name '*.pem' \) -not -path '*/vendor/*' | grep -q .; then
  echo "ERRO: arquivo sensivel no pacote"; exit 1
fi
LEAKS="$(grep -RIl --exclude-dir=vendor -E '^APP_KEY=base64:[A-Za-z0-9+/=]{20,}|BEGIN (RSA|EC|OPENSSH) PRIVATE KEY|^INSTALLER_TOKEN=[A-Za-z0-9_-]{8,}' "$STAGE" || true)"
if [ -n "$LEAKS" ]; then echo "$LEAKS";
  echo "ERRO: possivel segredo no pacote"; exit 1
fi
if [ -d "$APP/vendor/nadaf/bipolar-saas" ] && [ "${EDITION:-community}" = "community" ]; then
  echo "ERRO: codigo privado no pacote community"; exit 1
fi

echo "==> Zip"
mkdir -p "$(dirname "$OUT")"; rm -f "$OUT"
( cd "$STAGE" && zip -qr -X "$OUT" bipolar public_html )
echo "OK: $OUT ($(du -h "$OUT" | cut -f1)) versao $VERSION"
