<?php

namespace App\Enums;

enum EstadoPago: string
{
    case Pendiente = 'pendiente';
    case Abonado = 'abonado';
    case Pagado = 'pagado';
    case Anulado = 'anulado';

    public function label(): string
    {
        return match($this) {
            self::Pendiente => 'Pendiente',
            self::Abonado => 'Abonado',
            self::Pagado => 'Pagado',
            self::Anulado => 'Anulado',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pendiente => 'warning',
            self::Abonado => 'info',
            self::Pagado => 'success',
            self::Anulado => 'danger',
        };
    }
}
