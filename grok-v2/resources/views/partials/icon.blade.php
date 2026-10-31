{{-- Icones SVG inline (decorativos). Sem style; stroke via classe/atributo. --}}
<svg class="{{ $class ?? 'size-6' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($name)
  @case('registrar')<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>@break
  @case('historico')<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><circle cx="8" cy="15" r="1"/><circle cx="12" cy="15" r="1"/><circle cx="16" cy="15" r="1"/>@break
  @case('relatorios')<path d="M3 3v18h18"/><path d="m7 15 4-5 3 3 5-7"/>@break
  @case('compartilhar')<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>@break
  @case('conta')<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>@break
  @case('alertas')<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>@break
  @case('pacientes')<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>@break
  @case('clinica')<path d="M3 21h18M5 21V7l7-4 7 4v14"/><path d="M10 9h4M12 7v4M9 21v-5h6v5"/>@break
  @case('cadeado')<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>@break
  @case('chev')<path d="m6 9 6 6 6-6"/>@break
  @case('sol')<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>@break
  @case('lua')<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>@break
  @case('auto')<circle cx="12" cy="12" r="9"/><path d="M12 3v18" /><path d="M12 3a9 9 0 0 1 0 18Z" fill="currentColor"/>@break
  @case('impressora')<path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>@break
  @case('sair')<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>@break
@endswitch
</svg>
