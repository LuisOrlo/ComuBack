<?php

namespace Database\Factories;

use App\Models\Matricula;
use App\Models\CursoAbierto;
use App\Models\Horario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

class MatriculaFactory extends Factory
{
    protected $model = Matricula::class;

    public function definition(): array
    {
        $fechaInicio = $this->faker->dateTimeBetween('-30 days', '+1 days');
        
        return [
            'estudiante_id' => $this->faker->uuid(),
            'curso_abierto_id' => CursoAbierto::factory(),
            'horario_id' => null,
            'estado' => 'activo',
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => Carbon::instance($fechaInicio)->addWeeks(12),
            'observaciones' => $this->faker->paragraph(),
        ];
    }

    /**
     * Matrícula activa
     */
    public function activa(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'activo',
        ]);
    }

    /**
     * Matrícula completada
     */
    public function completada(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'completado',
        ]);
    }

    /**
     * Matrícula retirada
     */
    public function retirada(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'retirado',
        ]);
    }

    /**
     * Matrícula reprobada
     */
    public function reprobada(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'reprobado',
        ]);
    }

    /**
     * Con horario
     */
    public function conHorario(): static
    {
        return $this->state(fn (array $attributes) => [
            'horario_id' => Horario::factory(),
        ]);
    }
}
