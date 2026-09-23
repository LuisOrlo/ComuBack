<?php

namespace App\Services\Imports;

use App\Models\PerfilEstudiante;
use App\Models\Persona;
use Illuminate\Support\Facades\DB;

final class StudentImportAdapter
{
    public function execute(array $row, ?string $selectedPersonaId = null): array
    {
        return DB::transaction(fn () => $this->executeInTransaction($row, $selectedPersonaId));
    }

    /**
     * Same operation without opening a nested transaction. Used when the
     * academic adapter must atomically rollback a newly created student too.
     */
    public function executeInTransaction(array $row, ?string $selectedPersonaId = null): array
    {
            $student = $row['student'];
            $persona = null;

            if ($selectedPersonaId) {
                $persona = Persona::withTrashed()->lockForUpdate()->find($selectedPersonaId);
                if (!$persona || $persona->trashed() || $persona->tipo !== 'estudiante') {
                    throw new \RuntimeException('La Persona seleccionada ya no puede reutilizarse.');
                }
            } elseif (!empty($student['cedula'])) {
                $persona = Persona::withTrashed()
                    ->where('cedula', $student['cedula'])
                    ->lockForUpdate()
                    ->first();

                if ($persona?->trashed()) {
                    throw new \RuntimeException('La cédula pertenece a un registro eliminado.');
                }
                if ($persona && $persona->tipo !== 'estudiante') {
                    throw new \RuntimeException('La cédula pertenece a una Persona con otro rol.');
                }
            }

            if (!$persona) {
                $persona = Persona::create([
                    'tipo' => 'estudiante',
                    'cedula' => $student['cedula'] ?? null,
                    'nombres' => $student['nombres'],
                    'apellidos' => $student['apellidos'],
                    'correo' => $student['correo'] ?? null,
                    'celular' => $student['celular'] ?? null,
                    'ciudad_id' => $student['ciudad_id'] ?? null,
                    'ciudad' => $student['ciudad'] ?? null,
                ]);
                $action = 'CREATED';
            } else {
                $action = 'REUSED';
            }

            $perfil = $persona->perfilEstudiante()->lockForUpdate()->first();
            if (!$perfil) {
                $perfil = PerfilEstudiante::create([
                    'persona_id' => $persona->id,
                ]);
                $action .= '_PROFILE_CREATED';
            }

            return [
                'persona_id' => (string) $persona->id,
                'perfil_estudiante_id' => (string) $perfil->id,
                'action' => $action,
            ];
    }
}
