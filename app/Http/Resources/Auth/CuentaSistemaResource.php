<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CuentaSistemaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'persona' => [
                'id' => $this->persona->id,
                'tipo' => $this->persona->tipo,
                'cedula' => $this->persona->cedula,
                'nombres' => $this->persona->nombres,
                'apellidos' => $this->persona->apellidos,
                'correo' => $this->persona->correo,
                'celular' => $this->persona->celular,
                'es_activo' => $this->persona->es_activo,
            ],
            'roles' => array_unique(array_merge(
                $this->getRoleNames()->toArray(),
                match($this->persona->tipo) {
                    'admin' => ['Administrador'],
                    'instructor' => ['Instructor'],
                    'staff', 'secretaria' => ['Staff'],
                    default => []
                }
            )),
            'ultimo_acceso' => $this->last_login?->toIso8601String(),
            'creado_en' => $this->created_at->toIso8601String(),
        ];
    }
}
