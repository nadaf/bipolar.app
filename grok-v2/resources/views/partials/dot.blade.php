@php
  $lvl = $v === null ? null : (int) round($v);
  $cls = $lvl === null ? 'dot-none' : 'lvl-'.($lvl < 0 ? 'n'.(-$lvl) : $lvl);
  $sym = $lvl === null ? '·' : ($lvl < 0 ? '▼' : ($lvl > 0 ? '▲' : '●'));
@endphp
<span class="dot {{ $cls }}" aria-hidden="true">{{ $sym }}</span>
