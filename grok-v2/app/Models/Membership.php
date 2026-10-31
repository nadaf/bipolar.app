<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ponte usuario <-> tenant. EXCECAO documentada ao BelongsToTenant:
 * e usada para RESOLVER o tenant (antes de existir contexto). So consultar filtrando por user_id
 * ou, para gestao da clinica, pelo tenant ja resolvido (ClinicProfessionalController).
 *
 * Papeis: patient (dono dos dados), professional (le so o que o paciente conceder),
 * admin (administra a clinica: convida/remove profissionais; NAO le dados clinicos por isso).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $role
 */
class Membership extends Model
{
    public const PATIENT = 'patient';

    public const PROFESSIONAL = 'professional';

    public const ADMIN = 'admin';

    protected $fillable = ['tenant_id', 'user_id', 'role'];

    /** Vinculo do usuario no tenant pedido, ou o padrao (onde e paciente; senao o mais antigo). */
    public static function defaultFor(int $userId, ?int $tenantId = null): ?self
    {
        $q = self::query()->where('user_id', $userId);
        if ($tenantId !== null) {
            $q->where('tenant_id', $tenantId);
        }

        return $q->orderByRaw("role = 'patient' desc")->orderByRaw("role = 'admin' asc")->orderBy('id')->first();
    }
}
