<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'patient_id', 'action', 'subject_type', 'subject_id', 'scope_mask', 'ip_hash'];
}
