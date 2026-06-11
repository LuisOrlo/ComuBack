<?php

namespace App\Enums;

enum TipoPersona: string
{
    case Staff = 'staff';
    case Instructor = 'instructor';
    case Estudiante = 'estudiante';
    case ClienteExterno = 'cliente_externo';
    case Pasante = 'pasante';

    public function label(): string
    {
        return match($this) {
            self::Staff => 'Staff',
            self::Instructor => 'Instructor',
            self::Estudiante => 'Estudiante',
            self::ClienteExterno => 'Cliente Externo',
            self::Pasante => 'Pasante',
        };
    }

    public function isEducational(): bool
    {
        return in_array($this, [self::Instructor, self::Estudiante, self::Pasante]);
    }

    public function isInternal(): bool
    {
        return in_array($this, [self::Staff, self::Instructor, self::Pasante]);
    }
}
