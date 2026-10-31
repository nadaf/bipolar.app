<?php

namespace App\Models;

use App\Domain\Mood\Period;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $version
 * @property Carbon $entry_date
 * @property Period $period
 * @property int|null $mood
 * @property int|null $energy
 * @property int|null $anxiety
 * @property int|null $irritability
 * @property float|null $sleep_hours
 * @property bool|null $medication_taken
 * @property string|null $note
 * @property string|null $client_uuid
 */
class MoodEntry extends Model
{
    use BelongsToTenant, HasFactory;

    protected $attributes = ['version' => 1];

    protected $fillable = ['patient_id', 'entry_date', 'period', 'mood', 'energy', 'anxiety', 'irritability',
        'sleep_hours', 'medication_taken', 'note', 'client_uuid'];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
            'period' => Period::class,
            'mood' => 'integer',
            'energy' => 'integer',
            'anxiety' => 'integer',
            'irritability' => 'integer',
            'sleep_hours' => 'float',
            'medication_taken' => 'boolean',
            'note' => 'encrypted',
            'version' => 'integer',
        ];
    }

    /** Dono dos dados: sempre combinar com o escopo de tenant. */
    public function scopeOwnedBy(Builder $q, User $user): void
    {
        $q->where('patient_id', $user->id);
    }
}
