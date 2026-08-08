<?php

namespace App\Models;

use App\Models\Concerns\UsesIdentityConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AccessPermission extends Model
{
    use UsesIdentityConnection;

    protected $guarded = [];

    public function application(): BelongsTo
    {
        return $this->belongsTo(AccessApplication::class, 'application_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(AccessRole::class, 'access_role_permissions', 'permission_id', 'role_id');
    }
}
