<?php

namespace App\Models;

/**
 * BaseModel: Clase base para referencia de atributos compartidos
 * 
 * Esta clase documenta todos los modelos creados en FASE 2 y sus relaciones.
 * NO es una clase que hereden los modelos, sino una guía de referencia.
 */
class BaseModel
{
    /**
     * MODELOS CREADOS EN FASE 2
     * ==============================================================================
     * 
     * 1. CatalogoCurso
     *    - Tabla: academic.catalogo_cursos
     *    - Propósito: Define la estructura de un curso (código, nombre, créditos, módulos)
     *    - Relaciones:
     *      - programa() → Programa
     *      - cursosAbiertos() → CursoAbierto (1:N)
     *      - modulosPredeterminados() → Modulo (1:N)
     *    - Scopes: activos(), regulares(), personalizados(), talleres(), etc.
     * 
     * 2. CursoAbierto
     *    - Tabla: academic.cursos_abiertos
     *    - Propósito: Instancia de un catálogo de curso en un semestre específico
     *    - Relaciones:
     *      - catalogo() → CatalogoCurso
     *      - docente() → Persona
     *      - horario() → Horario (BelongsTo, FK en cursos_abiertos)
     *      - modulos() → Modulo (1:N)
     *      - matriculas() → Matricula (1:N)
     *      - cambiosHorarioDestino() → CambioHorario
     *      - cambiosHorarioOrigen() → CambioHorario
     *    - Scopes: activos(), vigentes(), proximos(), etc.
     *    - Métodos: obtenerEspaciosDisponibles(), estaLleno(), getPorcentajeOcupacion()
     * 
     * 3. Horario
     *    - Tabla: academic.horarios
     *    - Propósito: Define un horario específico (días + horas) para un curso
     *    - Relaciones:
     *      - cursoAbierto() → CursoAbierto
     *      - diasSemana() → HorarioDia (1:N)
     *      - matriculas() → Matricula (1:N)
     *    - Métodos: obtenerDiasSemana(), obtenerDiasNombres(), tieneConflictoHorario()
     * 
     * 4. HorarioDia
     *    - Tabla: academic.horarios_dias
     *    - Propósito: Mapea cada día de la semana a un horario
     *    - Relaciones:
     *      - horario() → Horario
     *    - Métodos: obtenerNombreDia(), esValido()
     * 
     * 5. Modulo
     *    - Tabla: academic.modulos
     *    - Propósito: Divide un curso en módulos (ej: Módulo 1, Módulo 2)
     *    - Relaciones:
     *      - catalogo() → CatalogoCurso (si es predeterminado)
     *      - cursoAbierto() → CursoAbierto (si es personalizado)
     *      - notas() → Nota (1:N)
     *    - Scopes: delCatalogo(), personalizados()
     *    - Métodos: esPredeterminado(), esPersonalizado(), obtenerDuracionSemanas()
     * 
     * 6. Matricula
     *    - Tabla: academic.matriculas
     *    - Propósito: Registro de inscripción de estudiante en un curso
     *    - Relaciones:
     *      - estudiante() → Persona
     *      - cursoAbierto() → CursoAbierto
     *      - horario() → Horario
     *      - notas() → Nota (1:N)
     *      - cambiosHorario() → CambioHorario (1:N)
     *      - trasladosModulo() → TrasladoModulo (1:N)
     *    - Estados: activo, completado, retirado, reprobado
     *    - Métodos: calcularPromedio(), calcularPromedioPonderado(), tieneTotalNotasRegistradas()
     * 
     * 7. Nota
     *    - Tabla: academic.notas
     *    - Propósito: Calificación de un estudiante en un módulo
     *    - Relaciones:
     *      - matricula() → Matricula
     *      - modulo() → Modulo
     *    - Scopes: registradas(), pendientes(), aprobadas(), reprobadas()
     *    - Métodos: estaRegistrada(), estaAprobada(), obtenerCalificacionDescriptiva()
     * 
     * 8. CambioHorario
     *    - Tabla: academic.cambios_horario
     *    - Propósito: Solicitud de cambio de horario o curso
     *    - Relaciones:
     *      - matriculaOrigen() → Matricula
     *      - cursoAbiertoAntiguo() → CursoAbierto
     *      - cursoAbiertoNuevo() → CursoAbierto
     *    - Estados: pendiente, aprobado, rechazado, completado
     *    - Métodos: estaPendiente(), estaAprobada(), puedeSerAprobada()
     * 
     * 9. TrasladoModulo
     *    - Tabla: academic.traslados_modulo
     *    - Propósito: Solicitud de cambio de módulo dentro del mismo curso
     *    - Relaciones:
     *      - matriculaOrigen() → Matricula
     *      - moduloAntiguo() → Modulo
     *      - moduloNuevo() → Modulo
     *    - Estados: pendiente, aprobado, rechazado, completado
     *    - Métodos: estaPendiente(), estaAprobado(), puedeSerAprobado()
     * 
     * ==============================================================================
     * 
     * DIAGRAMA DE RELACIONES:
     * 
     *   Programa
     *      ↓
     *   CatalogoCurso
     *      ├─→ CursoAbierto (1:N)
     *      │     ├─→ Persona (docente)
     *      │     ├─→ Horario (1:N)
     *      │     │     ├─→ HorarioDia (1:N)
     *      │     │     └─→ Matricula (1:N)
     *      │     ├─→ Modulo (1:N)
     *      │     │     └─→ Nota (1:N)
     *      │     └─→ Matricula (1:N)
     *      │           ├─→ Persona (estudiante)
     *      │           ├─→ Nota (1:N)
     *      │           ├─→ CambioHorario (1:N)
     *      │           └─→ TrasladoModulo (1:N)
     *      └─→ Modulo (1:N) [predeterminados]
     * 
     * ==============================================================================
     */
}
