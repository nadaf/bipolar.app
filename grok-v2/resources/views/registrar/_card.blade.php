{{-- Cartao (acordeao nativo <details>). O formulario salva so este bloco. --}}
<details class="regblock {{ !empty($private) ? 'regblock-private' : '' }}" id="bloco-{{ $key }}" @if(!empty($open)) open @endif>
  <summary>
    <span class="flex flex-col min-w-0">
      <span class="font-semibold flex items-center gap-2">
        @if(!empty($private))@include('partials.icon', ['name' => 'cadeado', 'class' => 'size-4']) <span class="sr-only">Privado:</span>@endif
        {{ $title }}
        @if(!empty($perPeriod))<span class="badge badge-muted">{{ $periodLabel }}</span>@else<span class="badge badge-muted">dia todo</span>@endif
      </span>
      <span id="s-{{ $key }}" class="regblock-status">{{ $summary ?: 'Ainda não registrado' }}</span>
    </span>
    @include('partials.icon', ['name' => 'chev', 'class' => 'chev'])
  </summary>
  <div class="regblock-body">
    {{ $slot ?? '' }}
    @isset($formFields)
    <form hx-post="{{ $action }}" hx-target="find [data-status]" hx-swap="innerHTML" data-bloco="{{ $key }}" data-titulo="{{ $title }}"
      data-intimo="{{ !empty($intimate) ? '1' : '0' }}" data-chave="{{ $date }}|{{ $period }}|{{ $key }}" class="space-y-5" novalidate>
      @csrf
      <input type="hidden" name="entry_date" value="{{ $date }}">
      <input type="hidden" name="period" value="{{ $period }}">
      <input type="hidden" id="v-{{ $key }}" name="version" value="{{ $version }}">
      {!! $formFields !!}
      <div class="flex flex-wrap items-center gap-3">
        <button class="btn-primary" data-continuar="1">Salvar e continuar</button>
        @if(!empty($intimate))<span class="hint">Privado: sem internet, não fica guardado no aparelho.</span>@endif
      </div>
      <div data-status aria-live="polite"></div>
    </form>
    @endisset
  </div>
</details>
