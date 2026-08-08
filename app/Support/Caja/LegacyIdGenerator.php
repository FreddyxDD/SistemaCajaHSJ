<?php

namespace App\Support\Caja;

use App\Models\Caja\CashSession;
use App\Models\Caja\ChargeDocument;
use App\Models\Caja\ChargeDocumentItem;
use App\Models\Caja\LegacyUsuario;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Genera identificadores con el mismo formato que el sistema legado de caja
 * (SISGESH_BD), leyendo el maximo actual dentro de la misma transaccion para
 * evitar colisiones. El algoritmo real del cliente de escritorio original no
 * esta documentado (posiblemente vive en un stored procedure o en el propio
 * cliente); esto es una aproximacion segura basada en el formato observado en
 * los datos existentes y debe validarse con el equipo que conoce el sistema
 * legado antes de un uso en produccion con alto volumen concurrente.
 */
class LegacyIdGenerator
{
    public static function nextCashSessionCode(): string
    {
        return self::nextPrefixedCode(CashSession::class, 'cod_aper_cierre_caja', 'AP', 8);
    }

    public static function nextDocumentId(): string
    {
        return self::nextPrefixedCode(ChargeDocument::class, 'id_documento', 'CD', 18);
    }

    public static function nextDocumentItemId(): string
    {
        return self::nextPrefixedCode(ChargeDocumentItem::class, 'id_cod_det', 'DD', 18);
    }

    /**
     * Numero de documento correlativo por serie, con el mismo formato
     * "{serie}-{13 digitos}" observado en Cabecera_documento_MH.num_documento.
     */
    public static function nextDocumentNumber(string $serie): string
    {
        $last = ChargeDocument::query()
            ->where('serie_documento', $serie)
            ->lockForUpdate()
            ->orderByDesc('num_documento')
            ->value('num_documento');

        $lastSequence = $last ? (int) substr($last, strpos($last, '-') + 1) : 0;

        return $serie.'-'.str_pad((string) ($lastSequence + 1), 13, '0', STR_PAD_LEFT);
    }

    /**
     * cod_usu (varchar(7)) tiene FK real hacia Usuario en el esquema legado: no basta
     * con generar un codigo, debe existir la fila. Se usa el prefijo "W" para
     * distinguir usuarios creados desde este modulo nuevo de los codigos "U" del
     * sistema antiguo, y se crea (una sola vez, find-or-create) una fila minima en
     * Usuario ligada al usuario central (HSJ_Identity) que realiza la accion, con
     * cod_tipo=T000005 (CAJA) segun el catalogo Tipo_Usuario existente.
     */
    public static function legacyUserCode(User $user): string
    {
        $code = 'W'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);

        LegacyUsuario::query()->firstOrCreate(
            ['cod_usu' => $code],
            [
                'cod_tipo' => 'T000005',
                'cod_per' => null,
                'nom_usu' => mb_substr($user->name, 0, 300),
                'usu_sis' => mb_substr('GCJ'.$user->id, 0, 100),
                'contraseña' => bin2hex(random_bytes(16)),
                'nom_usu_sis' => 'GESTIONCAJAHSJ',
                'hora_actu' => now()->format('H:i:s'),
                'fecha_actu' => now()->format('d/m/Y'),
                'usu_nivel_sistema' => 'A',
                'cod_usu_farma' => null,
                'nom_maquina' => 'GESTIONCAJAHSJ',
                'estado_usuario' => 'A',
                'estado_usu_tramite' => 'A',
                'estado_usu_menu' => 'A',
            ],
        );

        return $code;
    }

    private static function nextPrefixedCode(string $modelClass, string $column, string $prefix, int $digits): string
    {
        $last = DB::connection('caja')->table((new $modelClass)->getTable())
            ->lockForUpdate()
            ->orderByDesc($column)
            ->value($column);

        $lastSequence = $last ? (int) substr($last, strlen($prefix)) : 0;

        return $prefix.str_pad((string) ($lastSequence + 1), $digits, '0', STR_PAD_LEFT);
    }
}
