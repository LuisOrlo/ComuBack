<?php

namespace App\Http\Controllers\Api\V1;

/**
 * Documentación OpenAPI para endpoints de Catálogo de Cursos
 */
class CatalogoCursoDocumentation
{
    /**
     * @OA\Get(
     *     path="/academic/catalogos-cursos",
     *     operationId="getCatalogoCursos",
     *     tags={"Catálogo de Cursos"},
     *     summary="Listar catálogos de cursos",
     *     description="Obtiene lista paginada de catálogos de cursos con filtros opcionales",
     *     security={{"bearerAuth":{}}},
      *     @OA\Parameter(
      *         name="search",
      *         in="query",
      *         description="Buscar por nombre",
      *         required=false,
      *         @OA\Schema(type="string")
      *     ),
     *     @OA\Parameter(
     *         name="categoria",
     *         in="query",
     *         description="Filtrar por categoría (personalizado, regular)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="estado",
     *         in="query",
     *         description="Filtrar por estado (activo, inactivo)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items por página (default 15)",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Número de página",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de catálogos",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 items=@OA\Items(
      *                     @OA\Property(property="id", type="string", format="uuid"),
      *                     @OA\Property(property="nombre", type="string"),
      *                     @OA\Property(property="descripcion", type="string"),
      *                     @OA\Property(property="categoria", type="string"),
      *                     @OA\Property(property="duracion_horas", type="integer"),
      *                     @OA\Property(property="modulos_default", type="integer"),
      *                     @OA\Property(property="estado", type="string"),
      *                     @OA\Property(property="created_at", type="string", format="date-time"),
      *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             ),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     */
    public function index()
    {
    }

    /**
     * @OA\Post(
     *     path="/academic/catalogos-cursos",
     *     operationId="createCatalogoCurso",
     *     tags={"Catálogo de Cursos"},
     *     summary="Crear catálogo de curso",
     *     description="Crea un nuevo catálogo de curso",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Datos del catálogo",
     *         @OA\JsonContent(
      *             required={"nombre", "duracion_horas", "modulos_default"},
      *             @OA\Property(property="nombre", type="string", example="Matemáticas I"),
     *             @OA\Property(property="descripcion", type="string", example="Fundamentos de matemáticas"),
     *             @OA\Property(property="categoria", type="string", example="regular"),
     *             @OA\Property(property="duracion_horas", type="integer", example=60),
     *             @OA\Property(property="modulos_default", type="integer", example=2)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Catálogo creado exitosamente",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="string", format="uuid"),
     *             @OA\Property(property="codigo", type="string"),
     *             @OA\Property(property="nombre", type="string"),
     *             @OA\Property(property="estado", type="string"),
     *             @OA\Property(property="created_at", type="string", format="date-time")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validación falló",
     *         @OA\JsonContent(ref="#/components/schemas/ValidationError")
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     */
    public function store()
    {
    }

    /**
     * @OA\Get(
     *     path="/academic/catalogos-cursos/{id}",
     *     operationId="showCatalogoCurso",
     *     tags={"Catálogo de Cursos"},
     *     summary="Obtener catálogo de curso",
     *     description="Obtiene los detalles de un catálogo específico",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID del catálogo",
     *         required=true,
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Detalles del catálogo",
      *         @OA\JsonContent(
      *             @OA\Property(property="id", type="string"),
      *             @OA\Property(property="nombre", type="string"),
      *             @OA\Property(
     *                 property="modulos",
     *                 type="array",
     *                 items=@OA\Items(
     *                     @OA\Property(property="id", type="string"),
     *                     @OA\Property(property="nombre", type="string"),
     *                     @OA\Property(property="ponderacion", type="number")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Catálogo no encontrado"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     */
    public function show()
    {
    }

    /**
     * @OA\Put(
     *     path="/academic/catalogos-cursos/{id}",
     *     operationId="updateCatalogoCurso",
     *     tags={"Catálogo de Cursos"},
     *     summary="Actualizar catálogo de curso",
     *     description="Actualiza los datos de un catálogo existente",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID del catálogo",
     *         required=true,
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="nombre", type="string"),
     *             @OA\Property(property="descripcion", type="string"),
     *             @OA\Property(property="duracion_horas", type="integer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Catálogo actualizado"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Catálogo no encontrado"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validación falló"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     */
    public function update()
    {
    }

    /**
     * @OA\Delete(
     *     path="/academic/catalogos-cursos/{id}",
     *     operationId="deleteCatalogoCurso",
     *     tags={"Catálogo de Cursos"},
     *     summary="Eliminar catálogo de curso",
     *     description="Elimina (soft delete) un catálogo de curso",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID del catálogo",
     *         required=true,
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Catálogo eliminado"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Catálogo no encontrado"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="No autenticado"
     *     )
     * )
     */
    public function destroy()
    {
    }
}
