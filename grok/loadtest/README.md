# Kit de teste de carga — Bipolar (bipolar.app.br)

> **Não foi executado contra o site real.** Ele foi validado só localmente (servidor PHP de teste + MySQL 5.7).
> Rode no site real apenas numa janela combinada (de preferência de madrugada) e avise a hospedagem, se possível.

## O que tem aqui

| Arquivo | Para quê |
|---|---|
| `bipolar.js` | Script [k6](https://k6.io): login com CSRF, Hoje, salvar manhã/tarde/noite, Histórico (API de 30 dias + calendário), mídias, exportar CSV/JSON, relatório, lembretes — com pausas de 3–8 s entre ações |
| `run.sh` | Atalhos: `./run.sh smoke` (1 usuário, 30 s) e `./run.sh full` (perfil completo) |
| `results/` | Resumos gerados ao fim de cada execução (`resumo-*.txt` e `.json`) |

No servidor (vêm no `bipolar-update-qa1.zip`):

| Arquivo | Para quê |
|---|---|
| `tools/seed_loadtest.php` | Cria N contas `loadtest+001@bipolar.app.br` … já verificadas, mesma senha, com 30 dias de histórico. Rodar pelo **Cron do cPanel** (uma vez). Idempotente |
| `tools/cleanup_loadtest.php` | Apaga **todas** as contas `loadtest+NNN@` e os dados delas (sem `--yes` só simula) |
| `bipolar.app.br/loadtest-admin.php` | Alternativa pelo navegador para criar/apagar as contas. **Desativado (404)** até você preencher `loadtest_admin_secret`; cada segredo vale uma vez por ação |
| `app/lib/loadtest.php` | Funções usadas pelos três acima |

## Perfil de carga

Rampa de 30 s e **~2 min parado** em cada patamar: **5 → 10 → 25 → 50 → 100** usuários simultâneos (≈ 13 min no total).
O k6 **interrompe sozinho** (código de saída 99) quando, após 30 s de dados:

- a taxa de erros HTTP passa de **5%** (`MAX_ERROR_RATE`), ou
- o **p95** do tempo de resposta passa de **5 s** (`P95_MS`), ou
- menos de 95% das verificações (login, salvar, exportar…) passam.

## Passo a passo (site real)

### 1. Instale o k6 no seu computador
Windows: `winget install k6 --source winget` · macOS: `brew install k6` · Linux: veja https://grafana.com/docs/k6/latest/set-up/install-k6/

### 2. Libere o teste no `config.php` do servidor (temporário)
Edite `/home/SEU_USUARIO/app/config.php` e preencha (gere valores aleatórios, por exemplo em https://www.random.org/strings/ ou com `openssl rand -hex 24`):

```php
'loadtest_bypass_ratelimit_token' => 'COLE_AQUI_48_CARACTERES_ALEATORIOS',
```

Sem isso, o limite de **30 logins / 15 min por IP** e de **120 registros / hora por usuário** derrubaria o teste
(todos os usuários virtuais saem do mesmo IP). Requisições **sem** o cabeçalho `X-Loadtest-Bypass` continuam limitadas normalmente.

### 3. Crie as contas de teste (escolha UMA forma)

**a) Cron do cPanel (recomendado)** — cPanel → *Cron Jobs* → adicione para daqui a 2 minutos (ou “Once Per Minute” e apague depois):

```
/usr/local/bin/php /home/SEU_USUARIO/tools/seed_loadtest.php --count=100 --password='Carga-Teste-2026!' --days=30 >> /home/SEU_USUARIO/loadtest-seed.log 2>&1
```

Confira `/home/SEU_USUARIO/loadtest-seed.log` no Gerenciador de Arquivos (“contas criadas: 100”) e **apague o cron**.
(Rodar mais vezes não duplica nada.) Se o `php` do cron não for o 8.x, use `/usr/local/bin/ea-php83` ou `/opt/cpanel/ea-php83/root/usr/bin/php`.

**b) Navegador** — preencha `'loadtest_admin_secret' => '<32+ caracteres>'` no config, abra `https://bipolar.app.br/loadtest-admin.php`,
informe segredo, quantidade (100), senha comum (12+) e clique **Criar contas**.

**c) Pelo formulário de cadastro** (`SIGNUP=1` no k6) — só funciona com o `BYPASS_TOKEN` e com `require_email_verification = false`
(caso contrário as contas ficam aguardando confirmação de e-mail). As formas a/b são mais rápidas e não disparam e-mails.

### 4. Rode
Na pasta `loadtest/`:

```bash
export BASE_URL=https://bipolar.app.br CONFIRM_LIVE=sim
export BYPASS_TOKEN='o mesmo valor do config'
export PASSWORD='Carga-Teste-2026!' ACCOUNTS=100
./run.sh smoke     # 30 s com 1 usuário: deve terminar com 0% de erros
./run.sh full      # perfil completo
```

No Windows (PowerShell): `$env:BASE_URL="https://bipolar.app.br"; $env:CONFIRM_LIVE="sim"; ...; k6 run bipolar.js`.

Outras variáveis: `STAGES=5,10,25` (patamares), `HOLD=2m`, `RAMP=30s`, `THINK_MIN=3`, `THINK_MAX=8`, `P95_MS=5000`, `MAX_ERROR_RATE=0.05`, `EMAIL_DOMAIN=bipolar.app.br`.
Por segurança o script **se recusa** a rodar contra `bipolar.app.br` sem `CONFIRM_LIVE=sim`.

### 5. Limpe tudo (obrigatório)
1. Apague as contas: cron único com
   `/usr/local/bin/php /home/SEU_USUARIO/tools/cleanup_loadtest.php --yes >> /home/SEU_USUARIO/loadtest-seed.log 2>&1`
   (sem `--yes` ele só lista), ou o botão **Apagar TODAS as contas loadtest+** no painel.
2. No `config.php`, volte **`'loadtest_bypass_ratelimit_token' => ''`** e **`'loadtest_admin_secret' => ''`**.
3. Apague os crons criados e o arquivo `loadtest-seed.log`.

## Como ler o resultado
O resumo mostra requisições/s, % de erros, p95/p99, tempo de login e de salvar registro e o pico de usuários.
Em hospedagem compartilhada (cPanel/CloudLinux), o limite de **Entry Processes** (processos PHP simultâneos, muitas vezes 20–30)
costuma ser o gargalo: erros **508 / 503** ou p95 subindo de repente a partir de 25–50 usuários indicam esse limite, não um defeito do app.
Veja *cPanel → Métricas → Uso de recursos* logo após o teste.

Validação local (14/10/2026): 25 contas, 2 → 5 e 3 → 6 usuários contra o servidor PHP de teste — 0% de erros, p95 4–180 ms;
interrupção automática conferida (limite de p95 forçado em 2 ms → k6 parou com código 99).
