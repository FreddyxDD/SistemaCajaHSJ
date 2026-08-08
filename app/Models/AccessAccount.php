<?php

namespace App\Models;

use App\Models\Concerns\UsesIdentityConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AccessAccount extends Model
{
    use UsesIdentityConnection;

    protected $guarded = [];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'must_change_password' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(AccessRole::class, 'access_account_roles', 'account_id', 'role_id')
            ->withPivot(['assigned_at', 'assigned_by']);
    }
}
