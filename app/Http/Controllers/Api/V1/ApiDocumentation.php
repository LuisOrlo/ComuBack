<?php

/**
 * @OA\OpenApi(
 *     @OA\Info(
 *         version="1.0.0",
 *         title="API de Gestión Académica",
 *         description="Sistema completo de gestión de cursos, estudiantes, calificaciones y reportes académicos",
 *         contact=@OA\Contact(
 *             name="Equipo de Desarrollo",
 *             url="https://github.com/tu-repo"
 *         ),
 *         license=@OA\License(
 *             name="MIT",
 *             url="https://opensource.org/licenses/MIT"
 *         )
 *     ),
 *     @OA\Server(
 *         url="http://localhost:8000",
 *         description="Servidor de desarrollo"
 *     ),
 *     @OA\Server(
 *         url="https://api.production.com",
 *         description="Servidor de producción"
 *     ),
 *     @OA\Components(
 *         @OA\SecurityScheme(
 *             type="http",
 *             description="Login con email y contraseña",
 *             name="Token",
 *             in="header",
 *             scheme="bearer",
 *             bearerFormat="JWT",
 *             securityScheme="bearerAuth",
 *         ),
 *         @OA\Schema(
 *             schema="Error",
 *             type="object",
 *             @OA\Property(property="error", type="string"),
 *             @OA\Property(property="message", type="string")
 *         ),
 *         @OA\Schema(
 *             schema="ValidationError",
 *             type="object",
 *             @OA\Property(property="message", type="string"),
 *             @OA\Property(
 *                 property="errors",
 *                 type="object",
 *                 additionalProperties={"type": "array", "items": {"type": "string"}}
 *             )
 *         ),
 *         @OA\Schema(
 *             schema="PaginatedResponse",
 *             type="object",
 *             @OA\Property(property="data", type="array", items=@OA\Items()),
 *             @OA\Property(
 *                 property="links",
 *                 type="object",
 *                 @OA\Property(property="first", type="string"),
 *                 @OA\Property(property="last", type="string"),
 *                 @OA\Property(property="prev", type="string", nullable=true),
 *                 @OA\Property(property="next", type="string", nullable=true)
 *             ),
 *             @OA\Property(
 *                 property="meta",
 *                 type="object",
 *                 @OA\Property(property="current_page", type="integer"),
 *                 @OA\Property(property="from", type="integer", nullable=true),
 *                 @OA\Property(property="last_page", type="integer"),
 *                 @OA\Property(property="per_page", type="integer"),
 *                 @OA\Property(property="to", type="integer", nullable=true),
 *                 @OA\Property(property="total", type="integer")
 *             )
 *         )
 *     )
 * )
 */

namespace App\Http\Controllers\Api\V1;

/**
 * @OA\Tag(
 *     name="Catálogo de Cursos",
 *     description="Gestión de catálogo de cursos"
 * )
 * @OA\Tag(
 *     name="Cursos Abiertos",
 *     description="Gestión de cursos abiertos/ofertados"
 * )
 * @OA\Tag(
 *     name="Horarios",
 *     description="Gestión de horarios de clases"
 * )
 * @OA\Tag(
 *     name="Módulos",
 *     description="Gestión de módulos dentro de cursos"
 * )
 * @OA\Tag(
 *     name="Matrículas",
 *     description="Gestión de inscripciones de estudiantes"
 * )
 * @OA\Tag(
 *     name="Notas",
 *     description="Gestión de calificaciones"
 * )
 * @OA\Tag(
 *     name="Cambios de Horario",
 *     description="Gestión de cambios de horario"
 * )
 * @OA\Tag(
 *     name="Traslados de Módulo",
 *     description="Gestión de cambios de módulo"
 * )
 * @OA\Tag(
 *     name="Operaciones Bulk",
 *     description="Operaciones masivas (importación, actualización)"
 * )
 * @OA\Tag(
 *     name="Exportaciones",
 *     description="Exportar datos en múltiples formatos"
 * )
 * @OA\Tag(
 *     name="Reportes",
 *     description="Reportes académicos y análisis"
 * )
 */

class ApiDocumentation
{
}
