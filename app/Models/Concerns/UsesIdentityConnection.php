<?php

namespace App\Models\Concerns;

trait UsesIdentityConnection
{
    public function getConnectionName(): ?string
    {
        return app()->environment('testing') ? config('database.default') : 'identity';
    }
}
