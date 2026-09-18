<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRegistrationRequest;
use App\Models\SolicitudInscripcion;
use App\Models\CursoAbierto;
use App\Models\ClienteExterno;
use App\Models\Persona;
use App\Models\PerfilEstudiante;
use App\Services\RegistrationValidationService;
use App\Services\PaymentVerificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RegistrationController extends Controller
{
    private RegistrationValidationService $registrationValidator;
    private PaymentVerificationService $paymentVerifier;

    public function __construct(
        RegistrationValidationService $registrationValidator,
        PaymentVerificationService $paymentVerifier
    ) {
        $this->registrationValidator = $registrationValidator;
        $this->paymentVerifier = $paymentVerifier;
    }

    /**
     * POST /api/registrations
     * Crear una nueva solicitud de inscripción
     * 
     * @param StoreRegistrationRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreRegistrationRequest $request)
    {
        $validated = $request->validated();

        // 1. Determinar si es estudiante o participante externo con verificación de identidad
        $rawPersonaId = $validated['persona_id'] ?? null;
        $personaId = null;

        if (!empty($rawPersonaId)) {
            // Solo permitir vincular a un estudiante interno si está autenticado como ese estudiante o es personal autorizado
            $user = auth('sanctum')->user() ?: auth()->user();
            if ($user && ($user->persona_id === $rawPersonaId || $user->hasAnyRole(['Administrador', 'Secretaria']))) {
                $personaId = $rawPersonaId;
            }
        }

        $participanteExternoId = null;
        $esParticipanteExterno = false;
        $participanteExterno = null;
        $datosExterno = null;

        if (empty($personaId)) {
            // Es participante externo - registrar datos sin sobrescribir expedientes existentes
            $datosExterno = [
                'nombres' => $validated['nombres'] ?? '',
                'apellidos' => $validated['apellidos'] ?? '',
                'cedula' => $validated['cedula'] ?? null,
                'correo' => $validated['correo'] ?? null,
                'celular' => $validated['celular'] ?? null,
                'ocupacion' => $validated['ocupacion'] ?? null,
                'direccion' => $validated['direccion'] ?? null,
                'ciudad' => $validated['ciudad'] ?? null,
                'estado_civil' => $validated['estado_civil'] ?? null,
                'edad' => $validated['edad'] ?? null,
                'nivel_educativo' => $validated['nivel_educativo'] ?? null,
            ];

            $participanteExterno = ClienteExterno::where('correo', $validated['correo'])->first();
            $participanteExternoId = $participanteExterno?->id;
            $esParticipanteExterno = true;
        }

        // 2. Validar que el curso existe y está disponible
        $curso = CursoAbierto::find($validated['curso_abierto_id']);
        if (!$curso) {
            return response()->json([
                'mensaje' => 'El curso solicitado no existe',
                'errores' => ['El curso no fue encontrado'],
            ], Response::HTTP_NOT_FOUND);
        }

        // La validación no debe crear registros. Un UUID temporal permite
        // validar las reglas de identidad y capacidad antes de persistir al externo.
        $identificadorValidacion = $participanteExternoId ?? (string) Str::uuid();

        // 3. Validar registro (capacidad, duplicadas, etc.)
        $validacionRegistro = $this->registrationValidator->validar(
            $validated['curso_abierto_id'],
            $personaId,
            $participanteExternoId ?? $identificadorValidacion,
            $validated['monto_solicitado'],
            $validated['tipo_pago']
        );

        if (!$validacionRegistro['valido']) {
            return response()->json([
                'mensaje' => 'La solicitud de inscripción no cumple los requisitos',
                'errores' => $validacionRegistro['errores'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 4. Validar comprobante de pago
        $archivoUrl = $validated['archivo_comprobante_url'] ?? ($request->hasFile('archivo_comprobante') ? '/storage/temp-validation' : null);
        if ($archivoUrl) {
            $validacionPago = $this->paymentVerifier->validar(
                $archivoUrl,
                $validated['tipo_comprobante'],
                $validated['fecha_pago_declarada'],
                $validated['monto_solicitado'],
                $curso->precio_base
            );

            if (!$validacionPago['valido']) {
                return response()->json([
                    'mensaje' => 'El comprobante de pago no es válido',
                    'errores' => $validacionPago['errores'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if ($esParticipanteExterno && !$participanteExterno) {
            $participanteExterno = ClienteExterno::create($datosExterno);
            $participanteExternoId = $participanteExterno->id;
        }

        // --- VALIDACIONES APROBADAS: Proceder con la subida física de archivos ---
        if ($request->hasFile('archivo_comprobante') && empty($validated['archivo_comprobante_url'])) {
            $file = $request->file('archivo_comprobante');
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('comprobantes', $filename);
            $validated['archivo_comprobante_url'] = Storage::disk()->url($path);
        }

        if ($request->hasFile('archivo_cedula') && empty($validated['archivo_cedula_url'])) {
            $file = $request->file('archivo_cedula');
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('cedulas', $filename);
            $validated['archivo_cedula_url'] = Storage::disk()->url($path);
        }

        // 5. Crear la solicitud de inscripción
        $solicitud = SolicitudInscripcion::create([
            'persona_id' => $personaId,
            'participante_externo_id' => $participanteExternoId,
            'es_participante_externo' => $esParticipanteExterno,
            'curso_abierto_id' => $validated['curso_abierto_id'],
            'monto_solicitado' => $validated['monto_solicitado'],
            'tipo_pago' => $validated['tipo_pago'],
            'archivo_comprobante_url' => $validated['archivo_comprobante_url'] ?? null,
            'archivo_cedula_url' => $validated['archivo_cedula_url'] ?? null,
            'tipo_comprobante' => $validated['tipo_comprobante'],
            'fecha_pago_declarada' => $validated['fecha_pago_declarada'],
            'datos_declarados' => [
                'nombres' => $validated['nombres'] ?? null,
                'apellidos' => $validated['apellidos'] ?? null,
                'cedula' => $validated['cedula'] ?? null,
                'correo' => $validated['correo'] ?? null,
                'celular' => $validated['celular'] ?? null,
                'ocupacion' => $validated['ocupacion'] ?? null,
                'direccion' => $validated['direccion'] ?? null,
                'ciudad' => $validated['ciudad'] ?? null,
                'estado_civil' => $validated['estado_civil'] ?? null,
                'edad' => $validated['edad'] ?? null,
                'nivel_educativo' => $validated['nivel_educativo'] ?? null,
                'monto_declarado' => $validated['monto_declarado'] ?? $validated['monto_solicitado'] ?? null,
                'referencia_declarada' => $validated['referencia_declarada'] ?? $request->input('referencia_declarada'),
                'fecha_pago_declarada' => $validated['fecha_pago_declarada'] ?? null,
                'metodo_pago_declarado' => $validated['tipo_comprobante'] ?? $validated['tipo_pago'] ?? null,
            ],
            'estado' => SolicitudInscripcion::ESTADO_PENDIENTE_VALIDACION,
        ]);

        // Los datos declarados se conservan como propuesta en la solicitud sin modificar
        // el expediente del estudiante hasta que la administración los valide explícitamente.

        return response()->json([
            'mensaje' => 'Solicitud de inscripción registrada correctamente',
            'data' => $this->formatearSolicitud($solicitud),
            'recomendaciones' => $validacionPago['recomendaciones'] ?? [],
        ], Response::HTTP_CREATED);
    }

    /**
     * Formatear solicitud para respuesta
     */
    private function formatearSolicitud(SolicitudInscripcion $solicitud): array
    {
        return [
            'id' => $solicitud->id,
            'solicitante' => [
                'nombre' => $solicitud->obtenerNombreSolicitante(),
                'correo' => $solicitud->obtenerCorreoSolicitante(),
                'tipo' => $solicitud->esEstudiante() ? 'estudiante' : 'externo',
            ],
            'curso' => [
                'id' => $solicitud->cursoAbierto?->id,
                'nombre' => $solicitud->cursoAbierto?->nombre_instancia
                    ?: $solicitud->cursoAbierto?->catalogo?->nombre,
            ],
            'pago' => [
                'monto_solicitado' => $solicitud->monto_solicitado,
                'tipo_pago' => $solicitud->tipo_pago,
                'tipo_comprobante' => $solicitud->tipo_comprobante,
                'fecha_pago_declarada' => $solicitud->fecha_pago_declarada,
                'comprobante_url' => $solicitud->archivo_comprobante_url,
            ],
            'estado' => [
                'valor' => $solicitud->estado,
                'descripcion' => $solicitud->obtenerDescripcionEstado(),
            ],
            'fecha_registro' => $solicitud->created_at,
        ];
    }
}
