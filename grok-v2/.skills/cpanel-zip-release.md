# Skill: cpanel-zip-release

Gerar e verificar o pacote para HostGator cPanel (sem SSH).

## Passos
1. Atualize `VERSION` e `CHANGELOG.md`.
2. Testes verdes: `vendor/bin/pint --test && vendor/bin/phpstan analyse && vendor/bin/pest`.
3. `scripts/check-public-tree.sh` (nada privado/segredo).
4. `php artisan bipolar:export-sql` num banco vazio se migrations mudaram.
5. `scripts/build-release.sh dist/bipolar-release.zip` → faz `npm ci && npm run build`, `composer install --no-dev -o`,
   ajusta `public_html/index.php` para `../bipolar/`, bloqueia `.env`/chaves, e zipa `bipolar/` + `public_html/`.
6. Teste de fumaça local: extraia em pasta temporária, crie `.env` a partir do `.env.example` com um banco vazio e
   `INSTALLER_TOKEN`, rode `php -S 127.0.0.1:8099 -t public_html`, abra `/instalar`, depois `/entrar`.
7. Confira: `unzip -l` sem `.env`, sem `tests/`, sem `node_modules/`; `public_html/build/manifest.json` e `sw.js` presentes.
8. Publicação: tag `vX.Y.Z` → CI anexa o zip na Release (só quando o dono pedir o push).
9. Usuário final segue `INSTALL-CPANEL.md`. Nunca use `config:cache` no pacote.
