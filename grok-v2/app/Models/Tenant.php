<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tenant: clinica, profissional solo ou pessoal (paciente independente). Nao e tenant-owned.
 * slug opcional = subdominio <slug>.bipolar.app.br (validado por App\Tenancy\TenantSlug).
 *
 * @property int $id
 * @property string $kind
 * @property string $name
 * @property string|null $slug
 */
class Tenant extends Model
{
    public const PERSONAL = 'personal';

    public const CLINIC = 'clinic';

    public const SOLO = 'solo';

    protected $fillable = ['kind', 'name', 'slug'];
}
