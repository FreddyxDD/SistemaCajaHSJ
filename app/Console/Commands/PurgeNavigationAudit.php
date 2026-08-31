<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Support\Audit\AccessAudit;
use Illuminate\Console\Command;

/**
 * Purga el rastro de navegacion antiguo.
 *
 * La navegacion crece rapido: una fila por pagina abierta. Los accesos (ingresos,
 * salidas e intentos fallidos) y el rastro del catalogo NO se tocan nunca: son el
 * registro que pide auditoria y deben conservarse.
 */
class PurgeNavigationAudit extends Command
{
    protected $signature = 'auditoria:purgar
                            {--dias= : Días a conservar (por defecto, lo configurado)}
                            {--forzar : Purga sin pedir confirmación}';

    protected $description = 'Elimina el rastro de navegación anterior al periodo de retención';

    public function handle(): int
    {
        $dias = (int) ($this->option('dias') ?: config('auditoria.navegacion_dias_retencion', 90));

        if ($dias < 1) {
            $this->error('El periodo de retención debe ser de al menos 1 día.');

            return self::FAILURE;
        }

        $corte = now()->subDays($dias);

        $consulta = AuditEvent::query()
            ->where('event_type', AccessAudit::TIPO_NAVEGACION)
            ->where('occurred_at', '<', $corte);

        $total = $consulta->count();

        if ($total === 0) {
            $this->info("No hay navegación anterior a {$corte->format('d/m/Y')}.");

            return self::SUCCESS;
        }

        $this->warn("Se eliminarán {$total} registros de navegación anteriores a {$corte->format('d/m/Y')}.");
        $this->line('Los inicios de sesión y los intentos fallidos no se tocan.');

        if (! $this->option('forzar') && ! $this->confirm('¿Continuar?')) {
            return self::SUCCESS;
        }

        // En bloques: una sola sentencia sobre cientos de miles de filas bloquea la
        // tabla y el resto de la aplicacion escribe en ella en cada peticion.
        $eliminados = 0;

        do {
            $lote = $consulta->clone()->limit(5000)->pluck('id');

            if ($lote->isEmpty()) {
                break;
            }

            $eliminados += AuditEvent::query()->whereIn('id', $lote)->delete();
            $this->output->write('.');
        } while (true);

        $this->newLine();
        $this->info("Eliminados {$eliminados} registros de navegación.");

        return self::SUCCESS;
    }
}
