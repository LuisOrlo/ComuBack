<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePerfilInstructorRequest;
use App\Models\CursoAbierto;
use App\Models\HorasInstructor;
use App\Models\PerfilInstructor;
use App\Models\Persona;
use App\Models\Taller;
use App\Models\TransaccionEgreso;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InstructorController extends Controller
{
    public function index(Request $request)
    {
        $hoy = Carbon::today()->toDateString();
        $query = Persona::with(['perfilInstructor', 'ciudad', 'cuentaSistema'])
            ->instructores()->select('people.personas.*')
            ->selectSub(CursoAbierto::query()->selectRaw('count(*)')
                ->whereColumn('docente_id', 'people.personas.id')
                ->where('es_activo', true)
                ->where('estado', '!=', 'cancelado')
                ->where(function ($q) use ($hoy) {
                    $q->where('estado', 'en_progreso')
                      ->orWhere(function ($q2) use ($hoy) {
                          $q2->whereDate('fecha_inicio', '<=', $hoy)
                             ->where(fn ($q3) => $q3->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $hoy));
                      });
                }), 'cursos_actuales_count')
            ->selectSub(CursoAbierto::query()->selectRaw('count(*)')
                ->whereColumn('docente_id', 'people.personas.id')
                ->where('es_activo', true)
                ->where('estado', '!=', 'cancelado')
                ->where('estado', '!=', 'en_progreso')
                ->whereDate('fecha_inicio', '>', $hoy), 'cursos_proximos_count')
            ->selectSub(Taller::query()->selectRaw('count(*)')
                ->whereColumn('instructor_id', 'people.personas.id')
                ->whereIn('estado', ['confirmado', 'en_progreso'])
                ->whereDate('fecha', '<=', $hoy)
                ->where(fn ($q) => $q->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $hoy)), 'talleres_actuales_count')
            ->selectSub(Taller::query()->selectRaw('count(*)')
                ->whereColumn('instructor_id', 'people.personas.id')
                ->whereIn('estado', ['pendiente', 'confirmado'])
                ->whereDate('fecha', '>', $hoy), 'talleres_proximos_count');

        if ($request->filled('buscar')) $query->buscar($request->buscar);
        if ($request->filled('ciudad_id')) $query->where('ciudad_id', $request->ciudad_id);
        if ($request->has('activos')) $query->where('es_activo', $request->boolean('activos'));

        $paginator = $query->orderBy('apellidos')->orderBy('nombres')->paginate($request->integer('per_page', 15));
        return response()->json(['data' => $paginator->items(), 'meta' => [
            'total' => $paginator->total(), 'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
        ]]);
    }

    public function disponibles()
    {
        return response()->json(['data' => Persona::instructores()->activos()->with('perfilInstructor:id,persona_id,especialidad')
            ->select('id', 'nombres', 'apellidos')->orderBy('apellidos')->orderBy('nombres')->get()]);
    }

    public function show($id) { return response()->json(['data' => $this->instructor($id)]); }

    public function updatePerfil(StorePerfilInstructorRequest $request, $id)
    {
        $this->instructor($id);
        $perfil = PerfilInstructor::updateOrCreate(['persona_id' => $id], $request->only(['especialidad', 'bio']));
        return response()->json(['data' => $perfil, 'message' => 'Perfil actualizado exitosamente']);
    }

    public function setActivo(Request $request, $id)
    {
        $persona = $this->instructor($id);
        $data = $request->validate(['es_activo' => ['required', 'boolean']]);
        $persona->update(['es_activo' => $data['es_activo']]);
        return response()->json(['data' => $persona->fresh(), 'message' => $data['es_activo'] ? 'Instructor reactivado' : 'Instructor desactivado']);
    }

    public function cursos($id)
    {
        $this->instructor($id);
        $paginator = CursoAbierto::with('catalogo:id,nombre')->where('docente_id', $id)->orderByDesc('fecha_inicio')->paginate(15);
        return response()->json(['data' => $paginator->items(), 'meta' => ['total' => $paginator->total(), 'per_page' => $paginator->perPage()]]);
    }

    public function horas($id)
    {
        $this->instructor($id);
        $paginator = HorasInstructor::where('instructor_id', $id)->orderByDesc('fecha')->paginate(15);
        return response()->json(['data' => $paginator->items(), 'meta' => ['total' => $paginator->total(), 'per_page' => $paginator->perPage()]]);
    }

    public function detalle($id)
    {
        $persona = $this->instructor($id);
        $cursos = CursoAbierto::with(['catalogo:id,nombre', 'ciudad:id,nombre'])->where('docente_id', $id)->orderByDesc('fecha_inicio')->get();
        $talleres = Taller::with('ciudad:id,nombre')->where('instructor_id', $id)->orderByDesc('fecha')->get();
        $nombresCursos = $cursos->keyBy('id')->map(fn ($curso) => $this->courseName($curso));
        $horas = HorasInstructor::where('instructor_id', $id)->orderByDesc('fecha')->get();
        $horas->transform(function ($hora) use ($nombresCursos) { $hora->curso_nombre = $nombresCursos->get($hora->curso_abierto_id); return $hora; });
        $nombreCompleto = trim("{$persona->nombres} {$persona->apellidos}");
        $pagos = TransaccionEgreso::where('proveedor_beneficiario', $nombreCompleto)->orderByDesc('fecha_pago')->limit(100)->get();
        return response()->json(['data' => [
            'persona' => $persona, 'perfil' => $persona->perfilInstructor, 'cuenta' => $persona->cuentaSistema,
            'cursos' => $this->groupCourses($cursos), 'talleres' => $this->groupWorkshops($talleres),
            'pagos' => $pagos, 'horas' => $horas, 'hoja_vida' => $this->cvData($persona->perfilInstructor),
        ]]);
    }

    public function talleres($id)
    {
        $this->instructor($id);
        return response()->json(['data' => $this->groupWorkshops(Taller::with('ciudad:id,nombre')->where('instructor_id', $id)->orderByDesc('fecha')->get())]);
    }

    public function subirHojaVida(Request $request, $id)
    {
        $persona = $this->instructor($id);
        $request->validate(['archivo' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:10240']]);
        $file = $request->file('archivo'); $disk = Storage::disk(); $directory = "instructores/hojas-vida/{$id}";
        $filename = Str::uuid()->toString() . '.pdf'; $newPath = $disk->putFileAs($directory, $file, $filename);
        if (!$newPath) return response()->json(['message' => 'No se pudo almacenar el archivo'], 500);
        $perfil = PerfilInstructor::firstOrCreate(['persona_id' => $id]); $oldPath = $perfil->hoja_vida_path;
        try {
            $perfil->update(['hoja_vida_path' => $newPath, 'hoja_vida_nombre_original' => Str::limit(basename($file->getClientOriginalName()), 255, ''), 'hoja_vida_mime' => 'application/pdf', 'hoja_vida_size' => $file->getSize(), 'hoja_vida_updated_at' => now()]);
        } catch (\Throwable $exception) { $disk->delete($newPath); throw $exception; }
        if ($oldPath && $oldPath !== $newPath) $disk->delete($oldPath);
        return response()->json(['data' => $this->cvData($perfil->fresh()), 'message' => 'Hoja de vida guardada']);
    }

    public function verHojaVida(Request $request, $id)
    {
        $persona = $this->instructor($id); $perfil = $persona->perfilInstructor;
        abort_unless($perfil?->hoja_vida_path, 404, 'El instructor no tiene hoja de vida');
        $disk = Storage::disk(); abort_unless($disk->exists($perfil->hoja_vida_path), 404, 'Archivo no encontrado');
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $perfil->hoja_vida_nombre_original ?: 'hoja-vida.pdf');
        return response($disk->get($perfil->hoja_vida_path), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline') . '; filename="' . $name . '"', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function eliminarHojaVida($id)
    {
        $persona = $this->instructor($id); $perfil = $persona->perfilInstructor;
        if ($perfil?->hoja_vida_path) Storage::disk()->delete($perfil->hoja_vida_path);
        if ($perfil) $perfil->update(['hoja_vida_path' => null, 'hoja_vida_nombre_original' => null, 'hoja_vida_mime' => null, 'hoja_vida_size' => null, 'hoja_vida_updated_at' => null]);
        return response()->json(['message' => 'Hoja de vida eliminada']);
    }

    private function instructor($id): Persona { return Persona::instructores()->with(['perfilInstructor', 'ciudad', 'cuentaSistema'])->findOrFail($id); }
    private function courseName($curso): string { return $curso->es_personalizado ? ($curso->nombre_instancia ?: 'Curso personalizado') : ($curso->catalogo?->nombre ?: 'Curso'); }

    private function groupCourses($courses): array
    {
        $today = Carbon::today();
        $current = $courses->filter(fn ($c) => $c->estado === 'en_progreso' || (Carbon::parse($c->fecha_inicio)->lte($today) && (!$c->fecha_fin || Carbon::parse($c->fecha_fin)->gte($today)) && $c->estado !== 'cancelado'));
        $future = $courses->filter(fn ($c) => Carbon::parse($c->fecha_inicio)->gt($today));
        $history = $courses->reject(fn ($c) => $current->contains('id', $c->id) || $future->contains('id', $c->id));
        $map = fn ($items) => $items->map(fn ($c) => array_merge($c->toArray(), ['nombre' => $this->courseName($c)]))->values();
        return ['actuales' => $map($current), 'proximos' => $map($future), 'historicos' => $map($history)];
    }

    private function groupWorkshops($workshops): array
    {
        $today = Carbon::today();
        $current = $workshops->filter(fn ($w) => in_array($w->estado, ['confirmado', 'en_progreso']) && Carbon::parse($w->fecha)->lte($today) && (!$w->fecha_fin || Carbon::parse($w->fecha_fin)->gte($today)));
        $future = $workshops->filter(fn ($w) => Carbon::parse($w->fecha)->gt($today) && in_array($w->estado, ['pendiente', 'confirmado']));
        $history = $workshops->reject(fn ($w) => $current->contains('id', $w->id) || $future->contains('id', $w->id));
        return ['actuales' => $current->values(), 'proximos' => $future->values(), 'historicos' => $history->values()];
    }

    private function cvData(?PerfilInstructor $perfil): ?array
    {
        if (!$perfil?->hoja_vida_path) return null;
        return ['nombre_original' => $perfil->hoja_vida_nombre_original, 'mime' => $perfil->hoja_vida_mime, 'size' => $perfil->hoja_vida_size, 'updated_at' => $perfil->hoja_vida_updated_at];
    }
}
