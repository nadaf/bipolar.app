# Migração de dados 0.5 → 2.0

Status: **implementado na 2.0.4** (`App\Domain\Legacy`). Passo a passo para quem opera o cPanel:
`scripts/cpanel/LEIA-ME.txt`, seção "TRAZER OS DADOS DA VERSAO ANTIGA".

## Como funciona
- Conexão `legado` (`config/database.php`) lida de `OLD_DB_HOST/OLD_DB_DATABASE/OLD_DB_USERNAME/OLD_DB_PASSWORD`.
  Vazia = desligada (a página só mostra um aviso).
- **Somente leitura**: `LegacyReader` só aceita `SELECT` (sem `information_schema`), abre a sessão MySQL com
  `SET SESSION TRANSACTION READ ONLY` e a orientação é um usuário do cPanel só com o privilégio SELECT.
- Só roda por ação humana: página `/migrar-dados` (POST com o `INSTALLER_TOKEN`) ou `php artisan bipolar:import-legacy`.
  A instalação/atualização automática nunca importa nada.
- **Idempotente**: cada linha antiga tratada fica em `legacy_links (kind, legacy_id)`. Repetir pula o que já foi feito.
- **Em lotes** de 200 linhas, ~20 s por clique; `legacy_import_progress` guarda o último id de cada etapa, contagens e motivos.
- A tela e o comando mostram **só contagens e motivos**; nenhum conteúdo de saúde é exibido ou logado.

## O que é copiado
| 0.5 | 2.0 | Observações |
|---|---|---|
| users | users (+`legacy_id`) + tenant pessoal + membership `patient` | nome, e-mail, fuso (inválido → America/Sao_Paulo), e-mail verificado, módulos opcionais ligados (substâncias, sexualidade, pensamentos de morte → `morte`, religiosidade) |
| users.is_professional=1 | + tenant `solo` + membership `professional` | igual a uma conta de profissional solo |
| entries | mood_entries | humor −3..3 igual; ansiedade/irritabilidade 0..3; sono; medicação; anotação (recriptografada com a APP_KEY do 2.0); versão; client_uuid |
| daily_markers | day_blocks `sono`, `meudia`, `substancias` (período `dia`) | deitar/acordar/qualidade; sol/exercício (máx. 720 min), gastos, fala, ciclo (`pre_menstrual`→`lutea`, `nao_sei`→vazio); café, cigarros, álcool, nicotina (`vape_pod`→`vape`, `adesivo_chiclete`→`adesivo`, palha/charuto→`outra`), outras |
| period_markers | day_blocks `estresse`, `alimentacao`, `pensamentos`, `religiosidade`, `morte` | por período |
| sexuality_log | day_blocks `sexualidade` | desejo −2..2 → −3,−1,0,1,3; "diferente do habitual"; anotação |
| reminders (ativos) | alert_settings.reminders (grade semanal) | período pelo horário (<12 manhã, <18 tarde, senão noite); até 6 por dia |
| — | alert_settings | limiares padrão −2/+2 criados para cada conta |
| care_shares ativos e no prazo | care_grants aceitos | `scope_mask` igual bit a bit; tipo psicólogo/psiquiatra, outros → `outro` |

## O que NÃO é copiado (a tela mostra as quantidades)
Contas sem e-mail (só Google/Apple); contas cujo e-mail já existe no 2.0; etiquetas (`tags`); verificação em duas
etapas (segredo cifrado com a chave da 0.5: a pessoa liga de novo); convites pendentes e compartilhamentos encerrados
ou vencidos; relatórios por e-mail (`recipients`); alertas personalizados e histórico de alertas; detalhe de
substâncias por dose (`substance_log`, o total do dia vem de `daily_markers`); mídia; inscrições de push; passkeys;
doses de medicação por remédio (`doses_json`); atividades/frequência de sexualidade; tokens, limites e filas.
A 0.5 não tem clínicas (só existem no 2.0).

## Senhas
A 0.5 usa `password_hash()` do PHP (Argon2id quando disponível, senão bcrypt). O hash é copiado como está.
`App\Support\Passwords::check` usa `password_verify` (aceita bcrypt e Argon2id) em todos os pontos de senha, e no
primeiro login certo a senha é regravada em bcrypt (`Passwords::upgrade`). Sem hash utilizável → hash aleatório
(ninguém sabe) e a pessoa usa "Esqueci minha senha".

## Energia: conversão 0..3 → −3..+3
Na 0.5 a energia era intensidade 0..3 (rótulos: nenhuma, leve, moderada, intensa; os alertas tratavam 3 como sinal de mania).
Conversão (`Scales::ENERGY_FROM_LEGACY`): `0 → −2 (Baixa)`, `1 → 0 (Normal)`, `2 → +1 (Um pouco alta)`, `3 → +2 (Alta)`.
O valor original fica em `mood_entries.energy_legacy` para auditoria e para refazer a conversão se a regra mudar.
Valor fora de 0..3 → energia vazia (contado nos avisos).
