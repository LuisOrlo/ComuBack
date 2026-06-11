<?php

namespace Database\Factories;

use App\Models\TrasladoModulo;
use App\Models\Matricula;
use App\Models\Modulo;
use Illuminate\Database\Eloquent\Factories\Factory;

class TrasladoModuloFactory extends Factory
{
    protected $model = TrasladoModulo::class;

    public function definition(): array
    {
        $matricula = Matricula::factory()->create();
        $moduloAntiguo = Modulo::factory()->create();
        $moduloNuevo = Modulo::factory()->create();

        return [
            'matricula_origen_id' => $matricula->id,
            'modulo_antiguo_id' => $moduloAntiguo->id,
            'modulo_nuevo_id' => $moduloNuevo->id,
            'estado' => 'pendiente',
            'motivo' => $this->faker->paragraph(),
            'observaciones_admin' => null,
        ];
    }

    /**
     * Traslado pendiente
     */
    public function pendiente(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'pendiente',
        ]);
    }

    /**
     * Traslado aprobado
     */
    public function aprobado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'aprobado',
        ]);
    }

    /**
     * Traslado rechazado
     */
    public function rechazado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'rechazado',
        ]);
    }

    /**
     * Traslado completado
     */
    public function completado(): static
    {
        return $this->state(fn (array $attributes) => [
            'estado' => 'completado',
        ]);
    }
}
