<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Exportacion de Estudiantes</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 18px; margin-bottom: 4px; color: #2563eb; }
        .fecha { color: #666; margin-bottom: 16px; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { background: #2563eb; color: #fff; padding: 8px 6px; text-align: left; font-size: 9px; text-transform: uppercase; }
        td { padding: 6px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
        tr:nth-child(even) { background: #f9fafb; }
        .resumen { margin-bottom: 20px; padding: 10px; background: #eff6ff; border-radius: 4px; font-size: 9px; }
        .resumen strong { color: #2563eb; }
    </style>
</head>
<body>
    <h1>Listado de Estudiantes</h1>
    <div class="fecha">Generado: {{ $fecha }}</div>

    <div class="resumen">
        <strong>Total de estudiantes:</strong> {{ count($estudiantes) }}
    </div>

    <table>
        <thead>
            <tr>
                @foreach($campos as $campo)
                    <th>{{ ucfirst(str_replace('_', ' ', $campo)) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($estudiantes as $estudiante)
                <tr>
                    @foreach($campos as $campo)
                        <td>{{ $estudiante[$campo] ?? '' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
