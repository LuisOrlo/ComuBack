<?php

namespace Database\Factories;

use App\Models\CambioHorario;
use App\Models\Matricula;
use App\Models\CursoAbierto;
use Illuminate\Database\Eloquent\Factories\Factory;

class CambioHorarioFactory extends Factory
{
    protected $model = CambioHorario::class;

    public function definition(): array
    {
        $matriculaOrigen = Matricula::factory()->create();
        $cursoAntiguo = $matriculaOrigen->cursoAbierto;
        $cursoNuevo = CursoAbierto::factory()->create();

        return [
            'matricula_origen_id' => $matriculaOrigen->id,
            'curso_abierto_antiguo_id' => $cursoAntiguo->id,
            'curso_abierto_nuevo_id' => $cursoNuevo->id,
            'estado' => 'pendiente',
            'motivo' => $this->faker->paragraph(),
            'observaciones_admin' => null,
        ];
    }

    /**
     * Cambio pendiente
     */
    public function pendiente(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'pendiente',
        ]);
    }

    /**
     * Cambio aprobado
     */
    public function aprobado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'aprobado',
        ]);
    }

    /**
     * Cambio rechazado
     */
    public function rechazado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'rechazado',
        ]);
    }

    /**
     * Cambio completado
     */
    public function completado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'completado',
        ]);
    }
}
