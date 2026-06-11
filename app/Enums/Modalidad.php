<?php

namespace App\Enums;

enum Modalidad: string
{
    case Presencial = 'presencial';
    case Virtual = 'virtual';

    public function label(): string
    {
        return match($this) {
            self::Presencial => 'Presencial',
            self::Virtual => 'Virtual',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::Presencial => 'map-pin',
            self::Virtual => 'video',
        };
    }
}
