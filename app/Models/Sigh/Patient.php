<?php

namespace App\Models\Sigh;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pacientes (SIGH, conexion 'sigh', solo lectura): busqueda de paciente por nombres,
 * apellidos, historia clinica o numero de documento (de cualquier tipo) para
 * identificarlo antes de un cobro.
 *
 * @property int $IdPaciente
 * @property string $ApellidoPaterno
 * @property string $ApellidoMaterno
 * @property string $PrimerNombre
 * @property string|null $SegundoNombre
 * @property string $NroDocumento
 * @property int $NroHistoriaClinica
 * @property int|null $IdDocIdentidad
 * @property int|null $IdTipoSexo
 * @property Carbon|null $FechaNacimiento
 */
class Patient extends Model
{
    protected $connection = 'sigh';

    protected $table = 'Pacientes';

    protected $primaryKey = 'IdPaciente';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'FechaNacimiento' => 'date',
    ];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'IdDocIdentidad', 'IdDocIdentidad');
    }

    public function getFullNameAttribute(): string
    {
        // Las columnas son char de ancho fijo: sin recortar, el nombre sale con
        // decenas de espacios entre apellido y nombre.
        return collect([
            $this->ApellidoPaterno,
            $this->ApellidoMaterno,
            $this->PrimerNombre,
            $this->SegundoNombre,
        ])->map(fn ($parte) => trim((string) $parte))->filter()->implode(' ');
    }

    public function getSexLabelAttribute(): ?string
    {
        return match ((int) $this->IdTipoSexo) {
            1 => 'M',
            2 => 'F',
            default => null,
        };
    }

    public function getAgeAttribute(): ?int
    {
        return $this->FechaNacimiento?->age;
    }

    /**
     * Busqueda unica por nombres, apellidos, historia clinica o documento (cualquier
     * tipo), en cualquier orden y combinacion — ej. "Ochoa Freddy", "427761",
     * "21813479" o "Freddy 21813479" deben encontrar al mismo paciente.
     */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query->whereRaw('1 = 0');
        }

        // Termino puramente numerico: historia clinica exacta o documento por prefijo
        // (cubre DNI parcial o completo, sin castear la columna entera a texto).
        if (ctype_digit($term)) {
            return $query->where(function ($search) use ($term) {
                $search->where('NroHistoriaClinica', (int) $term)
                    ->orWhere('NroDocumento', 'like', "{$term}%");
            });
        }

        // Texto libre: cada palabra debe aparecer en algun campo de identidad,
        // sin importar el orden ("Ochoa Freddy" y "Freddy Ochoa" encuentran lo mismo).
        $words = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY);

        return $query->where(function ($outer) use ($words) {
            foreach ($words as $word) {
                $like = '%'.$word.'%';

                $outer->where(function ($inner) use ($like) {
                    $inner->where('ApellidoPaterno', 'like', $like)
                        ->orWhere('ApellidoMaterno', 'like', $like)
                        ->orWhere('PrimerNombre', 'like', $like)
                        ->orWhere('SegundoNombre', 'like', $like)
                        ->orWhere('NroDocumento', 'like', $like);
                });
            }
        });
    }
}
