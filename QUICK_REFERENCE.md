# Backend Architecture - Quick Reference

**Generated**: May 31, 2026  
**Full Analysis**: `/home/yorman/Documentos/GitHub/backend/BACKEND_ARCHITECTURE_ANALYSIS.md` (869 lines)

---

## THE "CODE" FIELD

**Location**: `CatalogoCurso.codigo`
- **Type**: String(50)
- **Constraint**: UNIQUE
- **Validation**: `required|string|max:50|unique:catalogo_cursos,codigo`
- **Purpose**: Course identification code (e.g., MAT101, ENG201)
- **Database Table**: `academic.catalogo_cursos`
- **Also Used In**: `Taller` model (workshops)

---

## CORE MODELS & RELATIONSHIPS

### Course Hierarchy
```
CatalogoCurso (Template)
    ↓ hasMany
    CursoAbierto (Instance)
        ↓ hasMany
        Matricula (Student Enrollment)
        Modulo (Grade Topics)
        Horario (Schedules)
```

### Personnel Hierarchy
```
Persona (Base Person)
    ├─ hasOne→ PerfilStaff (if staff)
    ├─ hasOne→ PerfilInstructor (if instructor)
    ├─ hasOne→ PerfilEstudiante (if student)
    └─ hasOne→ CuentaSistema (Login)
```

### Registration Workflow
```
SolicitudInscripcion (Request)
    ├─ belongsTo→ Persona (student)
    ├─ belongsTo→ ClienteExterno (external) [⚠️ MODEL MISSING]
    ├─ belongsTo→ CursoAbierto
    ├─ belongsTo→ Persona (validador/staff)
    ├─ hasOne→ Matricula (created after approval)
    └─ hasMany→ CuentaPorCobrar (payments)
```

---

## KEY FIELDS BY MODEL

| Model | Key Fields | Notes |
|-------|-----------|-------|
| **CatalogoCurso** | codigo (UNIQUE), nombre, modulos_default | Template |
| **CursoAbierto** | nombre_instancia, semestre, capacidad_maxima, precio_base | Instance |
| **Matricula** | estudiante_id, curso_abierto_id, estado, solicitud_inscripcion_id | Enrollment |
| **PerfilStaff** | cargo, salario_base, fecha_ingreso, es_pasante | Role |
| **SolicitudInscripcion** | estado, monto_solicitado, tipo_pago, comprobante_url | Payment |
| **CuentaPorCobrar** | monto_total, monto_abonado, estado | Billing |

---

## API ENDPOINTS

### Public (No Auth Required)
```
GET  /api/v1/catalogo-cursos/disponibles
POST /api/v1/registrations
```

### Staff Only
```
GET  /api/v1/staff/solicitudes-inscripcion
GET  /api/v1/staff/solicitudes-inscripcion/{id}
POST /api/v1/staff/solicitudes-inscripcion/{id}/validar
POST /api/v1/staff/solicitudes-inscripcion/{id}/rechazar
POST /api/v1/staff/solicitudes-inscripcion/{id}/cancelar
```

### Student Portal
```
GET  /api/v1/perfil/solicitudes-inscripcion
GET  /api/v1/perfil/cursos-completados
```

### Academic (Protected)
```
GET    /api/v1/academic/catalogos-cursos
POST   /api/v1/academic/catalogos-cursos
PUT    /api/v1/academic/catalogos-cursos/{id}
GET    /api/v1/academic/matriculas
POST   /api/v1/academic/matriculas
```

---

## ENROLLMENT STATES

```
SolicitudInscripcion Estados:
├─ registrado                    [Initial]
├─ pendiente_validacion          [Awaiting staff review]
├─ aprobado                      [Approved by staff]
├─ matricula_creada              [Enrollment created]
├─ rechazado                     [Rejected]
└─ cancelado                     [Cancelled]

Matricula Estados:
├─ activo                        [Currently enrolled]
├─ completado                    [Course finished]
├─ retirado                      [Student withdrew]
└─ reprobado                     [Failed]
```

---

## VALIDATION LAYERS

### 1. Form Requests
- `StoreCatalogoCursoRequest` - Create course
- `StoreMatriculaRequest` - Create enrollment
- `StoreRegistrationRequest` - Register for course
- `StorePerfilStaffRequest` - Create staff profile

### 2. Services
- `RegistrationValidationService` - Capacity, duplicates
- `PaymentVerificationService` - Payment proof validation
- `ScheduleConflictService` - Schedule conflicts

### 3. Database
- UNIQUE constraints on codigo, cedula
- FOREIGN KEY constraints
- CHECK constraints on enums
- Indexes for performance

---

## CRITICAL ISSUES

### 🔴 Priority 1: Missing ClienteExterno Model
- **File**: Should exist at `app/Models/ClienteExterno.php`
- **Table**: `people.clientes_externos` exists in DB
- **References**: 
  - `RegistrationController.php:9` (use statement)
  - `RegistrationController.php:47` (firstOrCreate)
  - `SolicitudInscripcion.php:85` (belongsTo)
- **Impact**: External registrations will crash
- **Fix**: Create model with fillable: nombres, apellidos, cedula, correo, celular, ciudad_id, observaciones

### 🟡 Priority 2: estudiantes_inscritos Inconsistency
- Referenced in capacity checks but not in $fillable
- Manually incremented during enrollment
- Risk of de-synchronization

### 🟡 Priority 3: Duplicate Participant Models
- `ClienteExterno` (registrations)
- `ParticipanteExterno` (workshops)
- Concept overlap

---

## UNUSED/LEGACY FIELDS

**CuentaPorCobrar** contains 8 unused FKs for legacy services:
- reserva_aula_id
- reserva_podcast_id
- servicio_streaming_id
- servicio_produccion_id
- edicion_video_id
- alquiler_equipo_id
- clase_extra_id
- asesoria_id

**CursoPersonalizado** has undefined fields:
- dirigido_a
- requisitos_especiales
- certificado_emitido
- costo_unitario

---

## FILE LOCATIONS (Absolute Paths)

**Models**:
```
/home/yorman/Documentos/GitHub/backend/app/Models/CatalogoCurso.php
/home/yorman/Documentos/GitHub/backend/app/Models/CursoAbierto.php
/home/yorman/Documentos/GitHub/backend/app/Models/Matricula.php
/home/yorman/Documentos/GitHub/backend/app/Models/SolicitudInscripcion.php
/home/yorman/Documentos/GitHub/backend/app/Models/PerfilStaff.php
/home/yorman/Documentos/GitHub/backend/app/Models/Persona.php
```

**Controllers**:
```
/home/yorman/Documentos/GitHub/backend/app/Http/Controllers/Api/V1/CatalogoCursoController.php
/home/yorman/Documentos/GitHub/backend/app/Http/Controllers/Api/V1/RegistrationController.php
/home/yorman/Documentos/GitHub/backend/app/Http/Controllers/Api/V1/StaffRegistrationController.php
/home/yorman/Documentos/GitHub/backend/app/Http/Controllers/Api/V1/MatriculaController.php
```

**Requests**:
```
/home/yorman/Documentos/GitHub/backend/app/Http/Requests/StoreCatalogoCursoRequest.php
/home/yorman/Documentos/GitHub/backend/app/Http/Requests/StoreRegistrationRequest.php
/home/yorman/Documentos/GitHub/backend/app/Http/Requests/StorePerfilStaffRequest.php
/home/yorman/Documentos/GitHub/backend/app/Http/Requests/StoreMatriculaRequest.php
```

**Services**:
```
/home/yorman/Documentos/GitHub/backend/app/Services/RegistrationStateService.php
/home/yorman/Documentos/GitHub/backend/app/Services/RegistrationValidationService.php
/home/yorman/Documentos/GitHub/backend/app/Services/PaymentVerificationService.php
/home/yorman/Documentos/GitHub/backend/app/Services/ScheduleConflictService.php
```

---

## QUICK VALIDATION REFERENCE

### Course Code (codigo)
```
Rule: required|string|max:50|unique:catalogo_cursos,codigo
Example: MAT101, ENG201, BIO305
```

### Course Registration (estudiantes_inscritos)
```
Validation: capacidad_maxima > estudiantes_inscritos
When: Student attempts enrollment
Service: RegistrationValidationService
```

### Staff Cargo (Position)
```
Rule: required|string|max:100
Examples: Director, Coordinador, Secretaria
```

### Payment Type
```
Enum: completo|abono
Rule: required|in:completo,abono
Comprobante: transferencia|deposito|efectivo|otro
```

---

## TESTING QUICK LINKS

**Models to test**:
- CatalogoCurso relationships and scopes
- SolicitudInscripcion state transitions
- Matricula grade calculations
- RegistrationValidationService validations

**Controllers to test**:
- RegistrationController::store (public)
- StaffRegistrationController::approve
- CatalogoCursoController::disponibles

**Missing tests**:
- ClienteExterno model operations
- Registration workflow end-to-end
- Payment verification edge cases

---

## RECOMMENDATIONS

1. **Create ClienteExterno model** (15 mins) - CRITICAL
2. **Add estudiantes_inscritos accessor** (30 mins) - HIGH
3. **Consolidate participant models** (2 hours) - MEDIUM
4. **Add missing tests** (3 hours) - MEDIUM
5. **Clean up legacy payment fields** (1 hour) - LOW

---

**Last Updated**: May 31, 2026
**Full Analysis**: See `BACKEND_ARCHITECTURE_ANALYSIS.md` (869 lines)
