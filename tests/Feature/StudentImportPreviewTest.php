<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentImportPreviewTest extends TestCase
{
    public function test_preview_requires_authentication(): void
    {
        $response = $this->postJson('/api/imports/students/preview', []);

        $response->assertUnauthorized();
    }

    public function test_secretaria_can_access_preview(): void
    {
        $account = $this->createAuthenticatedUser();
        Role::findOrCreate('Secretaria', 'web');
        $account->syncRoles('Secretaria');
        $file = UploadedFile::fake()->createWithContent('students.csv', "NOMBRES,APELLIDOS\nMaria,Perez\n");

        $response = $this->post('/api/imports/students/preview', ['archivo' => $file], ['Accept' => 'application/json']);

        $response->assertOk();
    }

    public function test_other_roles_cannot_access_preview(): void
    {
        $account = $this->createAuthenticatedUser();
        Role::findOrCreate('Instructor', 'web');
        $account->syncRoles('Instructor');
        $file = UploadedFile::fake()->createWithContent('students.csv', "NOMBRES,APELLIDOS\nMaria,Perez\n");

        $response = $this->post('/api/imports/students/preview', ['archivo' => $file], ['Accept' => 'application/json']);

        $response->assertForbidden();
    }

    public function test_preview_devuelve_mapeo_sugerido_sin_escribir_estudiantes(): void
    {
        $this->createAuthenticatedUser();
        $file = UploadedFile::fake()->createWithContent('students.csv', "NOMBRES,APELLIDOS,CEDULA\nMaria,Perez,0102030405\n");

        $response = $this->post('/api/imports/students/preview', ['archivo' => $file], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('data.stage', 'MAPPING');
        $response->assertJsonPath('data.suggested_mapping.NOMBRES', 'student.nombres');
        $this->assertDatabaseMissing('people.personas', ['cedula' => '0102030405']);
    }

    public function test_preview_acepta_xls(): void
    {
        $this->createAuthenticatedUser();
        $response = $this->post('/api/imports/students/preview', [
            'archivo' => $this->spreadsheetUpload('xls'),
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('data.stage', 'MAPPING');
    }

    public function test_preview_acepta_xlsx(): void
    {
        $this->createAuthenticatedUser();
        $response = $this->post('/api/imports/students/preview', [
            'archivo' => $this->spreadsheetUpload('xlsx'),
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('data.stage', 'MAPPING');
    }

    private function spreadsheetUpload(string $extension): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['NOMBRES', 'APELLIDOS', 'CEDULA'],
            ['Maria', 'Perez', '0102030405'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'student-import-');
        $writer = $extension === 'xls'
            ? new Xls($spreadsheet)
            : new Xlsx($spreadsheet);
        $writer->save($path);
        $content = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent("students.$extension", $content);
    }
}
