<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class StudentImportExecutionTest extends TestCase
{
    public function test_preview_confirmado_crea_persona_y_perfil(): void
    {
        $this->createAuthenticatedUser();
        $file = UploadedFile::fake()->createWithContent('students.csv', "NOMBRES,APELLIDOS,CEDULA\nMaria,Perez,0102030405\n");

        $preview = $this->post('/api/imports/students/preview', ['archivo' => $file], ['Accept' => 'application/json']);
        $previewId = $preview->json('data.preview_id');
        $mapping = $preview->json('data.suggested_mapping');

        $finalPreview = $this->postJson('/api/imports/students/preview', [
            'preview_id' => $previewId,
            'mapping' => $mapping,
        ]);
        $row = $finalPreview->json('data.rows.0');

        $response = $this->postJson('/api/imports/students/execute', [
            'preview_id' => $previewId,
            'confirmed_rows' => [['row_number' => $row['row_number']]],
        ]);

        $response->assertOk()->assertJsonPath('data.summary.imported', 1);
        $this->assertDatabaseHas('people.personas', ['cedula' => '0102030405', 'tipo' => 'estudiante']);
        $personaId = $response->json('data.rows.0.persona_id');
        $this->assertDatabaseHas('people.perfil_estudiante', ['persona_id' => $personaId]);
    }
}
