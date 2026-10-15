<?php
function flash_html(): string {
    $f = take_flash();
    if (!$f) return '';
    $role = $f['type'] === 'err' ? 'alert' : 'status';
    return '<div class="flash flash-' . e($f['type']) . '" role="' . $role . '">' . e($f['msg']) . '</div>';
}
/** Campo de senha com dica de força (auth.js). */
function password_field(string $id, string $name, string $label, string $autocomplete, bool $meter = false): string {
    $desc = $meter ? ' aria-describedby="' . $id . '-hint ' . $id . '-meter"' : '';
    $h = '<div class="field"><label for="' . $id . '">' . e($label) . '</label>'
        . '<div class="pw-wrap"><input type="password" id="' . $id . '" name="' . $name . '" required autocomplete="' . $autocomplete . '" minlength="' . PASSWORD_MIN . '" maxlength="' . PASSWORD_MAX . '"' . $desc . ($meter ? ' data-meter="' . $id . '-meter"' : '') . '>'
        . '<button type="button" class="btn btn-ghost btn-sm pw-toggle" data-target="' . $id . '" aria-pressed="false">Mostrar</button></div>';
    if ($meter) {
        $h .= '<p class="small" id="' . $id . '-hint">Mínimo de ' . PASSWORD_MIN . ' caracteres. Dica: uma frase com 3 ou 4 palavras é forte e fácil de lembrar.</p>'
            . '<div class="pw-meter" aria-hidden="true"><span></span></div><p class="small" id="' . $id . '-meter" aria-live="polite"></p>';
    }
    return $h . '</div>';
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
/** Para handlers de formulário (não-JSON): valida CSRF ou volta com mensagem. */
function form_csrf_or_back(string $back): void {
    if (!csrf_valid($_POST['csrf'] ?? null)) { flash('Sua sessão expirou. Tente novamente.', 'err'); redirect($back); }
}
