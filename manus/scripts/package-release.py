#!/usr/bin/env python3
"""Empacota a PWA cPanel sem credenciais nem dados locais."""
from pathlib import Path
from tempfile import TemporaryDirectory
import os
import shutil
import zipfile

ROOT = Path(__file__).resolve().parent.parent
OUT = Path(os.environ.get('BIPOLAR_DELIVERY_DIR', str(ROOT / 'exports'))).resolve()
OUT.mkdir(parents=True, exist_ok=True)
BUILD = ROOT / 'dist' / 'public'
if not (BUILD / 'index.html').is_file() or not (BUILD / 'sw.js').is_file():
    raise SystemExit('Execute pnpm build:static antes de empacotar.')

def archive(directory, target):
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as z:
        for path in sorted(directory.rglob('*')):
            if path.is_file():
                z.write(path, path.relative_to(directory))
    with zipfile.ZipFile(target) as z:
        if z.testzip() is not None:
            raise SystemExit('Falha de integridade no ZIP.')
    print(str(target))

with TemporaryDirectory(prefix='bipolar-release-') as temporary:
    stage = Path(temporary)
    public = stage / 'public_html'
    shutil.copytree(BUILD, public)
    # Configuração do template gerenciado não é utilizada pelo backend PHP.
    shutil.rmtree(public / 'api', ignore_errors=True)
    shutil.rmtree(public / '__manus__', ignore_errors=True)
    (public / 'api').mkdir()
    shutil.copy2(ROOT / 'backend-php/public/index.php', public / 'api/index.php')
    private = stage / 'bipolar-private'
    private.mkdir()
    for name in ['bootstrap.php', 'media-range.php', 'report-render.php', 'automation.php', 'automation-guards.php', 'cron.php', 'config.example.php']:
        shutil.copy2(ROOT / 'backend-php/private' / name, private / name)
    shutil.copy2(ROOT / 'backend-php/schema.sql', stage / 'schema.sql')
    shutil.copy2(ROOT / 'backend-php/migration-v2.sql', stage / 'migration-v2.sql')
    shutil.copytree(ROOT / 'backend-php/private/vendor', private / 'vendor')
    import json
    composer = json.loads((ROOT / 'backend-php/composer.json').read_text())
    composer['config']['vendor-dir'] = 'vendor'
    (private / 'composer.json').write_text(json.dumps(composer, indent=2) + '\n')
    shutil.copy2(ROOT / 'backend-php/composer.lock', private / 'composer.lock')
    shutil.copytree(ROOT / 'docs', stage / 'guias')
    (stage / 'LEIA-ANTES-DE-EXTRAIR.txt').write_text(
        'public_html/ contém arquivos públicos. bipolar-private/ deve ficar FORA de public_html, na pasta home da conta cPanel.\n'
        'Leia guias/IMPLANTACAO-CPANEL.md antes de ativar serverEnabled ou registrar informações de outras pessoas.\n'
        'Não há senhas nem credenciais neste pacote. O domínio não foi implantado automaticamente.\n', encoding='utf-8')
    archive(stage, OUT / 'bipolar-cpanel-deploy.zip')

with TemporaryDirectory(prefix='bipolar-source-') as temporary:
    source = Path(temporary) / 'bipolar'
    source.mkdir()
    directories = ['client', 'server', 'shared', 'drizzle', 'patches', 'backend-php', 'docs', 'scripts']
    def ignore(directory, names):
        return [name for name in names if name in ['node_modules', '.git', '.manus-logs', 'storage', 'config.php', 'vendor'] or name.startswith('.env') or name.endswith('.log')]
    for name in directories:
        if (ROOT / name).is_dir():
            shutil.copytree(ROOT / name, source / name, ignore=ignore)
    for name in ['package.json', 'pnpm-lock.yaml', 'pnpm-workspace.yaml', 'tsconfig.json', 'vite.config.ts', 'vitest.config.ts', 'app.config.ts', 'TODO.md', '.gitignore', '.prettierrc', '.prettierignore', 'components.json']:
        if (ROOT / name).is_file():
            shutil.copy2(ROOT / name, source / name)
    archive(source.parent, OUT / 'bipolar-codigo-fonte.zip')

for name in ['LEIA-ME.md', 'IMPLANTACAO-CPANEL.md', 'REFERENCIAS-E-ESCALA.md', 'RELATORIOS-E-NOTIFICACOES.md']:
    shutil.copy2(ROOT / 'docs' / name, OUT / name)
    print(str(OUT / name))
