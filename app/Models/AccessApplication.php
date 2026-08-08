<?php

namespace App\Models;

use App\Models\Concerns\UsesIdentityConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AccessApplication extends Model
{
    use UsesIdentityConnection;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function roles(): HasMany
    {
        return $this->hasMany(AccessRole::class, 'application_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(AccessPermission::class, 'application_id');
    }
}
