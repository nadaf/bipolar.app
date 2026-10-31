{{-- Fragmento de resposta de um cartao (HTMX). data-result alimenta o resumo do "Salvar tudo". --}}
<p class="{{ $tipo === 'ok' ? 'ok' : ($tipo === 'erro' ? 'alert' : 'warn') }}" data-result="{{ $tipo }}" role="{{ $tipo === 'ok' ? 'status' : 'alert' }}">
  {{ $msg }}
  @if($tipo === 'conflito') <a href="" class="underline font-semibold">Recarregar</a>@endif
</p>
@if(!empty($extra))<p class="info mt-2" role="status">{{ $extra }} <a class="underline font-semibold" href="tel:188">Ligar 188</a></p>@endif
@if($summary !== null)<span id="s-{{ $key }}" class="regblock-status" hx-swap-oob="true">✓ {{ $summary }}</span>@endif
@if($version !== null)<input type="hidden" id="v-{{ $key }}" name="version" value="{{ $version }}" hx-swap-oob="true">@endif
@if($moodVersion !== null)
  @foreach(array_keys(\App\Http\Controllers\Web\RegistrarController::MOOD_CARDS) as $mc)
    <input type="hidden" id="v-{{ $mc }}" name="version" value="{{ $moodVersion }}" hx-swap-oob="true">
  @endforeach
@endif
