#!/usr/bin/env bash
# Simula a hospedagem cPanel com o cpanel.zip: extrai numa pasta "home" nova, importa o SQL no MySQL,
# cria o .env como o LEIA-ME manda (APP_KEY vazio + INSTALLER_TOKEN), sobe php -S com a raiz em
# bipolar-2-0-public e confere: /, /entrar, cadastro, login, /registrar, assets, atualizador web e nenhum 500.
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
         bipolar/.env.example bipolar-2-0-public/index.php bipolar-2-0-public/.htaccess bipolar-2-0-public/build/manifest.json bipolar-2-0-public/sw.js bipolar/deploy/auto-update.php; do
  [ -f "$HOME_DIR/$f" ] || fail "falta $f"
done
for d in bipolar/storage/framework/sessions bipolar/storage/framework/views bipolar/storage/logs bipolar/bootstrap/cache; do
  [ -d "$HOME_DIR/$d" ] || fail "falta pasta $d"
done
[ ! -e "$HOME_DIR/bipolar/.env" ] || fail ".env veio no zip"
! find "$HOME_DIR" -name node_modules -o -name tests -not -path '*/vendor/*' | grep -q . || fail "node_modules/tests no zip"
! ls "$HOME_DIR"/bipolar/bootstrap/cache/*.php >/dev/null 2>&1 || fail "config/pacotes em cache no zip"

FIX="$(cd "$(dirname "$0")/.." && pwd)/tests/fixtures/sql"
emptydb() {
  "${MY[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>/dev/null \
    || "${MY[@]}" "$DB_NAME" -e "SELECT 1" >/dev/null 2>&1 || fail "sem acesso ao banco"
  local t; for t in $("${MY[@]}" -N "$DB_NAME" -e "SHOW TABLES" 2>/dev/null || true); do "${MY[@]}" "$DB_NAME" -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE \`$t\`" 2>/dev/null; done
  "${MY[@]}" "$DB_NAME" -e "DROP PROCEDURE IF EXISTS bipolar_verificar; DROP PROCEDURE IF EXISTS bipolar_atualizar" 2>/dev/null || true
}
imp() { "${MY[@]}" "$DB_NAME" < "$1" 2>&1 | grep -v Warning || true; }
schema() { mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" --no-data --skip-comments --skip-dump-date "$DB_NAME" 2>/dev/null | sed 's/AUTO_INCREMENT=[0-9]* //'; }
snap() { schema; local t; for t in $("${MY[@]}" -N "$DB_NAME" -e "SHOW TABLES" 2>/dev/null); do "${MY[@]}" -N "$DB_NAME" -e "CHECKSUM TABLE \`$t\`" 2>/dev/null; done; }

echo "==> Cenario: banco 2.0 novo (referencia)"
emptydb; [ -z "$(imp "$HOME_DIR/banco/instalar-banco-novo.sql")" ] || fail "erro no instalar-banco-novo.sql"; schema > "$HOME_DIR/fresh.schema"
echo "==> Cenario: instalar de novo num banco ja instalado -> PARA"
imp "$HOME_DIR/banco/instalar-banco-novo.sql" | grep -q 'PARE: banco nao esta vazio' || fail "instalar em banco cheio nao parou"
echo "==> Cenario: atualizar a partir do 2.0 anterior"
emptydb; imp "$FIX/install-2.0-prev.sql" >/dev/null
[ -z "$(imp "$HOME_DIR/banco/atualizar-banco.sql")" ] || fail "erro ao atualizar do 2.0 anterior"
schema | diff -q - "$HOME_DIR/fresh.schema" >/dev/null || fail "2.0 anterior atualizado != instalacao nova"
echo "==> Cenario: atualizar a partir do 2.0 que esta no ar (ganha password_resets)"
emptydb; imp "$FIX/install-2.0-live.sql" >/dev/null
[ -z "$(imp "$HOME_DIR/banco/atualizar-banco.sql")" ] || fail "erro ao atualizar do 2.0 no ar"
schema | diff -q - "$HOME_DIR/fresh.schema" >/dev/null || fail "2.0 no ar atualizado != instalacao nova"
"${MY[@]}" -N "$DB_NAME" -e "SELECT COUNT(*) FROM password_resets" >/dev/null 2>&1 || fail "password_resets nao criada"
echo "==> Cenario: atualizacao interrompida no meio -> retoma"
"${MY[@]}" "$DB_NAME" -e "DELETE FROM migrations WHERE id > 2" 2>/dev/null
[ -z "$(imp "$HOME_DIR/banco/atualizar-banco.sql")" ] || fail "nao retomou atualizacao parcial"
schema | diff -q - "$HOME_DIR/fresh.schema" >/dev/null || fail "retomada != instalacao nova"
echo "==> Cenario: banco vazio + atualizar -> instala tudo"
emptydb; [ -z "$(imp "$HOME_DIR/banco/atualizar-banco.sql")" ] || fail "atualizar em banco vazio falhou"
schema | diff -q - "$HOME_DIR/fresh.schema" >/dev/null || fail "atualizar em banco vazio != instalacao nova"
for extra in none buggy; do
  echo "==> Cenario: banco do Bipolar 0.5 ($extra) -> PARA sem alterar nada"
  for f in atualizar-banco.sql instalar-banco-novo.sql; do
    emptydb; imp "$FIX/legacy-0.5.3-schema.sql" >/dev/null
    [ "$extra" = buggy ] && { imp "$FIX/update-2.0-buggy.sql" >/dev/null; imp "$FIX/update-2.0-buggy.sql" | grep -q "1050" || fail "nao reproduziu o erro 1050"; }
    snap > "$HOME_DIR/before"
    imp "$HOME_DIR/banco/$f" | grep -q 'PARE: banco do Bipolar 0.5' || fail "$f nao parou no banco 0.5"
    snap | diff -q - "$HOME_DIR/before" >/dev/null || fail "$f alterou o banco 0.5"
  done
done

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

echo "==> php -S com raiz em bipolar-2-0-public"
( cd "$HOME_DIR/bipolar-2-0-public" && exec php -S "127.0.0.1:$PORT" > "$HOME_DIR/server.log" 2>&1 ) & PID=$!
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
grep -q 'Esqueci minha senha' "$HOME_DIR/body" || fail "/entrar sem Esqueci minha senha"
grep -q 'src="/favicon.svg"' "$HOME_DIR/body" || fail "/entrar sem logo"
expect 200 "$BASE/favicon.svg"
expect 200 "$BASE/esqueci-senha"; T2="$(csrf)"
expect 302 -X POST "$BASE/esqueci-senha" --data-urlencode "_token=$T2" --data-urlencode "email=smoke@exemplo.test"
[ "$("${MY[@]}" -N "$DB_NAME" -e "SELECT COUNT(*) FROM password_resets WHERE used_at IS NULL" 2>/dev/null)" = 1 ] || fail "pedido de senha nao registrado (e-mail via MAIL_MAILER=log)"
expect 200 "$BASE/entrar"; T="$(csrf)"
expect 302 -X POST "$BASE/entrar" --data-urlencode "_token=$T" --data-urlencode "email=smoke@exemplo.test" --data-urlencode "password=senha-muito-boa-1"
expect 200 "$BASE/registrar"
expect 200 "$BASE/historico"
expect 200 "$BASE/exportar"
expect 200 "$BASE/alertas"
grep -q 'src="/favicon.svg"' "$HOME_DIR/body" || fail "logo ausente no topo"
grep -q 'Notificações indisponíveis no servidor' "$HOME_DIR/body" || fail "/alertas sem VAPID nao avisou"

echo "==> Atualizador web (uso unico)"
expect 200 "$BASE/atualizar"
expect 200 -X POST "$BASE/atualizar" --data-urlencode "token=$TOKEN"
expect 404 "$BASE/atualizar"
echo "==> VAPID criado pelo instalador (sem SSH), privada nunca na tela, nao sobrescreve"
grep -qE '^VAPID_PUBLIC_KEY=\S{60,}$' "$HOME_DIR/bipolar/.env" || fail "VAPID_PUBLIC_KEY nao foi criada"
grep -qE '^VAPID_PRIVATE_KEY=\S{20,}$' "$HOME_DIR/bipolar/.env" || fail "VAPID_PRIVATE_KEY nao foi criada"
grep -q '^VAPID_SUBJECT=.\+' "$HOME_DIR/bipolar/.env" || fail "VAPID_SUBJECT vazio"
PRIV="$(sed -n 's/^VAPID_PRIVATE_KEY=//p' "$HOME_DIR/bipolar/.env")"; ! grep -qF "$PRIV" "$HOME_DIR/body" || fail "chave privada apareceu na tela"
cp "$HOME_DIR/bipolar/.env" "$HOME_DIR/env.1"
sed -i "s#^INSTALLER_TOKEN=.*#INSTALLER_TOKEN=${TOKEN}novo#" "$HOME_DIR/bipolar/.env"
expect 200 -X POST "$BASE/atualizar" --data-urlencode "token=${TOKEN}novo"
diff <(grep ^VAPID "$HOME_DIR/env.1") <(grep ^VAPID "$HOME_DIR/bipolar/.env") >/dev/null || fail "instalador sobrescreveu VAPID"

echo "==> Nenhum 500 / erro no log"
if grep -q ' 500 ' "$HOME_DIR/server.log"; then fail "houve 500 no servidor"; fi
if ls "$HOME_DIR"/bipolar/storage/logs/*.log >/dev/null 2>&1 && grep -q '\.ERROR' "$HOME_DIR"/bipolar/storage/logs/*.log; then fail "erro no log do Laravel"; fi
echo "OK: cpanel.zip funcionou na simulacao de cPanel"
