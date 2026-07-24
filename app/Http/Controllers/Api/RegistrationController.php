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

        // Procesar archivos adjuntos (si vienen en lugar de URLs)
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

        // 1. Determinar si es estudiante o participante externo
        $personaId = $validated['persona_id'] ?? null;
        $participanteExternoId = null;
        $esParticipanteExterno = false;

        if (empty($personaId)) {
            // Es participante externo - crear o buscar
            $participanteExterno = ClienteExterno::firstOrCreate(
                ['correo' => $validated['correo']],
                [
                    'nombres' => $validated['nombres'],
                    'apellidos' => $validated['apellidos'],
                    'cedula' => $validated['cedula'] ?? null,
                    'celular' => $validated['celular'] ?? null,
                    'ocupacion' => $validated['ocupacion'] ?? null,
                    'direccion' => $validated['direccion'] ?? null,
                    'ciudad' => $validated['ciudad'] ?? null,
                    'estado_civil' => $validated['estado_civil'] ?? null,
                    'fecha_nacimiento' => $validated['fecha_nacimiento'] ?? null,
                    'edad' => $validated['edad'] ?? null,
                ]
            );
            $participanteExternoId = $participanteExterno->id;
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

        // 3. Validar registro (capacidad, duplicadas, etc.)
        $validacionRegistro = $this->registrationValidator->validar(
            $validated['curso_abierto_id'],
            $personaId,
            $participanteExternoId,
            $validated['monto_solicitado'],
            $validated['tipo_pago']
        );

        if (!$validacionRegistro['valido']) {
            return response()->json([
                'mensaje' => 'La solicitud de inscripción no cumple los requisitos',
                'errores' => $validacionRegistro['errores'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 4. Validar comprobante de pago (si se envió archivo)
        $archivoUrl = $validated['archivo_comprobante_url'] ?? null;
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

        // 5. Crear la solicitud de inscripción
        $solicitud = SolicitudInscripcion::create([
            'persona_id' => $personaId,
            'participante_externo_id' => $participanteExternoId,
            'es_participante_externo' => $esParticipanteExterno,
            'curso_abierto_id' => $validated['curso_abierto_id'],
            'monto_solicitado' => $validated['monto_solicitado'],
            'tipo_pago' => $validated['tipo_pago'],
            'archivo_comprobante_url' => $archivoUrl,
            'archivo_cedula_url' => $validated['archivo_cedula_url'] ?? null,
            'tipo_comprobante' => $validated['tipo_comprobante'],
            'fecha_pago_declarada' => $validated['fecha_pago_declarada'],
            'estado' => SolicitudInscripcion::ESTADO_PENDIENTE_VALIDACION,
        ]);

        // 6. Si es estudiante interno, actualizar su perfil_estudiante con los datos enviados
        if (!empty($personaId)) {
            $perfil = PerfilEstudiante::firstOrNew(['persona_id' => $personaId]);
            $perfil->fill([
                'edad' => $validated['edad'] ?? $perfil->edad,
                'ocupacion' => $validated['ocupacion'] ?? $perfil->ocupacion,
                'direccion' => $validated['direccion'] ?? $perfil->direccion,
                'ciudad' => $validated['ciudad'] ?? $perfil->ciudad,
                'estado_civil' => $validated['estado_civil'] ?? $perfil->estado_civil,
                'fecha_nacimiento' => $validated['fecha_nacimiento'] ?? $perfil->fecha_nacimiento,
            ])->save();
        }

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
                'nombre' => $solicitud->cursoAbierto?->catalogo?->nombre,
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
