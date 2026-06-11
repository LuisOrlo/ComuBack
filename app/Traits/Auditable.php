<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait Auditable
{
    protected function audit(string $action, array $context = []): void
    {
        Log::channel('audit')->info($action, array_merge([
            'usuario_id' => auth()->id(),
            'username' => auth()->user()?->username,
            'ip' => request()->ip(),
            'timestamp' => now()->toIso8601String(),
        ], $context));
    }
}
