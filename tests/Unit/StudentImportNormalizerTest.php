<?php

namespace Tests\Unit;

use App\Services\Imports\StudentImportRowNormalizer;
use PHPUnit\Framework\TestCase;

class StudentImportNormalizerTest extends TestCase
{
    public function test_normaliza_valores_sin_perder_ceros_de_cedula(): void
    {
        $normalizer = new StudentImportRowNormalizer();
        $result = $normalizer->normalize([
            'NOMBRE' => '  MARIA   DANIELA ',
            'CEDULA' => ' 0102030405 ',
            'TEL' => '097 946 1752',
            'EMAIL' => ' TEST@EXAMPLE.COM ',
        ], [
            'NOMBRE' => 'student.full_name',
            'CEDULA' => 'student.cedula',
            'TEL' => 'student.celular',
            'EMAIL' => 'student.correo',
        ], 2);

        $this->assertSame('0102030405', $result['student']['cedula']);
        $this->assertSame('0979461752', $result['student']['celular']);
        $this->assertSame('test@example.com', $result['student']['correo']);
        $this->assertTrue($result['student']['name_inference']);
    }
}
