<?php

namespace App\Helpers;

use App\Enums\{
    CategoriaCurso,
    EstadoMatricula,
    EstadoOferta,
    EstadoPago,
    EstadoReserva,
    Modalidad,
    MetodoPago,
    TipoAsesoria,
    TipoEventoFinanciero,
    TipoPersona,
    TipoPago,
};

class EnumHelper
{
    /**
     * Get all enum values with labels and metadata
     */
    public static function all(): array
    {
        return [
            'EstadoOferta' => self::toArray(EstadoOferta::cases()),
            'EstadoMatricula' => self::toArray(EstadoMatricula::cases()),
            'MetodoPago' => self::toArray(MetodoPago::cases()),
            'EstadoPago' => self::toArray(EstadoPago::cases()),
            'EstadoReserva' => self::toArray(EstadoReserva::cases()),
            'TipoPersona' => self::toArray(TipoPersona::cases()),
            'CategoriaCurso' => self::toArray(CategoriaCurso::cases()),
            'TipoPago' => self::toArray(TipoPago::cases()),
            'TipoAsesoria' => self::toArray(TipoAsesoria::cases()),
            'Modalidad' => self::toArray(Modalidad::cases()),
            'TipoEventoFinanciero' => self::toArray(TipoEventoFinanciero::cases()),
        ];
    }

    /**
     * Convert enum cases to array with value and label
     */
    private static function toArray(array $cases): array
    {
        return array_map(function ($case) {
            $data = [
                'value' => $case->value,
                'label' => method_exists($case, 'label') ? $case->label() : $case->name,
            ];

            if (method_exists($case, 'color')) {
                $data['color'] = $case->color();
            }

            if (method_exists($case, 'icon')) {
                $data['icon'] = $case->icon();
            }

            return $data;
        }, $cases);
    }

    /**
     * Get specific enum group
     */
    public static function get(string $enumName): array
    {
        $all = self::all();
        return $all[$enumName] ?? [];
    }
}
