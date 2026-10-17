# Ponto de extensão (V4.7)

O app deste repositório é o diário completo e não depende de nenhum módulo extra.

`app/lib/extensions.php` define um gancho genérico, **desligado por padrão**: ele só faz algo se existir `app/ext/module.php`
(pasta que **não** faz parte deste repositório). Sem essa pasta:

- `ext_has()` responde `false`, `ext_page()` não substitui nenhuma página e `ext_partial()` devolve texto vazio;
- `/profissionais.php` e `/comecar.php` mostram páginas informativas, sem formulário;
- a Política de Privacidade não tem seções extras.

Um módulo instalado em `app/ext/` pode:

| Função | O que o módulo fornece |
|---|---|
| `ext_has('recurso')` | `return ['features' => ['recurso' => true]]` em `app/ext/module.php` |
| `ext_page('nome')` | `app/ext/pages/nome.php` — usado hoje por `profissionais` e `comecar` |
| `ext_partial('nome', [...])` | `app/ext/partials/nome.php` — usado hoje por `privacidade-contatos` |

Os nomes aceitos são só letras minúsculas, números e hífen. Para desligar um módulo instalado sem apagar arquivos:
`'extensions' => ['enabled' => false]` no `config.php`.
