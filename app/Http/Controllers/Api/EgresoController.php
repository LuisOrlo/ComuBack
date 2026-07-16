<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\TransaccionEgreso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EgresoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TransaccionEgreso::with(['categoria', 'registrador']);

        if ($desde = $request->get('fecha_desde')) {
            $query->where('fecha_pago', '>=', $desde);
        }
        if ($hasta = $request->get('fecha_hasta')) {
            $query->where('fecha_pago', '<=', $hasta . ' 23:59:59');
        }
        if ($cat = $request->get('categoria')) {
            $query->where('categoria_id', (int) $cat);
        }
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('descripcion', 'ilike', "%{$search}%")
                  ->orWhere('proveedor_beneficiario', 'ilike', "%{$search}%");
            });
        }

        $totalEgresado = (clone $query)->sum('monto');
        $totalPersonal = (clone $query)->whereIn('categoria_id', [1, 2])->sum('monto');
        $totalServicios = (clone $query)->whereIn('categoria_id', [3, 4, 5, 6, 7])->sum('monto');
        $totalEquipos = (clone $query)->where('categoria_id', [8])->sum('monto');
        $totalVarios = max(0, $totalEgresado - $totalPersonal - $totalServicios - $totalEquipos);

        $previoInicio = date('Y-m-d', strtotime(($desde ?: now()->startOfMonth()->format('Y-m-d')) . ' -1 month'));
        $previoFin = date('Y-m-d', strtotime(($hasta ?: now()->endOfMonth()->format('Y-m-d')) . ' -1 month'));
        $previoTotal = (float) TransaccionEgreso::whereBetween('fecha_pago', [$previoInicio, $previoFin . ' 23:59:59'])->sum('monto');
        $previoPersonal = (float) TransaccionEgreso::whereBetween('fecha_pago', [$previoInicio, $previoFin . ' 23:59:59'])->whereIn('categoria_id', [1, 2])->sum('monto');
        $previoServicios = (float) TransaccionEgreso::whereBetween('fecha_pago', [$previoInicio, $previoFin . ' 23:59:59'])->whereIn('categoria_id', [3, 4, 5, 6, 7])->sum('monto');
        $previoVarios = max(0, $previoTotal - $previoPersonal - $previoServicios);

        $grafico = DB::table('finance.transacciones_egreso')
            ->selectRaw("to_char(fecha_pago, 'YYYY-MM') as mes, SUM(monto) as total")
            ->when($desde, fn($q) => $q->where('fecha_pago', '>=', $desde))
            ->when($hasta, fn($q) => $q->where('fecha_pago', '<=', $hasta . ' 23:59:59'))
            ->groupBy(DB::raw("to_char(fecha_pago, 'YYYY-MM')"))
            ->orderBy(DB::raw("to_char(fecha_pago, 'YYYY-MM')"))
            ->get();

        $orderBy = $request->get('order_by', 'fecha_pago');
        $orderDir = $request->get('order_dir', 'desc');
        $allowed = ['fecha_pago' => 'fecha_pago', 'monto' => 'monto'];
        $sortCol = $allowed[$orderBy] ?? 'fecha_pago';
        $sortDir = $orderDir === 'asc' ? 'asc' : 'desc';

        $items = $query->orderBy($sortCol, $sortDir)
            ->paginate($request->get('per_page', 25));

        $graficoCategorias = TransaccionEgreso::whereNotNull('id')->get()->groupBy(function ($e) {
            if (in_array($e->categoria_id, [1, 2])) return 'Personal';
            if (in_array($e->categoria_id, [3, 4, 5, 6, 7])) return 'Servicios';
            if (in_array($e->categoria_id, [8])) return 'Equipos';
            return 'Varios';
        })->map(function ($g, $k) { return ['name' => $k, 'value' => (float) $g->sum('monto')]; })->values();

        $graficoProveedores = TransaccionEgreso::whereNotNull('proveedor_beneficiario')
            ->where('proveedor_beneficiario', '!=', '')
            ->get()->groupBy('proveedor_beneficiario')
            ->map(function ($g, $k) { return ['name' => $k, 'value' => (float) $g->sum('monto')]; })
            ->sortByDesc('value')->take(8)->values();

        $data = $items->map(fn($e) => [
            'id' => $e->id,
            'categoria_id' => $e->categoria_id,
            'categoria_nombre' => $e->categoria?->nombre,
            'subcategoria' => $e->subcategoria,
            'descripcion' => $e->descripcion,
            'monto' => (float) $e->monto,
            'proveedor_beneficiario' => $e->proveedor_beneficiario,
            'metodo_pago' => $e->metodo_pago ?? 'transferencia',
            'comprobante_url' => $e->comprobante_url,
            'fecha_pago' => $e->fecha_pago?->format('Y-m-d'),
            'registrado_por' => $e->registrador ? trim(($e->registrador->nombres ?? '') . ' ' . ($e->registrador->apellidos ?? '')) : null,
            'notas' => $e->notas,
        ]);

        return response()->json([
            'totales' => [
                'total' => (float) $totalEgresado,
                'personal' => (float) $totalPersonal,
                'servicios' => (float) $totalServicios,
                'equipos' => (float) $totalEquipos,
                'varios' => (float) $totalVarios,
                'previo_total' => round($previoTotal, 2),
                'previo_personal' => round($previoPersonal, 2),
                'previo_servicios' => round($previoServicios, 2),
                'previo_varios' => round($previoVarios, 2),
            ],
            'grafico' => $grafico,
            'grafico_categorias' => $graficoCategorias,
            'grafico_proveedores' => $graficoProveedores,
            'data' => $data->values(),
            'current_page' => $items->currentPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
            'last_page' => $items->lastPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'categoria_id' => 'required|integer|exists:pgsql.finance.categorias_egreso,id',
            'subcategoria' => 'nullable|string|max:100',
            'descripcion' => 'required|string',
            'monto' => 'required|numeric|min:0.01',
            'proveedor_beneficiario' => 'nullable|string|max:200',
            'metodo_pago' => 'nullable|string|max:50',
            'comprobante_url' => 'nullable|string',
            'fecha_pago' => 'nullable|date',
            'notas' => 'nullable|string',
        ]);

        $egreso = TransaccionEgreso::create([
            'categoria_id' => (int) $validated['categoria_id'],
            'subcategoria' => $validated['subcategoria'] ?? null,
            'descripcion' => $validated['descripcion'],
            'monto' => $validated['monto'],
            'proveedor_beneficiario' => $validated['proveedor_beneficiario'] ?? null,
            'metodo_pago' => $validated['metodo_pago'] ?? 'transferencia',
            'comprobante_url' => $validated['comprobante_url'] ?? null,
            'fecha_pago' => $validated['fecha_pago'] ?? now(),
            'registrado_por' => auth()->user()->persona_id ?? null,
            'notas' => $validated['notas'] ?? null,
        ]);

        Cache::forget('finance.resumen');

        return response()->json([
            'message' => 'Egreso registrado exitosamente',
            'data' => ['id' => $egreso->id],
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $e = TransaccionEgreso::with(['categoria', 'registrador'])->findOrFail($id);
        return response()->json([
            'data' => [
                'id' => $e->id,
                'categoria_id' => $e->categoria_id,
                'categoria_nombre' => $e->categoria?->nombre,
                'subcategoria' => $e->subcategoria,
                'descripcion' => $e->descripcion,
                'monto' => (float) $e->monto,
                'proveedor_beneficiario' => $e->proveedor_beneficiario,
                'metodo_pago' => $e->metodo_pago ?? 'transferencia',
                'comprobante_url' => $e->comprobante_url,
                'fecha_pago' => $e->fecha_pago?->format('Y-m-d'),
                'registrado_por' => $e->registrador ? trim(($e->registrador->nombres ?? '') . ' ' . ($e->registrador->apellidos ?? '')) : null,
                'notas' => $e->notas,
            ],
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $egreso = TransaccionEgreso::findOrFail($id);

        $validated = $request->validate([
            'categoria_id' => 'sometimes|integer|exists:pgsql.finance.categorias_egreso,id',
            'subcategoria' => 'nullable|string|max:100',
            'descripcion' => 'sometimes|string',
            'monto' => 'sometimes|numeric|min:0.01',
            'proveedor_beneficiario' => 'nullable|string|max:200',
            'metodo_pago' => 'nullable|string|max:50',
            'comprobante_url' => 'nullable|string',
            'fecha_pago' => 'nullable|date',
            'notas' => 'nullable|string',
        ]);

        $data = array_intersect_key($validated, array_flip([
            'categoria_id', 'subcategoria', 'descripcion', 'monto',
            'proveedor_beneficiario', 'metodo_pago', 'comprobante_url',
            'fecha_pago', 'notas',
        ]));

        if (isset($data['categoria_id'])) $data['categoria_id'] = (int) $data['categoria_id'];
        $egreso->update($data);

        Cache::forget('finance.resumen');

        return response()->json(['message' => 'Egreso actualizado exitosamente']);
    }

    public function destroy($id): JsonResponse
    {
        TransaccionEgreso::findOrFail($id)->delete();
        Cache::forget('finance.resumen');
        return response()->json(['message' => 'Egreso eliminado exitosamente']);
    }

    public function pagosPersonal($personaId): JsonResponse
    {
        $persona = Persona::select('id', 'nombres', 'apellidos', 'tipo')
            ->findOrFail($personaId);

        $nombreCompleto = trim("{$persona->nombres} {$persona->apellidos}");

        $query = TransaccionEgreso::with(['categoria'])
            ->where('proveedor_beneficiario', $nombreCompleto)
            ->orderBy('fecha_pago', 'desc');

        $totalPagado = (clone $query)->sum('monto');
        $cantidadPagos = (clone $query)->count();
        $ultimoPago = (clone $query)->value('fecha_pago');

        $items = $query->paginate(25);

        $data = $items->map(fn($e) => [
            'id' => $e->id,
            'categoria_id' => $e->categoria_id,
            'categoria_nombre' => $e->categoria?->nombre,
            'descripcion' => $e->descripcion,
            'monto' => (float) $e->monto,
            'metodo_pago' => $e->metodo_pago ?? 'transferencia',
            'comprobante_url' => $e->comprobante_url,
            'fecha_pago' => $e->fecha_pago?->format('Y-m-d'),
            'notas' => $e->notas,
        ]);

        return response()->json([
            'persona' => [
                'id' => $persona->id,
                'nombre_completo' => $nombreCompleto,
                'tipo' => $persona->tipo,
            ],
            'totales' => [
                'total_pagado' => (float) $totalPagado,
                'cantidad_pagos' => $cantidadPagos,
                'ultimo_pago' => $ultimoPago?->format('Y-m-d'),
            ],
            'data' => $data->values(),
            'current_page' => $items->currentPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
            'last_page' => $items->lastPage(),
        ]);
    }

    public function categorias(): JsonResponse
    {
        $cats = \App\Models\CategoriaEgreso::orderBy('id')->get();
        return response()->json(['data' => $cats]);
    }

    public function personalDisponible(): JsonResponse
    {
        $staff = \App\Models\Persona::whereIn('tipo', ['instructor', 'staff', 'secretaria', 'admin'])
            ->where('es_activo', true)
            ->select('id', 'nombres', 'apellidos', 'tipo')
            ->orderBy('nombres')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'nombre_completo' => trim("{$p->nombres} {$p->apellidos}"),
                'tipo' => $p->tipo,
            ]);

        return response()->json(['data' => $staff]);
    }
}
