<?php

namespace App\Enums;

enum CategoriaCurso: string
{
    case Regular = 'regular';
    case Personalizado = 'personalizado';

    public function label(): string
    {
        return match($this) {
            self::Regular => 'Regular',
            self::Personalizado => 'Personalizado',
        };
    }
}
