@extends('layouts.app')
@section('title', 'Aceitar convite — Bipolar')
@section('content')
<div class="mx-auto max-w-md card">
  <h1 class="text-xl font-semibold">Convite para acompanhar</h1>
  <p class="mt-2">Uma pessoa convidou você para ver parte dos registros dela, somente leitura. Ela escolheu as categorias e pode revogar quando quiser; cada acesso seu fica visível para ela.</p>
  <form method="post" action="{{ route('invite.accept', $token) }}" class="mt-4">@csrf<button class="btn-primary w-full">Aceitar convite</button></form>
  <p class="hint mt-3">Convite inválido ou vencido mostra “não encontrado”. Peça um novo link.</p>
</div>
@endsection
