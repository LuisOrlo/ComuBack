<?php

namespace Tests\Unit;

use App\Services\Imports\EnrollmentImportAdapter;
use PHPUnit\Framework\TestCase;

class EnrollmentImportAdapterTest extends TestCase
{
    public function test_exposes_read_only_preview_and_academic_execute_contracts(): void
    {
        $reflection = new \ReflectionClass(EnrollmentImportAdapter::class);

        $this->assertTrue($reflection->hasMethod('preview'));
        $this->assertTrue($reflection->hasMethod('execute'));
        $this->assertFalse($reflection->hasMethod('createFinance'));
    }

    public function test_normal_and_historical_courses_are_supported_but_custom_courses_are_not(): void
    {
        $adapter = new EnrollmentImportAdapter();
        $this->assertTrue(EnrollmentImportAdapter::supportsPersonalizationFlag(false));
        $this->assertTrue(EnrollmentImportAdapter::supportsPersonalizationFlag(false)); // historical normal course
        $this->assertFalse(EnrollmentImportAdapter::supportsPersonalizationFlag(true));
        $this->assertSame('CUSTOM_COURSE_NOT_SUPPORTED', $adapter->unsupportedCourseError()['code']);
    }

    public function test_execute_override_cannot_change_the_preview_course(): void
    {
        $stored = ['enabled' => true, 'curso_abierto_id' => '11111111-1111-1111-1111-111111111111'];

        $this->assertTrue(\App\Services\Imports\StudentImportService::academicOverrideMatches($stored, $stored));
        $this->assertFalse(\App\Services\Imports\StudentImportService::academicOverrideMatches($stored, [
            'enabled' => true,
            'curso_abierto_id' => '22222222-2222-2222-2222-222222222222',
        ]));
        $this->assertFalse(\App\Services\Imports\StudentImportService::academicOverrideMatches($stored, ['enabled' => false]));
    }
}
