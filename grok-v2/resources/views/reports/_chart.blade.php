{{-- Grafico de vida em SVG puro (sem JS, sem estilo inline). Eixo -3..+3; faixas = limiares de sinal. --}}
@php
  $W = 720; $H = 240; $L = 34; $R = 8; $T = 10; $B = 26;
  $n = max(count($series), 1);
  $x = fn (int $i) => $n === 1 ? $L + ($W - $L - $R) / 2 : $L + $i * ($W - $L - $R) / ($n - 1);
  $y = fn (float $v) => $T + (3 - $v) * ($H - $T - $B) / 6;
  $paths = function (string $k) use ($series, $x, $y) {
      $segs = []; $cur = [];
      foreach ($series as $i => $p) {
          if ($p[$k] === null) { if ($cur) { $segs[] = $cur; $cur = []; } continue; }
          $cur[] = round($x($i), 1).','.round($y((float) $p[$k]), 1);
      }
      if ($cur) $segs[] = $cur;
      return $segs;
  };
  $th = $r['thresholds'];
  $step = max(1, (int) ceil($n / 8));
@endphp
<svg class="chart" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-labelledby="chart-t chart-d">
  <title id="chart-t">Gráfico de vida: humor e energia por dia</title>
  <desc id="chart-d">Média diária de {{ $r['from'] }} a {{ $r['to'] }}. Acima de zero: elevado; abaixo: depressivo. Os dados estão também na tabela abaixo.</desc>
  <rect class="band-high" x="{{ $L }}" y="{{ $T }}" width="{{ $W - $L - $R }}" height="{{ round($y($th['mood_high']) - $T, 1) }}"/>
  <rect class="band-low" x="{{ $L }}" y="{{ round($y($th['mood_low']), 1) }}" width="{{ $W - $L - $R }}" height="{{ round($H - $B - $y($th['mood_low']), 1) }}"/>
  @foreach(range(-3, 3) as $v)
    <line class="{{ $v === 0 ? 'zero-line' : 'grid-line' }}" x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $y($v) }}" y2="{{ $y($v) }}" stroke-width="1"/>
    <text x="{{ $L - 6 }}" y="{{ $y($v) + 3 }}" text-anchor="end">{{ $v > 0 ? '+'.$v : $v }}</text>
  @endforeach
  @foreach($series as $i => $p)
    @if($i % $step === 0 || $i === $n - 1)<text x="{{ round($x($i), 1) }}" y="{{ $H - 8 }}" text-anchor="middle">{{ substr($p['date'], 8, 2) }}/{{ substr($p['date'], 5, 2) }}</text>@endif
  @endforeach
  @foreach(['energy', 'mood'] as $k)
    @foreach($paths($k) as $seg)
      @if(count($seg) > 1)<polyline class="{{ $k }}" points="{{ implode(' ', $seg) }}" stroke-width="2.5" @if($k === 'energy') stroke-dasharray="6 4" @endif/>@endif
    @endforeach
    @foreach($series as $i => $p)
      @if($p[$k] !== null)
        @if($k === 'mood')<circle class="pt-mood" cx="{{ round($x($i), 1) }}" cy="{{ round($y((float) $p[$k]), 1) }}" r="3.5"/>
        @else<rect class="pt-energy" x="{{ round($x($i) - 3, 1) }}" y="{{ round($y((float) $p[$k]) - 3, 1) }}" width="6" height="6"/>@endif
      @endif
    @endforeach
  @endforeach
</svg>
<p class="mt-2 flex flex-wrap gap-4 text-sm" aria-hidden="true">
  <span class="flex items-center gap-1"><svg width="28" height="10" class="chart"><line class="mood" x1="0" x2="28" y1="5" y2="5" stroke-width="2.5"/><circle class="pt-mood" cx="14" cy="5" r="3.5"/></svg> Humor (linha cheia, círculo)</span>
  <span class="flex items-center gap-1"><svg width="28" height="10" class="chart"><line class="energy" x1="0" x2="28" y1="5" y2="5" stroke-width="2.5" stroke-dasharray="6 4"/><rect class="pt-energy" x="11" y="2" width="6" height="6"/></svg> Energia (tracejada, quadrado)</span>
  <span>Faixas: zona de sinal alto (topo) e baixo (base)</span>
</p>
