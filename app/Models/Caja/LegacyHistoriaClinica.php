<?php

namespace App\Models\Caja;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Historia_clinica (SISGESH_BD): registro de historias clinicas del hospital y la
 * tabla a la que apunta la FK de Cabecera_documento_MH.id_hc. Es la fuente PRIMARIA
 * de busqueda de pacientes en Caja: una HC creada en Admision/Citas aparece aqui y
 * queda inmediatamente cobrable, mientras que SIGH.Pacientes es el maestro clinico
 * que puede ir por detras segun la sincronizacion.
 *
 * @property string $id_hc
 * @property string $cod_hc numero de historia clinica visible (padded con espacios)
 * @property string $ape_pat
 * @property string $ape_mat
 * @property string $primer_nombre
 * @property string|null $segundo_nombre
 * @property string|null $dni
 * @property string|null $sexo
 * @property string|null $fec_nac texto DD/MM/YYYY
 * @property int|null $IdPaciente cruce hacia SIGH.Pacientes
 */
class LegacyHistoriaClinica extends Model
{
    protected $connection = 'caja';

    protected $table = 'Historia_clinica';

    protected $primaryKey = 'id_hc';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    public function getHistoriaNumberAttribute(): string
    {
        return trim((string) $this->cod_hc);
    }

    public function getFullNameAttribute(): string
    {
        return collect([
            $this->ape_pat,
            $this->ape_mat,
            $this->primer_nombre,
            $this->segundo_nombre,
        ])->map(fn ($p) => trim((string) $p))->filter()->implode(' ');
    }

    public function getSexLabelAttribute(): ?string
    {
        return match (trim((string) $this->sexo)) {
            'M' => 'M',
            'F' => 'F',
            default => null,
        };
    }

    public function getAgeAttribute(): ?int
    {
        $raw = trim((string) $this->fec_nac);

        if ($raw === '' || $raw === '00/00/0000') {
            return null;
        }

        // fec_nac es texto DD/MM/YYYY en el esquema legado; una fecha invalida no debe
        // romper la busqueda, solo omitir la edad.
        try {
            return Carbon::createFromFormat('d/m/Y', $raw)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Busqueda flexible: numero de historia clinica, documento, o nombres/apellidos en
     * cualquier orden ("Ochoa Freddy" == "Freddy Ochoa").
     */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query->whereRaw('1 = 0');
        }

        if (ctype_digit($term)) {
            return $query->where(function ($search) use ($term) {
                $search->whereRaw('LTRIM(RTRIM(cod_hc)) = ?', [$term])
                    ->orWhere('dni', 'like', "{$term}%");
            });
        }

        $words = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY);

        return $query->where(function ($outer) use ($words) {
            foreach ($words as $word) {
                $like = '%'.$word.'%';

                $outer->where(function ($inner) use ($like) {
                    $inner->where('ape_pat', 'like', $like)
                        ->orWhere('ape_mat', 'like', $like)
                        ->orWhere('primer_nombre', 'like', $like)
                        ->orWhere('segundo_nombre', 'like', $like)
                        ->orWhere('dni', 'like', $like);
                });
            }
        });
    }
}
