<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PerfilController;
use App\Http\Controllers\Estudiantes\EstudianteController;
use App\Http\Controllers\Api\V1\CatalogoCursoController;
use App\Http\Controllers\Api\V1\CursoAbiertoController;
use App\Http\Controllers\Api\V1\HorarioController;
use App\Http\Controllers\Api\V1\ModuloController;
use App\Http\Controllers\Api\V1\MatriculaController;
use App\Http\Controllers\Api\V1\NotaController;
use App\Http\Controllers\Api\V1\CambioHorarioController;
use App\Http\Controllers\Api\V1\TrasladoModuloController;
use App\Http\Controllers\Api\V1\BulkOperationsController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\TallerController;
use App\Http\Controllers\Api\V1\HorarioTallerController;
use App\Http\Controllers\Api\V1\InscripcionTallerController;
use App\Http\Controllers\Api\V1\ParticipanteExternoController;
use App\Http\Controllers\Api\V1\AsistenciaTallerController;
use App\Http\Controllers\Api\V1\CursoPersonalizadoController;
use App\Http\Controllers\Api\V1\FileUploadController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\StaffRegistrationController;
use App\Http\Controllers\Api\V1\StudentProfileController;
use App\Http\Controllers\Api\V1\CiudadController;
use App\Http\Controllers\Api\V1\PersonaController;
use App\Http\Controllers\Api\V1\InstructorController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\AsistenciaStaffController;
use App\Http\Controllers\Api\V1\HorasInstructorController;
use App\Http\Controllers\Api\V1\AulaController;
use App\Http\Controllers\Api\V1\CertificadoController;
use App\Http\Controllers\Api\V1\FileAccessController;
use App\Http\Controllers\Api\V1\ReservaAulaController;
use App\Http\Controllers\Api\V1\ClienteExternoController;
use App\Http\Controllers\Api\V1\EquipoController;
use App\Http\Controllers\Api\V1\AlquilerEquipoController;
use App\Http\Controllers\Api\V1\PaquetePodcastController;
use App\Http\Controllers\Api\V1\ReservaPodcastController;
use App\Http\Controllers\Api\V1\TrabajoEdicionController;
use App\Http\Controllers\Api\V1\InstructorPortalController;
use App\Http\Controllers\Api\V1\FinanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ========================================================================
    // PUBLIC ROUTES - REGISTRATION MODULE (FASE 12)
    // ========================================================================

    // Catálogo de cursos disponibles (público)
    Route::get('catalogo-cursos/disponibles', [CatalogoCursoController::class, 'disponibles'])
        ->name('catalogo-cursos.disponibles');

    // Crear nueva solicitud de inscripción (público)
    Route::post('registrations', [RegistrationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('registrations.store');

    // Upload de comprobante (público para matrícula)
    Route::post('upload/comprobante', [FileUploadController::class, 'uploadComprobante'])
        ->middleware('throttle:5,1')
        ->name('upload.comprobante');

    // Upload de cédula (público para matrícula)
    Route::post('upload/cedula', [FileUploadController::class, 'uploadCedula'])
        ->middleware('throttle:5,1')
        ->name('upload.cedula');

    // Catálogo de cursos (público, solo lectura)
    Route::get('catalogo-cursos', [CatalogoCursoController::class, 'index'])
        ->name('public.catalogo-cursos.index');

    // Cursos abiertos (público, solo lectura para matrícula)
    Route::get('cursos-abiertos', [CursoAbiertoController::class, 'index'])
        ->name('public.cursos-abiertos.index');

    // ========================================================================
    // PUBLIC ROUTES - CERTIFICADOS (VERIFICACIÓN PÚBLICA)
    // ========================================================================
    Route::get('verificar-certificados', [CertificadoController::class, 'verificarPorCedula'])
        ->name('public.verificar-certificados');
    Route::get('verificar-certificados/codigo/{codigo}', [CertificadoController::class, 'verificarPorCodigo'])
        ->name('public.verificar-certificados.codigo');
    Route::get('certificados/{id}/descargar', [CertificadoController::class, 'descargarPdf'])
        ->name('public.certificados.descargar');
    // ========================================================================

    // ========================================================================
    // PUBLIC ROUTES - CIUDADES (READ ONLY)
    // ========================================================================
    Route::get('ciudades/todas/sin-paginacion', [CiudadController::class, 'todas'])
        ->name('ciudades.todas');

    Route::prefix('auth')->group(function () {
        Route::post('iniciar-sesion', [AuthController::class, 'login'])->name('auth.iniciar-sesion');
        Route::post('cerrar-sesion', [AuthController::class, 'logout'])->middleware('auth:sanctum')->name('auth.cerrar-sesion');
    });

    Route::middleware('auth:sanctum')->prefix('perfil')->group(function () {
        Route::get('/', [PerfilController::class, 'mostrar'])->name('perfil.mostrar');
        Route::put('/', [PerfilController::class, 'actualizar'])->name('perfil.actualizar');

        // Descarga de archivos protegidos (cédulas, comprobantes)
        Route::get('archivos/{filename}', [FileAccessController::class, 'serve'])
            ->name('archivos.serve');
        
        // ========================================================================
        // STUDENT PROFILE - MY REGISTRATIONS (FASE 12)
        // ========================================================================
        Route::prefix('solicitudes-inscripcion')->group(function () {
            Route::get('/', [StudentProfileController::class, 'mySolicitudes'])->name('mi-perfil.solicitudes');
        });

        Route::prefix('cursos-completados')->group(function () {
            Route::get('/', [StudentProfileController::class, 'completedCourses'])->name('mi-perfil.cursos-completados');
        });
    });

    Route::middleware(['auth:sanctum', 'role:Administrador'])->prefix('personas/estudiantes')->group(function () {
        Route::get('/', [EstudianteController::class, 'index'])->name('estudiantes.index');
        Route::post('/', [EstudianteController::class, 'store'])->name('estudiantes.store');

        // ========================================================================
        // RUTAS NOMBRADAS (deben ir antes de {estudiante} parametrizado)
        // ========================================================================
        Route::get('stats', [EstudianteController::class, 'stats'])->name('estudiantes.stats');
        Route::get('segmentos', [EstudianteController::class, 'segments'])->name('estudiantes.segments');
        Route::post('segmentos', [EstudianteController::class, 'storeSegment'])->name('estudiantes.segments.store');
        Route::delete('segmentos/{segmento}', [EstudianteController::class, 'destroySegment'])->name('estudiantes.segments.destroy');
        Route::get('segmentos/{segmento}/students', [EstudianteController::class, 'segmentStudents'])->name('estudiantes.segments.students');
        Route::post('importar', [EstudianteController::class, 'importStudents'])->name('estudiantes.import');
        Route::post('importar/validar', [EstudianteController::class, 'validateImport'])->name('estudiantes.import.validate');
        Route::post('exportar', [EstudianteController::class, 'exportStudents'])->name('estudiantes.export');

        // ========================================================================
        // RUTAS PARAMETRIZADAS POR ESTUDIANTE
        // ========================================================================
        Route::get('{estudiante}/academic-profile', [EstudianteController::class, 'academicProfile'])->name('estudiantes.academic-profile');
        Route::get('{estudiante}/financial-profile', [EstudianteController::class, 'financialProfile'])->name('estudiantes.financial-profile');
        Route::get('{estudiante}', [EstudianteController::class, 'show'])->name('estudiantes.show');
        Route::put('{estudiante}', [EstudianteController::class, 'update'])->name('estudiantes.update');
        Route::delete('{estudiante}', [EstudianteController::class, 'destroy'])->name('estudiantes.destroy');
    });

    // ========================================================================
    // ACADEMIC MODULE - CURSOS REGULARES (FASE 3)
    // ========================================================================

    Route::middleware(['auth:sanctum', 'role:Administrador'])->prefix('academic')->group(function () {

        // CIUDADES (CRUD COMPLETO)
        Route::prefix('ciudades')->group(function () {
            Route::get('/', [CiudadController::class, 'index'])->name('ciudades.index');
            Route::post('/', [CiudadController::class, 'store'])->name('ciudades.store');
            Route::get('{id}', [CiudadController::class, 'show'])->name('ciudades.show');
            Route::put('{id}', [CiudadController::class, 'update'])->name('ciudades.update');
            Route::delete('{id}', [CiudadController::class, 'destroy'])->name('ciudades.destroy');
        });

        // ========================================================================
        // PERSONAS (ADMINISTRACIÓN DE PERSONAL)
        // ========================================================================
        Route::prefix('personas')->group(function () {
            Route::get('/', [PersonaController::class, 'index'])->name('personas.index');
            Route::post('completo', [PersonaController::class, 'storeCompleto'])->name('personas.store-completo');
            Route::post('/', [PersonaController::class, 'store'])->name('personas.store');
            Route::get('{id}', [PersonaController::class, 'show'])->name('personas.show');
            Route::put('{id}', [PersonaController::class, 'update'])->name('personas.update');
            Route::delete('{id}', [PersonaController::class, 'destroy'])->name('personas.destroy');
            Route::post('{id}/cuenta', [PersonaController::class, 'crearCuenta'])->name('personas.crear-cuenta');
            Route::put('{id}/cuenta', [PersonaController::class, 'actualizarCuenta'])->name('personas.actualizar-cuenta');
        });

        // INSTRUCTORES
        Route::prefix('instructores')->group(function () {
            Route::get('/', [InstructorController::class, 'index'])->name('instructores.index');
            Route::get('disponibles', [InstructorController::class, 'disponibles'])->name('instructores.disponibles');
            Route::get('{id}', [InstructorController::class, 'show'])->name('instructores.show');
            Route::post('{id}/perfil', [InstructorController::class, 'updatePerfil'])->name('instructores.update-perfil');
            Route::get('{id}/cursos', [InstructorController::class, 'cursos'])->name('instructores.cursos');
            Route::get('{id}/horas', [InstructorController::class, 'horas'])->name('instructores.horas');
         });

        // SOLICITUDES DE INSCRIPCIÓN (aprobación)
        Route::prefix('solicitudes-inscripcion')->group(function () {
            Route::get('/', [StaffRegistrationController::class, 'index'])->name('solicitudes-inscripcion.index');
            Route::get('{id}', [StaffRegistrationController::class, 'show'])->name('solicitudes-inscripcion.show');
            Route::post('{id}/validar', [StaffRegistrationController::class, 'approve'])->name('solicitudes-inscripcion.approve');
            Route::post('{id}/rechazar', [StaffRegistrationController::class, 'reject'])->name('solicitudes-inscripcion.reject');
            Route::post('{id}/cancelar', [StaffRegistrationController::class, 'cancel'])->name('solicitudes-inscripcion.cancel');
            Route::patch('{id}/actualizar-estudiante', [StaffRegistrationController::class, 'updateEstudiante'])->name('solicitudes-inscripcion.update-estudiante');
            Route::patch('{id}/actualizar-pago', [StaffRegistrationController::class, 'updatePago'])->name('solicitudes-inscripcion.update-pago');
            Route::patch('{id}/actualizar-curso', [StaffRegistrationController::class, 'updateCurso'])->name('solicitudes-inscripcion.update-curso');
            Route::post('{id}/cedula', [StaffRegistrationController::class, 'uploadCedula'])->name('solicitudes-inscripcion.cedula');
            Route::post('{id}/comprobante', [StaffRegistrationController::class, 'uploadComprobante'])->name('solicitudes-inscripcion.comprobante');
        });

        // STAFF
        Route::prefix('staff')->group(function () {
            Route::get('/', [StaffController::class, 'index'])->name('staff.index');
            Route::get('{id}', [StaffController::class, 'show'])->name('staff.show');
            Route::post('{id}/perfil', [StaffController::class, 'updatePerfil'])->name('staff.update-perfil');
            Route::get('{id}/asistencia', [StaffController::class, 'asistencia'])->name('staff.asistencia');
        });

        // ASISTENCIA STAFF
        Route::prefix('asistencia-staff')->group(function () {
            Route::get('/', [AsistenciaStaffController::class, 'index'])->name('asistencia-staff.index');
            Route::post('/', [AsistenciaStaffController::class, 'store'])->name('asistencia-staff.store');
            Route::put('{id}', [AsistenciaStaffController::class, 'update'])->name('asistencia-staff.update');
            Route::delete('{id}', [AsistenciaStaffController::class, 'destroy'])->name('asistencia-staff.destroy');
        });

        // HORAS INSTRUCTOR
        Route::prefix('horas-instructor')->group(function () {
            Route::get('/', [HorasInstructorController::class, 'index'])->name('horas-instructor.index');
            Route::post('/', [HorasInstructorController::class, 'store'])->name('horas-instructor.store');
            Route::put('{id}', [HorasInstructorController::class, 'update'])->name('horas-instructor.update');
            Route::delete('{id}', [HorasInstructorController::class, 'destroy'])->name('horas-instructor.destroy');
            Route::post('bulk/pagar', [HorasInstructorController::class, 'bulkPagar'])->name('horas-instructor.bulk-pagar');
        });

        // CATALOGO CURSOS
        Route::prefix('catalogos-cursos')->group(function () {
            Route::get('/', [CatalogoCursoController::class, 'index'])->name('catalogos.index');
            Route::post('/', [CatalogoCursoController::class, 'store'])->name('catalogos.store');
            Route::post('upload-imagen', [CatalogoCursoController::class, 'uploadImagen'])->name('catalogos.upload-imagen');
            Route::get('{id}', [CatalogoCursoController::class, 'show'])->name('catalogos.show');
            Route::put('{id}', [CatalogoCursoController::class, 'update'])->name('catalogos.update');
            Route::delete('{id}', [CatalogoCursoController::class, 'destroy'])->name('catalogos.destroy');
        });

        // CURSOS ABIERTOS
        Route::prefix('cursos-abiertos')->group(function () {
            Route::get('/', [CursoAbiertoController::class, 'index'])->name('cursos-abiertos.index');
            Route::post('/', [CursoAbiertoController::class, 'store'])->name('cursos-abiertos.store');
            Route::get('{id}', [CursoAbiertoController::class, 'show'])->name('cursos-abiertos.show');
            Route::put('{id}', [CursoAbiertoController::class, 'update'])->name('cursos-abiertos.update');
            Route::delete('{id}', [CursoAbiertoController::class, 'destroy'])->name('cursos-abiertos.destroy');
            Route::get('{id}/horarios', [CursoAbiertoController::class, 'horarios'])->name('cursos-abiertos.horarios');
            Route::get('{id}/matriculas', [CursoAbiertoController::class, 'matriculas'])->name('cursos-abiertos.matriculas');
            Route::get('{id}/modulos', [CursoAbiertoController::class, 'modulos'])->name('cursos-abiertos.modulos');
            Route::get('{id}/estadisticas', [CursoAbiertoController::class, 'estadisticas'])->name('cursos-abiertos.estadisticas');
        });

        // HORARIOS
        Route::prefix('horarios')->group(function () {
            Route::get('/', [HorarioController::class, 'index'])->name('horarios.index');
            Route::post('/', [HorarioController::class, 'store'])->name('horarios.store');
            Route::get('{id}', [HorarioController::class, 'show'])->name('horarios.show');
            Route::put('{id}', [HorarioController::class, 'update'])->name('horarios.update');
            Route::delete('{id}', [HorarioController::class, 'destroy'])->name('horarios.destroy');
            Route::get('{id}/matriculas', [HorarioController::class, 'matriculas'])->name('horarios.matriculas');
            Route::get('{id}/descripcion', [HorarioController::class, 'descripcion'])->name('horarios.descripcion');
        });

        // MODULOS
        Route::prefix('modulos')->group(function () {
            Route::get('/', [ModuloController::class, 'index'])->name('modulos.index');
            Route::post('/', [ModuloController::class, 'store'])->name('modulos.store');
            Route::get('{id}', [ModuloController::class, 'show'])->name('modulos.show');
            Route::put('{id}', [ModuloController::class, 'update'])->name('modulos.update');
            Route::delete('{id}', [ModuloController::class, 'destroy'])->name('modulos.destroy');
            Route::get('{id}/notas', [ModuloController::class, 'notas'])->name('modulos.notas');
            Route::get('{id}/estadisticas', [ModuloController::class, 'estadisticas'])->name('modulos.estadisticas');
        });

        // SERVICIOS - AULAS
        Route::prefix('servicios/aulas')->group(function () {
            Route::get('/', [AulaController::class, 'index'])->name('aulas.index');
            Route::post('/', [AulaController::class, 'store'])->name('aulas.store');
            Route::get('{id}', [AulaController::class, 'show'])->name('aulas.show');
            Route::put('{id}', [AulaController::class, 'update'])->name('aulas.update');
            Route::delete('{id}', [AulaController::class, 'destroy'])->name('aulas.destroy');
        });

        // SERVICIOS - RESERVAS DE AULAS
        Route::prefix('servicios/reservas-aulas')->group(function () {
            Route::get('/', [ReservaAulaController::class, 'index'])->name('reservas-aulas.index');
            Route::post('/', [ReservaAulaController::class, 'store'])->name('reservas-aulas.store');
            Route::get('{id}', [ReservaAulaController::class, 'show'])->name('reservas-aulas.show');
            Route::put('{id}', [ReservaAulaController::class, 'update'])->name('reservas-aulas.update');
            Route::delete('{id}', [ReservaAulaController::class, 'destroy'])->name('reservas-aulas.destroy');
        });

        // SERVICIOS - CLIENTES EXTERNOS
        Route::prefix('servicios/clientes-externos')->group(function () {
            Route::get('/', [ClienteExternoController::class, 'index'])->name('clientes-externos.index');
            Route::post('/', [ClienteExternoController::class, 'store'])->name('clientes-externos.store');
            Route::get('{id}', [ClienteExternoController::class, 'show'])->name('clientes-externos.show');
            Route::post('buscar-cedula', [ClienteExternoController::class, 'buscarCedula'])
                ->middleware('throttle:5,1')
                ->name('clientes-externos.buscar-cedula');
        });

        // SERVICIOS - EQUIPOS
        Route::prefix('servicios/equipos')->group(function () {
            Route::get('/', [EquipoController::class, 'index'])->name('equipos.index');
            Route::post('/', [EquipoController::class, 'store'])->name('equipos.store');
            Route::get('{id}', [EquipoController::class, 'show'])->name('equipos.show');
            Route::match(['put', 'post'], '{id}', [EquipoController::class, 'update'])->name('equipos.update');
            Route::delete('{id}', [EquipoController::class, 'destroy'])->name('equipos.destroy');
        });

        // SERVICIOS - ALQUILERES DE EQUIPOS
        Route::prefix('servicios/alquileres-equipos')->group(function () {
            Route::get('/', [AlquilerEquipoController::class, 'index'])->name('alquileres-equipos.index');
            Route::post('/', [AlquilerEquipoController::class, 'store'])->name('alquileres-equipos.store');
            Route::get('vencidos', [AlquilerEquipoController::class, 'vencidos'])->name('alquileres-equipos.vencidos');
            Route::post('{id}/entregar', [AlquilerEquipoController::class, 'entregar'])->name('alquileres-equipos.entregar');
            Route::get('{id}', [AlquilerEquipoController::class, 'show'])->name('alquileres-equipos.show');
            Route::post('{id}/devolver', [AlquilerEquipoController::class, 'devolver'])->name('alquileres-equipos.devolver');
            Route::delete('{id}', [AlquilerEquipoController::class, 'destroy'])->name('alquileres-equipos.destroy');
        });

        // SERVICIOS - PAQUETES PODCAST
        Route::prefix('servicios/paquetes-podcast')->group(function () {
            Route::get('/', [PaquetePodcastController::class, 'index'])->name('paquetes-podcast.index');
            Route::post('/', [PaquetePodcastController::class, 'store'])->name('paquetes-podcast.store');
            Route::get('{id}', [PaquetePodcastController::class, 'show'])->name('paquetes-podcast.show');
            Route::put('{id}', [PaquetePodcastController::class, 'update'])->name('paquetes-podcast.update');
            Route::delete('{id}', [PaquetePodcastController::class, 'destroy'])->name('paquetes-podcast.destroy');
        });

        // SERVICIOS - RESERVAS PODCAST
        Route::prefix('servicios/reservas-podcast')->group(function () {
            Route::get('/', [ReservaPodcastController::class, 'index'])->name('reservas-podcast.index');
            Route::post('/', [ReservaPodcastController::class, 'store'])->name('reservas-podcast.store');
            Route::get('{id}', [ReservaPodcastController::class, 'show'])->name('reservas-podcast.show');
            Route::put('{id}', [ReservaPodcastController::class, 'update'])->name('reservas-podcast.update');
            Route::delete('{id}', [ReservaPodcastController::class, 'destroy'])->name('reservas-podcast.destroy');
            Route::post('{id}/pago', [ReservaPodcastController::class, 'registrarPago'])->name('reservas-podcast.pago');
        });

        // SERVICIOS - TRABAJOS EDICION DE VIDEO
        Route::prefix('servicios/trabajos-edicion')->group(function () {
            Route::get('/', [TrabajoEdicionController::class, 'index'])->name('trabajos-edicion.index');
            Route::post('/', [TrabajoEdicionController::class, 'store'])->name('trabajos-edicion.store');
            Route::get('{id}', [TrabajoEdicionController::class, 'show'])->name('trabajos-edicion.show');
            Route::put('{id}', [TrabajoEdicionController::class, 'update'])->name('trabajos-edicion.update');
            Route::delete('{id}', [TrabajoEdicionController::class, 'destroy'])->name('trabajos-edicion.destroy');
            Route::post('{id}/entregar', [TrabajoEdicionController::class, 'registrarEntrega'])->name('trabajos-edicion.entregar');
            Route::post('{id}/cobro', [TrabajoEdicionController::class, 'registrarCobro'])->name('trabajos-edicion.cobro');
        });

        // MATRICULAS
        Route::prefix('matriculas')->group(function () {
            Route::get('/', [MatriculaController::class, 'index'])->name('matriculas.index');
            Route::post('/', [MatriculaController::class, 'store'])->name('matriculas.store');
            Route::get('{id}', [MatriculaController::class, 'show'])->name('matriculas.show');
            Route::put('{id}', [MatriculaController::class, 'update'])->name('matriculas.update');
            Route::delete('{id}', [MatriculaController::class, 'destroy'])->name('matriculas.destroy');
            Route::get('{id}/notas', [MatriculaController::class, 'notas'])->name('matriculas.notas');
            Route::get('{id}/calificaciones', [MatriculaController::class, 'calificaciones'])->name('matriculas.calificaciones');
            Route::get('{id}/cambios-horario', [MatriculaController::class, 'cambiosHorario'])->name('matriculas.cambios-horario');
        });

        // NOTAS
        Route::prefix('notas')->group(function () {
            Route::get('/', [NotaController::class, 'index'])->name('notas.index');
            Route::post('/', [NotaController::class, 'store'])->name('notas.store');
            Route::get('{id}', [NotaController::class, 'show'])->name('notas.show');
            Route::put('{id}', [NotaController::class, 'update'])->name('notas.update');
            Route::delete('{id}', [NotaController::class, 'destroy'])->name('notas.destroy');
            Route::get('{id}/descriptiva', [NotaController::class, 'descriptiva'])->name('notas.descriptiva');
        });

        // CAMBIOS HORARIO
        Route::prefix('cambios-horario')->group(function () {
            Route::get('/', [CambioHorarioController::class, 'index'])->name('cambios-horario.index');
            Route::post('/', [CambioHorarioController::class, 'store'])->name('cambios-horario.store');
            Route::get('{id}', [CambioHorarioController::class, 'show'])->name('cambios-horario.show');
            Route::put('{id}', [CambioHorarioController::class, 'update'])->name('cambios-horario.update');
            Route::delete('{id}', [CambioHorarioController::class, 'destroy'])->name('cambios-horario.destroy');
            Route::post('{id}/aprobar', [CambioHorarioController::class, 'aprobar'])->name('cambios-horario.aprobar');
            Route::post('{id}/rechazar', [CambioHorarioController::class, 'rechazar'])->name('cambios-horario.rechazar');
            Route::post('{id}/completar', [CambioHorarioController::class, 'completar'])->name('cambios-horario.completar');
        });

        // TRASLADOS MODULO
        Route::prefix('traslados-modulo')->group(function () {
            Route::get('/', [TrasladoModuloController::class, 'index'])->name('traslados-modulo.index');
            Route::post('/', [TrasladoModuloController::class, 'store'])->name('traslados-modulo.store');
            Route::get('{id}', [TrasladoModuloController::class, 'show'])->name('traslados-modulo.show');
            Route::put('{id}', [TrasladoModuloController::class, 'update'])->name('traslados-modulo.update');
            Route::delete('{id}', [TrasladoModuloController::class, 'destroy'])->name('traslados-modulo.destroy');
            Route::post('{id}/aprobar', [TrasladoModuloController::class, 'aprobar'])->name('traslados-modulo.aprobar');
            Route::post('{id}/rechazar', [TrasladoModuloController::class, 'rechazar'])->name('traslados-modulo.rechazar');
            Route::post('{id}/completar', [TrasladoModuloController::class, 'completar'])->name('traslados-modulo.completar');
        });

        // ========================================================================
        // BULK OPERATIONS (FASE 7)
        // ========================================================================

        Route::prefix('bulk')->middleware('throttle:10,1')->group(function () {
            Route::post('notas/register', [BulkOperationsController::class, 'bulkRegisterNotas'])->name('bulk.notas.register');
            Route::post('notas/update', [BulkOperationsController::class, 'bulkUpdateNotas'])->name('bulk.notas.update');
            Route::post('notas/delete', [BulkOperationsController::class, 'bulkDeleteNotas'])->name('bulk.notas.delete');
            Route::post('matriculas/cambiar-estado', [BulkOperationsController::class, 'bulkChangeMatriculasStatus'])->name('bulk.matriculas.cambiar-estado');
        });

        // ========================================================================
        // WORKSHOPS (FASE 10)
        // ========================================================================

        Route::prefix('talleres')->group(function () {
            Route::get('/', [TallerController::class, 'index'])->name('talleres.index');
            Route::post('/', [TallerController::class, 'store'])->name('talleres.store');
            Route::get('{id}', [TallerController::class, 'show'])->name('talleres.show');
            Route::put('{id}', [TallerController::class, 'update'])->name('talleres.update');
            Route::delete('{id}', [TallerController::class, 'destroy'])->name('talleres.destroy');
            Route::get('{id}/estadisticas', [TallerController::class, 'estadisticas'])->name('talleres.estadisticas');
            Route::post('cambiar-estado-masivo', [TallerController::class, 'cambiarEstadoMasivo'])->name('talleres.cambiar-estado-masivo');
        });

        Route::prefix('talleres/{taller_id}/horarios')->group(function () {
            Route::get('/', [HorarioTallerController::class, 'index'])->name('horarios-talleres.index');
            Route::post('/', [HorarioTallerController::class, 'store'])->name('horarios-talleres.store');
            Route::get('{id}', [HorarioTallerController::class, 'show'])->name('horarios-talleres.show');
            Route::put('{id}', [HorarioTallerController::class, 'update'])->name('horarios-talleres.update');
            Route::delete('{id}', [HorarioTallerController::class, 'destroy'])->name('horarios-talleres.destroy');
        });

        Route::prefix('talleres/{taller_id}/inscripciones')->group(function () {
            Route::get('estudiantes', [InscripcionTallerController::class, 'indexEstudiantes'])->name('inscripciones-talleres.estudiantes.index');
            Route::post('estudiantes', [InscripcionTallerController::class, 'storeEstudiante'])->name('inscripciones-talleres.estudiantes.store');
            Route::get('externos', [InscripcionTallerController::class, 'indexExternos'])->name('inscripciones-talleres.externos.index');
            Route::post('externos', [InscripcionTallerController::class, 'storeExterno'])->name('inscripciones-talleres.externos.store');
        });

        Route::prefix('inscripciones-talleres')->group(function () {
            Route::get('estudiantes/{id}', [InscripcionTallerController::class, 'showEstudiante'])->name('inscripciones-talleres.estudiantes.show');
            Route::put('estudiantes/{id}', [InscripcionTallerController::class, 'updateEstadoEstudiante'])->name('inscripciones-talleres.estudiantes.update');
            Route::delete('estudiantes/{id}', [InscripcionTallerController::class, 'destroyEstudiante'])->name('inscripciones-talleres.estudiantes.destroy');
            Route::get('externos/{id}', [InscripcionTallerController::class, 'showExterno'])->name('inscripciones-talleres.externos.show');
            Route::put('externos/{id}', [InscripcionTallerController::class, 'updateEstadoExterno'])->name('inscripciones-talleres.externos.update');
            Route::delete('externos/{id}', [InscripcionTallerController::class, 'destroyExterno'])->name('inscripciones-talleres.externos.destroy');
        });

        Route::prefix('participantes-externos')->group(function () {
            Route::get('/', [ParticipanteExternoController::class, 'index'])->name('participantes-externos.index');
            Route::post('/', [ParticipanteExternoController::class, 'store'])->name('participantes-externos.store');
            Route::get('{id}', [ParticipanteExternoController::class, 'show'])->name('participantes-externos.show');
            Route::put('{id}', [ParticipanteExternoController::class, 'update'])->name('participantes-externos.update');
            Route::delete('{id}', [ParticipanteExternoController::class, 'destroy'])->name('participantes-externos.destroy');
        });

        Route::prefix('talleres/{taller_id}/asistencias')->group(function () {
            Route::get('/', [AsistenciaTallerController::class, 'index'])->name('asistencias-talleres.index');
            Route::post('/', [AsistenciaTallerController::class, 'store'])->name('asistencias-talleres.store');
            Route::get('estadisticas', [AsistenciaTallerController::class, 'estadisticas'])->name('asistencias-talleres.estadisticas');
            Route::get('{id}', [AsistenciaTallerController::class, 'show'])->name('asistencias-talleres.show');
            Route::put('{id}', [AsistenciaTallerController::class, 'update'])->name('asistencias-talleres.update');
            Route::delete('{id}', [AsistenciaTallerController::class, 'destroy'])->name('asistencias-talleres.destroy');
        });

        // ========================================================================
        // PERSONALIZED COURSES (FASE 11)
        // ========================================================================

        Route::prefix('cursos-personalizados')->group(function () {
            Route::get('/', [CursoPersonalizadoController::class, 'index'])->name('cursos-personalizados.index');
            Route::post('/', [CursoPersonalizadoController::class, 'store'])->name('cursos-personalizados.store');
            Route::get('{id}', [CursoPersonalizadoController::class, 'show'])->name('cursos-personalizados.show');
            Route::put('{id}', [CursoPersonalizadoController::class, 'update'])->name('cursos-personalizados.update');
            Route::delete('{id}', [CursoPersonalizadoController::class, 'destroy'])->name('cursos-personalizados.destroy');
            Route::get('{id}/estadisticas', [CursoPersonalizadoController::class, 'estadisticas'])->name('cursos-personalizados.estadisticas');
            Route::get('{id}/estudiantes', [CursoPersonalizadoController::class, 'estudiantes'])->name('cursos-personalizados.estudiantes');
            Route::get('{id}/participantes-externos', [CursoPersonalizadoController::class, 'participantesExternos'])->name('cursos-personalizados.participantes-externos');
            Route::post('{id}/participantes-externos/inscribir', [CursoPersonalizadoController::class, 'inscribirExterno'])->name('cursos-personalizados.inscribir-externo');
            Route::put('{id}/participantes-externos/{participante_id}', [CursoPersonalizadoController::class, 'actualizarEstadoExterno'])->name('cursos-personalizados.actualizar-externo');
            Route::delete('{id}/participantes-externos/{participante_id}', [CursoPersonalizadoController::class, 'desinscribirExterno'])->name('cursos-personalizados.desinscribir-externo');
            Route::get('{id}/modulos', [CursoPersonalizadoController::class, 'modulos'])->name('cursos-personalizados.modulos');
            Route::get('{id}/horarios', [CursoPersonalizadoController::class, 'horarios'])->name('cursos-personalizados.horarios');
        });

        // ========================================================================
        // STAFF MANAGEMENT - REGISTRATION VALIDATION (FASE 12)
        // ========================================================================

        // ========================================================================
        // EXPORT (FASE 7)
        // ========================================================================

        Route::prefix('export')->group(function () {
            Route::post('/', [ExportController::class, 'export'])->name('export.export');
            Route::get('template-csv', [ExportController::class, 'downloadCsvTemplate'])->name('export.template-csv');
            Route::get('formatos-disponibles', [ExportController::class, 'formatosDisponibles'])->name('export.formatos-disponibles');
        });

        // ========================================================================
        // REPORTS (FASE 7)
        // ========================================================================
// REPORTES (FASE 7)
Route::prefix('reports')->group(function () {
    Route::get('asistencia', [ReportController::class, 'reporteAsistencia'])->name('reports.asistencia');
    Route::get('desempeño', [ReportController::class, 'reporteDesempeño'])->name('reports.desempeño');
    Route::get('progreso', [ReportController::class, 'reporteProgreso'])->name('reports.progreso');
    Route::get('resumen-academico', [ReportController::class, 'resumenAcademico'])->name('reports.resumen-academico');
    Route::get('tipos-disponibles', [ReportController::class, 'tiposDisponibles'])->name('reports.tipos-disponibles');
    Route::get('comparativa-estudiantes', [ReportController::class, 'comparativaEstudiantes'])->name('reports.comparativa-estudiantes');
});

        // ========================================================================
        // CERTIFICADOS (GESTIÓN ADMIN)
        // ========================================================================
        Route::prefix('certificados')->group(function () {
            Route::get('/', [CertificadoController::class, 'index'])->name('certificados.index');
            Route::get('panel-estudiantes', [CertificadoController::class, 'panelEstudiantes'])->name('certificados.panel-estudiantes');
            Route::get('estudiantes', [CertificadoController::class, 'buscarEstudiantes'])->name('certificados.buscar-estudiantes');
            Route::post('bulk', [CertificadoController::class, 'bulkStore'])->name('certificados.bulk-store');
            Route::post('/', [CertificadoController::class, 'store'])->name('certificados.store');
            Route::get('{id}', [CertificadoController::class, 'show'])->name('certificados.show');
            Route::post('{id}/pdf', [CertificadoController::class, 'uploadPdf'])->name('certificados.upload-pdf');
            Route::delete('{id}/pdf', [CertificadoController::class, 'removePdf'])->name('certificados.remove-pdf');
            Route::patch('{id}/entregar', [CertificadoController::class, 'marcarEntregado'])->name('certificados.marcar-entregado');
            Route::delete('{id}', [CertificadoController::class, 'destroy'])->name('certificados.destroy');
        });
    }); // FIN PREFIX ACADEMIC

    // ========================================================================
    // PORTAL DEL INSTRUCTOR
    // ========================================================================
    Route::middleware(['auth:sanctum', 'role:Administrador|Instructor'])->prefix('instructor')->group(function () {
        Route::get('mis-cursos', [InstructorPortalController::class, 'misCursos'])->name('instructor.mis-cursos');
        Route::get('cursos/{id}', [InstructorPortalController::class, 'detalleCurso'])->name('instructor.detalle-curso');
        Route::get('cursos/{id}/estudiantes', [InstructorPortalController::class, 'estudiantesCurso'])->name('instructor.estudiantes-curso');
        Route::get('modulos/{moduloId}/clases', [InstructorPortalController::class, 'clasesModulo'])->name('instructor.clases-modulo');
        Route::get('clases/{claseId}', [InstructorPortalController::class, 'detalleClase'])->name('instructor.detalle-clase');
        Route::post('clases/{claseId}/asistencia', [InstructorPortalController::class, 'registrarAsistencia'])->name('instructor.registrar-asistencia');
        Route::post('notas', [InstructorPortalController::class, 'registrarNotas'])->name('instructor.registrar-notas');
    });

    // ========================================================================
    // FINANCE MODULE (CENTRO DE PAGOS)
    // ========================================================================
    Route::middleware(['auth:sanctum', 'role:Administrador'])->prefix('finanzas')->group(function () {
        Route::get('resumen', [FinanceController::class, 'getResumen'])->name('finance.resumen');
        Route::get('cuentas', [FinanceController::class, 'getCuentas'])->name('finance.cuentas');
        Route::get('cuentas/{id}', [FinanceController::class, 'getCuentaDetalle'])->name('finance.cuenta-detalle');
        Route::post('pagos', [FinanceController::class, 'registrarPago'])->name('finance.registrar-pago');
        Route::get('transacciones', [FinanceController::class, 'getTransacciones'])->name('finance.transacciones');
        Route::post('transacciones/{id}/verificar', [FinanceController::class, 'verificarTransaccion'])->name('finance.verificar-transaccion');
    });
});
