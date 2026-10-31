#!/usr/bin/env python3
"""Gera todos os arquivos de marca a partir do logo oficial (medidor de humor), redesenhado em SVG
fiel a resources/brand/src/logo-oficial-512.png. Requer rsvg-convert e Pillow.
Uso: python3 scripts/build-brand.py"""
import io, math, os, subprocess
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PUB, BRAND = os.path.join(ROOT, 'public'), os.path.join(ROOT, 'brand')

# Paleta amostrada do PNG oficial
NAVY, SMILE, DOT = '#294767', '#7ba1c8', '#2ca634'
INK, BG_LIGHT, BG_DARK, WHITE = '#1d2b36', '#f8fafc', '#020617', '#ffffff'
ARC = [(134, '#0353c1'), (150, '#0265c8'), (180, '#037ecb'), (195, '#0388ca'), (210, '#0397c7'), (225, '#08a7bf'),
       (245, '#24b6a6'), (261, '#45c086'), (279, '#a8c83a'), (295, '#d9c81c'), (315, '#fdc209'), (330, '#fc9a1e'),
       (345, '#fc7a35'), (360, '#fd673f'), (390, '#fc494e'), (406, '#fb3f4b')]
CX, CY, R, W = 256, 280, 165, 51          # arco: centro, raio médio, espessura (caixa 512)

def hexrgb(h): return tuple(int(h[i:i+2], 16) for i in (1, 3, 5))
def color_at(a):
    for (a0, c0), (a1, c1) in zip(ARC, ARC[1:]):
        if a0 <= a <= a1:
            t = (a - a0) / (a1 - a0); r0, r1 = hexrgb(c0), hexrgb(c1)
            return '#%02x%02x%02x' % tuple(round(r0[i] + (r1[i] - r0[i]) * t) for i in range(3))
    return ARC[-1][1]
def pt(a, r): return CX + r * math.cos(math.radians(a)), CY + r * math.sin(math.radians(a))

def arc_segments(a_from, a_to, width, step=2):
    out, a = [], a_from
    while a < a_to:
        b = min(a + step + 0.6, a_to)               # leve sobreposição evita frestas
        (x0, y0), (x1, y1) = pt(a, R), pt(b, R)
        out.append(f'<path d="M{x0:.2f} {y0:.2f}A{R} {R} 0 0 1 {x1:.2f} {y1:.2f}" stroke="{color_at(a + step / 2)}"/>')
        a += step
    return f'<g fill="none" stroke-width="{width}">' + ''.join(out) + '</g>'

def gauge(detail=True, mono=None):
    """Desenho em caixa 512x512. detail=False: versão simples p/ 16-32 px. mono: cor única (silhueta)."""
    w = W if detail else 70
    gap = 9 if detail else 11
    if mono:
        x0, y0 = pt(134, R); x1, y1 = pt(270 - gap, R); x2, y2 = pt(270 + gap, R); x3, y3 = pt(406, R)
        body = (f'<g fill="none" stroke="{mono}" stroke-width="{w}" stroke-linecap="round">'
                f'<path d="M{x0:.2f} {y0:.2f}A{R} {R} 0 0 1 {x1:.2f} {y1:.2f}"/><path d="M{x2:.2f} {y2:.2f}A{R} {R} 0 0 1 {x3:.2f} {y3:.2f}"/></g>')
    else:
        (lx, ly), (rx, ry) = pt(134, R), pt(406, R)
        body = (arc_segments(134, 270 - gap, w) + arc_segments(270 + gap, 406, w)
                + f'<circle cx="{lx:.2f}" cy="{ly:.2f}" r="{w/2}" fill="{color_at(134)}"/>'
                + f'<circle cx="{rx:.2f}" cy="{ry:.2f}" r="{w/2}" fill="{color_at(406)}"/>')
        if detail:
            ticks = ''.join(f'<path d="M{pt(a,154)[0]:.2f} {pt(a,154)[1]:.2f}L{pt(a,176)[0]:.2f} {pt(a,176)[1]:.2f}"/>'
                            for a in (164, 189, 214, 239, 301, 326, 351, 376))
            body += f'<g stroke="{WHITE}" stroke-width="8" stroke-linecap="round">{ticks}</g>'
    ink = mono or NAVY
    dot_r = 21.5 if detail else 30
    body += f'<circle cx="256" cy="109.5" r="{dot_r + 5}" fill="{"none" if mono else WHITE}"/><circle cx="256" cy="109.5" r="{dot_r}" fill="{mono or DOT}"/>'
    if detail:
        body += f'<path d="M253.4 137L258.6 137L269.5 241A13.5 13.5 0 0 1 242.5 241Z" fill="{ink}"/>'
        body += (f'<path d="M184.2 316.6A87.6 87.6 0 0 0 327.8 316.6" fill="none" stroke="{mono or SMILE}" '
                 f'stroke-width="16" stroke-linecap="round"/>')
    else:
        body += f'<path d="M248 150L264 150L280 250A24 24 0 0 1 232 250Z" fill="{ink}"/>'
    return body

def place(body, size, box):
    """Põe o desenho (caixa 512, conteúdo útil ~60..452 x 82..425) centralizado em 'box' px dentro de 'size'."""
    return place_at(body, size / 2, size / 2, box)

def place_at(body, cx, cy, box):
    s = box / 400
    tx, ty = cx - 256 * s, cy - 253 * s
    return f'<g transform="translate({tx:.2f} {ty:.2f}) scale({s:.4f})">{body}</g>'

def svg(w, h, body): return f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}">{body}</svg>'
def png(svgtxt, path, w, h):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    data = subprocess.run(['rsvg-convert', '-w', str(w), '-h', str(h)], input=svgtxt.encode(), capture_output=True, check=True).stdout
    im = Image.open(io.BytesIO(data)); im.save(path, optimize=True); return im
def write(path, txt):
    os.makedirs(os.path.dirname(path), exist_ok=True); open(path, 'w').write(txt)
def font(sz, w='700', c=INK): return f'font-family="DejaVu Sans, Arial, sans-serif" font-size="{sz}" font-weight="{w}" fill="{c}"'
def tile(n, rx, box, bg=WHITE, detail=True):
    return svg(n, n, f'<rect width="{n}" height="{n}" rx="{rx}" fill="{bg}"/>' + place(gauge(detail), n, box))

# --- SVG mestre (igual ao PNG oficial: azulejo branco, cantos 25%)
logo = svg(512, 512, f'<rect width="512" height="512" rx="128" fill="{WHITE}"/>' + gauge())
write(os.path.join(ROOT, 'resources/brand/logo.svg'), logo)
write(os.path.join(BRAND, 'logo.svg'), logo)
write(os.path.join(BRAND, 'logo-mark.svg'), svg(512, 512, gauge()))
fav = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><style>.b{{fill:#fff}}'
       f'@media (prefers-color-scheme: dark){{.b{{fill:#f1f5f9}}}}</style>'
       f'<rect class="b" width="512" height="512" rx="112"/>' + place(gauge(), 512, 440) + '</svg>')
write(os.path.join(PUB, 'favicon.svg'), fav)
mono_svg = svg(96, 96, place(gauge(mono=WHITE), 96, 84))
write(os.path.join(PUB, 'icons/badge.svg'), mono_svg)

# --- favicons: versão simplificada em 16/32, completa em 48
ico = [png(tile(16, 3, 16, detail=False), '/tmp/f16.png', 16, 16), png(tile(32, 6, 31, detail=False), '/tmp/f32.png', 32, 32),
       png(tile(48, 10, 44), '/tmp/f48.png', 48, 48)]
ico[2].save(os.path.join(PUB, 'favicon.ico'), sizes=[(16, 16), (32, 32), (48, 48)], append_images=ico[:2])
png(tile(32, 6, 31, detail=False), os.path.join(PUB, 'icons/favicon-32.png'), 32, 32)
png(svg(180, 180, f'<rect width="180" height="180" fill="{WHITE}"/>' + place(gauge(), 180, 140)), os.path.join(PUB, 'apple-touch-icon.png'), 180, 180)
for n in (192, 512):
    png(tile(n, n / 4, n * 0.78), os.path.join(PUB, f'icons/icon-{n}.png'), n, n)                      # any (como o oficial)
    png(svg(n, n, f'<rect width="{n}" height="{n}" fill="{WHITE}"/>' + place(gauge(), n, n * 0.62)),
        os.path.join(PUB, f'icons/maskable-{n}.png'), n, n)                                             # dentro da zona segura
png(mono_svg, os.path.join(PUB, 'icons/badge-96.png'), 96, 96)
png(svg(512, 512, place(gauge(mono=WHITE), 512, 440)), os.path.join(PUB, 'icons/monochrome-512.png'), 512, 512)

# --- telas de abertura iOS (retrato)
IOS = [(430, 932, 3), (393, 852, 3), (428, 926, 3), (390, 844, 3), (375, 812, 3), (414, 896, 2), (414, 896, 3),
       (414, 736, 3), (375, 667, 2), (320, 568, 2), (1024, 1366, 2), (834, 1194, 2), (820, 1180, 2), (810, 1080, 2),
       (768, 1024, 2), (744, 1133, 2)]
links = []
for w, h, r in IOS:
    W_, H_ = w * r, h * r
    k = min(W_, H_) * 0.34
    body = (f'<rect width="{W_}" height="{H_}" fill="{BG_LIGHT}"/>'
            + place_at(gauge(), W_ / 2, H_ / 2 - k * 0.2, k)
            + f'<text x="{W_/2}" y="{H_/2 + k*0.62:.1f}" text-anchor="middle" {font(k*0.2)}>Bipolar</text>')
    name = f'splash/apple-splash-{W_}x{H_}.png'
    png(svg(W_, H_, body), os.path.join(PUB, name), W_, H_)
    links.append(f'<link rel="apple-touch-startup-image" href="/{name}" media="(device-width: {w}px) and (device-height: {h}px) and (-webkit-device-pixel-ratio: {r}) and (orientation: portrait)">')
write(os.path.join(ROOT, 'resources/views/partials/apple-splash.blade.php'), '{{-- gerado por scripts/build-brand.py --}}\n' + '\n'.join(links) + '\n')

# --- lojas e redes
png(svg(1024, 1024, f'<rect width="1024" height="1024" fill="{WHITE}"/>' + place(gauge(), 1024, 800)), os.path.join(BRAND, 'icon-1024.png'), 1024, 1024)
png(svg(432, 432, place(gauge(), 432, 250)), os.path.join(BRAND, 'android-adaptive-foreground-432.png'), 432, 432)
png(svg(432, 432, f'<rect width="432" height="432" fill="{WHITE}"/>'), os.path.join(BRAND, 'android-adaptive-background-432.png'), 432, 432)
png(svg(432, 432, place(gauge(mono='#000'), 432, 250)), os.path.join(BRAND, 'android-adaptive-monochrome-432.png'), 432, 432)
fg = (f'<rect width="1024" height="500" fill="{WHITE}"/>' + place_at(gauge(), 250, 250, 340)
      + f'<text x="440" y="235" {font(88)}>Bipolar</text><text x="443" y="300" {font(34, "400", "#50606c")}>Seu humor, um dia de cada vez</text>'
      + f'<rect x="443" y="335" width="420" height="8" rx="4" fill="{NAVY}"/>')
png(svg(1024, 500, fg), os.path.join(BRAND, 'play-feature-1024x500.png'), 1024, 500)
# og: reproduz o cartão social oficial (sem a frase comercial)
line = '100,520 220,500 340,540 460,470 580,510 700,490 820,530 940,480 1100,505'
og = (f'<rect width="1200" height="630" fill="#f3f6f8"/><rect x="40" y="40" width="1120" height="550" rx="36" fill="#fff" stroke="#cfd9df" stroke-width="3"/>'
      + place_at(gauge(), 160, 160, 128)
      + f'<text x="250" y="190" {font(68)}>Bipolar</text>'
      + f'<text x="100" y="320" {font(46)}>Seu humor, um dia de cada vez</text>'
      + f'<text x="100" y="385" {font(30, "400", "#50606c")}>Diário de humor gratuito e de código aberto para pacientes.</text>'
      + f'<text x="100" y="430" {font(30, "400", "#50606c")}>Você no controle dos seus dados. Blog: blog.bipolar.app.br</text>'
      + f'<polyline points="{line}" fill="none" stroke="#5fae8f" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/>'
      + f'<text x="1100" y="570" text-anchor="end" {font(26, "400", "#2f6f73")}>bipolar.app.br</text>')
png(svg(1200, 630, og), os.path.join(PUB, 'og-image.png'), 1200, 630)
Image.open(os.path.join(PUB, 'og-image.png')).save(os.path.join(BRAND, 'og-1200x630.png'), optimize=True)
# Atalhos do manifesto (96 px): fundo da marca + ícone de linha das telas (mesmos de partials/icon)
GLYPH = {'registrar': '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
         'historico': '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><circle cx="8" cy="15" r="1"/><circle cx="12" cy="15" r="1"/><circle cx="16" cy="15" r="1"/>',
         'relatorios': '<path d="M3 3v18h18"/><path d="m7 15 4-5 3 3 5-7"/>'}
for k, g in GLYPH.items():
    body = (f'<rect width="96" height="96" rx="22" fill="{NAVY}"/>'
            f'<g transform="translate(24 24) scale(2)" fill="none" stroke="{WHITE}" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{g}</g>'
            f'<circle cx="78" cy="18" r="7" fill="{DOT}"/>')
    png(svg(96, 96, body), os.path.join(PUB, f'icons/shortcut-{k}-96.png'), 96, 96)
print('ok')
