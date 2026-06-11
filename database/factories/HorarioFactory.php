<?php

namespace Database\Factories;

use App\Models\Horario;
use App\Models\CursoAbierto;
use Illuminate\Database\Eloquent\Factories\Factory;

class HorarioFactory extends Factory
{
    protected $model = Horario::class;

    public function definition(): array
    {
        return [
            'curso_abierto_id' => CursoAbierto::factory(),
            'nombre_referencial' => $this->faker->randomElement(['Mañana', 'Tarde', 'Noche']) . ' - ' . $this->faker->bothify('###'),
            'hora_inicio' => $this->faker->time('H:i'),
            'hora_fin' => $this->faker->time('H:i'),
            'es_activo' => true,
        ];
    }

    /**
     * Horario inactivo
     */
    public function inactivo(): static
    {
        return $this->state(fn (array $attributes) => [
            'es_activo' => false,
        ]);
    }

    /**
     * Horario matutino
     */
    public function matutino(): static
    {
        return $this->state(fn (array $attributes) => [
            'hora_inicio' => '07:00',
            'hora_fin' => '12:00',
        ]);
    }

    /**
     * Horario vespertino
     */
    public function vespertino(): static
    {
        return $this->state(fn (array $attributes) => [
            'hora_inicio' => '13:00',
            'hora_fin' => '18:00',
        ]);
    }

    /**
     * Horario nocturno
     */
    public function nocturno(): static
    {
        return $this->state(fn (array $attributes) => [
            'hora_inicio' => '19:00',
            'hora_fin' => '22:00',
        ]);
    }
}
