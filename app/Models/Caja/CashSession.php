<?php

namespace App\Models\Caja;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CAJA_APERTURA_CIERRE: apertura/cierre de turno de caja por usuario.
 *
 * @property string $cod_aper_cierre_caja
 * @property string $cod_usu
 * @property string $fecha_apertura
 * @property string $hora_apertura
 * @property string $fecha_cierre
 * @property string $hora_cierre
 * @property string $estado_aper_cierre_caja
 */
class CashSession extends Model
{
    protected $connection = 'caja';

    protected $table = 'CAJA_APERTURA_CIERRE';

    protected $primaryKey = 'cod_aper_cierre_caja';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public const ESTADO_ABIERTO = 'P';

    public const ESTADO_CERRADO = 'C';

    public function documents(): HasMany
    {
        return $this->hasMany(ChargeDocument::class, 'cod_aper_cierre_caja', 'cod_aper_cierre_caja');
    }

    public function isOpen(): bool
    {
        return $this->estado_aper_cierre_caja === self::ESTADO_ABIERTO;
    }

    public function scopeOpen($query)
    {
        return $query->where('estado_aper_cierre_caja', self::ESTADO_ABIERTO);
    }

    /**
     * Momento de apertura. Las columnas son texto ('DD/MM/YYYY' y 'HH:MM:SS') y el
     * legado admite valores imposibles como '00/00/0000', asi que una fila corrupta
     * devuelve null en vez de romper la pantalla del cajero.
     */
    public function openedAt(): ?CarbonImmutable
    {
        return self::parseLegacyDateTime($this->fecha_apertura, $this->hora_apertura);
    }

    public function closedAt(): ?CarbonImmutable
    {
        return self::parseLegacyDateTime($this->fecha_cierre, $this->hora_cierre);
    }

    /** Horas que lleva (o duro) el turno; null si la apertura no es interpretable. */
    public function hoursOpen(): ?float
    {
        $desde = $this->openedAt();

        if (! $desde) {
            return null;
        }

        $hasta = $this->isOpen() ? CarbonImmutable::now() : ($this->closedAt() ?? CarbonImmutable::now());

        // diffInHours ya devuelve decimales en esta version de Carbon; floatDiffInHours
        // esta deprecado y emite un aviso por cada turno pintado.
        return round((float) $desde->diffInHours($hasta, true), 2);
    }

    /**
     * Un cajero no debe pasar mas de 12 horas en el mismo turno (config
     * caja.turno_horas_maximas). Pasado el limite el turno NO se cierra solo —eso
     * descuadraria el arqueo, que es un acto del cajero— pero queda marcado para que
     * el y el cajero central lo vean.
     */
    public function exceedsMaxDuration(): bool
    {
        return ($this->hoursOpen() ?? 0) > self::maxHours();
    }

    public static function maxHours(): float
    {
        return (float) config('caja.turno_horas_maximas', 12);
    }

    /** Duracion legible: "3 h 25 min". */
    public function durationLabel(): string
    {
        $horas = $this->hoursOpen();

        if ($horas === null) {
            return 'duración desconocida';
        }

        $enteras = (int) floor($horas);
        $minutos = (int) round(($horas - $enteras) * 60);

        return $enteras > 0 ? "{$enteras} h {$minutos} min" : "{$minutos} min";
    }

    private static function parseLegacyDateTime(?string $fecha, ?string $hora): ?CarbonImmutable
    {
        $fecha = trim((string) $fecha);
        $hora = trim((string) $hora) ?: '00:00:00';

        if ($fecha === '' || str_starts_with($fecha, '00/00')) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('d/m/Y H:i:s', "{$fecha} {$hora}");
        } catch (\Throwable) {
            return null;
        }
    }
}
