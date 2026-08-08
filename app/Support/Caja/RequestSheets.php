<?php

namespace App\Support\Caja;

/**
 * "Hojas de solicitud": reproducen en pantalla los formatos en papel que el equipo de
 * caja recibe desde Admisión/Citas, para que el cajero pueda ubicar los exámenes
 * marcados sin tener que buscarlos uno por uno.
 *
 * La hoja de RAYOS X es una transcripción fiel del formato impreso del Hospital San
 * José de Chincha: mismas secciones anatómicas, mismos códigos, y las columnas de
 * "placas usadas" y "medidas" que solo existen en ese papel (no están en la base de
 * datos). Las demás hojas no tienen formato impreso de referencia, así que se agrupan
 * por los rangos de código del propio catálogo (CPT), que sí son significativos.
 *
 * Los códigos corresponden a Nomenclatura_caja_MH.nomen_caja; la descripción y el
 * precio siempre se leen de la base, nunca del papel.
 */
class RequestSheets
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'rayos-x' => [
                'label' => 'Rayos X',
                'icon' => 'sparkles',
                'grupo' => 'RX',
                'note' => 'Transcripción del formato impreso de Rayos X. Las columnas de placas y medidas provienen del formato en papel.',
                'shows_plates' => true,
                'sections' => self::rayosXSections(),
            ],
            'laboratorio' => [
                'label' => 'Laboratorio',
                'icon' => 'beaker',
                'grupo' => 'LA',
                'note' => 'Agrupado por sección del catálogo (CPT). No existe formato impreso de referencia para laboratorio.',
                'shows_plates' => false,
                'prefix_sections' => [
                    '80' => 'Perfiles y paneles',
                    '81' => 'Análisis de orina',
                    '82' => 'Química clínica',
                    '83' => 'Química clínica (continuación)',
                    '84' => 'Química clínica y hormonas',
                    '85' => 'Hematología y coagulación',
                    '86' => 'Inmunología y serología',
                    '87' => 'Microbiología',
                    '88' => 'Anatomía patológica y citología',
                    '89' => 'Otros procedimientos de laboratorio',
                ],
            ],
            'ecografias' => [
                'label' => 'Ecografías',
                'icon' => 'signal',
                'grupo' => 'EC',
                'note' => 'Agrupado por sección del catálogo. No existe formato impreso de referencia.',
                'shows_plates' => false,
                'prefix_sections' => [],
            ],
            'tomografias' => [
                'label' => 'Tomografías',
                'icon' => 'cube',
                'grupo' => 'TM',
                'note' => 'Agrupado por sección del catálogo. No existe formato impreso de referencia.',
                'shows_plates' => false,
                'prefix_sections' => [],
            ],
            'consultas' => [
                'label' => 'Consultas',
                'icon' => 'user',
                'grupo' => 'CJ',
                // El usuario indicó que este formato no existe en papel y pidió crearlo.
                'note' => 'Hoja creada para Caja (no existe formato impreso). Reúne las consultas del catálogo.',
                'shows_plates' => false,
                'like' => 'CONSULTA%',
                'prefix_sections' => [],
            ],
        ];
    }

    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Secciones y códigos exactamente como aparecen en el formato impreso de Rayos X.
     * placas/medidas se muestran como referencia operativa del papel.
     *
     * @return array<string, array<string, array{placas: string, medidas: string}>>
     */
    private static function rayosXSections(): array
    {
        return [
            'Cabeza y cuello' => [
                '70260' => ['placas' => '02', 'medidas' => '10X12'],
                '70200' => ['placas' => '02', 'medidas' => '24X30'],
                '70240' => ['placas' => '02', 'medidas' => '24X30'],
                '70140' => ['placas' => '01', 'medidas' => '10X12'],
                '70160' => ['placas' => '02', 'medidas' => '18X24'],
                '70100' => ['placas' => '02', 'medidas' => '24X30'],
                '70120' => ['placas' => '04', 'medidas' => '24X30'],
                '70220' => ['placas' => '03', 'medidas' => '24X30'],
            ],
            'Columna y pelvis' => [
                '72040' => ['placas' => '04', 'medidas' => '10X12'],
                '72070' => ['placas' => '02', 'medidas' => '14X17'],
                '72074' => ['placas' => '04', 'medidas' => '14X17'],
                '72050' => ['placas' => '04', 'medidas' => '14X17'],
                '72100' => ['placas' => '02', 'medidas' => '14X17'],
                '72110' => ['placas' => '04', 'medidas' => '14X17'],
                '72171' => ['placas' => '02', 'medidas' => '14X17'],
                '72120' => ['placas' => '04', 'medidas' => '24X30'],
                '73510' => ['placas' => '02', 'medidas' => '24X30'],
            ],
            'Extremidades' => [
                '73020' => ['placas' => '01', 'medidas' => '10X12'],
                '73030' => ['placas' => '02', 'medidas' => '10X12'],
                '73010' => ['placas' => '04', 'medidas' => '24X30'],
                '73060' => ['placas' => '02', 'medidas' => '30X40'],
                '73070' => ['placas' => '02', 'medidas' => '24X30'],
                '73090' => ['placas' => '02', 'medidas' => '30X40'],
                '73100' => ['placas' => '02', 'medidas' => '24X30'],
                '76020' => ['placas' => '02', 'medidas' => '10X12'],
                '73120' => ['placas' => '03', 'medidas' => '14X17'],
                '76040' => ['placas' => '02', 'medidas' => '14X17'],
                '73550' => ['placas' => '02', 'medidas' => '30X40'],
                '73562' => ['placas' => '02', 'medidas' => '30X40'],
                '73590' => ['placas' => '02', 'medidas' => '24X30'],
                '73600' => ['placas' => '02', 'medidas' => '24X30'],
                '73620' => ['placas' => '02', 'medidas' => '10X12'],
            ],
            'Tórax' => [
                '73000' => ['placas' => '02', 'medidas' => '24X30'],
                '71200' => ['placas' => '02', 'medidas' => '14X14'],
                '71100' => ['placas' => '02', 'medidas' => '14X14'],
                '71010' => ['placas' => '02', 'medidas' => '14X14'],
                '71020' => ['placas' => '02', 'medidas' => '14X14'],
            ],
            'Aparato digestivo' => [
                '74000' => ['placas' => '01', 'medidas' => '14X17'],
                '74020' => ['placas' => '02', 'medidas' => '14X17'],
                '74305' => ['placas' => '03', 'medidas' => '24X30'],
                '74220' => ['placas' => '02', 'medidas' => '30X40'],
                '74246' => ['placas' => '06', 'medidas' => '14X17'],
                '74250' => ['placas' => '05', 'medidas' => '14X17'],
                '74280' => ['placas' => '05', 'medidas' => '14X17'],
            ],
            'Aparato urogenital' => [
                '74400' => ['placas' => '05', 'medidas' => '14X17'],
                '74430' => ['placas' => '03', 'medidas' => '14X14'],
                '74450' => ['placas' => '02', 'medidas' => '30X40'],
                '74740' => ['placas' => '05', 'medidas' => '14X14'],
            ],
        ];
    }
}
