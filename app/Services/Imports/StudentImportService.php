<?php

namespace App\Services\Imports;

use App\DTOs\Imports\StudentImportPreviewDTO;
use App\DTOs\Imports\StudentImportResultDTO;
use App\DTOs\Imports\FinancialImportDataDTO;
use App\Models\Ciudad;
use App\Models\Persona;
use App\Models\CursoAbierto;
use App\Models\Matricula;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StudentImportService
{
    private const TTL_MINUTES = 45;
    private const CACHE_PREFIX = 'student-import-preview:';

    public function __construct(
        private readonly StudentImportFileReader $reader,
        private readonly ImportColumnMapper $mapper,
        private readonly StudentImportRowNormalizer $normalizer,
        private readonly StudentImportValidator $validator,
        private readonly StudentImportAdapter $studentAdapter,
        private readonly EnrollmentImportAdapter $enrollmentAdapter,
        private readonly FinancialImportValidator $financialValidator,
        private readonly FinanceImportAdapter $financeAdapter,
    ) {}

    public function preview(?UploadedFile $file, ?string $previewId, array $mapping = [], array $enrollment = [], array $finance = []): array
    {
        $userId = (string) auth()->id();
        $payload = $previewId ? $this->getPreview($previewId, $userId) : null;

        if (!$payload) {
            if (!$file) {
                throw ValidationException::withMessages(['archivo' => 'Debe enviar un archivo.']);
            }
            $read = $this->reader->read($file);
            $previewId = (string) Str::uuid();
            $payload = [
                'preview_id' => $previewId,
                'user_id' => $userId,
                'headers' => $read['headers'],
                'raw_rows' => $read['rows'],
                'sheet' => $read['sheet'],
                'suggested_mapping' => $this->mapper->suggest($read['headers']),
                'stage' => 'MAPPING',
                'status' => 'READY',
                'enrollment' => $this->normalizeEnrollmentOptions($enrollment),
                'finance' => ['enabled' => false],
                'expires_at' => now()->addMinutes(self::TTL_MINUTES)->toIso8601String(),
            ];
            $this->putPreview($payload);
        }

        if (!$mapping) {
            return [
                'preview_id' => $payload['preview_id'],
                'headers' => $payload['headers'],
                'suggested_mapping' => $payload['suggested_mapping'],
                'sample_rows' => array_slice($payload['raw_rows'], 0, 10),
                'stage' => 'MAPPING',
                'sheet' => $payload['sheet'],
                'expires_at' => $payload['expires_at'],
                'enrollment' => $payload['enrollment'] ?? $this->normalizeEnrollmentOptions([]),
                'finance' => $payload['finance'] ?? ['enabled' => false],
            ];
        }

        $mappingErrors = $this->mapper->validate($payload['headers'], $mapping);
        if ($mappingErrors) {
            throw ValidationException::withMessages(['mapping' => $mappingErrors]);
        }

        $cityIndex = $this->cityIndex();
        $rows = [];
        foreach ($payload['raw_rows'] as $index => $rawRow) {
            $normalized = $this->normalizer->normalize($rawRow, $mapping, $index + 2);
            $student = $normalized['student'];
            $errors = $this->validator->validate($student);
            $identity = $this->resolveIdentity($student);
            $city = $this->resolveCity($student['ciudad'], $cityIndex);
            $student['ciudad_id'] = $city['ciudad_id'];

            if ($city['status'] === 'TEXT_ONLY_CITY') {
                $normalized['warnings'][] = [
                    'code' => 'CITY_NOT_FOUND',
                    'message' => 'La ciudad no existe en el catálogo y se conservará como texto.',
                ];
            }
            $normalized['student'] = $student;
            $status = $this->rowStatus($errors, $normalized['warnings'], $identity);

            $rows[] = array_merge($normalized, [
                'errors' => $errors,
                'identity_resolution' => $identity,
                'city_resolution' => $city,
                'status' => $status,
            ]);
        }

        $payload['mapping'] = $mapping;
        $payload['rows'] = $rows;
        $payload['enrollment'] = $this->normalizeEnrollmentOptions($enrollment ?: ($payload['enrollment'] ?? []));
        if ($payload['enrollment']['enabled']) {
            $payload['rows'] = $this->decorateEnrollmentPreview($payload['rows'], $payload['enrollment']);
        }
        $payload['finance'] = $this->normalizeFinanceOptions($finance ?: ($payload['finance'] ?? []), $payload['enrollment'], $payload['headers']);
        if ($payload['finance']['enabled']) {
            $payload['rows'] = $this->decorateFinancePreview($payload['rows'], $payload['finance']);
        }
        $payload['stage'] = 'PREVIEW';
        $this->putPreview($payload);

        return [
            'preview_id' => $payload['preview_id'],
            'headers' => $payload['headers'],
            'mapping' => $mapping,
            'rows' => $payload['rows'],
            'summary' => $this->summary($payload['rows']),
            'enrollment' => $payload['enrollment'],
            'finance' => $payload['finance'],
            'stage' => 'PREVIEW',
            'expires_at' => $payload['expires_at'],
        ];
    }

    public function execute(string $previewId, array $confirmedRows, array $enrollmentOverride = []): array
    {
        $userId = (string) auth()->id();
        $lock = Cache::lock(self::CACHE_PREFIX . $previewId . ':execute', 120);
        if (!$lock->get()) {
            throw ValidationException::withMessages(['preview_id' => 'El preview ya está siendo procesado.']);
        }

        try {
            // Authorize the sensitive financial operation before applying the
            // preview ownership check. A non-administrator must never reach
            // financial execution, even when the preview belongs to another
            // user.
            $storedPayload = Cache::get(self::CACHE_PREFIX . $previewId);
            if ($storedPayload && !empty($storedPayload['finance']['enabled'])) {
                $this->assertFinanceAccess($storedPayload['finance']);
            }
            $payload = $this->getPreview($previewId, $userId);
            if (($payload['stage'] ?? null) !== 'PREVIEW') {
                throw ValidationException::withMessages(['preview_id' => 'El preview todavía no está listo para ejecutar.']);
            }
            $this->validateEnrollmentOverride($payload['enrollment'] ?? [], $enrollmentOverride);
            $this->assertFinanceAccess($payload['finance'] ?? []);
            if (($payload['status'] ?? 'READY') === 'COMPLETED') {
                return $payload['result'];
            }

            $confirmed = collect($confirmedRows)->keyBy('row_number');
            $results = [];
            foreach ($payload['rows'] as $row) {
                $decision = $confirmed->get($row['row_number']);
                if (($row['status'] ?? null) === 'BLOCKED') {
                    $financeBlocked = !empty($row['finance']['errors']);
                    $results[] = (new StudentImportResultDTO(
                        rowNumber: $row['row_number'],
                        status: 'BLOCKED',
                        action: $financeBlocked ? 'BLOCKED_FINANCE' : 'BLOCKED',
                        warnings: array_merge($row['warnings'] ?? [], $row['finance']['warnings'] ?? []),
                        errors: $row['errors'] ?? [],
                        enrollmentStatus: !empty($row['enrollment']['errors']) ? 'BLOCKED' : null,
                        financeStatus: $financeBlocked ? 'FINANCE_BLOCKED' : null,
                    ))->toArray();
                    continue;
                }
                if (!$decision || !empty($decision['skip'])) {
                    $results[] = (new StudentImportResultDTO(
                        rowNumber: $row['row_number'],
                        status: 'SKIPPED',
                        action: $decision ? 'SKIPPED_BY_USER' : 'ROW_NOT_CONFIRMED',
                        warnings: $row['warnings'] ?? [],
                    ))->toArray();
                    continue;
                }

                $row = $this->applyDecision($row, $decision);
                $errors = $this->validator->validate($row['student']);
                if ($row['student']['name_inference'] ?? false) {
                    if (empty($decision['confirm_name_inference'])) {
                        $errors[] = ['code' => 'NAME_INFERENCE_NOT_CONFIRMED', 'message' => 'Debe confirmar o corregir la separación del nombre completo.'];
                    }
                }
                if ($errors) {
                    $results[] = (new StudentImportResultDTO($row['row_number'], 'FAILED', warnings: $row['warnings'], errors: $errors))->toArray();
                    continue;
                }
                if (!empty($payload['finance']['enabled']) && !empty($row['finance']['errors'])) {
                    $results[] = (new StudentImportResultDTO(
                        rowNumber: $row['row_number'],
                        status: 'FAILED',
                        warnings: array_merge($row['warnings'], $row['finance']['warnings'] ?? []),
                        errors: $row['finance']['errors'],
                        financeStatus: 'FINANCE_BLOCKED',
                    ))->toArray();
                    continue;
                }

                $identity = $this->resolveIdentity($row['student']);
                $identityDecision = $decision['identity_decision'] ?? null;
                if (in_array($identity['status'], ['POSSIBLE_DUPLICATE', 'EXISTING_PERSON_WITHOUT_STUDENT_PROFILE'], true)
                    && !$identityDecision) {
                    $results[] = (new StudentImportResultDTO(
                        rowNumber: $row['row_number'],
                        status: 'FAILED',
                        action: 'IDENTITY_DECISION_REQUIRED',
                        warnings: $row['warnings'],
                        errors: [[
                            'code' => 'IDENTITY_DECISION_REQUIRED',
                            'message' => 'Debe decidir si se crea una Persona nueva o se reutiliza una existente.',
                        ]],
                    ))->toArray();
                    continue;
                }
                if ($identityDecision === 'SKIP') {
                    $results[] = (new StudentImportResultDTO(
                        rowNumber: $row['row_number'],
                        status: 'SKIPPED',
                        action: 'SKIPPED_BY_USER',
                        warnings: $row['warnings'],
                    ))->toArray();
                    continue;
                }

                try {
                    $selectedId = $identityDecision === 'USE_EXISTING'
                        ? ($decision['selected_existing_persona_id'] ?? $identity['persona_id'])
                        : null;
                    $enrollmentOptions = $payload['enrollment'] ?? ['enabled' => false, 'sin_registro_financiero' => true];
                    $financeOptions = $payload['finance'] ?? ['enabled' => false];
                    if (!empty($enrollmentOptions['enabled']) || !empty($financeOptions['enabled'])) {
                        $result = DB::transaction(function () use ($row, $selectedId, $enrollmentOptions, $financeOptions, $previewId) {
                            $studentResult = $this->studentAdapter->executeInTransaction($row, $selectedId);
                            $academicResult = [];
                            if (!empty($enrollmentOptions['enabled'])) {
                                $existingMatriculaId = $financeOptions['enabled']
                                    ? ($row['enrollment']['existing_matricula_id'] ?? null)
                                    : null;
                                if ($existingMatriculaId) {
                                    $academicResult = [
                                        'matricula_id' => (string) $existingMatriculaId,
                                        'enrollment_action' => 'REUSED',
                                        'enrollment_status' => 'EXISTING',
                                    ];
                                } else {
                                    $academicResult = $this->enrollmentAdapter->execute(
                                        $studentResult['persona_id'],
                                        $enrollmentOptions['curso_abierto_id']
                                    );
                                }
                            }
                            $financeResult = [];
                            if (!empty($financeOptions['enabled'])) {
                                $matriculaId = $academicResult['matricula_id'] ?? ($row['enrollment']['existing_matricula_id'] ?? null);
                                if (!$matriculaId) throw new \RuntimeException('FINANCE_REQUIRES_ENROLLMENT');
                                $financeResult = $this->financeAdapter->execute(
                                    Matricula::findOrFail($matriculaId),
                                    FinancialImportDataDTO::fromArray($row['finance']['financial_modules'], $financeOptions['payment_options']),
                                    $previewId,
                                    (int) $row['row_number'],
                                    auth()->user()->persona_id ?? null,
                                );
                            }
                            return array_merge($studentResult, $academicResult, $financeResult);
                        });
                    } else {
                        $result = $this->studentAdapter->execute($row, $selectedId);
                    }
                    $results[] = (new StudentImportResultDTO(
                        $row['row_number'],
                        'IMPORTED',
                        $result['persona_id'],
                        $result['perfil_estudiante_id'],
                        $result['action'],
                        $row['warnings'],
                        [],
                        $result['matricula_id'] ?? null,
                        $result['enrollment_action'] ?? null,
                        $result['enrollment_status'] ?? null,
                        $result['finance_status'] ?? null,
                        $result['financial_lines_created'] ?? 0,
                        $result['accounts_created'] ?? 0,
                        $result['transactions_created'] ?? 0,
                        $result['financial_warnings'] ?? [],
                        $result['financial_transaction_ids'] ?? [],
                    ))->toArray();
                } catch (\Throwable $e) {
                    $results[] = (new StudentImportResultDTO(
                        rowNumber: $row['row_number'],
                        status: 'FAILED',
                        warnings: $row['warnings'],
                        errors: [[
                        'code' => 'IMPORT_FAILED',
                        'message' => $e->getMessage(),
                        ]],
                        enrollmentStatus: !empty($payload['enrollment']['enabled']) ? 'FAILED' : null,
                        financeStatus: !empty($payload['finance']['enabled']) ? 'FINANCE_FAILED' : null,
                    ))->toArray();
                }
            }

            $result = [
                'preview_id' => $previewId,
                'summary' => $this->executionSummary($results),
                'rows' => $results,
            ];
            $payload['status'] = 'COMPLETED';
            $payload['result'] = $result;
            $this->putPreview($payload);
            return $result;
        } finally {
            $lock->release();
        }
    }

    private function applyDecision(array $row, array $decision): array
    {
        if (!empty($decision['corrections'])) {
            $row = $this->normalizer->applyCorrections($row, $decision['corrections']);
            $row['student']['name_inference'] = false;
        }
        return $row;
    }

    private function validateEnrollmentOverride(array $stored, array $override): void
    {
        if (!$override) {
            return;
        }

        if (!empty($override['finance']['enabled'])) {
            throw ValidationException::withMessages(['enrollment' => 'La importación financiera todavía no está disponible.']);
        }

        if (!self::academicOverrideMatches($stored, $override)) {
            throw ValidationException::withMessages(['enrollment.curso_abierto_id' => 'La configuración académica no coincide con el preview confirmado.']);
        }

        if (($override['sin_registro_financiero'] ?? true) !== true) {
            throw ValidationException::withMessages(['enrollment.sin_registro_financiero' => 'La Fase 3 solo permite matrícula sin registro financiero.']);
        }
    }

    private function assertFinanceAccess(array $finance): void
    {
        if (!empty($finance['enabled']) && (!auth()->check() || !auth()->user()->hasRole('Administrador'))) {
            abort(403, 'Solo un Administrador puede ejecutar importaciones financieras.');
        }
    }

    private function normalizeFinanceOptions(array $options, array $enrollment, array $headers): array
    {
        $enabled = filter_var($options['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) return ['enabled' => false];

        $this->assertFinanceAccess(['enabled' => true]);
        $courseId = $enrollment['curso_abierto_id'] ?? null;
        $course = $courseId ? CursoAbierto::with(['catalogo', 'modulos'])->find($courseId) : null;
        $courseErrors = $this->financialValidator->validateCourse($course);
        $errors = array_merge(
            $this->financialValidator->validateOptions($options, (bool) ($enrollment['enabled'] ?? false), $courseId, $headers),
            $this->validateFinancialColumnMapping($options['groups'] ?? []),
            array_values(array_filter($courseErrors, fn (array $error): bool => $error['code'] !== 'CUSTOM_COURSE_NOT_SUPPORTED')),
        );
        if (!DB::table('finance.resumen_caja')->where('id', 1)->exists()) {
            $errors[] = ['code' => 'FINANCIAL_CASH_SUMMARY_NOT_INITIALIZED', 'message' => 'El resumen de caja no está inicializado.'];
        }
        if ($errors) {
            throw ValidationException::withMessages(['finance' => array_map(fn (array $error): string => $error['message'], $errors)]);
        }

        $payment = $options['payment_options'] ?? [];
        return [
            'enabled' => true,
            'course_id' => (string) $courseId,
            'course_name' => $course?->catalogo?->nombre ?? $course?->nombre_instancia,
            'custom_course' => (bool) ($course?->es_personalizado),
            'groups' => array_values($options['groups'] ?? []),
            'payment_options' => [
                'fecha_pago_default' => $payment['fecha_pago_default'],
                'metodo_pago' => $payment['metodo_pago'],
                'comprobante_url' => $payment['comprobante_url'] ?? null,
                'observaciones' => $payment['observaciones'] ?? null,
                'confirm_real_financial_impact' => true,
                'confirm_price_differences' => filter_var($payment['confirm_price_differences'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ],
        ];
    }

    private function validateFinancialColumnMapping(array $groups): array
    {
        $errors = [];

        foreach ($groups as $index => $group) {
            $columns = array_filter([
                $group['total_column'] ?? null,
                $group['paid_column'] ?? null,
                $group['balance_column'] ?? null,
            ], static fn (mixed $column): bool => $column !== null && $column !== '');

            if (count($columns) !== count(array_unique($columns))) {
                $errors[] = [
                    'code' => 'FINANCIAL_COLUMN_MAPPING_INVALID',
                    'message' => "TOTAL, ABONO y SALDO deben estar mapeados a columnas diferentes para el grupo financiero {$index}.",
                ];
            }
        }

        return $errors;
    }

    private function decorateFinancePreview(array $rows, array $options): array
    {
        foreach ($rows as &$row) {
            $this->allowExistingEnrollmentForFinance($row, $options);
            $modules = $this->normalizeFinancialRow($row['original_data'], $options['groups']);
            $row['finance'] = [
                'enabled' => true,
                'financial_modules' => $modules,
                'errors' => [],
                'warnings' => [],
                'status' => 'READY',
            ];
            $course = CursoAbierto::with('modulos')->find($options['course_id']);
            $validation = $course ? $this->financialValidator->validateModules($modules, $course, $options) : ['errors' => [], 'warnings' => []];
            $row['finance']['errors'] = $validation['errors'];
            $row['finance']['warnings'] = $validation['warnings'];
            if (($options['custom_course'] ?? false) === true) {
                $row['finance']['errors'][] = [
                    'code' => 'CUSTOM_COURSE_NOT_SUPPORTED',
                    'message' => 'Los cursos personalizados no están soportados en la importación financiera.',
                ];
            }
            $row['finance']['total'] = round(array_sum(array_column($modules, 'total')), 2);
            $row['finance']['paid'] = round(array_sum(array_column($modules, 'paid')), 2);
            $row['finance']['balance'] = round(array_sum(array_column($modules, 'balance')), 2);
            $row['finance']['transactions_expected'] = count(array_filter($modules, fn (array $module): bool => $module['paid'] > 0));
            if (!empty($row['enrollment']['existing_matricula_id'])) {
                $financePreview = $this->financeAdapter->preview(
                    Matricula::find($row['enrollment']['existing_matricula_id']),
                    FinancialImportDataDTO::fromArray($modules, $options['payment_options']),
                );
                $row['finance'] = array_merge($row['finance'], $financePreview);
            }
            if ($row['finance']['errors']) {
                $row['finance']['status'] = 'BLOCKED';
                $row['errors'] = array_merge($row['errors'], $row['finance']['errors']);
                $row['status'] = 'BLOCKED';
            } elseif (!$row['errors']) {
                $row['finance']['status'] = $row['finance']['total'] > 0 ? 'READY' : 'NO_FINANCE_REQUIRED';
                $row['status'] = $row['warnings'] ? 'WARNING' : 'READY';
            }
        }
        unset($row);
        return $rows;
    }

    private function normalizeFinancialRow(array $raw, array $groups): array
    {
        return array_map(function (array $group) use ($raw): array {
            return [
                'external_group_key' => $group['external_group_key'],
                'external_group_name' => $group['external_group_name'] ?? $group['external_group_key'],
                'modulo_id' => $group['modulo_id'],
                'total' => $this->financialValidator->parseAmount($raw[$group['total_column']] ?? null),
                'paid' => $this->financialValidator->parseAmount($raw[$group['paid_column']] ?? null),
                'balance' => $this->financialValidator->parseAmount($raw[$group['balance_column']] ?? null),
            ];
        }, $groups);
    }

    private function allowExistingEnrollmentForFinance(array &$row, array $options): void
    {
        if (empty($row['enrollment']['existing_matricula_id'])) return;
        if (($row['enrollment']['status'] ?? null) === 'CUSTOM_COURSE_NOT_SUPPORTED') return;
        $row['enrollment']['status'] = 'EXISTING_ENROLLMENT';
        $row['enrollment']['proposed_action'] = 'REUSE_ENROLLMENT';
        $row['enrollment']['errors'] = [];
        $row['errors'] = array_values(array_filter($row['errors'], fn (array $error): bool => !in_array($error['code'] ?? null, [
            'ALREADY_ENROLLED', 'ALREADY_COMPLETED', 'DUPLICATE_ENROLLMENT', 'SOFT_DELETED_ENROLLMENT',
        ], true)));
        if (!$row['errors']) {
            $row['status'] = $row['warnings'] ? 'WARNING' : 'READY';
        }
    }

    public static function academicOverrideMatches(array $stored, array $override): bool
    {
        if (!$override) {
            return true;
        }

        $storedEnabled = filter_var($stored['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $overrideEnabled = filter_var($override['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $storedCourse = $stored['curso_abierto_id'] ?? null;
        $overrideCourse = $override['curso_abierto_id'] ?? null;

        return $storedEnabled === $overrideEnabled
            && (!$storedEnabled || (string) $storedCourse === (string) $overrideCourse);
    }

    private function normalizeEnrollmentOptions(array $options): array
    {
        $enabled = filter_var($options['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $courseId = $options['curso_abierto_id'] ?? null;

        if (!$enabled) {
            return ['enabled' => false, 'curso_abierto_id' => null, 'sin_registro_financiero' => true];
        }
        if (!$courseId) {
            throw ValidationException::withMessages(['enrollment.curso_abierto_id' => 'Debe seleccionar un curso para matricular.']);
        }
        if (($options['sin_registro_financiero'] ?? true) !== true) {
            throw ValidationException::withMessages(['enrollment.sin_registro_financiero' => 'La Fase 3 solo permite matrícula sin registro financiero.']);
        }

        $course = CursoAbierto::with(['catalogo', 'ciudad'])->find($courseId);
        if (!$course) {
            throw ValidationException::withMessages(['enrollment.curso_abierto_id' => 'El curso seleccionado no existe o fue eliminado.']);
        }

        return [
            'enabled' => true,
            'curso_abierto_id' => (string) $course->id,
            'curso_nombre' => $course->catalogo?->nombre ?? $course->nombre_instancia,
            'nombre_instancia' => $course->nombre_instancia,
            'fecha_inicio' => $course->fecha_inicio?->toDateString(),
            'fecha_fin' => $course->fecha_fin?->toDateString(),
            'modalidad' => $course->modalidad,
            'ciudad' => $course->ciudad?->nombre,
            'estado' => $course->estado,
            'historico' => $course->fecha_inicio?->lt(now()->subDays(7)) ?? false,
            'es_personalizado' => (bool) $course->es_personalizado,
            'capacidad_maxima' => $course->capacidad_maxima,
            'estudiantes_inscritos' => $course->obtenerCountMatriculas(),
            'espacios_disponibles' => $course->capacidad_maxima > 0 ? $course->obtenerEspaciosDisponibles() : null,
            'sin_registro_financiero' => true,
        ];
    }

    private function decorateEnrollmentPreview(array $rows, array $options): array
    {
        foreach ($rows as &$row) {
            $personaId = $row['identity_resolution']['persona_id'] ?? null;
            if ($personaId) {
                $enrollment = $this->enrollmentAdapter->preview($personaId, $options['curso_abierto_id']);
            } else {
                $enrollment = [
                    'status' => 'PENDING_STUDENT',
                    'curso_abierto_id' => $options['curso_abierto_id'],
                    'curso_nombre' => $options['curso_nombre'] ?? null,
                    'fecha_inicio' => $options['fecha_inicio'] ?? null,
                    'fecha_fin' => $options['fecha_fin'] ?? null,
                    'historico' => $options['historico'] ?? false,
                    'es_personalizado' => $options['es_personalizado'] ?? false,
                    'proposed_action' => 'CREATE_ENROLLMENT',
                    'errors' => [],
                    'warnings' => [],
                ];
                if (!empty($options['es_personalizado'])) {
                    $enrollment['status'] = 'CUSTOM_COURSE_NOT_SUPPORTED';
                    $enrollment['proposed_action'] = 'BLOCK';
                    $enrollment['errors'] = [$this->enrollmentAdapter->unsupportedCourseError()];
                }
            }
            $row['enrollment'] = array_merge($options, $enrollment);
            if (($options['espacios_disponibles'] ?? null) !== null && (int) $options['espacios_disponibles'] <= 0 && empty($enrollment['errors'])) {
                $row['enrollment']['status'] = 'COURSE_FULL';
                $row['enrollment']['errors'] = [['code' => 'COURSE_FULL', 'message' => 'El curso no dispone de cupos disponibles.']];
                $enrollment['errors'] = $row['enrollment']['errors'];
            }
            if (!empty($enrollment['errors'])) {
                $row['errors'] = array_merge($row['errors'], $enrollment['errors']);
                $row['status'] = 'BLOCKED';
            }
        }
        unset($row);
        return $rows;
    }

    private function resolveIdentity(array $student): array
    {
        if (!empty($student['cedula'])) {
            $persona = Persona::withTrashed()->where('cedula', $student['cedula'])->first();
            if (!$persona) return ['status' => 'NEW', 'persona_id' => null, 'candidates' => []];
            if ($persona->trashed()) return ['status' => 'DELETED_PERSON', 'persona_id' => (string) $persona->id, 'candidates' => []];
            if ($persona->tipo !== 'estudiante') return ['status' => 'CONFLICT_PERSON_TYPE', 'persona_id' => (string) $persona->id, 'candidates' => []];
            $hasProfile = $persona->perfilEstudiante()->exists();
            return ['status' => $hasProfile ? 'EXISTING_STUDENT' : 'EXISTING_PERSON_WITHOUT_STUDENT_PROFILE', 'persona_id' => (string) $persona->id, 'candidates' => []];
        }

        $candidates = collect();
        if (!empty($student['correo'])) {
            $candidates = $candidates->merge(Persona::query()->estudiantes()->activos()->where('correo', $student['correo'])->limit(10)->get());
        }
        if (!empty($student['nombres']) && !empty($student['apellidos'])) {
            $candidates = $candidates->merge(Persona::query()->activos()->whereRaw('LOWER(nombres) = ?', [mb_strtolower($student['nombres'])])->whereRaw('LOWER(apellidos) = ?', [mb_strtolower($student['apellidos'])])->limit(10)->get());
        }
        if (!empty($student['celular'])) {
            $candidates = $candidates->merge(Persona::query()->estudiantes()->activos()->where('celular', $student['celular'])->limit(10)->get());
        }
        $candidates = $candidates->unique('id')->values();

        return [
            'status' => $candidates->isEmpty() ? 'NO_MATCH' : 'POSSIBLE_DUPLICATE',
            'persona_id' => null,
            'candidates' => $candidates->map(fn ($p) => [
                'id' => (string) $p->id,
                'nombres' => $p->nombres,
                'apellidos' => $p->apellidos,
                'cedula' => $p->cedula,
                'correo' => $p->correo,
                'celular' => $p->celular,
                'has_profile' => $p->perfilEstudiante()->exists(),
            ])->all(),
        ];
    }

    private function resolveCity(?string $value, array $index): array
    {
        if (!$value) return ['status' => 'NO_CITY', 'ciudad_id' => null, 'nombre' => null];
        $key = $this->cityKey($value);
        if (isset($index[$key])) return ['status' => 'MATCHED_CITY', 'ciudad_id' => $index[$key]['id'], 'nombre' => $index[$key]['nombre']];
        return ['status' => 'TEXT_ONLY_CITY', 'ciudad_id' => null, 'nombre' => $value];
    }

    private function cityIndex(): array
    {
        return Ciudad::query()->whereNull('deleted_at')->get(['id', 'nombre'])->mapWithKeys(fn ($city) => [$this->cityKey($city->nombre) => ['id' => $city->id, 'nombre' => $city->nombre]])->all();
    }

    private function cityKey(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        return strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    private function rowStatus(array $errors, array $warnings, array $identity): string
    {
        if ($errors || in_array($identity['status'], ['CONFLICT_PERSON_TYPE', 'DELETED_PERSON'], true)) return 'BLOCKED';
        if (collect($warnings)->contains(fn ($warning) => in_array($warning['code'] ?? null, ['NAME_INFERRED'], true))
            || in_array($identity['status'], ['POSSIBLE_DUPLICATE', 'EXISTING_PERSON_WITHOUT_STUDENT_PROFILE'], true)) return 'WARNING';
        return 'READY';
    }

    private function summary(array $rows): array
    {
        return [
            'total_rows' => count($rows),
            'ready' => count(array_filter($rows, fn ($row) => $row['status'] === 'READY')),
            'warnings' => count(array_filter($rows, fn ($row) => $row['status'] === 'WARNING')),
            'blocked' => count(array_filter($rows, fn ($row) => $row['status'] === 'BLOCKED')),
        ];
    }

    private function executionSummary(array $results): array
    {
        return [
            'processed' => count($results),
            'imported' => count(array_filter($results, fn ($row) => $row['status'] === 'IMPORTED')),
            'created_students' => count(array_filter($results, fn ($row) => ($row['action'] ?? '') === 'CREATED_PROFILE_CREATED')),
            'reused_students' => count(array_filter($results, fn ($row) => str_starts_with((string) ($row['action'] ?? ''), 'REUSED'))),
            'profiles_created' => count(array_filter($results, fn ($row) => str_contains((string) ($row['action'] ?? ''), 'PROFILE_CREATED'))),
            'enrollments_created' => count(array_filter($results, fn ($row) => ($row['enrollment_action'] ?? null) === 'CREATED')),
            'enrollments_skipped' => count(array_filter($results, fn ($row) => ($row['enrollment_action'] ?? null) === 'SKIPPED')),
            'enrollment_failed' => count(array_filter($results, fn ($row) => ($row['enrollment_status'] ?? null) === 'FAILED')),
            'finance_created' => count(array_filter($results, fn ($row) => ($row['finance_status'] ?? null) === 'FINANCE_CREATED')),
            'finance_already_imported' => count(array_filter($results, fn ($row) => ($row['finance_status'] ?? null) === 'FINANCE_ALREADY_IMPORTED')),
            'finance_failed' => count(array_filter($results, fn ($row) => in_array($row['finance_status'] ?? null, ['FINANCE_FAILED', 'FINANCE_BLOCKED'], true))),
            'financial_lines_created' => array_sum(array_map(fn ($row) => (int) ($row['financial_lines_created'] ?? 0), $results)),
            'accounts_created' => array_sum(array_map(fn ($row) => (int) ($row['accounts_created'] ?? 0), $results)),
            'transactions_created' => array_sum(array_map(fn ($row) => (int) ($row['transactions_created'] ?? 0), $results)),
            'blocked' => count(array_filter($results, fn ($row) => $row['status'] === 'BLOCKED')),
            'skipped' => count(array_filter($results, fn ($row) => $row['status'] === 'SKIPPED')),
            'failed' => count(array_filter($results, fn ($row) => $row['status'] === 'FAILED')),
        ];
    }

    private function getPreview(string $previewId, string $userId): ?array
    {
        $payload = Cache::get(self::CACHE_PREFIX . $previewId);
        if (!$payload) throw ValidationException::withMessages(['preview_id' => 'El preview no existe o expiró.']);
        if ((string) ($payload['user_id'] ?? '') !== $userId) throw ValidationException::withMessages(['preview_id' => 'El preview no pertenece al usuario autenticado.']);
        if (($payload['expires_at'] ?? null) && now()->greaterThan($payload['expires_at'])) throw ValidationException::withMessages(['preview_id' => 'El preview expiró.']);
        return $payload;
    }

    private function putPreview(array $payload): void
    {
        Cache::put(self::CACHE_PREFIX . $payload['preview_id'], $payload, now()->addMinutes(self::TTL_MINUTES));
    }
}
