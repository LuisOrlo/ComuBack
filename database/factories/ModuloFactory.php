<?php

namespace Database\Factories;

use App\Models\Modulo;
use App\Models\CatalogoCurso;
use App\Models\CursoAbierto;
use Illuminate\Database\Eloquent\Factories\Factory;

class ModuloFactory extends Factory
{
    protected $model = Modulo::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->words(2, true),
            'descripcion' => $this->faker->paragraph(),
            'semana_inicio' => $this->faker->numberBetween(1, 10),
            'semana_fin' => $this->faker->numberBetween(11, 16),
            'ponderacion' => $this->faker->randomFloat(2, 10, 50),
            'catalogo_curso_id' => null,
            'curso_abierto_id' => CursoAbierto::factory(),
            'tipo' => 'personalizado',
        ];
    }

    /**
     * Módulo del catálogo (predeterminado)
     */
    public function delCatalogo(): static
    {
        return $this->state(fn (array $attributes) => [
            'catalogo_curso_id' => CatalogoCurso::factory(),
            'curso_abierto_id' => null,
            'tipo' => 'predeterminado',
        ]);
    }

    /**
     * Módulo del curso abierto (personalizado)
     */
    public function delCurso(): static
    {
        return $this->state(fn (array $attributes) => [
            'catalogo_curso_id' => null,
            'curso_abierto_id' => CursoAbierto::factory(),
            'tipo' => 'personalizado',
        ]);
    }

    /**
     * Primer módulo (semanas 1-4)
     */
    public function primero(): static
    {
        return $this->state(fn (array $attributes) => [
            'semana_inicio' => 1,
            'semana_fin' => 4,
            'ponderacion' => 25,
        ]);
    }

    /**
     * Segundo módulo (semanas 5-8)
     */
    public function segundo(): static
    {
        return $this->state(fn (array $attributes) => [
            'semana_inicio' => 5,
            'semana_fin' => 8,
            'ponderacion' => 25,
        ]);
    }

    /**
     * Tercer módulo (semanas 9-12)
     */
    public function tercero(): static
    {
        return $this->state(fn (array $attributes) => [
            'semana_inicio' => 9,
            'semana_fin' => 12,
            'ponderacion' => 25,
        ]);
    }

    /**
     * Cuarto módulo (semanas 13-16)
     */
    public function cuarto(): static
    {
        return $this->state(fn (array $attributes) => [
            'semana_inicio' => 13,
            'semana_fin' => 16,
            'ponderacion' => 25,
        ]);
    }
}
