#!/usr/bin/env python3
"""Gera todos os arquivos de marca a partir do logo oficial (onda + ponto, gradiente azul-verde-laranja).
Requer: rsvg-convert e Pillow. Uso: python3 scripts/build-brand.py"""
import io, os, subprocess
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PUB, BRAND = os.path.join(ROOT, 'public'), os.path.join(ROOT, 'brand')
C1, C2, C3 = '#3b4f9a', '#2f6f73', '#ef9a4b'   # azul, verde-petróleo, laranja
INK, BG_LIGHT, BG_DARK = '#1d2b36', '#f8fafc', '#020617'
GRAD = f'<linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{C1}"/><stop offset=".5" stop-color="{C2}"/><stop offset="1" stop-color="{C3}"/></linearGradient>'
# marca em caixa 64x64 (do logo original); escala s, centro (cx, cy)
def mark(s, cx, cy, color='#fff'):
    return (f'<g transform="translate({cx - 32*s} {cy - 32*s}) scale({s})">'
            f'<path d="M10 34 C18 14, 26 14, 32 32 S46 50, 54 30" fill="none" stroke="{color}" stroke-width="5" stroke-linecap="round"/>'
            f'<circle cx="32" cy="32" r="4.5" fill="{color}"/></g>')
def svg(w, h, body, defs=GRAD):
    return f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}"><defs>{defs}</defs>{body}</svg>'
def png(svgtxt, path, w, h):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    data = subprocess.run(['rsvg-convert', '-w', str(w), '-h', str(h)], input=svgtxt.encode(), capture_output=True, check=True).stdout
    im = Image.open(io.BytesIO(data))
    im.save(path, optimize=True)
    return im
def tile(n, rx=0, s=None):   # quadrado com gradiente + marca; s = escala da marca
    s = s if s is not None else n / 64
    return svg(n, n, f'<rect width="{n}" height="{n}" rx="{rx}" fill="url(#g)"/>' + mark(s, n/2, n/2))
def font(sz, w='700', c=INK):
    return f'font-family="DejaVu Sans, Arial, sans-serif" font-size="{sz}" font-weight="{w}" fill="{c}"'

def write(path, txt):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w').write(txt)

# --- fontes SVG
logo = svg(64, 64, f'<rect width="64" height="64" rx="16" fill="url(#g)"/>' + mark(1, 32, 32))
write(os.path.join(ROOT, 'resources/brand/logo.svg'), logo)
fav = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><style>.b{{fill:url(#g)}}'
       f'@media (prefers-color-scheme: dark){{.b{{stroke:#e2e8f0;stroke-width:2}}}}</style><defs>{GRAD}</defs>'
       f'<rect class="b" x="1" y="1" width="62" height="62" rx="15"/>' + mark(1, 32, 32) + '</svg>')
write(os.path.join(PUB, 'favicon.svg'), fav)
mono = svg(96, 96, mark(1.35, 48, 48), defs='')          # badge/notificação: branco sobre transparente
write(os.path.join(PUB, 'icons/badge.svg'), mono)

# --- favicons e ícones web/PWA
ico_imgs = [png(tile(n, rx=n/4), f'/tmp/fav{n}.png', n, n) for n in (16, 32, 48)]
ico_imgs[2].save(os.path.join(PUB, 'favicon.ico'), sizes=[(16, 16), (32, 32), (48, 48)], append_images=ico_imgs[:2])
png(tile(32, rx=8), os.path.join(PUB, 'icons/favicon-32.png'), 32, 32)
png(tile(180, s=180/64*0.8), os.path.join(PUB, 'apple-touch-icon.png'), 180, 180)          # iOS arredonda sozinho
for n in (192, 512):
    png(tile(n, rx=n*0.22, s=n/64*0.8), os.path.join(PUB, f'icons/icon-{n}.png'), n, n)      # any
    png(tile(n, s=n/64*0.66), os.path.join(PUB, f"icons/maskable-{n}.png"), n, n)  # sangra, marca na zona segura 80%
png(mono, os.path.join(PUB, 'icons/badge-96.png'), 96, 96)
png(svg(512, 512, mark(512/64*0.9, 256, 256), defs=''), os.path.join(PUB, 'icons/monochrome-512.png'), 512, 512)

# --- telas de abertura iOS (retrato): (largura css, altura css, dpr)
IOS = [(430, 932, 3), (393, 852, 3), (428, 926, 3), (390, 844, 3), (375, 812, 3), (414, 896, 2), (414, 896, 3),
       (414, 736, 3), (375, 667, 2), (320, 568, 2), (1024, 1366, 2), (834, 1194, 2), (820, 1180, 2), (810, 1080, 2),
       (768, 1024, 2), (744, 1133, 2)]
links = []
for w, h, r in IOS:
    W, H = w*r, h*r
    k = min(W, H) * 0.28
    body = (f'<rect width="{W}" height="{H}" fill="{BG_LIGHT}"/>'
            f'<rect x="{W/2-k/2}" y="{H/2-k/2-k*0.25}" width="{k}" height="{k}" rx="{k*0.25}" fill="url(#g)"/>' + mark(k/64, W/2, H/2-k*0.25)
            + f'<text x="{W/2}" y="{H/2+k*0.55}" text-anchor="middle" {font(k*0.22)}>Bipolar</text>')
    name = f'splash/apple-splash-{W}x{H}.png'
    png(svg(W, H, body), os.path.join(PUB, name), W, H)
    links.append(f'<link rel="apple-touch-startup-image" href="/{name}" media="(device-width: {w}px) and (device-height: {h}px) and (-webkit-device-pixel-ratio: {r}) and (orientation: portrait)">')
write(os.path.join(ROOT, 'resources/views/partials/apple-splash.blade.php'),
      '{{-- gerado por scripts/build-brand.py --}}\n' + '\n'.join(links) + '\n')

# --- lojas e redes (pasta /brand, fora do site)
png(tile(1024, s=1024/64*0.8), os.path.join(BRAND, 'icon-1024.png'), 1024, 1024)               # App Store: sem transparência, sem cantos
png(svg(432, 432, mark(264/64*0.95, 216, 216), defs=''), os.path.join(BRAND, 'android-adaptive-foreground-432.png'), 432, 432)
png(svg(432, 432, '<rect width="432" height="432" fill="url(#g)"/>'), os.path.join(BRAND, 'android-adaptive-background-432.png'), 432, 432)
png(svg(432, 432, mark(264/64*0.95, 216, 216, '#000'), defs=''), os.path.join(BRAND, 'android-adaptive-monochrome-432.png'), 432, 432)
fg = svg(1024, 500, '<rect width="1024" height="500" fill="url(#g)"/>' + mark(3.6, 250, 250)
         + f'<text x="420" y="235" {font(84, c="#fff")}>Bipolar</text>'
         + f'<text x="422" y="300" {font(34, "400", "#fff")}>Diário de humor por período</text>')
png(fg, os.path.join(BRAND, 'play-feature-1024x500.png'), 1024, 500)
og = svg(1200, 630, f'<rect width="1200" height="630" fill="{BG_LIGHT}"/><rect x="40" y="40" width="1120" height="550" rx="36" fill="#fff" stroke="#cfd9df" stroke-width="3"/>'
         + '<rect x="100" y="110" width="128" height="128" rx="32" fill="url(#g)"/>' + mark(2, 164, 174)
         + f'<text x="260" y="200" {font(72)}>Bipolar</text>'
         + f'<text x="100" y="340" {font(46)}>Diário de humor por período</text>'
         + f'<text x="100" y="410" {font(32, "400", "#50606c")}>Você no controle dos seus dados.</text>'
         + f'<text x="100" y="460" {font(32, "400", "#50606c")}>Blog: blog.bipolar.app.br</text>'
         + f'<rect x="100" y="520" width="1000" height="10" rx="5" fill="url(#g)"/>')
png(og, os.path.join(PUB, 'og-image.png'), 1200, 630)
Image.open(os.path.join(PUB, 'og-image.png')).save(os.path.join(BRAND, 'og-1200x630.png'), optimize=True)
write(os.path.join(BRAND, 'logo.svg'), logo)
print('ok')
