<?php

namespace App\Models;

use App\Casts\SafeEncrypted;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Bloco do Registrar (dado do paciente, criptografado em repouso).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property Carbon $entry_date
 * @property string $period
 * @property string $block
 * @property array<string, mixed> $data
 * @property int $version
 * @property Carbon|null $updated_at
 */
class DayBlock extends Model
{
    use BelongsToTenant;

    protected $attributes = ['version' => 1];

    protected $fillable = ['patient_id', 'entry_date', 'period', 'block', 'data'];

    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d', 'data' => SafeEncrypted::class.':array,empty', 'version' => 'integer', 'patient_id' => 'integer'];
    }

    public function scopeOwnedBy(Builder $q, User $user): void
    {
        $q->where('patient_id', $user->id);
    }
}
