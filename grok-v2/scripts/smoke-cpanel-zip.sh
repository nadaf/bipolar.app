#!/usr/bin/env bash
# Simula a hospedagem cPanel com o cpanel.zip: extrai numa pasta "home" nova, importa o SQL no MySQL,
# cria o .env como o LEIA-ME manda (APP_KEY vazio + INSTALLER_TOKEN), sobe php -S com a raiz em
# public_html e confere: /, /entrar, cadastro, login, /registrar, assets, atualizador web e nenhum 500.
# Uso: DB_HOST=127.0.0.1 DB_USER=u DB_PASS=s DB_NAME=banco_vazio scripts/smoke-cpanel-zip.sh cpanel.zip [porta]
set -euo pipefail
ZIP="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"; PORT="${2:-8139}"
: "${DB_HOST:=127.0.0.1}" "${DB_USER:?}" "${DB_PASS:?}" "${DB_NAME:?}"
HOME_DIR="$(mktemp -d)"; JAR="$HOME_DIR/cookies.txt"; BASE="http://127.0.0.1:$PORT"
PID=""; cleanup() { [ -n "$PID" ] && kill "$PID" 2>/dev/null || true; [ "${KEEP:-0}" = 1 ] || rm -rf "$HOME_DIR"; }; trap cleanup EXIT
fail() { echo "FALHOU: $*"; [ -d "$HOME_DIR/bipolar/storage/logs" ] && tail -n 40 "$HOME_DIR"/bipolar/storage/logs/*.log 2>/dev/null; exit 1; }
MY=(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS")

echo "==> Extraindo em $HOME_DIR"
( cd "$HOME_DIR" && unzip -q "$ZIP" )
for f in LEIA-ME.txt cron.txt banco/instalar-banco-novo.sql banco/atualizar-banco.sql bipolar/vendor/autoload.php \
         bipolar/.env.example public_html/index.php public_html/.htaccess public_html/build/manifest.json public_html/sw.js; do
  [ -f "$HOME_DIR/$f" ] || fail "falta $f"
done
for d in bipolar/storage/framework/sessions bipolar/storage/framework/views bipolar/storage/logs bipolar/bootstrap/cache; do
  [ -d "$HOME_DIR/$d" ] || fail "falta pasta $d"
done
[ ! -e "$HOME_DIR/bipolar/.env" ] || fail ".env veio no zip"
! find "$HOME_DIR" -name node_modules -o -name tests -not -path '*/vendor/*' | grep -q . || fail "node_modules/tests no zip"
! ls "$HOME_DIR"/bipolar/bootstrap/cache/*.php >/dev/null 2>&1 || fail "config/pacotes em cache no zip"

echo "==> Banco: instalar + atualizar (deve ser no-op) + atualizar de novo"
"${MY[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>/dev/null \
  || "${MY[@]}" "$DB_NAME" -e "SELECT 1" >/dev/null 2>&1 || fail "sem acesso ao banco"
TABLES="$("${MY[@]}" -N "$DB_NAME" -e "SHOW TABLES" 2>/dev/null || true)"
for t in $TABLES; do "${MY[@]}" "$DB_NAME" -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE \`$t\`" 2>/dev/null; done
"${MY[@]}" "$DB_NAME" < "$HOME_DIR/banco/instalar-banco-novo.sql" 2>&1 | grep -v Warning && fail "erro no instalar-banco-novo.sql" || true
N1="$("${MY[@]}" -N "$DB_NAME" -e "SELECT COUNT(*) FROM migrations" 2>/dev/null)"
"${MY[@]}" "$DB_NAME" < "$HOME_DIR/banco/atualizar-banco.sql" 2>&1 | grep -v Warning && fail "erro no atualizar-banco.sql" || true
"${MY[@]}" "$DB_NAME" < "$HOME_DIR/banco/atualizar-banco.sql" 2>&1 | grep -v Warning && fail "erro no atualizar-banco.sql (2a vez)" || true
N2="$("${MY[@]}" -N "$DB_NAME" -e "SELECT COUNT(*) FROM migrations" 2>/dev/null)"
[ "$N1" = "$N2" ] && [ "$N1" -gt 0 ] || fail "atualizar-banco mudou um banco ja atualizado ($N1 -> $N2)"

echo "==> .env como no LEIA-ME (APP_KEY vazio, gerado no 1o acesso)"
TOKEN="smoke$(date +%s)instalador0123456789abcdef"
sed -e "s#^APP_URL=.*#APP_URL=$BASE#" -e "s#^APP_KEY=.*#APP_KEY=#" -e "s#^INSTALLER_TOKEN=.*#INSTALLER_TOKEN=$TOKEN#" \
    -e "s#^DB_HOST=.*#DB_HOST=$DB_HOST#" -e "s#^DB_DATABASE=.*#DB_DATABASE=$DB_NAME#" -e "s#^DB_USERNAME=.*#DB_USERNAME=$DB_USER#" \
    -e "s#^DB_PASSWORD=.*#DB_PASSWORD=$DB_PASS#" -e "s#^SESSION_SECURE_COOKIE=.*#SESSION_SECURE_COOKIE=false#" \
    -e "s#^BIPOLAR_BASE_DOMAIN=.*#BIPOLAR_BASE_DOMAIN=localhost#" -e "s#^MAIL_MAILER=.*#MAIL_MAILER=log#" \
    "$HOME_DIR/bipolar/.env.example" > "$HOME_DIR/bipolar/.env"

echo "==> php -S com raiz em public_html"
( cd "$HOME_DIR/public_html" && exec php -S "127.0.0.1:$PORT" > "$HOME_DIR/server.log" 2>&1 ) & PID=$!
for i in $(seq 1 40); do curl -s -o /dev/null "$BASE/robots.txt" && break; sleep 0.25; done

code() { curl -s -o "$HOME_DIR/body" -w '%{http_code}' -b "$JAR" -c "$JAR" "$@"; }
expect() { local want="$1"; shift; local got; got="$(code "$@")"; [ "$got" = "$want" ] || fail "$* -> $got (esperado $want)"; echo "  ok $got ${*: -1}"; }
expect 302 "$BASE/"
grep -q '^APP_KEY=base64:' "$HOME_DIR/bipolar/.env" || fail "APP_KEY nao foi gerada no 1o acesso"
expect 200 "$BASE/entrar"
grep -o '/build/assets/[^"]*' "$HOME_DIR/body" | sort -u > "$HOME_DIR/assets"
[ -s "$HOME_DIR/assets" ] || fail "/entrar nao referencia /build/assets"
while read -r a; do expect 200 "$BASE$a"; done < "$HOME_DIR/assets"
for a in /sw.js /manifest.webmanifest /favicon.svg /offline.html; do expect 200 "$BASE$a"; done

csrf() { grep -o 'name="_token" value="[^"]*"' "$HOME_DIR/body" | head -1 | sed 's/.*value="//;s/"$//'; }
expect 200 "$BASE/criar-conta"; T="$(csrf)"
expect 302 -X POST "$BASE/criar-conta" --data-urlencode "_token=$T" --data-urlencode "name=Teste Smoke" \
  --data-urlencode "email=smoke@exemplo.test" --data-urlencode "password=senha-muito-boa-1" \
  --data-urlencode "password_confirmation=senha-muito-boa-1" --data-urlencode "account_type=patient"
expect 200 "$BASE/registrar"
grep -q 'Registrar' "$HOME_DIR/body" || fail "/registrar sem conteudo"
rm -f "$JAR"
expect 200 "$BASE/entrar"; T="$(csrf)"
expect 302 -X POST "$BASE/entrar" --data-urlencode "_token=$T" --data-urlencode "email=smoke@exemplo.test" --data-urlencode "password=senha-muito-boa-1"
expect 200 "$BASE/registrar"
expect 200 "$BASE/historico"
expect 200 "$BASE/exportar"

echo "==> Atualizador web (uso unico)"
expect 200 "$BASE/atualizar"
expect 200 -X POST "$BASE/atualizar" --data-urlencode "token=$TOKEN"
expect 404 "$BASE/atualizar"

echo "==> Nenhum 500 / erro no log"
if grep -q ' 500 ' "$HOME_DIR/server.log"; then fail "houve 500 no servidor"; fi
if ls "$HOME_DIR"/bipolar/storage/logs/*.log >/dev/null 2>&1 && grep -q '\.ERROR' "$HOME_DIR"/bipolar/storage/logs/*.log; then fail "erro no log do Laravel"; fi
echo "OK: cpanel.zip funcionou na simulacao de cPanel"
