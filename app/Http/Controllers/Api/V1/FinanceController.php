<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CuentaPorCobrar;
use App\Models\TransaccionIngreso;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    public function getCuentas(Request $request): JsonResponse
    {
        $query = CuentaPorCobrar::with([
            'matricula.estudiante',
            'matricula.cursoAbierto.catalogo',
            'solicitudInscripcion.estudiante',
            'solicitudInscripcion.participanteExterno',
            'solicitudInscripcion.cursoAbierto.catalogo',
            'inscripcionTaller.participanteExterno',
            'inscripcionTaller.taller'
        ]);

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->has('search')) {
            $term = $request->search;
            $query->where(function($q) use ($term) {
                $q->whereHas('matricula.estudiante', fn($sq) => $sq->where('nombres', 'ilike', "%$term%")->orWhere('apellidos', 'ilike', "%$term%"))
                  ->orWhereHas('solicitudInscripcion.estudiante', fn($sq) => $sq->where('nombres', 'ilike', "%$term%")->orWhere('apellidos', 'ilike', "%$term%"))
                  ->orWhereHas('solicitudInscripcion.participanteExterno', fn($sq) => $sq->where('nombres', 'ilike', "%$term%")->orWhere('apellidos', 'ilike', "%$term%"));
            });
        }

        if ($request->has('origen')) {
            $origen = $request->origen;
            if ($origen === 'curso') {
                $query->where(function($q) {
                    $q->whereNotNull('matricula_id')->orWhereNotNull('solicitud_inscripcion_id');
                });
            } elseif ($origen === 'taller') {
                $query->whereNotNull('inscripcion_taller_id');
            } elseif ($origen === 'servicio') {
                $query->whereNotNull('reserva_aula_id')
                      ->orWhereNotNull('reserva_podcast_id')
                      ->orWhereNotNull('alquiler_equipo_id');
            }
        }

        $cuentas = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return response()->json($cuentas);
    }

    public function getCuentaDetalle($id): JsonResponse
    {
        $cuenta = CuentaPorCobrar::with([
            'matricula.estudiante',
            'matricula.cursoAbierto.catalogo',
            'solicitudInscripcion.estudiante',
            'solicitudInscripcion.participanteExterno',
            'solicitudInscripcion.cursoAbierto.catalogo',
            'inscripcionTaller.participanteExterno',
            'inscripcionTaller.taller',
        ])->findOrFail($id);

        $transacciones = TransaccionIngreso::where('cuenta_cobrar_id', $id)
            ->orderBy('fecha_pago', 'desc')
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'monto' => (float) $t->monto,
                'metodo_pago' => $t->metodo_pago,
                'comprobante_url' => $t->comprobante_url,
                'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                'estado_verificacion' => $t->estado_verificacion,
                'observaciones' => $t->observaciones,
            ])
            ->values();

        return response()->json([
            'datos' => $cuenta,
            'transacciones' => $transacciones
        ]);
    }

    public function registrarPago(Request $request): JsonResponse
    {
        $request->validate([
            'cuenta_cobrar_id' => [
                'required',
                'uuid',
                Rule::exists('pgsql.finance.cuentas_por_cobrar', 'id'),
            ],
            'monto' => 'required|numeric|min:0.01',
            'metodo_pago' => 'required|string',
            'comprobante_url' => 'nullable|string',
            'fecha_pago' => 'nullable|date',
            'observaciones' => 'nullable|string'
        ]);

        DB::beginTransaction();
        try {
            $cuenta = CuentaPorCobrar::lockForUpdate()->find($request->cuenta_cobrar_id);
            
            $saldo = $cuenta->monto_total - $cuenta->monto_abonado;
            if ($request->monto > ($saldo + 0.01)) { 
                return response()->json(['mensaje' => 'El monto ($' . $request->monto . ') supera el saldo pendiente ($' . $saldo . ')'], 422);
            }

            $transaccion = TransaccionIngreso::create([
                'cuenta_cobrar_id' => $cuenta->id,
                'monto' => $request->monto,
                'metodo_pago' => $request->metodo_pago,
                'comprobante_url' => $request->comprobante_url,
                'fecha_pago' => $request->fecha_pago ?? now(),
                'registrado_por' => auth()->user()->persona_id ?? null,
                'observaciones' => $request->observaciones,
                'estado_verificacion' => 'pendiente'
            ]);

            DB::commit();
            Cache::forget('finance.resumen');
            return response()->json([
                'mensaje' => 'Pago registrado correctamente',
                'datos' => $transaccion
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error registrando pago: " . $e->getMessage());
            return response()->json(['mensaje' => 'Error al registrar el pago: ' . $e->getMessage()], 500);
        }
    }

    public function getTransacciones(Request $request): JsonResponse
    {
        $query = TransaccionIngreso::with([
            'cuentaPorCobrar.matricula.estudiante',
            'cuentaPorCobrar.solicitudInscripcion.estudiante',
            'cuentaPorCobrar.solicitudInscripcion.participanteExterno',
            'registrador:id,nombres,apellidos',
            'verificador:id,nombres,apellidos'
        ]);

        if ($request->has('estado_verificacion')) {
            $query->where('estado_verificacion', $request->estado_verificacion);
        }

        $transacciones = $query->orderBy('fecha_pago', 'desc')->paginate($request->get('per_page', 15));
        return response()->json($transacciones);
    }

    public function verificarTransaccion(Request $request, $id): JsonResponse
    {
        $request->validate([
            'estado' => 'required|in:aprobado,rechazado',
            'motivo_rechazo' => 'required_if:estado,rechazado|nullable|string',
            'observaciones' => 'nullable|string'
        ]);

        $transaccion = TransaccionIngreso::findOrFail($id);
        
        if ($transaccion->estado_verificacion !== 'pendiente') {
            return response()->json(['mensaje' => 'Esta transacción ya ha sido verificada'], 422);
        }

        $transaccion->update([
            'estado_verificacion' => $request->estado,
            'motivo_rechazo' => $request->motivo_rechazo,
            'observaciones' => $request->observaciones ?? $transaccion->observaciones,
            'verificado_por' => auth()->user()->persona_id ?? null,
            'fecha_verificacion' => now()
        ]);

        return response()->json([
            'mensaje' => 'Transacción ' . ($request->estado === 'aprobado' ? 'aprobada' : 'rechazada') . ' correctamente',
            'datos' => $transaccion
        ]);
    }

    public function getResumen(): JsonResponse
    {
        $stats = Cache::remember('finance.resumen', now()->addMinutes(5), function () {
            $base = CuentaPorCobrar::whereIn('estado', ['pendiente', 'abonado']);

            $saldoDeudor = fn($q) => $q->get()->sum(fn($c) => $c->monto_total - $c->monto_abonado);

            return [
                'total_pendiente' => $saldoDeudor(clone $base),
                'total_cobrado' => DB::table('finance.transacciones_ingreso')->where('estado_verificacion', 'aprobado')->sum('monto'),
                'pendientes_verificacion' => TransaccionIngreso::where('estado_verificacion', 'pendiente')->count(),
                'cuentas_con_deuda' => CuentaPorCobrar::whereIn('estado', ['pendiente', 'abonado'])->count(),
                'distribucion' => [
                    'cursos' => $saldoDeudor((clone $base)->where(fn($q) => $q->whereNotNull('matricula_id')->orWhereNotNull('solicitud_inscripcion_id'))),
                    'talleres' => $saldoDeudor((clone $base)->whereNotNull('inscripcion_taller_id')),
                    'servicios' => $saldoDeudor((clone $base)->where(fn($q) => $q->whereNotNull('reserva_aula_id')->orWhereNotNull('reserva_podcast_id')->orWhereNotNull('alquiler_equipo_id'))),
                ],
            ];
        });

        return response()->json(['datos' => $stats]);
    }
}
