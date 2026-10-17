# Histórico de versões (versão grok)

## 0.5.0-beta — V4.8
- **Pensamentos por período** (Manhã/Tarde/Noite), escala Não/Leve/Moderado/Intenso: positivos, negativos, acelerados, grandiosidade, ruminação/culpa.
  Com o módulo ligado, "acelerados" do dia passa a ser o maior valor dos períodos (alertas e relatórios antigos continuam funcionando).
- **Pensamentos de morte ou suicídio**: módulo privado, desligado por padrão, protegido pelo mesmo PIN/biometria (Revelar/Ocultar).
  Ao marcar qualquer nível ≥ 1 abre na hora um painel de apoio acessível (CVV 188, SAMU 192, contato de confiança), que funciona sem internet.
  Ninguém é avisado automaticamente; só alertas personalizados criados pela pessoa (modelo "Pensamentos de morte", desligado). Categoria própria na IA (desligada),
  relatórios só com seleção explícita. Política de privacidade cita como categoria sensível.
- Modelo de alerta **"Sinais de hipomania"** (acelerados alto + grandiosidade + sono baixo), desligado por padrão.
- **Nicotina** em Hábitos, ao lado do café: cigarros (0–100, inteiro) e "outra forma" (vape/pod, palha, charuto/cachimbo, narguilé, adesivo/chiclete).
  Histórico, relatórios, CSV/JSON e alerta "cigarros acima de X por N dias".
- **Compartilhar com profissional de saúde** (controlado pelo paciente): convite por e-mail (link de uso único, 14 dias, preso ao e-mail; copiar/WhatsApp,
  e-mail só se o SMTP estiver configurado) ou por código do profissional (24 h). Categorias escolhidas (sensíveis desligadas), prazo opcional,
  revogação imediata, registro de acessos visível ao paciente. Profissional autodeclarado (CRP/CRM informado, não verificado) vê só leitura em "Meus pacientes".
- Migração `migrations/2026-11-10-v4-8-pensamentos-nicotina-profissionais.sql`. Service worker `bp-v15`. Testes `V48Test`, `V48CareTest`, `tests/js/regress-v48.mjs`.

## 0.4.2-beta — V4.7.2
- **Correção:** o bloco "Meu dia" podia mostrar (e salvar) os dados de outra data depois de trocar a data — por exemplo, registrar hoje e escolher ontem.
  Agora uma resposta atrasada de outra data é descartada, toda troca de data feita pelo app ("Editar", "Novo registro", após salvar, IA) recarrega o bloco,
  e sem conexão o bloco fica vazio com aviso e não permite salvar por cima de um dia que não foi carregado.
- Testes: `tests/Integration/V472Test.php` e `tests/js/regress-v472.mjs`. Service worker `bp-v14`.

## 0.4.1-beta — V4.7.1
- Valores fora da escala (estresse, alimentação, sono, sinais do dia…) são recusados com erro 422 e mensagem em português.
- Limpar o estresse/alimentação de um período apaga a linha vazia; dias sem nenhum dado deixam de existir no banco.
- Migração `migrations/2026-11-04-v4-7-1-limpeza-vazios.sql` (remove linhas vazias antigas).

## 0.4.0-beta — V4.7
- Aviso "salvo" some sozinho (~4 s) e não cobre a barra de abas.
- **Estresse por período** (Manhã/Tarde/Noite) e novo módulo **Alimentação** por período (doces, ultraprocessados, comer fora de hora/compulsão),
  presente no histórico, relatórios, exportação, alertas personalizados (modelo "Doces e ansiedade") e, com autorização, na IA.
- Botões "Ocultar/Revelar" com rótulo e `aria-label` corretos; termos citam a verificação em duas etapas.
- Ponto de extensão genérico `app/lib/extensions.php`, desligado sem `app/ext/` (ver `docs/EXTENSOES.md`). O app público funciona completo sem ele.

## 0.3.x — V4.5 e V4.6
- Páginas informativas `/profissionais.php` e `/comecar.php`, imagens Open Graph, `sitemap.xml` e ajustes de SEO.
- Recursos comerciais do site oficial (formulários de contato, planos e cobrança) **não fazem parte deste repositório**.
