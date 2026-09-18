<?php

namespace Tests\Unit;

use App\Services\StudentScheduleConflictService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class StudentScheduleConflictServiceTest extends TestCase
{
    private function conflicts(array $newActivity, array $existingActivities): array
    {
        $service = new StudentScheduleConflictService();
        $method = new ReflectionMethod($service, 'findConflicts');
        $method->setAccessible(true);

        return $method->invoke($service, $newActivity, $existingActivities);
    }

    private function activity(string $type = 'curso', string $start = '2026-01-01', string $end = '2026-03-31', array $slots = [['day' => 3, 'start' => '18:00', 'end' => '20:00']]): array
    {
        return [
            'tipo' => $type,
            'actividad_id' => 'activity-1',
            'nombre' => 'Actividad existente',
            'fecha_inicio' => $start,
            'fecha_fin' => $end,
            'horarios' => array_map(static fn (array $slot) => [
                'dia_semana' => $slot['day'],
                'hora_inicio' => $slot['start'],
                'hora_fin' => $slot['end'],
                '_inicio' => ((int) substr($slot['start'], 0, 2) * 60) + (int) substr($slot['start'], 3, 2),
                '_fin' => ((int) substr($slot['end'], 0, 2) * 60) + (int) substr($slot['end'], 3, 2),
            ], $slots),
        ];
    }

    private function newActivity(string $start = '2026-02-01', string $end = '2026-02-28', array $slots = [['day' => 3, 'start' => '19:00', 'end' => '21:00']]): array
    {
        return $this->activity('curso', $start, $end, $slots);
    }

    public function test_overlapping_dates_day_and_hours_conflict(): void
    {
        $this->assertCount(1, $this->conflicts($this->newActivity(), [$this->activity()]));
    }

    public function test_adjacent_hours_do_not_conflict(): void
    {
        $this->assertSame([], $this->conflicts(
            $this->newActivity(slots: [['day' => 3, 'start' => '20:00', 'end' => '22:00']]),
            [$this->activity()]
        ));
    }

    public function test_different_dates_or_days_do_not_conflict(): void
    {
        $this->assertSame([], $this->conflicts(
            $this->newActivity(start: '2026-04-01', end: '2026-06-30'),
            [$this->activity()]
        ));

        $this->assertSame([], $this->conflicts(
            $this->newActivity(slots: [['day' => 4, 'start' => '19:00', 'end' => '21:00']]),
            [$this->activity()]
        ));
    }

    public function test_course_and_workshop_activities_use_the_same_algorithm(): void
    {
        $conflicts = $this->conflicts(
            $this->newActivity(),
            [$this->activity('taller')]
        );

        $this->assertSame('taller', $conflicts[0]['tipo']);
        $this->assertSame('Miércoles', $conflicts[0]['dia']);
    }
}
