<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Inscricao de Web Push de um aparelho (sem dado de saude).
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $p256dh
 * @property string $auth
 * @property string|null $last_slot
 */
class PushSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'last_slot'];

    protected $hidden = ['endpoint', 'p256dh', 'auth'];
}
