{{-- Marca: logo + nome. Logo e o mesmo SVG do icone (public/favicon.svg), decorativo (o nome ja esta em texto). --}}
<a href="/" class="brand inline-flex items-center gap-2 font-bold no-underline {{ $class ?? '' }}"><img src="/favicon.svg" alt="" width="{{ $size ?? 28 }}" height="{{ $size ?? 28 }}" class="rounded-lg"><span>Bipolar</span></a>
