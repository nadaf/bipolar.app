#!/usr/bin/env bash
# Simula o cron de atualizacao automatica (bipolar/deploy/auto-update.php) com releases FALSOS servidos
# por php -S (AUTO_UPDATE_API_URL). Cenarios: sem versao nova nao faz nada; versao nova instala e
# preserva .env/storage/.htaccess customizado; sha256 errado aborta; migration que falha restaura tudo.
# Uso: DB_HOST=127.0.0.1 DB_USER=u DB_PASS=s DB_NAME=banco_vazio scripts/smoke-auto-update.sh cpanel.zip [porta]
set -euo pipefail
ZIP="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"; PORT="${2:-8141}"
: "${DB_HOST:=127.0.0.1}" "${DB_USER:?}" "${DB_PASS:?}" "${DB_NAME:?}"
T="$(mktemp -d)"; H="$T/home"; R="$T/rel"; B="http://127.0.0.1:$PORT"; PID=""
cleanup() { [ -n "$PID" ] && kill "$PID" 2>/dev/null || true; [ "${KEEP:-0}" = 1 ] || rm -rf "$T"; }; trap cleanup EXIT
fail() { echo "FALHOU: $*"; cat "$H/bipolar/storage/logs/auto-update.log" 2>/dev/null | tail -n 30; exit 1; }
MY=(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS")
q() { "${MY[@]}" -N "$DB_NAME" -e "$1" 2>/dev/null; }

echo "==> Instalacao base (como no LEIA-ME)"
mkdir -p "$H" "$R"; ( cd "$H" && unzip -q "$ZIP" )
for t in $(q "SHOW TABLES" || true); do q "SET FOREIGN_KEY_CHECKS=0; DROP TABLE \`$t\`"; done
"${MY[@]}" "$DB_NAME" < "$H/banco/instalar-banco-novo.sql" 2>&1 | grep -v Warning && fail "sql base" || true
sed -e "s#^DB_HOST=.*#DB_HOST=$DB_HOST#" -e "s#^BIPOLAR_BASE_DOMAIN=.*#BIPOLAR_BASE_DOMAIN=localhost#" -e "s#^APP_URL=.*#APP_URL=http://127.0.0.1:$((PORT+1))#" -e "s#^DB_DATABASE=.*#DB_DATABASE=$DB_NAME#" -e "s#^DB_USERNAME=.*#DB_USERNAME=$DB_USER#" \
    -e "s#^DB_PASSWORD=.*#DB_PASSWORD=$DB_PASS#" -e "s#^APP_KEY=.*#APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));')#" "$H/bipolar/.env.example" > "$H/bipolar/.env"
echo "SEGREDO_DO_SERVIDOR=nao-mexer" >> "$H/bipolar/.env"
echo "dado do usuario" > "$H/bipolar/storage/app/dado.txt"
echo "# customizado pelo dono" >> "$H/bipolar-2-0-public/.htaccess"
q "INSERT INTO migrations (migration, batch) VALUES ('au_smoke_marcador', 99)"
BASEVER="$(cat "$H/bipolar/VERSION")"

# pacote falso: zip base + arquivos extras; $1=tag $2=nome-do-marcador $3=migration (ok|falha|-) $4=sha (ok|errado)
mkrel() {
  local tag="$1" s="$T/stage-$1"; mkdir -p "$s/bipolar/database/migrations" "$R/$tag"
  cp "$ZIP" "$R/$tag/cpanel.zip"; echo "$tag" > "$s/bipolar/$2"
  case "$3" in
    ok) printf '%s\n' '<?php' 'use Illuminate\Database\Migrations\Migration; use Illuminate\Support\Facades\Schema;' \
      'return new class extends Migration { public function up(): void { Schema::create("au_smoke", function ($t) { $t->id(); }); } };' \
      > "$s/bipolar/database/migrations/2099_01_01_000000_au_smoke.php" ;;
    falha) printf '%s\n' '<?php' 'use Illuminate\Database\Migrations\Migration; use Illuminate\Support\Facades\Schema;' \
      'return new class extends Migration { public function up(): void { Schema::create("au_quebrada", function ($t) { $t->id(); }); throw new RuntimeException("migration quebrada de teste"); } };' \
      > "$s/bipolar/database/migrations/2099_01_02_000000_au_quebrada.php" ;;
  esac
  php -r '$z=new ZipArchive; $z->open($argv[1]); $s=$argv[2]; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($s, FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){ $z->addFile($f->getPathname(), substr($f->getPathname(), strlen($s)+1)); } exit($z->close() ? 0 : 1);' "$R/$tag/cpanel.zip" "$s"
  if [ "$4" = ok ]; then (cd "$R/$tag" && sha256sum cpanel.zip > cpanel.zip.sha256); else printf '%064d  cpanel.zip\n' 0 > "$R/$tag/cpanel.zip.sha256"; fi
}
publish() { # lista de tags (mais nova primeiro) -> releases.json
  local first=1; { echo '['; for tag in "$@"; do [ $first = 1 ] || echo ','; first=0
    printf '{"tag_name":"%s","draft":false,"prerelease":true,"assets":[{"name":"cpanel.zip","browser_download_url":"%s/%s/cpanel.zip"},{"name":"cpanel.zip.sha256","browser_download_url":"%s/%s/cpanel.zip.sha256"}]}' "$tag" "$B" "$tag" "$B" "$tag"
  done; echo ']'; } > "$R/releases.json"
}
run() { env AUTO_UPDATE_API_URL="$B/releases.json" php "$H/bipolar/deploy/auto-update.php"; }

( cd "$R" && exec php -S "127.0.0.1:$PORT" > "$T/srv.log" 2>&1 ) & PID=$!
for i in $(seq 1 40); do curl -s -o /dev/null "$B/" && break; sleep 0.25; done

echo "==> 1) sem versao nova -> nada"
mkrel "v$BASEVER" igual.txt - ok; publish "v$BASEVER"
run || fail "exit != 0 sem versao nova"
[ ! -e "$H/bipolar/igual.txt" ] && [ ! -d "$H/bipolar/storage/backups" ] || fail "mexeu sem versao nova"

echo "==> 2) versao nova -> instala e preserva"
mkrel v9.0.0 NOVO.txt ok ok; publish v9.0.0 "v$BASEVER"
run || fail "instalacao falhou"
[ -f "$H/bipolar/NOVO.txt" ] || fail "arquivo novo nao veio"
grep -q '^SEGREDO_DO_SERVIDOR=nao-mexer' "$H/bipolar/.env" || fail ".env nao preservado"
[ "$(cat "$H/bipolar/storage/app/dado.txt")" = "dado do usuario" ] || fail "storage nao preservado"
grep -q 'customizado pelo dono' "$H/bipolar-2-0-public/.htaccess" || fail ".htaccess customizado nao preservado"
[ "$(cat "$H/bipolar/storage/app/versao-instalada.txt")" = v9.0.0 ] || fail "versao nao gravada"
[ "$(q "SELECT COUNT(*) FROM migrations WHERE migration LIKE '%au_smoke'")" = 1 ] || fail "migration nao rodou"
[ ! -e "$H/bipolar/storage/framework/down" ] || fail "ficou em manutencao"
ls "$H"/bipolar/storage/backups/*/arquivos.zip "$H"/bipolar/storage/backups/*/banco.sql >/dev/null || fail "sem backup"
run; [ "$(ls -d "$H"/bipolar/storage/backups/*/ | wc -l)" = 1 ] || fail "rodou de novo na mesma versao"

echo "==> 3) sha256 errado -> aborta sem mexer"
mkrel v9.0.1 NOVO2.txt - errado; publish v9.0.1 v9.0.0
run && fail "sha errado deveria sair com erro"
[ ! -e "$H/bipolar/NOVO2.txt" ] && [ "$(cat "$H/bipolar/storage/app/versao-instalada.txt")" = v9.0.0 ] || fail "sha errado alterou arquivos"
grep -q 'sha256 nao confere' "$H/bipolar/storage/logs/auto-update.log" || fail "sha errado nao logado"

echo "==> 4) migration falha -> restaura arquivos e banco"
mkrel v9.0.2 NOVO3.txt falha ok; publish v9.0.2 v9.0.0
run && fail "migration quebrada deveria sair com erro"
[ ! -e "$H/bipolar/NOVO3.txt" ] || fail "arquivos nao restaurados"
[ ! -e "$H/bipolar/database/migrations/2099_01_02_000000_au_quebrada.php" ] || fail "migration quebrada ficou"
[ -f "$H/bipolar/NOVO.txt" ] && [ -f "$H/bipolar/vendor/autoload.php" ] || fail "restauracao incompleta"
[ "$(cat "$H/bipolar/storage/app/versao-instalada.txt")" = v9.0.0 ] || fail "versao mudou apos falha"
[ -z "$(q "SHOW TABLES LIKE 'au_quebrada'")" ] || fail "banco nao restaurado (au_quebrada ficou)"
[ "$(q "SELECT COUNT(*) FROM migrations WHERE migration='au_smoke_marcador'")" = 1 ] || fail "dados do banco perdidos"
[ ! -e "$H/bipolar/storage/framework/down" ] || fail "ficou em manutencao apos falha"
grep -q 'backup restaurado' "$H/bipolar/storage/logs/auto-update.log" || fail "restauracao nao logada"

echo "==> 5) desligado pelo .env"
echo "AUTO_UPDATE_ENABLED=false" >> "$H/bipolar/.env"; mkrel v9.0.3 NOVO4.txt - ok; publish v9.0.3
run; [ ! -e "$H/bipolar/NOVO4.txt" ] || fail "instalou mesmo desligado"

echo "==> 6) o site ainda abre depois de tudo"
sed -i '/^AUTO_UPDATE_ENABLED=false/d' "$H/bipolar/.env"
( cd "$H/bipolar-2-0-public" && exec php -S "127.0.0.1:$((PORT+1))" > "$T/site.log" 2>&1 ) & SP=$!
for i in $(seq 1 40); do curl -s -o /dev/null "http://127.0.0.1:$((PORT+1))/robots.txt" && break; sleep 0.25; done
c="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$((PORT+1))/entrar")"; kill $SP 2>/dev/null || true
[ "$c" = 200 ] || fail "/entrar -> $c depois da atualizacao"
q "DELETE FROM migrations WHERE migration='au_smoke_marcador' OR migration LIKE '%au_smoke'"; q "DROP TABLE IF EXISTS au_smoke"
echo "OK: atualizacao automatica funcionou nos 6 cenarios"
