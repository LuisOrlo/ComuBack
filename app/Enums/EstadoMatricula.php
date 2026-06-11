<?php

namespace App\Enums;

enum EstadoMatricula: string
{
    case Activo = 'activo';
    case Completado = 'completado';
    case Retirado = 'retirado';
    case Reprobado = 'reprobado';

    public function label(): string
    {
        return match($this) {
            self::Activo => 'Activo',
            self::Completado => 'Completado',
            self::Retirado => 'Retirado',
            self::Reprobado => 'Reprobado',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Activo => 'success',
            self::Completado => 'info',
            self::Retirado => 'warning',
            self::Reprobado => 'danger',
        };
    }
}
