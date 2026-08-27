<?php

namespace App\Models;

use App\Models\Concerns\UsesIdentityConnection;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * users vive en HSJ_Identity, compartida entre citashsj, intranet_hsj y esta app.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $activo
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['registration_document_number', 'registration_source', 'name', 'email', 'password', 'rol', 'tipo_usuario', 'activo'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable, UsesIdentityConnection;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /** @return HasOne<AccessAccount, $this> */
    public function accessAccount(): HasOne
    {
        return $this->hasOne(AccessAccount::class);
    }

    public function hasRole(string $role, string $application = 'gestioncajahsj'): bool
    {
        return $this->accessAccount()
            ->whereHas('roles', fn ($query) => $query
                ->where('code', $role)
                ->whereHas('application', fn ($applicationQuery) => $applicationQuery->where('code', $application)->where('is_active', true)))
            ->exists();
    }

    /**
     * Permiso efectivo incluyendo el pase completo del rol administrador, que es la
     * misma regla que aplica el middleware `permission:*`. Usar en vistas para que la
     * UI muestre exactamente lo que la ruta va a permitir.
     */
    public function canDo(string $permission, string $application = 'gestioncajahsj'): bool
    {
        return $this->hasRole('administrador', $application) || $this->hasPermission($permission, $application);
    }

    /**
     * Abrir una caja es una capacidad operativa, no administrativa. El rol
     * administrador conserva su pase global para supervisar la aplicacion, pero no
     * debe poder iniciar un turno ni operar como cajero por ese solo hecho.
     */
    public function canOpenCashSession(string $application = 'gestioncajahsj'): bool
    {
        return $this->hasRole('cajero', $application)
            || $this->hasRole('cajero_central', $application);
    }

    public function hasPermission(string $permission, string $application = 'gestioncajahsj'): bool
    {
        return $this->accessAccount()
            ->whereHas('roles', fn ($roleQuery) => $roleQuery
                ->whereHas('application', fn ($applicationQuery) => $applicationQuery->where('code', $application)->where('is_active', true))
                ->whereHas('permissions', fn ($permissionQuery) => $permissionQuery
                    ->where('code', $permission)
                    ->whereHas('application', fn ($permissionApplicationQuery) => $permissionApplicationQuery->where('code', $application))))
            ->exists();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function hasAnyPermission(array $permissions, string $application = 'gestioncajahsj'): bool
    {
        return $this->accessAccount()
            ->whereHas('roles', fn ($roleQuery) => $roleQuery
                ->whereHas('application', fn ($applicationQuery) => $applicationQuery->where('code', $application)->where('is_active', true))
                ->whereHas('permissions', fn ($permissionQuery) => $permissionQuery
                    ->whereIn('code', $permissions)
                    ->whereHas('application', fn ($permissionApplicationQuery) => $permissionApplicationQuery->where('code', $application))))
            ->exists();
    }
}
