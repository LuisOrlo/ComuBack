<?php

namespace App\Services;

use App\Models\CursoAbierto;
use App\Models\Matricula;
use App\Models\Nota;
use Illuminate\Support\Collection;
use League\Csv\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * ExportService
 * 
 * Servicio para exportar datos en múltiples formatos:
 * - CSV
 * - PDF
 * - Excel
 */
class ExportService
{
    /**
     * Exportar calificaciones
     */
    public function exportCalificaciones(
        ?string $cursoAbiertoId = null,
        ?string $filtroEstado = null,
        string $formato = 'csv'
    ) {
        $query = Nota::with(['matricula', 'modulo', 'matricula.curso_abierto']);

        if ($cursoAbiertoId) {
            $query->whereHas('matricula', function ($q) use ($cursoAbiertoId) {
                $q->where('curso_abierto_id', $cursoAbiertoId);
            });
        }

        if ($filtroEstado) {
            $query->whereHas('matricula', function ($q) use ($filtroEstado) {
                $q->where('estado', $filtroEstado);
            });
        }

        $notas = $query->get();

        $data = $notas->map(function ($nota) {
            return [
                'ID Nota' => $nota->id,
                'Estudiante' => $nota->matricula->estudiante->nombre ?? 'N/A',
                'Email Estudiante' => $nota->matricula->estudiante->email ?? 'N/A',
                'Curso' => $nota->matricula->curso_abierto->nombre ?? 'N/A',
                'Módulo' => $nota->modulo->nombre ?? 'N/A',
                'Ponderación' => $nota->modulo->ponderacion . '%',
                'Calificación' => $nota->calificacion,
                'Calificación Ponderada' => ($nota->calificacion * $nota->modulo->ponderacion / 100),
                'Estado Matrícula' => $nota->matricula->estado,
                'Fecha Creación' => $nota->created_at->format('Y-m-d H:i:s'),
                'Observaciones' => $nota->observaciones ?? 'Sin observaciones',
            ];
        });

        return $this->exportByFormat($data, $formato, 'calificaciones');
    }

    /**
     * Exportar asistencia
     */
    public function exportAsistencia(
        ?string $cursoAbiertoId = null,
        ?string $filtroEstado = null,
        string $formato = 'csv'
    ) {
        $query = Matricula::with(['estudiante', 'curso_abierto', 'curso_abierto.horarios'])
            ->whereHas('curso_abierto.horarios');

        if ($cursoAbiertoId) {
            $query->where('curso_abierto_id', $cursoAbiertoId);
        }

        if ($filtroEstado) {
            $query->where('estado', $filtroEstado);
        }

        $matriculas = $query->get();

        $data = $matriculas->map(function ($matricula) {
            $totalSesiones = $matricula->curso_abierto->horarios->count();
            $asistencias = $matricula->asistencias()->count();
            $inasistencias = $totalSesiones - $asistencias;
            $porcentajeAsistencia = $totalSesiones > 0 
                ? round(($asistencias / $totalSesiones) * 100, 2)
                : 0;

            return [
                'ID Matrícula' => $matricula->id,
                'Estudiante' => $matricula->estudiante->nombre,
                'Email' => $matricula->estudiante->email,
                'Curso' => $matricula->curso_abierto->nombre,
                'Total Sesiones' => $totalSesiones,
                'Asistencias' => $asistencias,
                'Inasistencias' => $inasistencias,
                'Porcentaje Asistencia' => $porcentajeAsistencia . '%',
                'Estado' => $matricula->estado,
                'Fecha Matrícula' => $matricula->fecha_inicio->format('Y-m-d'),
                'Fecha Fin' => $matricula->fecha_fin?->format('Y-m-d') ?? 'N/A',
            ];
        });

        return $this->exportByFormat($data, $formato, 'asistencia');
    }

    /**
     * Exportar horarios
     */
    public function exportHorarios(
        ?string $cursoAbiertoId = null,
        string $formato = 'csv'
    ) {
        $query = CursoAbierto::with(['horarios', 'horarios.horarios_dias']);

        if ($cursoAbiertoId) {
            $query->where('id', $cursoAbiertoId);
        }

        $cursos = $query->get();

        $data = collect();

        foreach ($cursos as $curso) {
            foreach ($curso->horarios as $horario) {
                $dias = $horario->horarios_dias->pluck('dia_semana')->join(', ');
                
                $data->push([
                    'Curso' => $curso->nombre,
                    'ID Horario' => $horario->id,
                    'Profesor' => $horario->profesor?->nombre ?? 'N/A',
                    'Días' => $dias,
                    'Hora Inicio' => $horario->hora_inicio,
                    'Hora Fin' => $horario->hora_fin,
                    'Aula' => $horario->aula ?? 'N/A',
                    'Capacidad' => $horario->capacidad,
                    'Inscritos' => $horario->matriculas()->count(),
                    'Disponibles' => $horario->capacidad - $horario->matriculas()->count(),
                ]);
            }
        }

        return $this->exportByFormat($data, $formato, 'horarios');
    }

    /**
     * Exportar todo (calificaciones + asistencia + horarios)
     */
    public function exportTodo(
        ?string $cursoAbiertoId = null,
        ?string $filtroEstado = null,
        string $formato = 'csv'
    ) {
        // Si es CSV o Excel, retornar múltiples archivos en un zip
        // Si es PDF, crear un PDF con múltiples páginas

        if ($formato === 'pdf') {
            return $this->exportTodoPdf($cursoAbiertoId, $filtroEstado);
        }

        // Para CSV y Excel, retornar colección de datos
        return [
            'calificaciones' => $this->exportCalificaciones($cursoAbiertoId, $filtroEstado, 'array'),
            'asistencia' => $this->exportAsistencia($cursoAbiertoId, $filtroEstado, 'array'),
            'horarios' => $this->exportHorarios($cursoAbiertoId, 'array'),
        ];
    }

    /**
     * Exportar por formato especificado
     */
    private function exportByFormat(Collection $data, string $formato, string $nombreArchivo)
    {
        return match ($formato) {
            'csv' => $this->toCsv($data, $nombreArchivo),
            'pdf' => $this->toPdf($data, $nombreArchivo),
            'excel' => $this->toExcel($data, $nombreArchivo),
            'array' => $data->toArray(),
            default => throw new \InvalidArgumentException("Formato no soportado: {$formato}"),
        };
    }

    /**
     * Convertir a CSV
     */
    private function toCsv(Collection $data, string $nombreArchivo)
    {
        if ($data->isEmpty()) {
            return response()->json(['error' => 'Sin datos para exportar'], 400);
        }

        $csv = Writer::createFromString('');
        $csv->insertOne($data->first());
        
        foreach ($data as $row) {
            $csv->insertOne(array_values($row));
        }

        $filename = "{$nombreArchivo}_" . now()->format('Y-m-d_H-i-s') . '.csv';

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Convertir a PDF
     */
    private function toPdf(Collection $data, string $nombreArchivo)
    {
        if ($data->isEmpty()) {
            return response()->json(['error' => 'Sin datos para exportar'], 400);
        }

        $html = $this->generatePdfHtml($data, $nombreArchivo);
        
        $pdf = Pdf::loadHtml($html);
        $filename = "{$nombreArchivo}_" . now()->format('Y-m-d_H-i-s') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Convertir a Excel
     */
    private function toExcel(Collection $data, string $nombreArchivo)
    {
        if ($data->isEmpty()) {
            return response()->json(['error' => 'Sin datos para exportar'], 400);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = array_keys($data->first());
        foreach ($headers as $col => $header) {
            $sheet->setCellValueByColumnAndRow($col + 1, 1, $header);
        }

        $row = 2;
        foreach ($data as $item) {
            $col = 1;
            foreach (array_values($item) as $value) {
                $sheet->setCellValueByColumnAndRow($col, $row, $value);
                $col++;
            }
            $row++;
        }

        // Auto ajustar ancho de columnas
        foreach ($headers as $col => $header) {
            $sheet->getColumnDimensionByColumn($col + 1)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = "{$nombreArchivo}_" . now()->format('Y-m-d_H-i-s') . '.xlsx';

        $temp = tempnam(sys_get_temp_dir(), $filename);
        $writer->save($temp);

        return response()->download($temp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Generar HTML para PDF
     */
    private function generatePdfHtml(Collection $data, string $titulo): string
    {
        $headers = array_keys($data->first());
        
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; }
                h1 { color: #333; text-align: center; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th { background-color: #4CAF50; color: white; padding: 12px; text-align: left; }
                td { border: 1px solid #ddd; padding: 8px; }
                tr:nth-child(even) { background-color: #f2f2f2; }
                .footer { margin-top: 20px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <h1>{$titulo}</h1>
            <p>Generado: {{ now()->format('Y-m-d H:i:s') }}</p>
            <table>
                <thead>
                    <tr>
        HTML;

        foreach ($headers as $header) {
            $html .= "<th>{$header}</th>";
        }

        $html .= <<<HTML
                    </tr>
                </thead>
                <tbody>
        HTML;

        foreach ($data as $row) {
            $html .= "<tr>";
            foreach (array_values($row) as $value) {
                $html .= "<td>{$value}</td>";
            }
            $html .= "</tr>";
        }

        $html .= <<<HTML
                </tbody>
            </table>
            <div class="footer">
                <p>Reporte exportado el {{ now()->format('Y-m-d H:i:s') }}</p>
            </div>
        </body>
        </html>
        HTML;

        return $html;
    }

    /**
     * Exportar todo a PDF (múltiples páginas)
     */
    private function exportTodoPdf(?string $cursoAbiertoId, ?string $filtroEstado)
    {
        $calificaciones = $this->exportCalificaciones($cursoAbiertoId, $filtroEstado, 'array');
        $asistencia = $this->exportAsistencia($cursoAbiertoId, $filtroEstado, 'array');
        $horarios = $this->exportHorarios($cursoAbiertoId, 'array');

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; }
                h1 { color: #333; page-break-before: always; }
                h1:first-child { page-break-before: avoid; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 10px; }
                th { background-color: #4CAF50; color: white; padding: 8px; text-align: left; }
                td { border: 1px solid #ddd; padding: 6px; }
                tr:nth-child(even) { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <h1>Reporte Académico Completo</h1>
            <p>Generado: {{ now()->format('Y-m-d H:i:s') }}</p>

            <h2>Calificaciones</h2>
            {$this->generateTableHtml($calificaciones)}

            <h2>Asistencia</h2>
            {$this->generateTableHtml($asistencia)}

            <h2>Horarios</h2>
            {$this->generateTableHtml($horarios)}
        </body>
        </html>
        HTML;

        $pdf = Pdf::loadHtml($html);
        $filename = 'reporte_completo_' . now()->format('Y-m-d_H-i-s') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Generar tabla HTML a partir de array
     */
    private function generateTableHtml(array $data): string
    {
        if (empty($data)) {
            return '<p>Sin datos disponibles</p>';
        }

        $headers = array_keys($data[0]);
        $html = '<table><thead><tr>';

        foreach ($headers as $header) {
            $html .= "<th>{$header}</th>";
        }

        $html .= '</tr></thead><tbody>';

        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= "<td>{$value}</td>";
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }
}
