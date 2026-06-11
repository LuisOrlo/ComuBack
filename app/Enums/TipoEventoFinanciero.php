<?php

namespace App\Enums;

enum TipoEventoFinanciero: string
{
    case Ingreso = 'INGRESO';
    case Egreso = 'EGRESO';

    public function label(): string
    {
        return match($this) {
            self::Ingreso => 'Ingreso',
            self::Egreso => 'Egreso',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Ingreso => 'success',
            self::Egreso => 'danger',
        };
    }
}
