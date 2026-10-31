# Marca Bipolar

Logo: medidor de humor. É um arco em gradiente que vai do azul ao verde (lado esquerdo) e do amarelo ao vermelho (lado direito), com marcas brancas, um ponto verde no topo, um ponteiro azul-marinho apontando para cima e um sorriso azul-claro.
Original: `resources/brand/src/logo-oficial-512.png`. Ele foi redesenhado em SVG fiel ao original (`resources/brand/logo.svg`, `brand/logo-mark.svg` sem fundo).
Tudo é gerado por `scripts/build-brand.py` (requer `rsvg-convert` e Pillow). Em 16 e 32 px, o ícone usa uma versão simplificada, só com o arco, o ponto e o ponteiro.

## Cores (amostradas do logo oficial)
| Uso | Cor |
|---|---|
| Arco, início (azul) | `#0353c1` |
| Arco, azul-claro / turquesa | `#037ecb` / `#08a7bf` |
| Arco, verde (topo esquerdo) | `#45c086` |
| Ponto do topo (verde) | `#2ca634` |
| Arco, lima / amarelo | `#a8c83a` / `#fdc209` |
| Arco, laranja | `#fc9a1e` |
| Arco, fim (vermelho) | `#fb3f4b` |
| Ponteiro (azul-marinho), `theme_color` | `#294767` |
| Sorriso | `#7ba1c8` |
| Marcas do arco e fundo do ícone | `#ffffff` |
| Texto | `#1d2b36` |
| Fundo claro / abertura Android | `#f8fafc` |
| Fundo escuro | `#020617` |

## Uso
- Deixe um respiro de pelo menos 1/8 do lado do ícone em volta dele. Não distorça, não gire, não troque as cores do gradiente, não ponha sombra.
- Tamanho mínimo: 16 px (favicon).
- Ícones "maskable" e adaptativos: a marca fica dentro da zona segura (medidor em ~62% do maskable; 250/432 px no Android).

## Arquivos
| Arquivo | Para quê |
|---|---|
| `public/favicon.ico` (16/32/48), `public/favicon.svg` (contorno no modo escuro) | navegador |
| `public/apple-touch-icon.png` (180) | iOS, tela inicial |
| `public/icons/icon-192/512.png` (any), `maskable-192/512.png` | PWA |
| `public/icons/monochrome-512.png`, `badge-96.png`, `badge.svg` | notificações/selos Android |
| `public/splash/apple-splash-*.png` + `resources/views/partials/apple-splash.blade.php` | abertura iOS |
| `manifest.webmanifest` background_color/theme_color + ícones | abertura automática Android |
| `#abertura` no layout (CSS, só no app instalado, 1ª carga, respeita reduced-motion) | abertura dentro do app |
| `public/og-image.png`, `brand/og-1200x630.png` | compartilhamento em redes |
| `brand/icon-1024.png` | App Store (sem transparência, sem cantos) |
| `brand/android-adaptive-{foreground,background,monochrome}-432.png` | ícone adaptativo do Android |
| `brand/play-feature-1024x500.png` | Google Play, imagem de destaque |
