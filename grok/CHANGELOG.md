# Histórico de versões (versão grok)

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
