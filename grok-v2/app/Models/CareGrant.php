<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Concessao emitida PELO paciente. Somente leitura, expira, revogavel, auditada.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property int|null $grantee_user_id
 * @property int $scope_mask
 * @property string|null $data_from
 * @property Carbon|null $expires_at
 */
class CareGrant extends Model
{
    use BelongsToTenant;

    protected $fillable = ['patient_id', 'grantee_user_id', 'grantee_kind', 'scope_mask', 'data_from', 'expires_at'];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'patient_id' => 'integer', 'grantee_user_id' => 'integer', 'scope_mask' => 'integer', 'expires_at' => 'datetime', 'accepted_at' => 'datetime',
            'revoked_at' => 'datetime', 'invite_expires_at' => 'datetime', 'data_from' => 'string'];
    }

    public function scopeActive(Builder $q): void
    {
        $q->whereNull('revoked_at')->whereNotNull('accepted_at')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function allows(int $bit): bool
    {
        return ($this->scope_mask & $bit) === $bit;
    }
}
