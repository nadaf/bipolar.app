<div class="space-y-3">
  <p>Bloco privado. Para ver ou registrar, desbloqueie com seu PIN ou senha. Fica liberado por 10 minutos.</p>
  <form method="post" action="{{ route('stepup') }}" class="flex flex-wrap items-end gap-2">
    @csrf
    <input type="hidden" name="voltar" value="{{ route('diary.today', ['data' => $date, 'periodo' => $period]) }}#bloco-{{ $key }}">
    <input type="hidden" name="method" value="{{ auth()->user()->pin_hash ? 'pin' : 'password' }}">
    <div>
      <label class="label" for="su-{{ $key }}">{{ auth()->user()->pin_hash ? 'PIN' : 'Senha' }}</label>
      <input id="su-{{ $key }}" name="secret" type="password" class="input" required
        autocomplete="{{ auth()->user()->pin_hash ? 'off' : 'current-password' }}" @if(auth()->user()->pin_hash) inputmode="numeric" @endif>
    </div>
    <button class="btn-primary">Desbloquear</button>
  </form>
  @error('secret')<p class="erro" role="alert">{{ $message }}</p>@enderror
</div>
