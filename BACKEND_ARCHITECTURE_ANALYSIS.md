# Laravel Backend Architecture - Comprehensive Analysis

**Project**: Academic Management System (FASE 12 - Registration Module)
**Status**: 80% complete across 12 phases
**Database**: PostgreSQL primary, SQLite in-memory for tests
**Framework**: Laravel 13.8 with Sanctum authentication

---

## 1. DATABASE MODELS AND THEIR RELATIONSHIPS

### 1.1 Core Course Models

#### **CatalogoCurso** (Catalog Course Template)
- **Table**: `academic.catalogo_cursos`
- **Purpose**: Abstract template defining course structure
- **Key Fields**:
  - `id` (UUID, PK)
  - `programa_id` (nullable, FK to academic programs)
  - `codigo` (string, 50 chars, **UNIQUE**) - **Course code - the "code" field**
  - `nombre` (string, 255 chars) - Course name
  - `descripcion` (text, nullable)
  - `creditos` (integer, default: 3)
  - `horas_totales` (integer, default: 40)
  - `modulos_default` (integer, 1-10 modules)
  - `categoria` (enum: 'regular', 'personalizado', 'taller', default: 'regular')
  - `imagen` (string, nullable) - Course image URL
  - `es_activo` (boolean, default: true)
  - `created_at`, `updated_at`, `deleted_at` (with soft deletes)

- **Relationships**:
  - `programa()` BelongsTo - Academic program reference
  - `cursosAbiertos()` HasMany - Open course instances (e.g., "Calc I 2026-1", "Calc I 2026-2")
  - `modulosPredeterminados()` HasMany - Default modules for catalog

- **Scopes**:
  - `activos()` - Only active courses
  - `regulares()` - Only regular courses (category 'regular' or NULL)
  - `personalizados()` - Only personalized courses
  - `talleres()` - Only workshops
  - `delPrograma($id)` - Filter by program
  - `buscar($term)` - Search by name or code (case-insensitive)

- **Key Methods**:
  - `esValido()` - Validates structure (non-empty code/name, modulos_default 1-10)
  - `obtenerModulosParaCurso($cursoAbierto)` - Get all modules for specific open course

#### **CursoAbierto** (Open/Offered Course Instance)
- **Table**: `academic.cursos_abiertos`
- **Purpose**: Specific instance of a catalog course in a given semester
- **Key Fields**:
  - `id` (UUID, PK)
  - `catalogo_curso_id` (FK to CatalogoCurso)
  - `nombre_instancia` (string) - Instance name (e.g., "Section A", "Group 1")
  - `semestre` (string) - Semester identifier (e.g., "2026-1")
  - `fecha_inicio` (datetime)
  - `fecha_fin` (datetime)
  - `capacidad_maxima` (integer) - Max enrollment capacity
  - `docente_id` (FK to Persona - instructor)
  - `es_activo` (boolean)
  - `observaciones` (text, nullable)
  - `modalidad` (enum: 'presencial', 'virtual')
  - `ciudad_id` (FK to cities)
  - `horario_id` (FK to schedule)
  - `precio_base` (numeric, 10.2 precision)
  - `created_at`, `updated_at`, `deleted_at` (with soft deletes)

- **Special Note**: `estudiantes_inscritos` field is referenced in code but **NOT in fillable array** - it's calculated from matriculas count

- **Relationships**:
  - `catalogo()` BelongsTo - Parent catalog
  - `docente()` BelongsTo - Teaching instructor
  - `ciudad()` BelongsTo - City
  - `horarios()` HasMany - Course schedules
  - `modulos()` HasMany - Course-specific modules
  - `matriculas()` HasMany - Student enrollments
  - `cambiosHorarioDestino()` HasMany - Schedule changes targeting this course
  - `cambiosHorarioOrigen()` HasMany - Schedule changes from this course

- **Scopes**:
  - `activos()`, `delSemestre()`, `delCatalogo()`, `delDocente()`
  - `vigentes()` - Currently active (date between inicio-fin, es_activo=true)
  - `proximos($dias)` - Starting within N days
  - `buscar($term)` - Search by name or catalog name/code

- **Key Methods**:
  - `obtenerCountMatriculas()` - Count active/completed enrollments
  - `obtenerEspaciosDisponibles()` - Available seats
  - `estaLleno()`, `hayEspacios()` - Capacity checks
  - `getPorcentajeOcupacion()` - Occupancy percentage (0-100)
  - `estaVigente()` - Checks if currently running
  - `getNombreCompleto()` - Returns "CatalogName (InstanceName)"

#### **Matricula** (Student Enrollment)
- **Table**: `academic.matriculas`
- **Purpose**: Links a student to an open course
- **Key Fields**:
  - `id` (UUID, PK)
  - `estudiante_id` (FK to Persona - student)
  - `curso_abierto_id` (FK to CursoAbierto)
  - `horario_id` (FK to schedule)
  - `estado` (enum: 'activo', 'completado', 'retirado', 'reprobado')
  - `fecha_inicio`, `fecha_fin` (datetime)
  - `calificacion_final` (float, nullable)
  - `observaciones` (text, nullable)
  - `solicitud_inscripcion_id` (FK, nullable - FASE 12 new)
  - `created_at`, `updated_at`, `deleted_at` (with soft deletes)

- **Relationships**:
  - `solicitudInscripcion()` BelongsTo - Registration request that created this
  - `estudiante()` BelongsTo - Student person
  - `cursoAbierto()` BelongsTo - Enrolled course
  - `horario()` BelongsTo - Assigned schedule
  - `notas()` HasMany - Grade records
  - `cambiosHorario()` HasMany - Schedule changes from this enrollment
  - `trasladosModulo()` HasMany - Module transfers from this enrollment

- **States**: ACTIVO → COMPLETADO or RETIRADO or REPROBADO

- **Key Methods**:
  - `obtenerNotas()` - Get all grades with module info
  - `calcularPromedio()` - Simple average of grades
  - `calcularPromedioPonderado()` - Weighted average by module
  - `tieneTotalNotasRegistradas()` - Check all modules graded
  - `estaEnVigencia()` - Currently active and dates valid

### 1.2 Staff and Personnel Models

#### **Persona** (Person - Core Identity)
- **Table**: `people.personas`
- **Purpose**: Generic person entity (student, instructor, staff)
- **Key Fields**:
  - `id` (UUID, PK)
  - `tipo` (enum: 'estudiante', 'instructor', 'staff', 'secretaria', 'admin')
  - `cedula` (string) - ID number
  - `nombres`, `apellidos` (strings)
  - `correo`, `celular` (strings)
  - `ciudad_id` (FK)
  - `cedula_photo_url`, `ficha_registro_url` (nullable)
  - `es_activo` (boolean)
  - `created_at`, `updated_at`, `deleted_at` (with soft deletes)

- **Relationships**:
  - `cuentaSistema()` HasOne - Login credentials
  - `perfilEstudiante()` HasOne - If student
  - `perfilInstructor()` HasOne - If instructor
  - `perfilStaff()` HasOne - If staff
  - `ciudad()` BelongsTo

- **Scopes**:
  - `estudiantes()`, `instructores()`, `staff()`
  - `activos()`
  - `buscar($term)` - Search by name, cedula, email

#### **PerfilStaff** (Staff Profile)
- **Table**: `people.perfil_staff`
- **Purpose**: Staff-specific attributes
- **Key Fields**:
  - `id` (UUID, PK)
  - `persona_id` (FK to Persona, UNIQUE)
  - `cargo` (string, 100 chars) - Position/role
  - `salario_base` (decimal:2, nullable)
  - `fecha_ingreso` (date, nullable)
  - `es_pasante` (boolean) - Intern status

- **Relationships**:
  - `persona()` BelongsTo

#### **PerfilInstructor** (Instructor Profile)
- **Table**: `people.perfil_instructor`
- **Key Fields**:
  - `id` (UUID, PK)
  - `persona_id` (FK to Persona, UNIQUE)
  - `especialidad` (string, 200 chars)
  - `bio` (text)

### 1.3 Registration & Enrollment (FASE 12)

#### **SolicitudInscripcion** (Registration Request)
- **Table**: `academic.solicitudes_inscripcion`
- **Purpose**: Track enrollment request workflow from submission to matricula creation
- **Key Fields**:
  - `id` (UUID, PK)
  - `persona_id` (FK to Persona, nullable - for registered students)
  - `participante_externo_id` (FK to ClienteExterno, nullable - for external participants)
  - `es_participante_externo` (boolean) - Flag
  - `curso_abierto_id` (FK to CursoAbierto)
  - `monto_solicitado` (decimal:2) - Enrollment fee
  - `tipo_pago` (enum: 'completo', 'abono')
  - `archivo_comprobante_url` (URL to payment proof)
  - `tipo_comprobante` (enum: 'transferencia', 'deposito', 'efectivo', 'otro')
  - `fecha_pago_declarada` (date) - When student claims payment made
  - `estado` (enum: 'registrado', 'pendiente_validacion', 'aprobado', 'rechazado', 'matricula_creada', 'cancelado')
  - `validado_por` (FK to Persona - staff who approved)
  - `motivo_rechazo` (text, nullable)
  - `observaciones_validacion` (text, nullable)
  - `fecha_validacion` (datetime, nullable)
  - `created_at`, `updated_at`, `deleted_at` (with soft deletes)

- **States Flow**:
  ```
  registrado → pendiente_validacion → aprobado → matricula_creada
                                   ↘ rechazado
  (can be cancelado from pendiente_validacion)
  ```

- **Relationships**:
  - `estudiante()` BelongsTo - If registered student
  - `participanteExterno()` BelongsTo - If external participant
  - `cursoAbierto()` BelongsTo
  - `validador()` BelongsTo - Staff member who validated
  - `matricula()` HasOne - Created enrollment (after approval)
  - `cuentasPorCobrar()` HasMany - Payment records

- **Key Methods**:
  - `esEstudiante()` - Is registered student?
  - `esParticipanteExterno()` - Is external participant?
  - `estaPendiente()`, `fueAprobada()`, `fueRechazada()`, `tieneMatricula()`
  - `estaActiva()` - In workflow (not rejected/cancelled)
  - `obtenerNombreSolicitante()`, `obtenerCorreoSolicitante()`

#### **ClienteExterno** (External Client) - **MISSING MODEL**
- **Table**: `people.clientes_externos` (exists in DB)
- **Schema**:
  - `id` (UUID, PK)
  - `nombres`, `apellidos` (strings)
  - `cedula` (string, 20 chars)
  - `correo` (string, 150 chars)
  - `celular` (string, 20 chars)
  - `ciudad_id` (FK, nullable)
  - `observaciones` (text, nullable)
  - `created_at` (timestamp)

- **Status**: ⚠️ **CRITICAL ISSUE**: Model is referenced in code (`RegistrationController.php` line 9, `SolicitudInscripcion.php` line 85) but **does not exist** as `app/Models/ClienteExterno.php`

### 1.4 Related Models

#### **Modulo** (Course Module/Topic)
- **Table**: `academic.modulos`
- **Purpose**: Grade components within a course
- **Key Fields**:
  - `id` (UUID, PK)
  - `catalogo_curso_id` (FK, nullable) - Default modules
  - `curso_abierto_id` (FK, nullable) - Custom modules
  - `nombre` (string)
  - `descripcion` (text, nullable)
  - `semana_inicio`, `semana_fin` (integer) - Week range
  - `ponderacion` (float) - Weight in final grade (0-100%)

- **Key Methods**:
  - `esPredeterminado()` - From catalog
  - `esPersonalizado()` - From specific course
  - `obtenerDuracionSemanas()`
  - `obtenerPonderacionPorcentaje()`

#### **CuentaPorCobrar** (Accounts Receivable)
- **Table**: `finance.cuentas_por_cobrar`
- **Purpose**: Track payments for enrollments
- **Key Fields**:
  - `id` (UUID, PK)
  - `solicitud_inscripcion_id` (FK, nullable - NEW in FASE 12)
  - `matricula_id` (FK, nullable)
  - `monto_total` (decimal:2)
  - `monto_abonado` (decimal:2)
  - `estado` (enum: 'pendiente', 'abonado', 'pagado', 'anulado')
  - Multiple other FKs for different service types (legacy)

- **Scopes**: `pendientes()`, `abonadas()`, `pagadas()`, `deSolicitud()`, `deMatricula()`

- **Key Methods**:
  - `obtenerSaldoPendiente()`
  - `estaCompletamentePagada()`
  - `obtenerPorcentajePagado()`

#### **CuentaSistema** (System Login Account)
- **Table**: `people.cuentas_sistema`
- **Purpose**: Authentication credentials
- **Key Fields**:
  - `id` (UUID, PK)
  - `persona_id` (FK to Persona)
  - `username` (string)
  - `password_hash` (hashed, implements Authenticatable)

---

## 2. COURSE STRUCTURE ANALYSIS

### 2.1 Course Fields Summary

**CatalogoCurso (Template):**
```
codigo          → ✅ Required, Unique, Max 50 chars
nombre          → ✅ Required, Max 255 chars
descripcion     → ⚠️ Optional
creditos        → ⚠️ Optional, default 3
horas_totales   → ⚠️ Optional, default 40
modulos_default → ⚠️ Optional, default 2
categoria       → ⚠️ Optional, enum, default 'regular'
imagen          → ⚠️ Optional, URL string
es_activo       → ✅ Boolean, default true
programa_id     → ⚠️ Optional UUID
```

**CursoAbierto (Instance):**
```
nombre_instancia        → ✅ Required
semestre                → ✅ Required
fecha_inicio/fin        → ✅ Required, validation: inicio ≤ fin
capacidad_maxima        → ✅ Required, > 0
precio_base             → ✅ Required, numeric
docente_id              → ⚠️ Optional
modalidad               → ✅ Required, enum
ciudad_id               → ⚠️ Optional
horario_id              → ⚠️ Optional
observaciones           → ⚠️ Optional
es_activo               → ⚠️ Boolean, default true
catalogo_curso_id       → ✅ Required, FK
estudiantes_inscritos   → ❌ NOT IN FILLABLE (calculated from matriculas)
```

### 2.2 The "Code" Field Deep Dive

**Definition**: `codigo` in `CatalogoCurso` model
- **Type**: String, max 50 characters
- **Constraints**: UNIQUE across all catalog courses
- **Purpose**: Identifier code for the course (e.g., "MAT101", "ENG201", "BIO305")
- **Validation Rule**: `'codigo' => 'required|string|max:50|unique:catalogo_cursos,codigo'`

**Where It's Used**:
1. **Stored**: `academic.catalogo_cursos` table, column `codigo`
2. **Validated**: 
   - `StoreCatalogoCursoRequest` (create)
   - `UpdateCatalogoCursoRequest` (update with Rule::unique ignore)
   - `StoreTallerRequest` (workshops also have codigo)
3. **Searched**:
   - `CatalogoCurso::buscar($term)` - searches by codigo or nombre (case-insensitive ilike)
   - `CursoAbierto::buscar($term)` - searches catalog's codigo
   - `TallerController::index()` - searches by codigo
4. **Displayed**:
   - `CatalogoCursoDocumentation.php` - OpenAPI documentation
   - `ExportService.php` - Export as "Código Curso"

**Example Values**:
- MAT101 - Calculus I
- ENG201 - English II
- BIO305 - Advanced Biology

---

## 3. STAFF REGISTRATION & MANAGEMENT

### 3.1 Staff Registration Flow

**Step 1: Create Persona (Base Person Record)**
- Endpoint: (handled via admin operations)
- Creates: `people.personas` record with `tipo = 'staff'|'secretaria'|'admin'`
- Fields: cedula, nombres, apellidos, correo, celular, ciudad_id

**Step 2: Create PerfilStaff (Role Profile)**
- Controller: `StaffController` (if exists) or manual
- Creates: `people.perfil_staff` record linked to Persona
- Form: `StorePerfilStaffRequest`
- Fields: cargo, salario_base, fecha_ingreso, es_pasante

**Step 3: Create CuentaSistema (Login)**
- Creates: `people.cuentas_sistema` record
- Fields: username, password_hash (implements Authenticatable for Sanctum)

### 3.2 Staff Validation Workflow (FASE 12)

**Staff Role**: Approve/reject student registration requests

**Endpoints**:
```
GET  /api/v1/staff/solicitudes-inscripcion
     → List all pending registrations with filters
     → Staff can see curso, solicitante, monto, estado

GET  /api/v1/staff/solicitudes-inscripcion/{id}
     → View full request details including payment proof

POST /api/v1/staff/solicitudes-inscripcion/{id}/validar
     → Approve request (ValidateRegistrationRequest)
     → Creates Matricula + CuentaPorCobrar
     → Updates SolicitudInscripcion estado to matricula_creada
     → Updates curso.estudiantes_inscritos += 1

POST /api/v1/staff/solicitudes-inscripcion/{id}/rechazar
     → Reject request (RejectRegistrationRequest)
     → Updates estado to rechazado
     → Stores motivo_rechazo

POST /api/v1/staff/solicitudes-inscripcion/{id}/cancelar
     → Cancel request (from pendiente_validacion only)
```

**Validation Logic** (in `StaffRegistrationController::approve()`):
1. Check solicitud is in `pendiente_validacion` state
2. Verify auth user is Persona instance
3. Call `RegistrationStateService::approve()`
4. Service performs:
   - Update solicitud to `aprobado`
   - Record `validado_por` (staff ID), `observaciones_validacion`, `fecha_validacion`
   - Create Matricula (calls `crearMatricula()`)
   - Create CuentaPorCobrar (calls `crearCuentaPorCobrar()`)
   - Update solicitud to `matricula_creada`
5. Return: matricula_id, cuenta_cobrar_id

---

## 4. STUDENT ENROLLMENT FLOW

### 4.1 Enrollment Creation Process

**Step 1: Student Registration Request**
- **Endpoint**: `POST /api/v1/registrations` (PUBLIC)
- **Form**: `StoreRegistrationRequest`
- **Inputs**:
  - Option A (Registered Student): `persona_id` only
  - Option B (External): `nombres`, `apellidos`, `cedula`, `celular`, `correo`, `ciudad_id`
  - Required: `curso_abierto_id`, `monto_solicitado`, `tipo_pago`, `archivo_comprobante_url`, `tipo_comprobante`, `fecha_pago_declarada`

**Step 2: Validations in RegistrationController::store()**
1. If external: Create or find `ClienteExterno` via email
2. Verify course exists (CursoAbierto::find)
3. Call `RegistrationValidationService::validar()`:
   - Check course capacity
   - Check for duplicate enrollments
   - Validate payment type
   - Return: `['valido' => bool, 'errores' => []]`
4. Call `PaymentVerificationService::validar()`:
   - Validate payment proof URL
   - Check payment type matches comprobante
   - Verify fecha_pago_declarada ≤ today
   - Return: `['valido' => bool, 'errores' => [], 'recomendaciones' => []]`

**Step 3: Create SolicitudInscripcion**
- State: `registrado` → `pendiente_validacion`
- Record all payment details

**Step 4: Staff Approval**
- Staff receives notification
- Reviews: student info, course, payment proof, amount
- Approves via `POST /api/v1/staff/solicitudes-inscripcion/{id}/validar`

**Step 5: Automatic Matricula Creation**
- `RegistrationStateService::crearMatricula()`:
  - Creates Matricula record
  - Calls `ScheduleConflictService` to validate no conflicts
  - Sets estado = 'activo'
  - Sets fecha_inicio = course.fecha_inicio, fecha_fin = course.fecha_fin
- Side effects: Increments `curso.estudiantes_inscritos` by 1

**Step 6: CuentaPorCobrar Creation**
- `RegistrationStateService::crearCuentaPorCobrar()`:
  - Creates payment record
  - monto_total = solicitud.monto_solicitado
  - estado = 'pendiente'
  - Links to both solicitud and matricula

### 4.2 Validation Rules

**RegistrationValidationService::validar()**:
- ✅ Course exists and active
- ✅ Capacity check: `capacidad_maxima - estudiantes_inscritos > 0`
- ✅ No duplicate enrollment (student not already in course)
- ✅ For abono type: validate minimum threshold
- ✅ External participant: limit per course (if defined)

**PaymentVerificationService::validar()**:
- ✅ Comprobante URL is valid
- ✅ Comprobante type matches payment declaration
- ✅ Payment date not in future
- ✅ Amount > 0
- ✅ For completo: monto_solicitado ≥ curso.precio_base
- ✅ For abono: monto_solicitado ≥ 10% of precio_base (if defined)

---

## 5. API ROUTES & CONTROLLERS

### 5.1 Course Management Routes

```
GET    /api/v1/academic/catalogos-cursos
POST   /api/v1/academic/catalogos-cursos
GET    /api/v1/academic/catalogos-cursos/{id}
PUT    /api/v1/academic/catalogos-cursos/{id}
DELETE /api/v1/academic/catalogos-cursos/{id}
GET    /api/v1/catalogo-cursos/disponibles          [PUBLIC]
POST   /api/v1/academic/catalogos-cursos/upload-imagen

GET    /api/v1/academic/cursos-abiertos
POST   /api/v1/academic/cursos-abiertos
GET    /api/v1/academic/cursos-abiertos/{id}
PUT    /api/v1/academic/cursos-abiertos/{id}
DELETE /api/v1/academic/cursos-abiertos/{id}
```

**CatalogoCursoController Methods**:
- `index()` - Supports: programa_id, categoria, activos, buscar, per_page
- `store()` - Create with validation (codigo UNIQUE)
- `show()` - Get single catalog
- `update()` - Update with unique check on codigo
- `destroy()` - Soft delete
- `disponibles()` - Get available open courses (public endpoint)
- `uploadImagen()` - File upload to storage

### 5.2 Enrollment Management Routes

```
GET    /api/v1/academic/matriculas
POST   /api/v1/academic/matriculas
GET    /api/v1/academic/matriculas/{id}
PUT    /api/v1/academic/matriculas/{id}
DELETE /api/v1/academic/matriculas/{id}
GET    /api/v1/academic/matriculas/{id}/notas
GET    /api/v1/academic/matriculas/{id}/calificaciones
GET    /api/v1/academic/matriculas/{id}/cambios-horario
```

**MatriculaController Methods**:
- `index()` - Filters: estudiante_id, curso_abierto_id, estado, activas
- `store()` - Create enrollment (usually via registration approval)
- `show()` - Get with related notas, cursoAbierto, horario
- `update()` - Update estado or dates
- `destroy()` - Soft delete
- `notas()` - Get grades paginated
- `calificaciones()` - Get weighted average and stats
- `cambiosHorario()` - Get schedule change history

### 5.3 Registration/Solicitud Routes (FASE 12)

```
[PUBLIC]
POST   /api/v1/registrations                                → Create new registration request

[STAFF ONLY]
GET    /api/v1/staff/solicitudes-inscripcion               → List all requests
GET    /api/v1/staff/solicitudes-inscripcion/{id}          → View request details
POST   /api/v1/staff/solicitudes-inscripcion/{id}/validar  → Approve (create matricula)
POST   /api/v1/staff/solicitudes-inscripcion/{id}/rechazar → Reject
POST   /api/v1/staff/solicitudes-inscripcion/{id}/cancelar → Cancel

[STUDENT ONLY]
GET    /api/v1/perfil/solicitudes-inscripcion              → My registration requests
GET    /api/v1/perfil/cursos-completados                   → My completed courses
```

### 5.4 Staff Management Routes

```
GET    /api/v1/academic/personas/staff
POST   /api/v1/academic/personas/staff
GET    /api/v1/academic/personas/staff/{id}
PUT    /api/v1/academic/personas/staff/{id}
DELETE /api/v1/academic/personas/staff/{id}
```

---

## 6. DATABASE MIGRATIONS

### 6.1 Migration Timeline (Phases)

**Phase 1** (2026_05_29_000000-000005):
- `fix_catalogo_cursos_columns.php` - Added codigo, programa_id, creditos, horas_totales, timestamps
- `phase_1_reestructurar_horarios.php` - Restructured schedule tables
- `phase_1_validacion_capacidad.php` - Added capacity validation constraints
- `phase_1_cascadas_correctas.php` - Foreign key cascade rules
- `phase_1_auditoria_cambios_horario.php` - Audit log for schedule changes
- `phase_1_indices_rendimiento.php` - Performance indexes (including idx_catalogo_cursos_codigo)

**Phase 10** (2026_05_30_000001-000006):
- `create_talleres_table.php` - Workshops table (has codigo field, unique)
- `create_horarios_talleres_table.php`
- `create_inscripciones_talleres_table.php`
- `create_asistencias_talleres_table.php`
- `create_participantes_externos_table.php` - External participants
- `create_inscripciones_externos_talleres_table.php` - External enrollments

**Phase 11** (2026_05_30_000007-000008):
- `add_personalizado_to_catalogo_cursos.php` - Added categoria column
- `create_participantes_cursos_personalizados_table.php` - Pivot for external participants in courses

**Phase 12** (2026_05_30_000009-000011):
- `create_solicitudes_inscripcion_table.php` - Registration requests table
- `alter_matriculas_add_solicitud_inscripcion_id.php` - Link enrollments to requests
- `alter_cuentas_por_cobrar_add_solicitud_inscripcion_id.php` - Link invoices to requests

---

## 7. VALIDATION IMPLEMENTATIONS

### 7.1 Form Request Validators

#### **StoreCatalogoCursoRequest**
```php
'programa_id' => 'nullable|uuid',
'codigo' => 'required|string|max:50|unique:catalogo_cursos,codigo',
'nombre' => 'required|string|max:255',
'descripcion' => 'nullable|string|max:1000',
'creditos' => 'nullable|integer|min:1|max:10',
'horas_totales' => 'nullable|integer|min:1|max:200',
'modulos_default' => 'nullable|integer|min:1|max:10',
'es_activo' => 'boolean',
'categoria' => 'nullable|in:regular,personalizado,taller',
'imagen' => 'nullable|string|max:255',
```

#### **UpdateCatalogoCursoRequest**
- All fields `sometimes|required` except nullable ones
- `codigo` uses `Rule::unique('catalogo_cursos', 'codigo')->ignore($catalogoId)`
- Messages customized for user-friendly errors

#### **StoreMatriculaRequest**
```php
'estudiante_id' => 'required|uuid|exists:personas,id',
'curso_abierto_id' => 'required|uuid|exists:cursos_abiertos,id',
'horario_id' => 'required|uuid|exists:horarios,id',
'estado' => 'required|in:activo,completado,retirado,reprobado',
'fecha_inicio' => 'required|date|date_format:Y-m-d',
'fecha_fin' => 'required|date|date_format:Y-m-d|after:fecha_inicio',
'observaciones' => 'nullable|string|max:1000',
```

#### **StoreRegistrationRequest**
- Dual-mode: registered student OR external participant
- Conditional validation in `prepareForValidation()`
- Payment validation: tipo_pago, comprobante, date constraints
- Custom messages for all fields

#### **StorePerfilStaffRequest**
```php
'cargo' => 'required|string|max:100',
'salario_base' => 'nullable|numeric|min:0',
'fecha_ingreso' => 'nullable|date',
'es_pasante' => 'boolean',
```

### 7.2 Service-Level Validation

**RegistrationValidationService**:
- `validar()` - Main validation for new registrations
- `validarParaPendiente()` - Validates transition to pending
- Checks:
  - Course capacity available
  - No duplicate enrollment
  - Payment amount ≥ threshold
  - Payment type valid

**PaymentVerificationService**:
- Validates payment proof URL format
- Verifies comprobante type
- Checks payment date not in future
- Returns structured response with validation messages

**ScheduleConflictService** (used during matricula creation):
- Detects schedule conflicts between courses
- Validates horarios don't overlap
- Bidirectional overlap checking

---

## 8. DEAD CODE & UNUSED FIELDS

### 8.1 CRITICAL ISSUES

**🔴 Missing Model: ClienteExterno**
- **Status**: ⚠️ REFERENCED BUT MISSING
- **Location**: Referenced in:
  - `RegistrationController.php` line 9 (use statement)
  - `RegistrationController.php` line 47 (firstOrCreate call)
  - `SolicitudInscripcion.php` line 85 (belongsTo relation)
- **Expected Table**: `people.clientes_externos` exists in DB
- **Issue**: Model class does not exist at `app/Models/ClienteExterno.php`
- **Impact**: Registration feature will crash when creating external participant registrations
- **Fix**: Create `app/Models/ClienteExterno.php` extending Model with:
  ```php
  protected $table = 'people.clientes_externos';
  protected $connection = 'pgsql';
  protected $fillable = ['nombres', 'apellidos', 'cedula', 'correo', 'celular', 'ciudad_id', 'observaciones'];
  ```

### 8.2 Field Handling Issues

**estudiantes_inscritos** (in CursoAbierto):
- ⚠️ **Status**: Referenced in code but not in $fillable
- **Usage**: 
  - Retrieved in controllers for capacity checks
  - Used in raw SQL: `whereRaw('capacidad_maxima > estudiantes_inscritos')`
  - Calculated in `RegistrationStateService::crearMatricula()` (+= 1)
- **Issue**: Field must exist in DB but model doesn't populate it directly
- **Current Approach**: Likely computed from `matriculas()->count()` or attribute accessor
- **Risk**: Inconsistency if not properly synchronized

### 8.3 Unused/Legacy Fields in CuentaPorCobrar

**Status**: Multiple FKs for legacy services (not used in FASE 12):
- `inscripcion_taller_id` - Workshops have separate payment tracking
- `reserva_aula_id` - Room reservations (not in active modules)
- `reserva_podcast_id` - Podcast reservations (not in active modules)
- `servicio_streaming_id` - Streaming services (not in active modules)
- `servicio_produccion_id` - Production services (not in active modules)
- `edicion_video_id` - Video editing (not in active modules)
- `alquiler_equipo_id` - Equipment rental (not in active modules)
- `clase_extra_id` - Extra classes (not in active modules)
- `asesoria_id` - Advising (not in active modules)

**Impact**: None (optional, not validated), but clutters schema

### 8.4 Unused Model Features

**CursoPersonalizado Model**:
- Uses global scope for auto-filtering by categoria='personalizado'
- Adds fields: dirigido_a, requisitos_especiales, certificado_emitido, costo_unitario
- **Issue**: These fields not in migrations or visible in actual usage
- **Status**: Appears to be template for future extension

**ParticipanteExterno Model**:
- Different from `ClienteExterno` (separate table `academic.participantes_externos`)
- Used for workshops only
- Fields: nombre, email, telefono, institucion, tipo
- **Status**: Duplicates ClienteExterno concept (possible consolidation needed)

### 8.5 Unused Controller Actions

**No obvious dead controller methods**, but:
- `StaffController` existence unclear (AGENTS.md references generic staff endpoints)
- Some profile controllers may have unused endpoints

---

## 9. COMPREHENSIVE FIELD REFERENCE

### 9.1 Course Catalog Fields

| Field | Type | Required | Unique | Default | Notes |
|-------|------|----------|--------|---------|-------|
| id | UUID | ✓ | ✓ (PK) | - | |
| programa_id | UUID | ✗ | ✗ | NULL | FK to programs |
| **codigo** | string(50) | ✓ | ✓ | - | **THE "CODE" FIELD** |
| nombre | string(255) | ✓ | ✗ | - | |
| descripcion | text | ✗ | ✗ | NULL | |
| creditos | integer | ✗ | ✗ | 3 | 1-10 range |
| horas_totales | integer | ✗ | ✗ | 40 | 1-200 range |
| modulos_default | integer | ✗ | ✗ | 2 | 1-10 modules |
| categoria | string(50) | ✗ | ✗ | 'regular' | enum: regular\|personalizado\|taller |
| imagen | string(255) | ✗ | ✗ | NULL | Image URL |
| es_activo | boolean | ✗ | ✗ | true | |
| created_at | timestamp | ✓ | ✗ | now() | |
| updated_at | timestamp | ✓ | ✗ | now() | |
| deleted_at | timestamp | ✗ | ✗ | NULL | Soft delete |

### 9.2 Open Course Fields

| Field | Type | Fillable | Calculated | Notes |
|-------|------|----------|------------|-------|
| id | UUID | - | ✓ | |
| catalogo_curso_id | UUID | ✓ | - | FK |
| nombre_instancia | string | ✓ | - | Section identifier |
| semestre | string | ✓ | - | e.g., "2026-1" |
| fecha_inicio | datetime | ✓ | - | |
| fecha_fin | datetime | ✓ | - | Must be ≥ fecha_inicio |
| capacidad_maxima | integer | ✓ | - | Max students |
| **estudiantes_inscritos** | integer | ✗ | ✓ | Computed from matriculas |
| docente_id | UUID | ✓ | - | FK to Persona (instructor) |
| es_activo | boolean | ✓ | - | |
| observaciones | text | ✓ | - | |
| modalidad | string | ✓ | - | enum: presencial\|virtual |
| ciudad_id | bigint | ✓ | - | FK to cities |
| horario_id | UUID | ✓ | - | FK to schedule |
| precio_base | numeric(10,2) | ✓ | - | Base enrollment fee |
| created_at | timestamp | - | ✓ | |
| updated_at | timestamp | - | ✓ | |
| deleted_at | timestamp | - | ✓ | Soft delete |

---

## 10. SUMMARY & RECOMMENDATIONS

### 10.1 Architecture Strengths

✅ **Clear Separation of Concerns**:
- Catalog (template) vs Open Course (instance) pattern
- Service layer for complex validation
- Form requests for input sanitization

✅ **Complete Enrollment Workflow**:
- Registration request → Staff validation → Automatic matricula → Payment tracking

✅ **Flexible Participant Support**:
- Registered students
- External participants
- Workshops with separate tracking

✅ **Comprehensive Validation**:
- Form-level (Laravel validators)
- Service-level (business logic)
- Database-level (constraints)

### 10.2 Critical Fixes Needed

🔴 **Priority 1: Create ClienteExterno Model**
- File: `app/Models/ClienteExterno.php`
- Without this, external registrations will crash
- Estimated effort: 15 minutes

🔴 **Priority 2: Clarify estudiantes_inscritos Field**
- Add database migration if missing
- Implement mutator/accessor in CursoAbierto
- Add test to ensure consistency
- Estimated effort: 30 minutes

🟡 **Priority 3: Consolidate External Participant Models**
- Current: `ClienteExterno` (for registrations) + `ParticipanteExterno` (for workshops)
- Consider merging or clear separation
- Update documentation
- Estimated effort: 2 hours

### 10.3 Code Quality Recommendations

1. **Add Missing Documentation**:
   - API examples for registration flow
   - Payment verification process
   - Staff approval workflow

2. **Add Tests**:
   - Missing ClienteExterno model tests
   - Registration state transitions
   - Payment validation edge cases

3. **Optimize Queries**:
   - Add eager loading for nested relations
   - Cache categoria lookups
   - Index on solicitud_inscripcion.estado

---

## 11. KEY FILE REFERENCE

| Resource | Location | Status |
|----------|----------|--------|
| CatalogoCurso Model | `app/Models/CatalogoCurso.php` | ✅ |
| CursoAbierto Model | `app/Models/CursoAbierto.php` | ✅ |
| Matricula Model | `app/Models/Matricula.php` | ✅ |
| SolicitudInscripcion Model | `app/Models/SolicitudInscripcion.php` | ✅ |
| **ClienteExterno Model** | `app/Models/ClienteExterno.php` | ❌ MISSING |
| PerfilStaff Model | `app/Models/PerfilStaff.php` | ✅ |
| PerfilInstructor Model | `app/Models/PerfilInstructor.php` | ✅ |
| Persona Model | `app/Models/Persona.php` | ✅ |
| --- | --- | --- |
| CatalogoCursoController | `app/Http/Controllers/Api/V1/CatalogoCursoController.php` | ✅ |
| CursoAbiertoController | `app/Http/Controllers/Api/V1/CursoAbiertoController.php` | ✅ |
| MatriculaController | `app/Http/Controllers/Api/V1/MatriculaController.php` | ✅ |
| RegistrationController | `app/Http/Controllers/Api/V1/RegistrationController.php` | ✅ (References missing model) |
| StaffRegistrationController | `app/Http/Controllers/Api/V1/StaffRegistrationController.php` | ✅ |
| --- | --- | --- |
| StoreCatalogoCursoRequest | `app/Http/Requests/StoreCatalogoCursoRequest.php` | ✅ |
| UpdateCatalogoCursoRequest | `app/Http/Requests/UpdateCatalogoCursoRequest.php` | ✅ |
| StoreMatriculaRequest | `app/Http/Requests/StoreMatriculaRequest.php` | ✅ |
| StoreRegistrationRequest | `app/Http/Requests/StoreRegistrationRequest.php` | ✅ |
| StorePerfilStaffRequest | `app/Http/Requests/StorePerfilStaffRequest.php` | ✅ |
| --- | --- | --- |
| RegistrationStateService | `app/Services/RegistrationStateService.php` | ✅ |
| RegistrationValidationService | `app/Services/RegistrationValidationService.php` | ✅ |
| PaymentVerificationService | `app/Services/PaymentVerificationService.php` | ✅ |
| ScheduleConflictService | `app/Services/ScheduleConflictService.php` | ✅ |

