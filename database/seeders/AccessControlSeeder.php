<?php

namespace Database\Seeders;

use App\Models\AccessAccount;
use App\Models\AccessApplication;
use App\Models\AccessPermission;
use App\Models\AccessRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AccessControlSeeder extends Seeder
{
    public function run(): void
    {
        $application = AccessApplication::query()->updateOrCreate(
            ['code' => 'gestioncajahsj'],
            [
                'name' => 'Gestion de Caja HSJ',
                'description' => 'Modulo de apertura/cierre de caja, cobro de servicios y catalogos de facturacion.',
                'base_url' => null,
                'is_active' => true,
            ],
        );

        $permissions = collect([
            ['code' => 'caja.view', 'name' => 'Ver caja', 'module' => 'Caja'],
            ['code' => 'caja.session.open', 'name' => 'Abrir turno de caja', 'module' => 'Caja'],
            ['code' => 'caja.session.close', 'name' => 'Cerrar turno de caja', 'module' => 'Caja'],
            ['code' => 'caja.charge.create', 'name' => 'Registrar cobro', 'module' => 'Caja'],
            ['code' => 'caja.void.request', 'name' => 'Solicitar anulacion de cobro', 'module' => 'Anulaciones'],
            ['code' => 'caja.void.approve', 'name' => 'Aprobar o rechazar anulaciones', 'module' => 'Anulaciones'],
            ['code' => 'caja.charge.void', 'name' => 'Anular cobro directamente (sin aprobacion)', 'module' => 'Anulaciones'],
            ['code' => 'caja.cashiers.view', 'name' => 'Ver cajeros y sus turnos', 'module' => 'Caja'],
            ['code' => 'caja.registers.manage', 'name' => 'Administrar puntos de cobro', 'module' => 'Caja'],
            ['code' => 'caja.catalog.manage', 'name' => 'Administrar catalogo y formas de pago', 'module' => 'Caja'],
            ['code' => 'caja.catalog.audit', 'name' => 'Ver auditoria de cambios del catalogo', 'module' => 'Caja'],
            ['code' => 'caja.prices.view', 'name' => 'Consultar precios del tarifario', 'module' => 'Caja'],
            ['code' => 'reports.view', 'name' => 'Ver reportes y analitica', 'module' => 'Reportes'],
            ['code' => 'users.view', 'name' => 'Ver usuarios del aplicativo', 'module' => 'Usuarios'],
            ['code' => 'users.manage', 'name' => 'Crear, editar y asignar roles a usuarios', 'module' => 'Usuarios'],
        ]);

        $permissions->each(fn (array $permission) => AccessPermission::query()->updateOrCreate(
            ['application_id' => $application->id, 'code' => $permission['code']],
            [
                'name' => $permission['name'],
                'module' => $permission['module'],
                'description' => $permission['description'] ?? null,
            ],
        ));

        $roles = collect([
            ['code' => 'administrador', 'name' => 'Administrador', 'description' => 'Acceso completo al modulo de Caja.', 'is_system' => true],
            ['code' => 'jefe_economia', 'name' => 'Jefe de Economia', 'description' => 'Jefe de la unidad de economia: aprueba anulaciones, supervisa cajeros y reportes.', 'is_system' => true],
            ['code' => 'cajero_central', 'name' => 'Cajero central', 'description' => 'Aprueba anulaciones, supervisa turnos de todos los cajeros y opera caja.', 'is_system' => true],
            ['code' => 'cajero', 'name' => 'Cajero', 'description' => 'Abre/cierra su turno, registra cobros y solicita anulaciones.', 'is_system' => true],
            ['code' => 'supervisor_caja', 'name' => 'Supervisor de caja', 'description' => 'Supervisa turnos, cobros y catalogos.', 'is_system' => true],
            ['code' => 'auditor', 'name' => 'Auditor', 'description' => 'Consulta turnos y cobros, sin registrar.', 'is_system' => true],
            ['code' => 'consulta_precios', 'name' => 'Consulta de precios', 'description' => 'Solo consulta el tarifario y simula descuentos; no opera caja.', 'is_system' => true],
        ]);

        $roles->each(fn (array $role) => AccessRole::query()->updateOrCreate(
            ['application_id' => $application->id, 'code' => $role['code']],
            [
                'name' => $role['name'],
                'description' => $role['description'],
                'is_system' => $role['is_system'],
            ],
        ));

        // El cajero NO puede anular por si mismo: solo solicita. La aprobacion es
        // exclusiva del Jefe de Economia y del Cajero central.
        $matrix = [
            // La apertura es exclusivamente operativa: ni siquiera el administrador
            // la recibe por matriz. La accion aplica ademas una regla explicita de rol
            // porque el administrador tiene pase global en canDo().
            'administrador' => $permissions->pluck('code')->reject(
                fn (string $permission) => $permission === 'caja.session.open',
            )->all(),
            'jefe_economia' => [
                'caja.view', 'caja.cashiers.view', 'caja.void.request', 'caja.void.approve',
                'caja.registers.manage', 'caja.catalog.manage', 'caja.catalog.audit', 'caja.prices.view', 'reports.view',
                'users.view', 'users.manage',
            ],
            'cajero_central' => [
                'caja.view', 'caja.session.open', 'caja.session.close', 'caja.charge.create',
                'caja.cashiers.view', 'caja.void.request', 'caja.void.approve',
                'caja.catalog.manage', 'caja.catalog.audit', 'caja.prices.view', 'reports.view', 'users.view',
            ],
            'cajero' => [
                'caja.view', 'caja.session.open', 'caja.session.close',
                'caja.charge.create', 'caja.void.request', 'caja.prices.view',
            ],
            'supervisor_caja' => [
                'caja.view', 'caja.session.close', 'caja.charge.create',
                'caja.cashiers.view', 'caja.void.request',
                'caja.registers.manage', 'caja.catalog.manage', 'caja.catalog.audit', 'caja.prices.view', 'reports.view',
            ],
            'auditor' => ['caja.view', 'caja.cashiers.view', 'caja.catalog.audit', 'caja.prices.view', 'reports.view', 'users.view'],
            'consulta_precios' => ['caja.prices.view'],
        ];

        $roleIds = AccessRole::query()->where('application_id', $application->id)->pluck('id', 'code');
        $permissionIds = AccessPermission::query()->where('application_id', $application->id)->pluck('id', 'code');

        foreach ($matrix as $roleCode => $permissionCodes) {
            $roleId = $roleIds[$roleCode] ?? null;
            if (! $roleId) {
                continue;
            }

            DB::connection('identity')->table('access_role_permissions')->where('role_id', $roleId)->delete();

            foreach ($permissionCodes as $permissionCode) {
                $permissionId = $permissionIds[$permissionCode] ?? null;
                if ($permissionId) {
                    DB::connection('identity')->table('access_role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }

        // Reutiliza el usuario administrador central si ya existe (compartido con citashsj);
        // si no existe en este ambiente, lo crea.
        $admin = User::query()->where('email', 'admin@hsj.local')->first();

        if (! $admin) {
            $admin = User::query()->create([
                'name' => 'Administrador HSJ',
                'email' => 'admin@hsj.local',
                'password' => Hash::make('CitasHSJ2026!'),
                'email_verified_at' => now(),
                'rol' => 'administrador',
                'tipo_usuario' => 'administrativo',
                'activo' => true,
                'registration_source' => 'manual',
            ]);
        }

        $account = AccessAccount::query()->updateOrCreate(
            ['user_id' => $admin->id],
            [
                'username' => 'admin',
                'email' => $admin->email,
                'display_name' => $admin->name,
                'status' => 'active',
                'must_change_password' => false,
            ],
        );

        $account->roles()->syncWithoutDetaching([$roleIds['administrador']]);
    }
}
