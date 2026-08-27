<?php

namespace App\Models\Sigh;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Atenciones (SIGH): la cita/atencion que Admision registra para el paciente. Es lo
 * que el paciente trae al mostrador de caja, asi que verla aqui evita preguntarle
 * "que viene a pagar".
 *
 * Solo lectura: la atencion la crea y actualiza CITAS/SIGH, nunca Caja.
 *
 * El cruce desde Caja es Historia_clinica.IdPaciente -> Atenciones.IdPaciente. No hay
 * join posible en SQL porque SISGESH_BD y SIGH viven en instancias distintas: se
 * consulta con el IdPaciente ya resuelto.
 *
 * @property int $IdAtencion
 * @property int $IdPaciente
 * @property Carbon|null $FechaIngreso
 * @property string|null $HoraIngreso
 */
class Atencion extends Model
{
    protected $connection = 'sigh';

    protected $table = 'Atenciones';

    protected $primaryKey = 'IdAtencion';

    public $timestamps = false;

    protected $casts = [
        'FechaIngreso' => 'datetime',
        'FechaEgreso' => 'datetime',
    ];

    /**
     * Atenciones del paciente con los nombres ya resueltos (servicio, especialidad,
     * medico, estado y tipo de servicio), de la mas reciente a la mas antigua.
     */
    public function scopeForPatientWithDetails(Builder $query, int $idPaciente): Builder
    {
        return $query
            ->from('Atenciones as a')
            ->leftJoin('Servicios as s', 's.IdServicio', '=', 'a.IdServicioIngreso')
            ->leftJoin('Especialidades as e', 'e.IdEspecialidad', '=', 'a.IdEspecialidadMedico')
            ->leftJoin('Medicos as m', 'm.IdMedico', '=', 'a.IdMedicoIngreso')
            ->leftJoin('Empleados as em', 'em.IdEmpleado', '=', 'm.IdEmpleado')
            ->leftJoin('EstadosAtencion as es', 'es.IdEstadoAtencion', '=', 'a.idEstadoAtencion')
            ->leftJoin('TiposServicio as ts', 'ts.IdTipoServicio', '=', 'a.IdTipoServicio')
            ->where('a.IdPaciente', $idPaciente)
            ->selectRaw("
                a.IdAtencion,
                a.FechaIngreso,
                a.HoraIngreso,
                a.Edad,
                RTRIM(s.Nombre) as servicio,
                RTRIM(e.Nombre) as especialidad,
                LTRIM(RTRIM(ISNULL(em.ApellidoPaterno,'') + ' ' + ISNULL(em.ApellidoMaterno,'') + ' ' + ISNULL(em.Nombres,''))) as medico,
                RTRIM(es.Descripcion) as estado,
                RTRIM(ts.Descripcion) as tipo_servicio
            ")
            ->orderByDesc('a.FechaIngreso')
            ->orderByDesc('a.IdAtencion');
    }
}
