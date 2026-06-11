<?php

namespace App\Enums;

enum MetodoPago: string
{
    case Efectivo = 'efectivo';
    case Transferencia = 'transferencia';
    case Deposito = 'deposito';
    case Tarjeta = 'tarjeta';
    case Otro = 'otro';

    public function label(): string
    {
        return match($this) {
            self::Efectivo => 'Efectivo',
            self::Transferencia => 'Transferencia',
            self::Deposito => 'Depósito',
            self::Tarjeta => 'Tarjeta',
            self::Otro => 'Otro',
        };
    }
}
