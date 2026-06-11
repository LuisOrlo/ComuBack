<?php

namespace Database\Factories;

use App\Models\CatalogoCurso;
use Illuminate\Database\Eloquent\Factories\Factory;

class CatalogoCursoFactory extends Factory
{
    protected $model = CatalogoCurso::class;

    public function definition(): array
    {
        return [
            'programa_id' => $this->faker->uuid(),
            'codigo' => $this->faker->unique()->bothify('???-###'),
            'nombre' => $this->faker->sentence(3),
            'descripcion' => $this->faker->paragraph(),
            'creditos' => $this->faker->numberBetween(1, 5),
            'horas_totales' => $this->faker->numberBetween(30, 120),
            'modulos_default' => $this->faker->numberBetween(2, 4),
            'categoria' => $this->faker->randomElement(['regular', 'personalizado']),
            'es_activo' => true,
        ];
    }

    /**
     * Curso inactivo
     */
    public function inactivo(): static
    {
        return $this->state(fn (array $attributes) => [
            'es_activo' => false,
        ]);
    }

    /**
     * Curso personalizado
     */
    public function personalizado(): static
    {
        return $this->state(fn (array $attributes) => [
            'categoria' => 'personalizado',
        ]);
    }

    /**
     * Curso regular
     */
    public function regular(): static
    {
        return $this->state(fn (array $attributes) => [
            'categoria' => 'regular',
        ]);
    }
}
