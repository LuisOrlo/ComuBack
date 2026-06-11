<?php

namespace App\Enums;

enum EstadoOferta: string
{
    case Pendiente = 'pendiente';
    case Confirmado = 'confirmado';
    case EnProgreso = 'en_progreso';
    case Completado = 'completado';
    case Cancelado = 'cancelado';

    public function label(): string
    {
        return match($this) {
            self::Pendiente => 'Pendiente',
            self::Confirmado => 'Confirmado',
            self::EnProgreso => 'En Progreso',
            self::Completado => 'Completado',
            self::Cancelado => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pendiente => 'warning',
            self::Confirmado => 'info',
            self::EnProgreso => 'primary',
            self::Completado => 'success',
            self::Cancelado => 'danger',
        };
    }
}
