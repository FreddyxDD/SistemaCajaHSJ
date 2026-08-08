<?php

namespace App\Models;

use App\Models\Concerns\UsesIdentityConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AccessRole extends Model
{
    use UsesIdentityConnection;

    protected $guarded = [];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(AccessApplication::class, 'application_id');
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(AccessAccount::class, 'access_account_roles', 'role_id', 'account_id');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(AccessPermission::class, 'access_role_permissions', 'role_id', 'permission_id');
    }
}
