<?php

namespace Tests\Unit;

use App\Http\Requests\StorePersonalizadoCursoRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class PersonalizadoCursoRequestTest extends TestCase
{
    private function validar(array $datos)
    {
        $request = StorePersonalizadoCursoRequest::create('/', 'POST', $datos);
        $translator = new Translator(new ArrayLoader(), 'en');
        $factory = new Factory($translator);
        $validator = $factory->make($request->all(), $request->rules());
        $request->withValidator($validator);

        return $validator;
    }

    private function datosBase(): array
    {
        return [
            'nombre' => 'Curso personalizado de prueba',
            'modalidad' => 'virtual',
            'fecha_inicio' => '2026-09-20',
            'fecha_fin' => '2026-09-20',
            'hora_inicio' => '18:00',
            'hora_fin' => '20:00',
            'precio_total' => 300,
            'capacidad' => 10,
        ];
    }

    public function test_virtual_sin_ciudad_y_curso_de_un_dia_es_valido(): void
    {
        $validator = $this->validar($this->datosBase());

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_presencial_sin_ciudad_es_invalido(): void
    {
        $datos = $this->datosBase();
        $datos['modalidad'] = 'presencial';

        $validator = $this->validar($datos);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('ciudad_id', $validator->errors()->toArray());
    }

    public function test_fecha_final_anterior_es_invalida(): void
    {
        $datos = $this->datosBase();
        $datos['fecha_inicio'] = '2026-09-20';
        $datos['fecha_fin'] = '2026-09-19';

        $validator = $this->validar($datos);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('fecha_fin', $validator->errors()->toArray());
    }

    public function test_hora_final_no_puede_ser_menor_o_igual(): void
    {
        $datos = $this->datosBase();
        $datos['hora_inicio'] = '20:00';
        $datos['hora_fin'] = '20:00';

        $validator = $this->validar($datos);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('hora_fin', $validator->errors()->toArray());
    }

    public function test_precio_y_capacidad_invalidos(): void
    {
        $datos = $this->datosBase();
        $datos['precio_total'] = -1;
        $datos['capacidad'] = 0;

        $validator = $this->validar($datos);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('precio_total', $validator->errors()->toArray());
        $this->assertArrayHasKey('capacidad', $validator->errors()->toArray());
    }
}
