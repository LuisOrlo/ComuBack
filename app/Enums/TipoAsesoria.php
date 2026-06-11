<?php

namespace App\Enums;

enum TipoAsesoria: string
{
    case Academica = 'academica';
    case Profesional = 'profesional';
    case Vocacional = 'vocacional';

    public function label(): string
    {
        return match($this) {
            self::Academica => 'Académica',
            self::Profesional => 'Profesional',
            self::Vocacional => 'Vocacional',
        };
    }
}
