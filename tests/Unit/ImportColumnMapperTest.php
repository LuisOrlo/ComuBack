<?php

namespace Tests\Unit;

use App\Services\Imports\ImportColumnMapper;
use PHPUnit\Framework\TestCase;

class ImportColumnMapperTest extends TestCase
{
    public function test_sugiere_campos_para_encabezados_comunes(): void
    {
        $mapper = new ImportColumnMapper();
        $suggestions = $mapper->suggest(['NOMBRES Y APELLIDOS', 'CÉDULA', 'TELÉFONO', 'LOCALIDAD', 'OTRA']);

        $this->assertSame('student.full_name', $suggestions['NOMBRES Y APELLIDOS']);
        $this->assertSame('student.cedula', $suggestions['CÉDULA']);
        $this->assertSame('student.celular', $suggestions['TELÉFONO']);
        $this->assertSame('student.ciudad', $suggestions['LOCALIDAD']);
        $this->assertSame(ImportColumnMapper::IGNORE, $suggestions['OTRA']);
    }

    public function test_valida_nombres_separados(): void
    {
        $mapper = new ImportColumnMapper();
        $errors = $mapper->validate(['NOMBRES', 'APELLIDOS'], [
            'NOMBRES' => 'student.nombres',
            'APELLIDOS' => 'student.apellidos',
        ]);

        $this->assertSame([], $errors);
    }
}
