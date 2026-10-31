<?php

namespace App\Models;

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
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'timezone', 'theme', 'modules'];

    /** Mesmos padroes do banco (modelo recem-criado ja tem tudo, sem MissingAttributeException). */
    protected $attributes = ['timezone' => 'America/Sao_Paulo', 'theme' => 'sistema', 'modules' => null, 'pin_hash' => null,
        'pending_email' => null, 'email_change_hash' => null, 'email_change_expires_at' => null];

    protected $hidden = ['password', 'remember_token', 'mfa_totp_secret', 'pin_hash', 'email_change_hash'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mfa_enabled_at' => 'datetime',
            'mfa_totp_secret' => 'encrypted',
            'password' => 'hashed',
            'email_change_expires_at' => 'datetime',
        ];
    }

    /** Modulos opcionais ligados pelo proprio usuario (sensiveis comecam desligados). */
    public function moduleOn(string $module): bool
    {
        return in_array($module, explode(',', (string) $this->modules), true);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
