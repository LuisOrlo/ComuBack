<?php

namespace Tests\Unit;

use App\Services\Imports\StudentImportValidator;
use PHPUnit\Framework\TestCase;

class StudentImportValidatorTest extends TestCase
{
    public function test_aplica_las_reglas_actuales_de_cedula_y_celular(): void
    {
        $validator = new StudentImportValidator();
        $errors = $validator->validate([
            'nombres' => 'Maria',
            'apellidos' => 'Perez',
            'cedula' => '123',
            'correo' => 'correo-invalido',
            'celular' => '099',
            'ciudad' => null,
        ]);

        $codes = array_column($errors, 'code');
        $this->assertContains('INVALID_CEDULA', $codes);
        $this->assertContains('INVALID_EMAIL', $codes);
        $this->assertContains('INVALID_PHONE', $codes);
    }

    public function test_exige_nombres_y_apellidos(): void
    {
        $errors = (new StudentImportValidator())->validate([
            'nombres' => null,
            'apellidos' => null,
            'cedula' => null,
            'correo' => null,
            'celular' => null,
            'ciudad' => null,
        ]);

        $this->assertCount(2, $errors);
    }
}
