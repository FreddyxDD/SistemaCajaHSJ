<?php

namespace App\Console\Commands;

use App\Models\AccessAccount;
use App\Models\AccessApplication;
use App\Models\AccessRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class SyncActiveCashiers extends Command
{
    protected $signature = 'caja:sync-active-cashiers
        {--output= : Ruta del CSV de credenciales temporales}
        {--dry-run : Solo muestra las cuentas que se procesarian}';

    protected $description = 'Crea en HSJ_Identity los cajeros legacy activos con movimientos y exige cambio de clave';

    public function handle(): int
    {
        $application = AccessApplication::query()->where('code', 'gestioncajahsj')->first();
        $role = AccessRole::query()
            ->where('application_id', $application?->id)
            ->where('code', 'cajero')
            ->first();

        if (! $application || ! $role) {
            throw new RuntimeException('Ejecuta primero AccessControlSeeder para crear el rol cajero.');
        }

        $cashiers = DB::connection('caja')
            ->table('Usuario as u')
            ->where('u.cod_tipo', 'T000005')
            ->where('u.estado_usuario', 'A')
            ->where(function ($query): void {
                $query->whereExists(fn ($sessions) => $sessions
                    ->selectRaw('1')
                    ->from('CAJA_APERTURA_CIERRE as s')
                    ->whereColumn('s.cod_usu', 'u.cod_usu'))
                    ->orWhereExists(fn ($documents) => $documents
                        ->selectRaw('1')
                        ->from('Cabecera_documento_MH as d')
                        ->whereColumn('d.cod_usu', 'u.cod_usu'));
            })
            ->orderBy('u.cod_usu')
            ->get(['u.cod_usu', 'u.nom_usu', 'u.usu_sis']);

        $this->info("Cajeros elegibles: {$cashiers->count()}");

        if ($this->option('dry-run')) {
            $this->table(
                ['Codigo', 'Nombre', 'Usuario', 'Correo de acceso'],
                $cashiers->map(fn ($cashier) => [
                    $cashier->cod_usu,
                    $cashier->nom_usu,
                    $cashier->usu_sis,
                    $this->emailFor($cashier->usu_sis),
                ])->all(),
            );

            return self::SUCCESS;
        }

        $credentials = [];
        $created = 0;
        $existing = 0;

        foreach ($cashiers as $cashier) {
            $email = $this->emailFor($cashier->usu_sis);
            $temporaryPassword = $this->temporaryPassword();

            DB::connection('identity')->transaction(function () use (
                $cashier,
                $email,
                $temporaryPassword,
                $role,
                &$credentials,
                &$created,
                &$existing,
            ): void {
                $user = User::query()
                    ->where('registration_document_number', $cashier->cod_usu)
                    ->orWhere('email', $email)
                    ->first();

                if ($user) {
                    $existing++;
                    $account = $user->accessAccount;
                } else {
                    $password = Hash::make($temporaryPassword);
                    $user = User::query()->create([
                        'registration_document_number' => $cashier->cod_usu,
                        'registration_source' => 'manual',
                        'name' => Str::title(Str::lower($cashier->nom_usu)),
                        'email' => $email,
                        'password' => $password,
                        'rol' => 'cajero',
                        'tipo_usuario' => 'administrativo',
                        'activo' => true,
                    ]);
                    $user->forceFill(['email_verified_at' => now()])->save();

                    $account = AccessAccount::query()->create([
                        'user_id' => $user->id,
                        'username' => Str::lower($cashier->usu_sis),
                        'email' => $email,
                        'password' => $password,
                        'display_name' => $user->name,
                        'status' => 'active',
                        'must_change_password' => true,
                    ]);

                    $credentials[] = [
                        $cashier->cod_usu,
                        $user->name,
                        $account->username,
                        $email,
                        $temporaryPassword,
                    ];
                    $created++;
                }

                if (! $account instanceof AccessAccount) {
                    throw new RuntimeException("El usuario {$user->email} no tiene cuenta de acceso.");
                }

                $account->roles()->syncWithoutDetaching([
                    $role->id => ['assigned_at' => now(), 'assigned_by' => null],
                ]);
            });
        }

        $this->info("Cuentas creadas: {$created}. Existentes: {$existing}.");
        if ($credentials !== []) {
            $output = $this->option('output') ?: storage_path('app/private/credenciales-cajeros.csv');
            $this->writeCredentials($output, $credentials);
            $this->warn("Credenciales temporales: {$output}");
        } else {
            $this->comment('No se genero un archivo nuevo porque no hubo cuentas nuevas.');
        }

        return self::SUCCESS;
    }

    private function emailFor(string $username): string
    {
        return Str::lower(trim($username)).'@hsj.local';
    }

    private function temporaryPassword(): string
    {
        return 'Caja!'.Str::upper(Str::random(3)).Str::lower(Str::random(3)).random_int(1000, 9999);
    }

    /**
     * @param  array<int, array<int, string>>  $credentials
     */
    private function writeCredentials(string $path, array $credentials): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $stream = fopen($path, 'wb');
        if ($stream === false) {
            throw new RuntimeException("No se pudo escribir {$path}.");
        }

        fputcsv($stream, ['codigo_legacy', 'nombre', 'usuario', 'correo_acceso', 'contrasena_temporal']);
        foreach ($credentials as $credential) {
            fputcsv($stream, $credential);
        }
        fclose($stream);
    }
}
