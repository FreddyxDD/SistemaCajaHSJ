<?php

namespace App\Support\Caja;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class CajaDatabaseEnvironment
{
    public const DEVELOPMENT = 'development';

    public const INSTITUTIONAL = 'institutional';

    /** @return array<int, string> */
    public function allowed(): array
    {
        return [self::DEVELOPMENT, self::INSTITUTIONAL];
    }

    public function enabled(): bool
    {
        return (bool) config('caja.environment_switch_enabled', false);
    }

    public function default(): string
    {
        $default = (string) config('caja.default_environment', self::DEVELOPMENT);

        return in_array($default, $this->allowed(), true) ? $default : self::DEVELOPMENT;
    }

    public function selected(Request $request): string
    {
        $selected = (string) $request->session()->get('caja_database_environment', $this->default());

        return in_array($selected, $this->allowed(), true) ? $selected : $this->default();
    }

    public function label(string $environment): string
    {
        return $environment === self::INSTITUTIONAL ? 'Institucional' : 'Desarrollo';
    }

    public function shortLabel(string $environment): string
    {
        return $environment === self::INSTITUTIONAL ? 'INST' : 'DEV';
    }

    /**
     * Reconfigura el alias `caja` utilizado por todos los modelos existentes.
     *
     * @return array{server: string|null, database: string|null}
     */
    public function activate(string $environment, bool $verify = false): array
    {
        if (! in_array($environment, $this->allowed(), true)) {
            throw new InvalidArgumentException('Entorno de Caja no permitido.');
        }

        $connection = config('database.connections.caja_'.$environment);

        $missingBaseConfiguration = ! is_array($connection)
            || blank($connection['host'] ?? null)
            || blank($connection['database'] ?? null);
        $missingInstitutionalCredentials = $environment === self::INSTITUTIONAL
            && (blank($connection['username'] ?? null) || blank($connection['password'] ?? null));

        if ($missingBaseConfiguration || $missingInstitutionalCredentials) {
            throw new RuntimeException('La conexión de Caja seleccionada no está configurada.');
        }

        config(['database.connections.caja' => $connection]);
        DB::purge('caja');

        if (! $verify) {
            return ['server' => null, 'database' => null];
        }

        $identity = DB::connection('caja')->selectOne('SELECT @@SERVERNAME AS server_name, DB_NAME() AS database_name');
        $actualDatabase = (string) ($identity->database_name ?? '');
        $expectedDatabase = (string) $connection['database'];

        if ($actualDatabase === '' || strcasecmp($actualDatabase, $expectedDatabase) !== 0) {
            DB::purge('caja');

            throw new RuntimeException('La conexión respondió desde una base de datos distinta a la configurada.');
        }

        return [
            'server' => isset($identity->server_name) ? (string) $identity->server_name : null,
            'database' => $actualDatabase,
        ];
    }
}
