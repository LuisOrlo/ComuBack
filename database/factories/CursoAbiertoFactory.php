<?php

namespace Database\Factories;

use App\Models\CursoAbierto;
use App\Models\CatalogoCurso;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

class CursoAbiertoFactory extends Factory
{
    protected $model = CursoAbierto::class;

    public function definition(): array
    {
        $fechaInicio = $this->faker->dateTimeBetween('+1 days', '+30 days');
        $fechaFin = Carbon::instance($fechaInicio)->addWeeks(12);

        return [
            'catalogo_curso_id' => CatalogoCurso::factory(),
            'nombre_instancia' => 'Grupo ' . $this->faker->bothify('##'),
            'semestre' => $this->faker->numerify('####-#'),
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'capacidad_maxima' => $this->faker->numberBetween(20, 50),
            'docente_id' => $this->faker->uuid(),
            'es_activo' => true,
            'observaciones' => $this->faker->paragraph(),
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
     * Curso vigente (fechas actuales)
     */
    public function vigente(): static
    {
        return $this->state(fn (array $attributes) => [
            'fecha_inicio' => Carbon::now()->subDays(5),
            'fecha_fin' => Carbon::now()->addDays(30),
            'es_activo' => true,
        ]);
    }

    /**
     * Curso próximo
     */
    public function proximo(): static
    {
        return $this->state(fn (array $attributes) => [
            'fecha_inicio' => Carbon::now()->addDays(7),
            'fecha_fin' => Carbon::now()->addDays(60),
            'es_activo' => true,
        ]);
    }

    /**
     * Curso finalizado
     */
    public function finalizado(): static
    {
        return $this->state(fn (array $attributes) => [
            'fecha_inicio' => Carbon::now()->subDays(60),
            'fecha_fin' => Carbon::now()->subDays(1),
            'es_activo' => true,
        ]);
    }

    /**
     * Con capacidad completa
     */
    public function lleno(): static
    {
        return $this->state(fn (array $attributes) => [
            'capacidad_maxima' => 10,
        ]);
    }
}
