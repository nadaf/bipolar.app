#!/usr/bin/env bash
# Gera o cpanel.zip (hospedagem compartilhada, SEM npm/composer/SSH no servidor).
# O MESMO script roda local e no CI. Uso: scripts/build-cpanel-zip.sh [saida.zip]
#   SKIP_NPM=1  -> usa o public/build ja gerado (nao roda npm)
# Conteudo do zip (extrair na pasta "home" da hospedagem, ao lado da pasta bipolar-2-0-public):
#   LEIA-ME.txt          passo a passo em portugues simples
#   bipolar/             app (FORA da pasta publica), vendor/ pronto, storage/ e bootstrap/cache/ vazios
#   bipolar-2-0-public/  raiz do site (pasta do dominio; index.php aponta para ../bipolar), build/, sw.js, icones
#   banco/               instalar-banco-novo.sql e atualizar-banco.sql (phpMyAdmin)
#   cron.txt             linha do Cron Jobs do cPanel
# NUNCA inclui: .env, auth.json, node_modules, tests, .git, logs, chaves, config em cache.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
OUT="${1:-$ROOT/dist/cpanel.zip}"
case "$OUT" in /*) ;; *) OUT="$ROOT/$OUT" ;; esac
VERSION="$(cat VERSION)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

echo "==> Front-end (Tailwind + Vite + Workbox)"
if [ "${SKIP_NPM:-0}" != "1" ]; then npm ci --no-audit --no-fund >/dev/null; npm run build >/dev/null; fi
test -f public/build/manifest.json && test -f public/sw.js
php -r '$m=json_decode(file_get_contents("public/build/manifest.json"),true); foreach($m as $e){ if(!is_file("public/build/".$e["file"])){fwrite(STDERR,"falta ".$e["file"]."\n"); exit(1);} }'

echo "==> Copiando arquivos"
APP="$STAGE/bipolar"; WEBDIR="${WEBDIR:-bipolar-2-0-public}"; WEB="$STAGE/$WEBDIR"; DB="$STAGE/banco"
mkdir -p "$APP" "$WEB" "$DB"
for p in app bootstrap config database deploy lang resources routes artisan composer.json composer.lock VERSION .env.example INSTALL-CPANEL.md; do
  [ -e "$p" ] && cp -R "$p" "$APP/"
done
rm -rf "$APP/resources/js" "$APP/resources/css"
rm -f "$APP"/bootstrap/cache/*.php   # nada em cache: config/rotas vem do .env do servidor
mkdir -p "$APP"/storage/{app/public,framework/{cache/data,sessions,views},logs} "$APP/bootstrap/cache"
for d in storage/app storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
  printf '*\n!.gitignore\n' > "$APP/$d/.gitignore"
done
cp -R public/. "$WEB/"
rm -f "$WEB/hot"

echo "==> Composer (--no-dev -o)"
( cd "$APP" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts -q \
  && composer dump-autoload -o --no-dev --no-scripts -q )
rm -f "$APP"/bootstrap/cache/*.php   # package:discover roda sozinho no 1o acesso (sem artisan)
test -f "$APP/vendor/autoload.php"

echo "==> index.php apontando para ../bipolar"
php -r '
$f = $argv[1]; $s = file_get_contents($f);
$s = str_replace("__DIR__.\x27/../", "__DIR__.\x27/../bipolar/", $s);
$s = preg_replace("/\\\$app->handleRequest/", "\$app->usePublicPath(__DIR__);\n\$app->handleRequest", $s, 1);
file_put_contents($f, $s);' "$WEB/index.php"
grep -q "/../bipolar/vendor/autoload.php" "$WEB/index.php"
grep -q "/../bipolar/bootstrap/app.php" "$WEB/index.php"
grep -q "usePublicPath" "$WEB/index.php"

printf 'Require all denied\nDeny from all\n' > "$APP/.htaccess"
cat >> "$WEB/.htaccess" <<'HT'

# Bipolar: bloquear arquivos ocultos e de configuracao
<FilesMatch "^\.|\.(env|ini|log|sql|lock)$">
    Require all denied
</FilesMatch>
HT

echo "==> SQL e textos"
cp database/sql/install-mysql57.sql "$DB/instalar-banco-novo.sql"
cp database/sql/update-mysql57.sql "$DB/atualizar-banco.sql"
cp scripts/cpanel/LEIA-ME.txt "$STAGE/LEIA-ME.txt"
cp scripts/cpanel/cron.txt "$STAGE/cron.txt"
sed -i "s/{{VERSAO}}/$VERSION/g" "$STAGE/LEIA-ME.txt"
# finais de linha do Windows nos textos (abrem certo no Bloco de Notas)
sed -i 's/$/\r/' "$STAGE/LEIA-ME.txt" "$STAGE/cron.txt"

echo "==> Verificando o pacote"
for bad in node_modules tests .git; do
  if find "$STAGE" -name "$bad" -not -path '*/vendor/*' | grep -q .; then echo "ERRO: $bad no pacote"; exit 1; fi
done
if find "$STAGE" \( -name '.env' -o -name 'auth.json' -o -name '*.key' -o -name 'id_rsa*' -o -name '*.pem' -o -name 'installer.lock' -o -name '*.log' \) -not -path '*/vendor/*' | grep -q .; then
  echo "ERRO: arquivo sensivel no pacote"; exit 1
fi
LEAKS="$(grep -RIl --exclude-dir=vendor -E '^APP_KEY=base64:[A-Za-z0-9+/=]{20,}|BEGIN (RSA|EC|OPENSSH) PRIVATE KEY|^INSTALLER_TOKEN=[A-Za-z0-9_-]{8,}|^VAPID_PRIVATE_KEY=.{8,}|^MAIL_PASSWORD=.+|^DB_PASSWORD=.+' "$STAGE" || true)"
if [ -n "$LEAKS" ]; then echo "$LEAKS"; echo "ERRO: possivel segredo no pacote"; exit 1; fi
if [ -d "$APP/vendor/nadaf/bipolar-saas" ] && [ "${EDITION:-community}" = "community" ]; then
  echo "ERRO: codigo privado no pacote community"; exit 1
fi

echo "==> Zip"
mkdir -p "$(dirname "$OUT")"; rm -f "$OUT"
( cd "$STAGE" && find . -type d -exec chmod 755 {} + && find . -type f -exec chmod 644 {} + \
  && zip -qr -X "$OUT" LEIA-ME.txt cron.txt banco bipolar "$WEBDIR" )
echo "OK: $OUT ($(du -h "$OUT" | cut -f1)) versao $VERSION"
