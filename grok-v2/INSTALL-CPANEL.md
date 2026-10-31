# Como instalar o Bipolar 2.0 na HostGator (cPanel) — passo a passo

Este guia é para quem **não é programador**. Você só vai usar o navegador.
Tempo estimado: 30 minutos. Não precisa de SSH.

> ⚠️ Antes de tudo: **faça um backup** do site atual e do banco de dados atual
> (cPanel → *Backup* → baixar backup completo, ou *phpMyAdmin* → *Exportar*).
> A versão 2.0 usa um **banco de dados NOVO**. Não apague o banco antigo.

---

## 1. Baixar o pacote (arquivo .zip)

Você recebe um arquivo chamado `bipolar-release.zip` (ou `bipolar-v2-release.zip`).

**Se ele estiver no GitHub:**

- **Versões oficiais (recomendado):** abra a página do projeto no GitHub →
  clique em **Releases** (lado direito) → escolha a versão mais nova (ex.: `v2.0.0-beta.1`) →
  em **Assets**, clique em `bipolar-release.zip` para baixar.
- **Versão de teste (de um commit):** na página do projeto clique em **Actions** →
  clique no item mais recente com ✅ verde chamado **CI e pacote cPanel** →
  role até **Artifacts** → clique em **bipolar-cpanel-zip**.
  O GitHub baixa um zip que tem **dentro** o `bipolar-release.zip`: abra-o no seu computador e
  use o `bipolar-release.zip` de dentro. (Esse download exige estar logado no GitHub e
  some depois de 30 dias.)

Dentro do zip há duas pastas:

| Pasta | Para onde vai |
|---|---|
| `bipolar/` | fica **fora** da pasta pública (o programa e as bibliotecas) |
| `public_html/` | é o que o visitante vê (a "raiz do site") |

## 2. Criar o banco de dados

1. No cPanel, abra **Assistente de banco de dados MySQL**.
2. Crie um banco, por exemplo `bipolar2` (o cPanel vai mostrar algo como `usuario_bipolar2`).
3. Crie um usuário com **senha forte** (use o gerador do cPanel) e **anote** usuário e senha.
4. Marque **TODOS OS PRIVILÉGIOS** e conclua.

## 3. Enviar os arquivos

1. cPanel → **Gerenciador de Arquivos**. Você estará na sua pasta inicial (ex.: `/home/seuusuario`).
2. Clique em **Carregar** e envie o `bipolar-release.zip` para essa pasta inicial
   (**não** dentro de `public_html`).
3. Clique com o botão direito no zip → **Extract** (Extrair). Aparecem `bipolar/` e `public_html/`.
   - Se já existe um site em `public_html`, o cPanel pergunta se quer sobrescrever.
     **Renomeie antes** a pasta antiga para `public_html_antigo` (assim dá para voltar).
4. Apague o zip depois de extrair.

> Se o site for um **subdomínio** (ex.: `beta.bipolar.app.br`), a pasta pública dele costuma
> ser outra (ex.: `beta.bipolar.app.br/`). Nesse caso mova o conteúdo de `public_html/` para lá
> e, no arquivo `index.php` dessa pasta, troque `/../bipolar/` pelo caminho certo
> (peça ajuda se tiver dúvida).

## 4. Configurar o arquivo `.env`

1. No Gerenciador de Arquivos, ative **Configurações → Mostrar arquivos ocultos**.
2. Entre na pasta `bipolar/`, copie `.env.example` e dê o nome **`.env`**.
3. Clique com o botão direito em `.env` → **Edit** e preencha:
   - `APP_URL=` o endereço do site, com https (ex.: `https://bipolar.app.br`)
   - `DB_DATABASE=` nome completo do banco (ex.: `usuario_bipolar2`)
   - `DB_USERNAME=` usuário do banco
   - `DB_PASSWORD=` senha do banco
   - `INSTALLER_TOKEN=` um código longo que só você sabe (mínimo 40 letras/números;
     pode gerar um no gerador de senhas do cPanel e juntar dois)
   - Dados de e-mail (`MAIL_...`) da conta de e-mail criada no cPanel.
4. Deixe `APP_KEY=` **vazio**: o instalador cria sozinho.
5. Salve.

> 🔒 **Nunca** envie o arquivo `.env` para ninguém nem para o GitHub. Ele tem senhas.

### 4.1 Subdomínios (opcional): clínicas e pessoas

Para endereços como `minhaclinica.bipolar.app.br`:
1. cPanel → **Domínios** (ou **Subdomínios**) → crie o subdomínio **`*`** (asterisco) apontando para a
   mesma pasta `public_html`. Se o cPanel não aceitar `*`, crie um subdomínio para cada clínica.
2. cPanel → **SSL/TLS Status** → rode o **AutoSSL** para incluir os subdomínios.
3. No `.env`, confira `BIPOLAR_BASE_DOMAIN=bipolar.app.br`.
4. O endereço de cada clínica ou pessoa é escolhido dentro do app (Ajustes ou API).
   Nomes como `www`, `api`, `blog`, `admin`, `mail`, `demo`, `clinica` são reservados.
5. **Não** preencha `SESSION_DOMAIN`: cada endereço tem o próprio login, de propósito.

## 5. Criar as tabelas (instalador)

1. Abra no navegador: `https://SEU-SITE/instalar`
2. Digite o mesmo código que você colocou em `INSTALLER_TOKEN` e clique **Instalar / atualizar**.
3. Deve aparecer **"Pronto!"**.
4. **Volte ao `.env` e apague o valor de `INSTALLER_TOKEN`** (deixe `INSTALLER_TOKEN=`). Salve.
   Assim o instalador fica desligado (a página passa a dar "não encontrado").

**Opção B (sem instalador):** cPanel → **phpMyAdmin** → escolha o banco → **Importar** →
envie o arquivo `bipolar/database/sql/install-mysql57.sql`. Depois disso a `APP_KEY`
precisa ser criada: use o instalador uma vez mesmo assim, ou peça ajuda.

## 6. Tarefas automáticas (cron)

cPanel → **Trabalhos Cron** → **Adicionar novo trabalho cron**:

- Configuração comum: **Uma vez por minuto** (`* * * * *`)
- Comando (troque `seuusuario`):

```
cd /home/seuusuario/bipolar && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Isso envia lembretes e processa a fila de envios.

## 7. Conferir

- `https://SEU-SITE/` deve abrir a tela **Entrar**.
- O cadeado do navegador (https) deve estar ativo (cPanel → **SSL/TLS Status** → *Run AutoSSL*).
- Em **Selecionar versão do PHP** (ou *MultiPHP Manager*), o domínio deve usar **PHP 8.3**.

## 8. Atualizar para uma versão nova

1. Baixe o zip novo (passo 1) e faça backup.
2. Extraia por cima (as pastas `bipolar/` e `public_html/`). **O `.env` não vem no zip**, então
   o seu continua lá.
3. Coloque de novo um `INSTALLER_TOKEN` no `.env`, abra `/instalar`, rode, e apague o token.

## Problemas comuns

| Sintoma | O que fazer |
|---|---|
| Tela branca ou "500" | Confira PHP 8.3 e os dados do banco no `.env`. Veja `bipolar/storage/logs/`. |
| `/instalar` diz "não encontrado" | `INSTALLER_TOKEN` vazio ou com menos de 32 caracteres. |
| "Código de instalação incorreto" | O código digitado é diferente do `.env` (atenção a espaços). |
| Página sem estilo | A pasta `public_html/build/` não foi extraída; extraia de novo. |

Em crise? **CVV 188** (24h, gratuito) · **SAMU 192**.
