# AGENTS.md — Academic Management System Backend

**Project**: Laravel 13.8 REST API for university academic management (12 completed phases, 80% complete)

**Key fact**: This is a specialized multi-phase Laravel system with explicit phase-based documentation. Always consult phase docs first before modifying code. Database structure is source-of-truth in `DB.sql`.

**Latest Phase**: FASE 12 - Registration Module (public catalog + staff validation workflow)

---

## Quick Commands

```bash
# Full setup
composer install
php artisan migrate

# Run all tests (Feature + Unit, ~136 tests)
php artisan test

# Run specific test suite
php artisan test tests/Feature/CourseTest.php

# Serve locally (includes queue + logs)
composer run dev

# Clear everything (config, cache, cache:forget)
php artisan config:clear && php artisan cache:clear

# Swagger docs (already integrated, auto-generated)
php artisan l5-swagger:generate
# Then visit: http://localhost:8000/api/documentation
```

---

## Critical Architecture Facts

### API Structure
- **Prefix**: All endpoints under `/api/v1/academic/` (auth/profile routes outside academic)
- **Authentication**: Laravel Sanctum (JWT tokens, stateless)
- **Database**: PostgreSQL primary (pgsql), SQLite in-memory for tests
- **Response format**: JSON, pagination with meta (current_page, per_page, total, last_page)

### Three Model Hierarchies (No simple CRUD)

1. **Regular Courses** (`catalogo_cursos` + `cursos_abiertos`):
   - Catalog (abstract template) → OpenCourse (instance, e.g. "Calc I 2026-1")
   - Modules, schedules, enrollments, grades, attendance (but NOT external participants)

2. **Workshops** (`talleres` + `horarios_talleres`):
   - Separate system, simpler than courses
   - No grades, only attendance counters per session
   - Can accept students OR external participants (separate tables)

3. **Personalized Courses** (inherits `CursoAbierto`, filtered by `categoria='personalizado'`):
   - Reuses enrollment, modules, grades, schedules from CursoAbierto
   - **Special**: Accepts external participants without user accounts (via `ParticipanteExterno`)

### Database Schemas
- **academic**: Courses, modules, schedules, enrollments, grades, attendance, workshops, external participants
- **people**: Users (students, instructors, staff)
- **public**: Metadata (PostgreSQL default schema)
- All tables use UUID primary keys + soft deletes

### Schedule Conflict Detection (Critical for FASE 10+)
- `ScheduleConflictService`: Validates course schedules vs other courses/workshops
- `WorkshopScheduleConflictService`: Validates workshop schedules (weekday 1-7, no overlaps)
- Both check if `hora_fin > hora_inicio` and bidirectionally check overlap windows
- **Do not skip**: Migrations fail if schedule validation logic is missing

---

## Phase-Based Development

Phases are sequential. **Do NOT bypass phase docs before modifying code.**

| Phase | Status | Key Files | Note |
|-------|--------|-----------|------|
| 1-8 | ✅ Complete | `FASE_1_*.md` through `FASE_8_*.md` | 59 endpoints, all docs + Swagger |
| 10 | ✅ Complete | `FASE_10_WORKSHOPS.md` | 22 endpoints (workshops + external participants) |
| 11 | ✅ Complete | `FASE_11_PERSONALIZED_COURSES.md` | 13 endpoints (reutilizes CursoAbierto + external participants) |
| 12 | ✅ Complete | `FASE_12_REGISTRATION_MODULE.md` | 8 endpoints (public catalog + staff approval + student portal) |
| 9, 13 | ⏳ Pending | `ROADMAP_FASES_8_12.md` | 9: integration tests; 13: RBAC + payments |

**When modifying past phases**: Read the phase doc, verify existing code matches, then add. Phases are layered—breaking FASE 1 breaks everything.

---

## Models: Key Quirks

### Eloquent Global Scopes
- **CursoPersonalizado**: Auto-filters `->where('categoria', 'personalizado')` on `CursoAbierto`
- **Taller**: No auto-filtering (standalone table)
- **ParticipanteExterno**: No auto-filtering

### Many-to-Many (Not Standard BelongsTo)
- `CursoPersonalizado::participantesExternos()`: Pivot table `participantes_cursos_personalizados` with `(fecha_inscripcion, estado)`
- `Taller::inscripciones_externos()`: Pivot `inscripciones_externos_talleres` (similar)
- **Standard enrollments** (`matriculas`) remain one-to-many for both courses and workshops

### Pivot Fields Are Important
- Pivot timestamps and extra fields (estado, fecha_inscripcion) are included in queries
- When updating pivot state: use `updateExistingPivot()`, not `update()`

---

## Testing

### Test Structure
- **Feature tests** (`tests/Feature/`): HTTP requests, controller logic, validations
- **Unit tests** (`tests/Unit/`): Models, scopes, utility methods
- **Config**: `phpunit.xml` runs SQLite `:memory:` (not file-based)
- **Factories**: 7 factories in `database/factories/` for seeding test data

### Key Test Patterns
```php
// Test a single endpoint
php artisan test tests/Feature/CourseTest.php --filter=testCreateCourse

// Run with coverage
php artisan test --coverage

// Refresh migrations + run tests
php artisan migrate:fresh && php artisan test
```

### Migrations in Tests
- Use `$this->artisan('migrate')` in test setup or `$this->withoutMigrations()`
- SQLite in-memory means zero file I/O, but foreign key constraints are ON by default
- `DB_FOREIGN_KEYS=true` in phpunit.xml

---

## Validation

### Two Levels of Validation
1. **Form Requests** (`app/Http/Requests/*.php`): Input validation + messages
2. **Services** (`app/Services/*.php`): Business logic (schedule conflicts, capacity, ponderacion sum)

### Common Validation Patterns
- **Dates**: `after_or_equal:today`, `after:fecha_inicio`
- **UUIDs**: `uuid`, `exists:table_name,id`
- **Enums**: Explicit `in:val1,val2,val3` (Laravel 13 doesn't auto-map backed enums to in())
- **Capacity**: Must validate both in controller (after querying DB) and in service

### Services Return Structured Arrays
```php
// WorkshopScheduleConflictService::validarSinCruces()
[
    'valido' => bool,
    'errores' => ['msg1', 'msg2'],
    'conflictos' => ['talleres' => [...], 'cursos' => [...]]
]
```

---

## API Endpoints: Organization

### Grouped by Resource (Academic Module)
```
/catalogos-cursos       → CatalogoCursoController (5 endpoints + disponibles)
/cursos-abiertos        → CursoAbiertoController (9 endpoints)
/horarios               → HorarioController (7 endpoints)
/modulos                → ModuloController (7 endpoints)
/matriculas             → MatriculaController (8 endpoints)
/notas                  → NotaController (6 endpoints)
/cambios-horario        → CambioHorarioController (8 endpoints)
/traslados-modulo       → TrasladoModuloController (8 endpoints)
/bulk/*                 → BulkOperationsController (4 endpoints)
/export/*               → ExportController (3 endpoints)
/reports/*              → ReportController (6 endpoints)
/talleres               → TallerController (7 endpoints + bulk)
/talleres/{id}/horarios → HorarioTallerController (5 endpoints)
/talleres/{id}/inscripciones → InscripcionTallerController (10 endpoints)
/participantes-externos → ParticipanteExternoController (5 endpoints)
/talleres/{id}/asistencias → AsistenciaTallerController (6 endpoints)
/cursos-personalizados  → CursoPersonalizadoController (13 endpoints)
```

### Public Endpoints (FASE 12 - Registration Module)
```
GET  /catalogo-cursos/disponibles        → View available courses for registration
POST /registrations                      → Submit new registration request
```

### Protected Endpoints (FASE 12 - Staff Management)
```
GET  /staff/solicitudes-inscripcion      → List all registration requests (staff)
GET  /staff/solicitudes-inscripcion/{id} → View request details (staff)
POST /staff/solicitudes-inscripcion/{id}/validar  → Approve request + create enrollment (staff)
POST /staff/solicitudes-inscripcion/{id}/rechazar → Reject request (staff)
POST /staff/solicitudes-inscripcion/{id}/cancelar → Cancel request (staff)
```

### Protected Endpoints (FASE 12 - Student Portal)
```
GET  /perfil/solicitudes-inscripcion     → View my registration requests (student)
GET  /perfil/cursos-completados          → View my completed courses (student)
```

### Pagination
- Default 15 items per page
- Query param: `?per_page=50`
- Response includes `meta` with pagination info

### Filters (Most Controllers Support)
- `?estado=active`
- `?search=term` (name, code, description)
- `?fecha_inicio=2026-06-01`
- Read controller `index()` for full list

---

## Bulk Operations & Exports

### Bulk Constraints
- Max **1000 items per request** (prevents timeout)
- 207 Multi-Status response for partial failures
- `POST /bulk/notas/register`, `/bulk/notas/update`, `/bulk/notas/delete`, `/bulk/matriculas/cambiar-estado`

### Export Formats
- **CSV** (league/csv): Lightweight, Excel-compatible
- **PDF** (barryvdh/laravel-dompdf): Professional layout
- **Excel** (phpoffice/phpspreadsheet): Editable, multi-sheet
- Endpoint: `POST /export/` with `format` and `type` params

### Reports (Auto-Calculate Stats)
- `asistencia`, `desempeño`, `progreso`, `resumen-academico`, `tipos-disponibles`, `comparativa-estudiantes`
- All endpoints: `GET /reports/{type}?filters...`
- Include auto-recommended actions based on data

---

## Documentation & Swagger

### API Doc Generation
- **Tool**: L5-Swagger (darkaonline/l5-swagger) + Knuckles Scribe
- **Command**: `php artisan l5-swagger:generate` (auto-discovers @OA annotations)
- **UI**: http://localhost:8000/api/documentation
- **Interactive**: "Try it out" button; enter Bearer token in top-right "Authorize" button

### Documentation Code Structure
- `app/Http/Controllers/Api/V1/ApiDocumentation.php`: Global config (info, tags, security schemes)
- `app/Http/Controllers/Api/V1/CatalogoCursoDocumentation.php`: FASE 1-4 endpoints
- `app/Http/Controllers/Api/V1/Phase7Documentation.php`: FASE 7 (bulk, export, reports)
- **New endpoint?** Add @OA\* annotations to controller method, then run `l5-swagger:generate`

### Scribe Config (Fallback)
- `config/scribe.php`: Type=postman, uses code comments as fallback if @OA annotations missing

---

## Common Mistakes to Avoid

1. **Forgetting service validation**: Passing input directly to model without calling `ScheduleConflictService->validarSinCruces()` → schedule collisions silently occur.

2. **Using wrong DB connection**: Models specify `protected $connection = 'pgsql'` but tests run SQLite. Ensure migrations run on both.

3. **Mixing Pivot Syntaxes**: `->attach()` creates, `->detach()` removes, `->updateExistingPivot()` updates state. Using `->update()` on pivot data is wrong.

4. **Missing `soft deletes`**: All models use `SoftDeletes`. When checking existence, use `->exists()` not explicit null checks; queries auto-exclude deleted records.

5. **Ignoring Capacity Validation**: Courses & workshops must validate `capacidad - totalInscritos > 0` AFTER calculating totals from both students & external participants.

6. **Skipping Schedule Validation**: Workshops and courses must call conflict service; tests fail silently if you don't.

7. **Not checking FASE docs**: Every phase builds on prior phases. Modifying without reading phase doc causes regressions.

---

## Database Gotchas

### PostgreSQL Specifics
- `ARRAY` types used historically (now replaced by relation tables in FASE 1)
- `SEARCH_PATH=academic` in .env points to main schema
- Foreign key constraints are enforced
- Migrations must be compatible with both PostgreSQL AND SQLite (for tests)

### Migration Ordering
- Numbered by date (`2026_05_30_000001_*`)
- Run sequentially; if one fails, subsequent migrations don't run
- Drop dependencies in `down()` method (e.g., drop child table before parent)

### Schemas in Code
- `protected $table = 'academic.table_name'` in models (full schema.table syntax)
- Migrations use `Schema::connection(config('database.default'))` to respect `.env` DB choice

---

## File Locations Worth Knowing

| Path | Purpose |
|------|---------|
| `database/migrations/` | FASE 1-11 migrations (72 total, 8 new in FASES 10-11) |
| `app/Models/` | 13 Eloquent models (11 original + CursoPersonalizado + Taller hierarchy) |
| `app/Http/Controllers/Api/V1/` | 14 controllers + 3 doc classes |
| `app/Http/Requests/` | 30+ Form Request classes (validation rules) |
| `app/Services/` | 7 business logic services |
| `tests/Feature/` | ~92 HTTP integration tests |
| `tests/Unit/` | ~24 model & service unit tests |
| `database/factories/` | 7 factories for test seeding |
| `routes/api.php` | All 72 endpoint route definitions |
| `DB.sql` | **Source of truth** for table schema |
| `FASE_*.md` | Phase-specific implementation guides |
| `config/l5-swagger.php` | Swagger/OpenAPI configuration |

---

## When Adding a New Endpoint

1. **Check the phase**: Is it part of a scheduled phase? Read `ROADMAP_FASES_8_12.md`.
2. **Create Form Request**: Validation rules in `app/Http/Requests/Store*.php`, `Update*.php`.
3. **Add business logic**: If involving scheduling/capacity/grades, add service validation.
4. **Add to controller**: Implement method in relevant controller.
5. **Add route**: Edit `routes/api.php`, organize under resource prefix.
6. **Add @OA annotations**: Document with OpenAPI comments in controller method.
7. **Run Swagger generation**: `php artisan l5-swagger:generate`
8. **Test**: Write Feature test in `tests/Feature/`, run `php artisan test`.

---

## Environment & Config

### .env Defaults (See .env.example)
- `DB_CONNECTION=pgsql` (not sqlite—that's for tests)
- `DB_DATABASE=academia_productora`
- `SANCTUM_STATEFUL_DOMAINS=localhost,localhost:3000,127.0.0.1,127.0.0.1:8000`
- `SCRIBE_TYPE=postman` (use Scribe for API doc backup)

### Composer Scripts
- `composer setup` — Install + migrate + npm build
- `composer dev` — Dev server + queue + logs + Vite (concurrently)
- `composer test` — Clear config + run tests

---

## References

- **Full system overview**: `RESUMEN_GENERAL.md`
- **Phase roadmap**: `ROADMAP_FASES_8_12.md`
- **Documentation index**: `INDICE_DOCUMENTACION.md`
- **API endpoints**: `FASE_4_RUTAS_API.md` (FASES 1-4), `FASE_7_BULK_EXPORT_REPORTS.md` (FASE 7), `FASE_10_WORKSHOPS.md`, `FASE_11_PERSONALIZED_COURSES.md`
- **Current status**: `STATUS_ACTUAL_FASE_8.md` (updated with FASES 10-11 now at ~78% complete)

---

**Last updated**: May 30, 2026 (FASE 11 complete)

