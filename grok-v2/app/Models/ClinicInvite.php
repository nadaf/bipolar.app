<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Convite de clinica por e-mail (admin -> profissional). Guarda so o hash do token.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property string $role
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
class ClinicInvite extends Model
{
    use BelongsToTenant;

    protected $fillable = ['email', 'role', 'token_hash', 'invited_by', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function scopePending(Builder $q): void
    {
        $q->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
