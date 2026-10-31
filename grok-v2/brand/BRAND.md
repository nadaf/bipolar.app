# Marca Bipolar

Logo: onda (altos e baixos do humor) com um ponto no centro (o dia de hoje), em branco sobre gradiente diagonal.
Fonte única de tudo: `scripts/build-brand.py` (rode de novo após mudar cores ou desenho; requer `rsvg-convert` e Pillow).

## Cores
| Uso | Cor |
|---|---|
| Gradiente 1 (azul) | `#3b4f9a` |
| Gradiente 2 (verde-petróleo) | `#2f6f73` |
| Gradiente 3 (laranja) | `#ef9a4b` |
| Texto | `#1d2b36` |
| Fundo claro / abertura Android | `#f8fafc` |
| Fundo escuro | `#020617` |
| `theme_color` (barra do sistema) | `#3b4f9a` |

O gradiente vai sempre do canto superior esquerdo (azul) ao inferior direito (laranja). A marca é sempre branca sobre o gradiente, ou monocromática (branca ou preta) sobre transparente.

## Uso
- Deixe um respiro de pelo menos 1/8 do lado do ícone em volta dele. Não distorça, não gire, não troque as cores do gradiente, não ponha sombra.
- Tamanho mínimo: 16 px (favicon).
- Ícones "maskable" e adaptativos: a marca fica dentro da zona segura (80% no PWA, 264/432 px no Android).

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
