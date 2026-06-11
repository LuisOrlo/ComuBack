<?php

namespace Database\Factories;

use App\Models\Nota;
use App\Models\Matricula;
use App\Models\Modulo;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotaFactory extends Factory
{
    protected $model = Nota::class;

    public function definition(): array
    {
        return [
            'matricula_id' => Matricula::factory(),
            'modulo_id' => Modulo::factory(),
            'calificacion' => $this->faker->randomFloat(2, 0, 5),
            'observaciones' => $this->faker->paragraph(),
        ];
    }

    /**
     * Nota aprobada (>= 3.0)
     */
    public function aprobada(): static
    {
        return $this->state(fn (array $attributes) => [
            'calificacion' => $this->faker->randomFloat(2, 3.0, 5.0),
        ]);
    }

    /**
     * Nota reprobada (< 3.0)
     */
    public function reprobada(): static
    {
        return $this->state(fn (array $attributes) => [
            'calificacion' => $this->faker->randomFloat(2, 0, 2.99),
        ]);
    }

    /**
     * Nota excelente (>= 4.5)
     */
    public function excelente(): static
    {
        return $this->state(fn (array $attributes) => [
            'calificacion' => $this->faker->randomFloat(2, 4.5, 5.0),
        ]);
    }

    /**
     * Nota buena (3.5 - 4.4)
     */
    public function buena(): static
    {
        return $this->state(fn (array $attributes) => [
            'calificacion' => $this->faker->randomFloat(2, 3.5, 4.4),
        ]);
    }

    /**
     * Nota regular (3.0 - 3.4)
     */
    public function regular(): static
    {
        return $this->state(fn (array $attributes) => [
            'calificacion' => $this->faker->randomFloat(2, 3.0, 3.4),
        ]);
    }
}
