<?php

namespace Tests\Feature;

use App\Models\CatalogoCurso;
use App\Models\CuentaPorCobrar;
use App\Models\CursoAbierto;
use App\Models\Matricula;
use App\Models\Modulo;
use App\Models\Persona;
use App\Models\TransaccionIngreso;
use App\Models\Finance\LineaPagoModulo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentImportFinanceTest extends TestCase
{
    public function test_imports_total_payment_and_updates_cash_once(): void
    {
        $user = $this->createAuthenticatedUser();
        $course = $this->makeCourse(72);
        $preview = $this->previewFor($course, 72, 72, 0);

        $before = (float) \DB::table('finance.resumen_caja')->where('id', 1)->value('total_ingresos');
        $result = $this->execute($preview);
        $matriculaId = $result->json('data.rows.0.matricula_id');
        $matricula = Matricula::findOrFail($matriculaId);
        $line = LineaPagoModulo::where('matricula_id', $matriculaId)->firstOrFail();
        $account = CuentaPorCobrar::where('matricula_id', $matriculaId)->firstOrFail();

        $this->assertSame('FINANCE_CREATED', $result->json('data.rows.0.finance_status'));
        $this->assertSame(72.0, (float) $line->monto_abonado);
        $this->assertSame(LineaPagoModulo::ESTADO_PAGADO, $line->estado);
        $this->assertSame(72.0, (float) $account->monto_abonado);
        $this->assertSame(0.0, (float) $account->saldo_pendiente);
        $this->assertSame(72.0, (float) \DB::table('finance.resumen_caja')->where('id', 1)->value('total_ingresos') - $before);
        $this->assertSame(1, TransaccionIngreso::where('linea_pago_modulo_id', $line->id)->count());
        $this->assertSame((string) $user->persona_id, (string) TransaccionIngreso::where('linea_pago_modulo_id', $line->id)->value('registrado_por'));
    }

    public function test_imports_partial_payment_and_zero_payment_without_transactions(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse(72);
        $partial = $this->execute($this->previewFor($course, 72, 30, 42));
        $partialLine = LineaPagoModulo::where('matricula_id', $partial->json('data.rows.0.matricula_id'))->firstOrFail();
        $partialAccount = CuentaPorCobrar::where('matricula_id', $partial->json('data.rows.0.matricula_id'))->firstOrFail();
        $this->assertSame(30.0, (float) $partialLine->monto_abonado);
        $this->assertSame(42.0, (float) $partialAccount->saldo_pendiente);

        $zeroCourse = $this->makeCourse(72);
        $zero = $this->execute($this->previewFor($zeroCourse, 0, 0, 0));
        $zeroMatricula = $zero->json('data.rows.0.matricula_id');
        $this->assertSame('NO_FINANCE_REQUIRED', $zero->json('data.rows.0.finance_status'));
        $this->assertSame(0, LineaPagoModulo::where('matricula_id', $zeroMatricula)->count());
        $this->assertSame(0, CuentaPorCobrar::where('matricula_id', $zeroMatricula)->count());
    }

    public function test_existing_phase_three_matricula_receives_finance_without_recreation(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse(72);
        $persona = Persona::create(['tipo' => 'estudiante', 'nombres' => 'Existente', 'apellidos' => 'Fase 3', 'cedula' => '1234567890', 'es_activo' => true]);
        $matricula = Matricula::create(['estudiante_id' => $persona->id, 'curso_abierto_id' => $course->id, 'precio_total_legacy' => 0, 'tipo_pago' => 'completo', 'estado' => Matricula::ESTADO_ACTIVO]);
        $preview = $this->previewFor($course, 72, 72, 0, '1234567890');
        $result = $this->execute($preview);

        $this->assertSame((string) $matricula->id, (string) $result->json('data.rows.0.matricula_id'));
        $this->assertSame(1, Matricula::where('id', $matricula->id)->count());
        $this->assertSame(1, LineaPagoModulo::where('matricula_id', $matricula->id)->count());
    }

    public function test_custom_course_is_blocked(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse(72, true);
        $preview = $this->previewFor($course, 72, 72, 0);

        $preview->assertOk();
        $this->assertSame('CUSTOM_COURSE_NOT_SUPPORTED', $preview->json('data.rows.0.finance.errors.0.code'));
        $this->assertSame(0, Matricula::where('curso_abierto_id', $course->id)->count());
    }

    public function test_financial_preview_requires_administrator(): void
    {
        $persona = Persona::create(['tipo' => 'secretaria', 'nombres' => 'Secretaria', 'apellidos' => 'Test', 'cedula' => '1234567891', 'es_activo' => true]);
        $account = \App\Models\CuentaSistema::create(['persona_id' => $persona->id, 'username' => Str::uuid()->toString(), 'password_hash' => 'secret']);
        $account->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Secretaria', 'web'));
        $this->actingAs($account, 'sanctum');

        $course = $this->makeCourse(72);
        $file = UploadedFile::fake()->createWithContent('finance.csv', "NOMBRES,APELLIDOS\nSecretaria,Test\n");
        $first = $this->post('/api/imports/students/preview', ['archivo' => $file], ['Accept' => 'application/json']);
        $response = $this->postJson('/api/imports/students/preview', [
            'preview_id' => $first->json('data.preview_id'),
            'mapping' => $first->json('data.suggested_mapping'),
            'enrollment' => ['enabled' => true, 'curso_abierto_id' => $course->id, 'sin_registro_financiero' => true],
            'finance' => ['enabled' => true],
        ]);

        $response->assertStatus(403);
    }

    public function test_financial_conflict_does_not_overwrite_existing_line(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse(72);
        $persona = Persona::create(['tipo' => 'estudiante', 'nombres' => 'Con', 'apellidos' => 'Finanzas', 'cedula' => '1234567892', 'es_activo' => true]);
        $matricula = Matricula::create(['estudiante_id' => $persona->id, 'curso_abierto_id' => $course->id, 'precio_total_legacy' => 0, 'tipo_pago' => 'completo', 'estado' => Matricula::ESTADO_ACTIVO]);
        LineaPagoModulo::create(['matricula_id' => $matricula->id, 'modulo_id' => $course->modulos()->first()->id, 'monto_original' => 72, 'monto_ajustado' => 72, 'monto_abonado' => 0, 'estado' => 'pendiente', 'orden' => 1]);

        $preview = $this->previewFor($course, 72, 72, 0, '1234567892');
        $this->assertSame('FINANCE_CONFLICT', $preview->json('data.rows.0.finance.errors.0.code'));
        $this->assertSame(1, LineaPagoModulo::where('matricula_id', $matricula->id)->count());
    }

    public function test_same_preview_execute_does_not_duplicate_finance(): void
    {
        $this->createAuthenticatedUser();
        $preview = $this->previewFor($this->makeCourse(72), 72, 72, 0);
        $first = $this->execute($preview);
        $second = $this->postJson('/api/imports/students/execute', [
            'preview_id' => $preview->json('data.preview_id'),
            'confirmed_rows' => [['row_number' => 2, 'identity_decision' => 'CREATE_NEW']],
        ]);
        $matriculaId = $first->json('data.rows.0.matricula_id');

        $second->assertOk();
        $this->assertSame($first->json('data.rows.0.finance_status'), $second->json('data.rows.0.finance_status'));
        $this->assertSame(1, LineaPagoModulo::where('matricula_id', $matriculaId)->count());
        $this->assertSame(1, TransaccionIngreso::where('linea_pago_modulo_id', LineaPagoModulo::where('matricula_id', $matriculaId)->value('id'))->count());
    }

    public function test_balance_mismatch_is_blocked_before_persistence(): void
    {
        $this->createAuthenticatedUser();
        $preview = $this->previewFor($this->makeCourse(72), 72, 30, 30);

        $preview->assertOk();
        $this->assertSame('FINANCIAL_BALANCE_MISMATCH', $preview->json('data.rows.0.finance.errors.0.code'));
        $this->assertSame(0, Matricula::query()->count());
    }

    public function test_missing_cash_summary_blocks_financial_preview(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse(72);
        \DB::table('finance.resumen_caja')->delete();
        $file = UploadedFile::fake()->createWithContent('finance.csv', "NOMBRES,APELLIDOS,TOTAL,ABONO,SALDO\nFin,Prueba,72,72,0\n");
        $first = $this->post('/api/imports/students/preview', ['archivo' => $file], ['Accept' => 'application/json']);
        $response = $this->postJson('/api/imports/students/preview', [
            'preview_id' => $first->json('data.preview_id'),
            'mapping' => $first->json('data.suggested_mapping'),
            'enrollment' => ['enabled' => true, 'curso_abierto_id' => $course->id, 'sin_registro_financiero' => true],
            'finance' => ['enabled' => true, 'confirm_real_financial_impact' => true, 'groups' => [], 'payment_options' => []],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('resumen de caja', strtolower(json_encode($response->json('errors'))));
    }

    public function test_secretary_cannot_execute_financial_preview(): void
    {
        $this->createAuthenticatedUser();
        $preview = $this->previewFor($this->makeCourse(72), 72, 72, 0);
        $persona = Persona::create(['tipo' => 'secretaria', 'nombres' => 'Secretaria', 'apellidos' => 'Execute', 'cedula' => '1234567893', 'es_activo' => true]);
        $account = \App\Models\CuentaSistema::create(['persona_id' => $persona->id, 'username' => Str::uuid()->toString(), 'password_hash' => 'secret']);
        $account->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Secretaria', 'web'));
        $this->actingAs($account, 'sanctum');

        $response = $this->postJson('/api/imports/students/execute', [
            'preview_id' => $preview->json('data.preview_id'),
            'confirmed_rows' => [['row_number' => 2, 'identity_decision' => 'CREATE_NEW']],
        ]);

        $response->assertStatus(403);
    }

    public function test_imports_multiple_modules_with_one_transaction_per_module(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse(72);
        $firstModule = $course->modulos()->first();
        $secondModule = Modulo::create([
            'curso_abierto_id' => $course->id,
            'nombre_modulo' => 'Módulo II',
            'numero_orden' => 2,
            'precio_base' => 57,
        ]);
        $file = UploadedFile::fake()->createWithContent(
            'finance.csv',
            "NOMBRES,APELLIDOS,CEDULA,TOTAL1,ABONO1,SALDO1,TOTAL2,ABONO2,SALDO2\nMulti,Modulo,1234567894,72,72,0,57,30,27\n"
        );
        $first = $this->post('/api/imports/students/preview', ['archivo' => $file], ['Accept' => 'application/json']);
        $preview = $this->postJson('/api/imports/students/preview', [
            'preview_id' => $first->json('data.preview_id'),
            'mapping' => $first->json('data.suggested_mapping'),
            'enrollment' => ['enabled' => true, 'curso_abierto_id' => $course->id, 'sin_registro_financiero' => true],
            'finance' => [
                'enabled' => true,
                'confirm_real_financial_impact' => true,
                'groups' => [
                    ['external_group_key' => 'module_1', 'external_group_name' => 'MÓDULO I', 'total_column' => 'TOTAL1', 'paid_column' => 'ABONO1', 'balance_column' => 'SALDO1', 'modulo_id' => $firstModule->id],
                    ['external_group_key' => 'module_2', 'external_group_name' => 'MÓDULO II', 'total_column' => 'TOTAL2', 'paid_column' => 'ABONO2', 'balance_column' => 'SALDO2', 'modulo_id' => $secondModule->id],
                ],
                'payment_options' => ['fecha_pago_default' => '2020-01-15', 'metodo_pago' => 'transferencia', 'confirm_price_differences' => true],
            ],
        ]);

        $preview->assertOk();
        $result = $this->execute($preview);
        $matriculaId = $result->json('data.rows.0.matricula_id');

        $this->assertSame('FINANCE_CREATED', $result->json('data.rows.0.finance_status'));
        $this->assertSame(2, LineaPagoModulo::where('matricula_id', $matriculaId)->count());
        $this->assertSame(2, TransaccionIngreso::where('cuenta_cobrar_id', CuentaPorCobrar::where('matricula_id', $matriculaId)->value('id'))->count());
        $this->assertSame(129.0, (float) CuentaPorCobrar::where('matricula_id', $matriculaId)->value('monto_total'));
        $this->assertSame(102.0, (float) CuentaPorCobrar::where('matricula_id', $matriculaId)->value('monto_abonado'));
        $this->assertSame('2020-01-15', TransaccionIngreso::where('cuenta_cobrar_id', CuentaPorCobrar::where('matricula_id', $matriculaId)->value('id'))->first()->fecha_pago->toDateString());
    }

    public function test_repeated_total_paid_balance_headers_are_mappable_by_block(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse(72);
        $firstModule = $course->modulos()->first();
        $secondModule = Modulo::create(['curso_abierto_id' => $course->id, 'nombre_modulo' => 'Módulo II', 'numero_orden' => 2, 'precio_base' => 57]);
        $file = UploadedFile::fake()->createWithContent(
            'historico.csv',
            "NOMBRES Y APELLIDOS,CEDULA,TELEFONO,CIUDAD,TOTAL,ABONO,SALDO,TOTAL,ABONO,SALDO\nASANZA CARRIÓN MARIA DANIELA,,097 946 1752,ZARUMA,72,72,0,57,57,0\n"
        );
        $first = $this->post('/api/imports/students/preview', ['archivo' => $file], ['Accept' => 'application/json']);
        $preview = $this->postJson('/api/imports/students/preview', [
            'preview_id' => $first->json('data.preview_id'),
            'mapping' => $first->json('data.suggested_mapping'),
            'enrollment' => ['enabled' => true, 'curso_abierto_id' => $course->id, 'sin_registro_financiero' => true],
            'finance' => [
                'enabled' => true,
                'confirm_real_financial_impact' => true,
                'groups' => [
                    ['external_group_key' => 'module_1', 'external_group_name' => 'MÓDULO I', 'total_column' => 'TOTAL', 'paid_column' => 'ABONO', 'balance_column' => 'SALDO', 'modulo_id' => $firstModule->id],
                    ['external_group_key' => 'module_2', 'external_group_name' => 'MÓDULO II', 'total_column' => 'TOTAL [2]', 'paid_column' => 'ABONO [2]', 'balance_column' => 'SALDO [2]', 'modulo_id' => $secondModule->id],
                ],
                'payment_options' => ['fecha_pago_default' => '2020-01-15', 'metodo_pago' => 'efectivo', 'confirm_price_differences' => true],
            ],
        ]);

        $preview->assertOk();
        $this->assertSame(129.0, (float) $preview->json('data.rows.0.finance.total'));
        $result = $this->postJson('/api/imports/students/execute', [
            'preview_id' => $preview->json('data.preview_id'),
            'confirmed_rows' => [['row_number' => 2, 'identity_decision' => 'CREATE_NEW', 'confirm_name_inference' => true]],
        ]);
        $matriculaId = $result->json('data.rows.0.matricula_id');
        $this->assertSame('FINANCE_CREATED', $result->json('data.rows.0.finance_status'));
        $this->assertSame(2, LineaPagoModulo::where('matricula_id', $matriculaId)->count());
    }

    public function test_invalid_or_duplicate_module_mapping_is_blocked(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse(72);
        $otherCourse = $this->makeCourse(72);
        $file = UploadedFile::fake()->createWithContent('finance.csv', "NOMBRES,APELLIDOS,TOTAL,ABONO,SALDO\nMap,Invalid,72,72,0\n");
        $first = $this->post('/api/imports/students/preview', ['archivo' => $file], ['Accept' => 'application/json']);
        $base = [
            'preview_id' => $first->json('data.preview_id'),
            'mapping' => $first->json('data.suggested_mapping'),
            'enrollment' => ['enabled' => true, 'curso_abierto_id' => $course->id, 'sin_registro_financiero' => true],
            'finance' => ['enabled' => true, 'confirm_real_financial_impact' => true, 'payment_options' => ['fecha_pago_default' => '2020-01-15', 'metodo_pago' => 'efectivo', 'confirm_price_differences' => true]],
        ];
        $base['finance']['groups'] = [['external_group_key' => 'other', 'total_column' => 'TOTAL', 'paid_column' => 'ABONO', 'balance_column' => 'SALDO', 'modulo_id' => $otherCourse->modulos()->first()->id]];
        $invalid = $this->postJson('/api/imports/students/preview', $base);
        $this->assertSame('MODULE_MAPPING_INVALID', $invalid->json('data.rows.0.finance.errors.0.code'));

        $base['finance']['groups'][] = $base['finance']['groups'][0];
        $duplicate = $this->postJson('/api/imports/students/preview', $base);
        $duplicate->assertStatus(422);
        $this->assertStringContainsString('dos grupos Excel', json_encode($duplicate->json('errors')));
    }

    public function test_blocked_row_preserves_financial_errors_and_warnings(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse(36);
        $file = UploadedFile::fake()->createWithContent(
            'finance.csv',
            "NOMBRES,APELLIDOS,CEDULA,CIUDAD,TOTAL,ABONO,SALDO\nBloqueado,Financiero,1234567895,CIUDAD DESCONOCIDA,36,36,21\n"
        );
        $first = $this->post('/api/imports/students/preview', ['archivo' => $file], ['Accept' => 'application/json']);
        $preview = $this->postJson('/api/imports/students/preview', [
            'preview_id' => $first->json('data.preview_id'),
            'mapping' => $first->json('data.suggested_mapping'),
            'enrollment' => ['enabled' => true, 'curso_abierto_id' => $course->id, 'sin_registro_financiero' => true],
            'finance' => [
                'enabled' => true,
                'confirm_real_financial_impact' => true,
                'groups' => [[
                    'external_group_key' => 'module_1',
                    'external_group_name' => 'MÓDULO I',
                    'total_column' => 'TOTAL',
                    'paid_column' => 'ABONO',
                    'balance_column' => 'SALDO',
                    'modulo_id' => $course->modulos()->first()->id,
                ]],
                'payment_options' => ['fecha_pago_default' => now()->subDay()->toDateString(), 'metodo_pago' => 'efectivo', 'confirm_price_differences' => true],
            ],
        ]);

        $preview->assertOk();
        $this->assertSame('BLOCKED', $preview->json('data.rows.0.status'));
        $result = $this->postJson('/api/imports/students/execute', [
            'preview_id' => $preview->json('data.preview_id'),
            'confirmed_rows' => [['row_number' => 2, 'skip' => true]],
        ]);

        $result->assertOk();
        $this->assertSame('BLOCKED', $result->json('data.rows.0.status'));
        $this->assertSame('BLOCKED_FINANCE', $result->json('data.rows.0.action'));
        $this->assertSame('FINANCIAL_BALANCE_MISMATCH', $result->json('data.rows.0.errors.0.code'));
        $this->assertSame('CITY_NOT_FOUND', $result->json('data.rows.0.warnings.0.code'));
    }

    public function test_duplicate_columns_are_rejected_before_financial_preview(): void
    {
        $this->createAuthenticatedUser();
        $course = $this->makeCourse(36);
        $file = UploadedFile::fake()->createWithContent('finance.csv', "NOMBRES,APELLIDOS,CEDULA,TOTAL,ABONO,SALDO\nDuplicado,Mapping,1234567896,36,36,0\n");
        $first = $this->post('/api/imports/students/preview', ['archivo' => $file], ['Accept' => 'application/json']);
        $response = $this->postJson('/api/imports/students/preview', [
            'preview_id' => $first->json('data.preview_id'),
            'mapping' => $first->json('data.suggested_mapping'),
            'enrollment' => ['enabled' => true, 'curso_abierto_id' => $course->id, 'sin_registro_financiero' => true],
            'finance' => [
                'enabled' => true,
                'confirm_real_financial_impact' => true,
                'groups' => [[
                    'external_group_key' => 'module_1',
                    'external_group_name' => 'MÓDULO I',
                    'total_column' => 'TOTAL',
                    'paid_column' => 'TOTAL',
                    'balance_column' => 'SALDO',
                    'modulo_id' => $course->modulos()->first()->id,
                ]],
                'payment_options' => ['fecha_pago_default' => now()->subDay()->toDateString(), 'metodo_pago' => 'efectivo', 'confirm_price_differences' => true],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('columnas diferentes', json_encode($response->json('errors')));
    }

    private function previewFor(CursoAbierto $course, float $total, float $paid, float $balance, ?string $cedula = null)
    {
        $cedula ??= (string) random_int(1000000000, 1999999999);
        $file = UploadedFile::fake()->createWithContent('finance.csv', "NOMBRES,APELLIDOS,CEDULA,TOTAL,ABONO,SALDO\nFin,Importado,{$cedula},{$total},{$paid},{$balance}\n");
        $first = $this->post('/api/imports/students/preview', ['archivo' => $file], ['Accept' => 'application/json']);
        return $this->postJson('/api/imports/students/preview', [
            'preview_id' => $first->json('data.preview_id'),
            'mapping' => $first->json('data.suggested_mapping'),
            'enrollment' => ['enabled' => true, 'curso_abierto_id' => $course->id, 'sin_registro_financiero' => true],
            'finance' => [
                'enabled' => true,
                'confirm_real_financial_impact' => true,
                'groups' => [[
                    'external_group_key' => 'module_1',
                    'external_group_name' => 'MÓDULO I',
                    'total_column' => 'TOTAL',
                    'paid_column' => 'ABONO',
                    'balance_column' => 'SALDO',
                    'modulo_id' => $course->modulos()->first()->id,
                ]],
                'payment_options' => ['fecha_pago_default' => now()->subDay()->toDateString(), 'metodo_pago' => 'efectivo', 'confirm_price_differences' => true],
            ],
        ]);
    }

    private function execute($preview)
    {
        $row = $preview->json('data.rows.0');
        return $this->postJson('/api/imports/students/execute', [
            'preview_id' => $preview->json('data.preview_id'),
            'confirmed_rows' => [['row_number' => $row['row_number'], 'identity_decision' => 'CREATE_NEW']],
        ]);
    }

    private function makeCourse(float $price, bool $custom = false): CursoAbierto
    {
        $catalogo = CatalogoCurso::create(['categoria' => 'regular', 'nombre' => 'Finance test', 'descripcion' => 'Finance fixture', 'modulos_default' => 1, 'creditos' => 1, 'horas_totales' => 1, 'es_activo' => true]);
        $course = CursoAbierto::create(['catalogo_curso_id' => $catalogo->id, 'modalidad' => 'virtual', 'capacidad_maxima' => 10, 'precio_base' => $price, 'es_personalizado' => $custom, 'es_activo' => true, 'fecha_inicio' => now()->addDay(), 'fecha_fin' => now()->addDays(2), 'nombre_instancia' => 'Finance test']);
        Modulo::create(['curso_abierto_id' => $course->id, 'nombre_modulo' => 'Módulo I', 'numero_orden' => 1, 'precio_base' => $price]);
        return $course->fresh();
    }
}
