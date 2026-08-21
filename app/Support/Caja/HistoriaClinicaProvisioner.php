<?php

namespace App\Support\Caja;

use App\Models\Caja\LegacyHistoriaClinica;
use App\Models\Sigh\Patient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registra en Caja la historia clinica de un paciente que hoy solo existe en SIGH.
 *
 * Historia_clinica (SISGESH_BD) NO es un espejo completo del maestro de pacientes:
 * tiene ~85 mil filas frente a las ~389 mil de SIGH.Pacientes, y es la tabla a la que
 * apunta la FK de Cabecera_documento_MH.id_hc. Es decir, un paciente que Admision
 * registro en SIGH pero que nunca paso por caja no se puede cobrar hasta que exista
 * aqui.
 *
 * No se inventa numeracion: el cod_hc es el NroHistoriaClinica que SIGH ya asigno, y
 * el cruce queda guardado en IdPaciente, asi que la fila creada es la misma historia,
 * no una nueva.
 */
class HistoriaClinicaProvisioner
{
    public static function ensureFromSigh(Patient $patient): LegacyHistoriaClinica
    {
        $existente = LegacyHistoriaClinica::query()
            ->where('IdPaciente', $patient->IdPaciente)
            ->first();

        if ($existente) {
            return $existente;
        }

        $numeroHc = (int) $patient->NroHistoriaClinica;

        if ($numeroHc <= 0) {
            throw new RuntimeException(
                'Este paciente no tiene número de historia clínica en SIGH. Debe generarse en Admisión antes de cobrar.'
            );
        }

        // Puede existir la HC con ese numero pero sin el cruce a SIGH (dato viejo):
        // se reutiliza y se completa el IdPaciente en vez de duplicar la historia.
        $porNumero = LegacyHistoriaClinica::query()
            ->whereRaw('LTRIM(RTRIM(cod_hc)) = ?', [(string) $numeroHc])
            ->first();

        if ($porNumero) {
            $porNumero->update(['IdPaciente' => $patient->IdPaciente]);

            return $porNumero;
        }

        return DB::connection('caja')->transaction(function () use ($patient, $numeroHc) {
            $historia = new LegacyHistoriaClinica;

            $historia->forceFill([
                'id_hc' => self::nextIdHc(),
                'cod_hc' => (string) $numeroHc,
                'ape_pat' => mb_substr((string) $patient->ApellidoPaterno, 0, 50),
                'ape_mat' => mb_substr((string) $patient->ApellidoMaterno, 0, 50),
                'primer_nombre' => mb_substr((string) $patient->PrimerNombre, 0, 80),
                'segundo_nombre' => mb_substr((string) $patient->SegundoNombre, 0, 80),
                'tercer_nombre' => mb_substr((string) $patient->TercerNombre, 0, 80),
                // cod_dis tiene FK real hacia Distrito_MH: no admite vacio.
                'cod_dis' => self::districtCode($patient),
                'fec_nac' => $patient->FechaNacimiento?->format('d/m/Y') ?? '',
                'edad' => (string) ($patient->age ?? ''),
                'dni' => mb_substr((string) $patient->NroDocumento, 0, 8),
                'sexo' => $patient->sex_label ?? '',
                'fecha_actu_registro' => now()->format('d/m/Y'),
                'hora_actu_registro' => now()->format('H:i:s'),
                'nom_usu_creacio' => mb_substr((string) (Auth::user()?->name ?? 'GESTIONCAJAHSJ'), 0, 70),
                'estado' => 'A',
                'completos_datos' => mb_substr($patient->full_name, 0, 300),
                'id_lote' => 0,
                'estado_hc_uso' => 'A',
                'IdPaciente' => $patient->IdPaciente,
                'tipo_documento' => 'DNI',
            ]);

            $historia->save();

            return $historia;
        });
    }

    /**
     * Distrito de domicilio. En SIGH `IdDistritoDomicilio` ya es el ubigeo (ej. 110202),
     * el mismo formato que Distrito_MH.cod_dis, asi que se copia tal cual cuando existe
     * en el catalogo de Caja. Si no, se usa el distrito del hospital: la columna tiene
     * FK y no admite vacio, y el dato demografico real vive en SIGH de todas formas.
     */
    private static function districtCode(Patient $patient): string
    {
        $ubigeo = trim((string) $patient->IdDistritoDomicilio);

        $existe = $ubigeo !== '' && DB::connection('caja')
            ->table('Distrito_MH')
            ->where('cod_dis', $ubigeo)
            ->exists();

        return $existe ? $ubigeo : config('caja.distrito_por_defecto', '110201');
    }

    /** id_hc observado en Historia_clinica: "HC" + 18 digitos. */
    private static function nextIdHc(): string
    {
        $last = DB::connection('caja')
            ->table('Historia_clinica')
            ->lockForUpdate()
            ->orderByDesc('id_hc')
            ->value('id_hc');

        $secuencia = $last ? (int) substr($last, 2) : 0;

        return 'HC'.str_pad((string) ($secuencia + 1), 18, '0', STR_PAD_LEFT);
    }
}
