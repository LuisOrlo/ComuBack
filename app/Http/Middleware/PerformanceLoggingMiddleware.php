<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PerformanceLoggingMiddleware
{
    private const SLOW_REQUEST_MS = 500.0;
    private const MAX_SLOW_QUERIES = 5;
    private const MAX_SQL_LENGTH = 1000;

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('performance.enabled', false)) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $queryCount = 0;
        $queryTimeMs = 0.0;
        $slowQueries = [];
        $cacheHits = 0;
        $cacheMisses = 0;
        $trackCache = $this->tracksCache($request);

        DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryTimeMs, &$slowQueries): void {
            $queryCount++;
            $durationMs = (float) $query->time;
            $queryTimeMs += $durationMs;

            $slowQueries[] = [
                'duration_ms' => round($durationMs, 2),
                // Deliberately use the SQL template only. Bindings are never logged.
                'sql' => $this->normalizeSql($query->sql),
            ];

            usort($slowQueries, static fn (array $left, array $right): int =>
                $right['duration_ms'] <=> $left['duration_ms']
            );

            if (count($slowQueries) > self::MAX_SLOW_QUERIES) {
                array_pop($slowQueries);
            }
        });

        if ($trackCache) {
            Event::listen(CacheHit::class, function (CacheHit $event) use (&$cacheHits): void {
                if ($this->isTrackedCacheKey($event->key)) {
                    $cacheHits++;
                }
            });

            Event::listen(CacheMissed::class, function (CacheMissed $event) use (&$cacheMisses): void {
                if ($this->isTrackedCacheKey($event->key)) {
                    $cacheMisses++;
                }
            });
        }

        $response = null;

        try {
            $response = $next($request);

            return $response;
        } finally {
            $totalMs = (hrtime(true) - $startedAt) / 1_000_000;

            if ($totalMs > self::SLOW_REQUEST_MS || $queryCount > 20) {
                $payload = [
                    'path' => $this->safePath($request),
                    'method' => $request->method(),
                    'status' => $response?->getStatusCode(),
                    'total_ms' => round($totalMs, 2),
                    'query_count' => $queryCount,
                    'sql_total_ms' => round($queryTimeMs, 2),
                    'non_sql_ms' => round(max(0.0, $totalMs - $queryTimeMs), 2),
                    'slow_queries' => $slowQueries,
                    'cache' => $trackCache ? $this->cacheStatus($cacheHits, $cacheMisses) : null,
                ];

                if ($trackCache) {
                    $payload['cache_hits'] = $cacheHits;
                    $payload['cache_misses'] = $cacheMisses;
                }

                Log::channel(config('performance.channel', 'stderr'))
                    ->info('performance.request', $payload);
            }
        }
    }

    private function tracksCache(Request $request): bool
    {
        return $request->is('api/finanzas/estadisticas')
            || $request->is('api/finanzas/resumen');
    }

    private function isTrackedCacheKey(string $key): bool
    {
        return $key === 'finance.resumen'
            || $key === 'estadisticas.version'
            || str_starts_with($key, 'estadisticas.');
    }

    private function cacheStatus(int $hits, int $misses): string
    {
        return match (true) {
            $misses > 0 && $hits > 0 => 'PARTIAL',
            $misses > 0 => 'MISS',
            $hits > 0 => 'HIT',
            default => 'NO_EVENTS',
        };
    }

    private function safePath(Request $request): string
    {
        $route = $request->route();

        if (is_object($route) && method_exists($route, 'uri')) {
            return '/' . ltrim($route->uri(), '/');
        }

        return '/' . ltrim($request->path(), '/');
    }

    private function normalizeSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        $sql = preg_replace_callback(
            "/'(?:''|[^'])*'/",
            static fn (): string => "'[redacted]'",
            $sql,
        ) ?? $sql;

        return mb_strlen($sql) > self::MAX_SQL_LENGTH
            ? mb_substr($sql, 0, self::MAX_SQL_LENGTH) . '…'
            : $sql;
    }
}
