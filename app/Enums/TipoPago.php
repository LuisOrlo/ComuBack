<?php

namespace App\Enums;

enum TipoPago: string
{
    case Completo = 'completo';
    case Bono = 'bono';

    public function label(): string
    {
        return match($this) {
            self::Completo => 'Completo',
            self::Bono => 'Bono',
        };
    }
}
