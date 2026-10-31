@extends('layouts.app')
@section('title', 'Alertas personalizados — Bipolar')
@section('content')
<h1 class="text-xl font-semibold mb-2">Alertas personalizados</h1>
<p class="mb-4 text-slate-700">Escolha a partir de qual nível um registro aparece como <strong>sinal</strong> nos seus relatórios.
  Baixo conta como sinal <strong>depressivo</strong> (▼); alto conta como sinal de <strong>mania</strong> (▲).
  Nada é enviado a ninguém automaticamente. Padrão: −2 e +2.</p>
<form class="card space-y-5" method="post" action="{{ route('settings.alerts.save') }}"
      hx-post="{{ route('settings.alerts.save') }}" hx-target="#alert-status" hx-swap="innerHTML">
  @csrf
  @foreach([['mood', 'Humor', $mood], ['energy', 'Energia', $energy]] as [$k, $label, $scale])
  <fieldset>
    <legend class="label">{{ $label }}</legend>
    <div class="grid gap-3 sm:grid-cols-2">
      <div>
        <label for="{{ $k }}_low" class="label">▼ Sinal baixo a partir de</label>
        <select id="{{ $k }}_low" name="{{ $k }}_low" class="input">
          @foreach([-1, -2, -3] as $v)
            @php $i = $scale[$v]; $txt = is_array($i) ? $i[0] : $i; @endphp
            <option value="{{ $v }}" @selected($t[$k.'_low'] === $v)>{{ $v }} · {{ $txt }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="{{ $k }}_high" class="label">▲ Sinal alto a partir de</label>
        <select id="{{ $k }}_high" name="{{ $k }}_high" class="input">
          @foreach([1, 2, 3] as $v)
            @php $i = $scale[$v]; $txt = is_array($i) ? $i[0] : $i; @endphp
            <option value="{{ $v }}" @selected($t[$k.'_high'] === $v)>+{{ $v }} · {{ $txt }}</option>
          @endforeach
        </select>
      </div>
    </div>
  </fieldset>
  @endforeach
  <button class="btn-primary w-full sm:w-auto">Salvar alertas</button>
  <div id="alert-status" role="status" aria-live="polite"></div>
</form>
<p class="mt-4 text-sm text-slate-700">Pensamentos de morte nunca geram aviso automático a terceiros. Se precisar agora: CVV 188 · SAMU 192.</p>
@endsection
