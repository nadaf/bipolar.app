<?php

namespace App\Models;

use App\Casts\SafeEncrypted;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Identidade global (nao pertence a um tenant); vinculos via memberships.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $timezone
 * @property string $theme
 * @property string|null $modules
 * @property string|null $pin_hash
 * @property string|null $pending_email
 * @property string|null $email_change_hash
 * @property Carbon|null $email_change_expires_at
 * @property string|null $mfa_totp_secret
 * @property Carbon|null $mfa_enabled_at
 * @property array<int, string>|null $mfa_recovery_codes
 * @property int|null $mfa_last_step
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'timezone', 'theme', 'modules'];

    /** Mesmos padroes do banco (modelo recem-criado ja tem tudo, sem MissingAttributeException). */
    protected $attributes = ['timezone' => 'America/Sao_Paulo', 'theme' => 'sistema', 'modules' => null, 'pin_hash' => null,
        'pending_email' => null, 'mfa_totp_secret' => null, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_step' => null, 'email_change_hash' => null, 'email_change_expires_at' => null];

    protected $hidden = ['password', 'remember_token', 'mfa_totp_secret', 'mfa_recovery_codes', 'pin_hash', 'email_change_hash'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mfa_enabled_at' => 'datetime',
            'mfa_totp_secret' => SafeEncrypted::class,
            'mfa_recovery_codes' => SafeEncrypted::class.':array',
            'mfa_last_step' => 'integer',
            'password' => 'hashed',
            'email_change_expires_at' => 'datetime',
        ];
    }

    /** Modulos opcionais ligados pelo proprio usuario (sensiveis comecam desligados). */
    public function moduleOn(string $module): bool
    {
        return in_array($module, explode(',', (string) $this->modules), true);
    }

    public function mfaOn(): bool
    {
        return $this->mfa_enabled_at !== null && $this->mfa_totp_secret !== null;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
