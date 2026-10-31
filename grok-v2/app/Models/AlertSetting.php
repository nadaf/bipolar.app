<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Limiares de alerta do proprio paciente (humor e energia, baixo e alto).
 *
 * @property int $patient_id
 * @property int $mood_low
 * @property int $mood_high
 * @property int $energy_low
 * @property int $energy_high
 */
class AlertSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['patient_id', 'mood_low', 'mood_high', 'energy_low', 'energy_high', 'reminders'];

    protected function casts(): array
    {
        return ['mood_low' => 'integer', 'mood_high' => 'integer', 'energy_low' => 'integer', 'energy_high' => 'integer', 'reminders' => 'array'];
    }
}
