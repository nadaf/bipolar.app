<?php

namespace App\Domain\Account;

use App\Mail\PasswordResetMail;
use App\Models\Membership;
use App\Models\User;
use App\Tenancy\HostTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Recuperacao de senha.
 * - Resposta sempre neutra (nao revela se o e-mail existe).
 * - Token aleatorio de 64 caracteres; no banco so o sha256; expira em 60 min; uso unico.
 * - Amarrado ao host (e tenant) do pedido: so funciona no mesmo endereco; so envia se a conta
 *   tem vinculo com este host.
 * - Link assinado (URL::temporarySignedRoute) para a tela web.
 */
final class PasswordReset
{
    public const MINUTES = 60;

    public function __construct(private HostTenant $host) {}

    public function request(string $email, string $host): void
    {
        $user = User::where('email', trim($email))->first();
        if ($user === null || Membership::defaultFor($user->id, $this->host->tenantId) === null) {
            return;
        }
        $token = Str::random(64);
        DB::table('password_resets')->where('user_id', $user->id)->where('host', $host)->whereNull('used_at')->delete();
        DB::table('password_resets')->insert([
            'user_id' => $user->id, 'host_tenant_id' => $this->host->tenantId, 'host' => $host,
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(self::MINUTES),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = URL::temporarySignedRoute('password.reset', now()->addMinutes(self::MINUTES), ['token' => $token]);
        Mail::to($user->email)->send(new PasswordResetMail($url));
    }

    /** Linha valida (nao usada, nao expirada, mesmo host/tenant) ou null. */
    public function find(string $token, string $host): ?object
    {
        if (strlen($token) !== 64) {
            return null;
        }
        $row = DB::table('password_resets')->where('token_hash', hash('sha256', $token))->first();
        if ($row === null || $row->used_at !== null || now()->greaterThan($row->expires_at)
            || ! hash_equals((string) $row->host, $host) || ($row->host_tenant_id === null ? null : (int) $row->host_tenant_id) !== $this->host->tenantId) {
            return null;
        }

        return $row;
    }

    /** Troca a senha, gasta o token, apaga os outros pedidos e encerra os acessos da API. */
    public function reset(string $token, string $password, string $host): bool
    {
        return DB::transaction(function () use ($token, $password, $host) {
            $row = $this->find($token, $host);
            if ($row === null) {
                return false;
            }
            $used = DB::table('password_resets')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
            if ($used !== 1) {
                return false;
            }
            $user = User::findOrFail($row->user_id);
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            DB::table('password_resets')->where('user_id', $user->id)->whereNull('used_at')->delete();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            return true;
        });
    }
}
