<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Listado General de Estudiantes - Comunikate Academy</title>
    <style>
        @page {
            margin: 15mm 12mm;
        }
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 8.5px;
            color: #374151;
            margin: 0;
            padding: 0;
        }
        .header {
            text-align: center;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e89400;
        }
        .header h1 {
            font-size: 15px;
            font-weight: bold;
            color: #1f2937;
            margin: 0 0 3px 0;
            letter-spacing: 0.5px;
        }
        .header .subtitle {
            font-size: 8.5px;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-box {
            border: 1px solid #e5e7eb;
            background-color: #f9fafb;
            padding: 6px 10px;
            margin-bottom: 12px;
            font-size: 8px;
        }
        .info-box table {
            width: 100%;
            border-collapse: collapse;
        }
        .info-box td {
            padding: 2px 0;
            border: none;
        }
        .info-box strong {
            color: #1f2937;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        table.data-table th {
            background-color: #1f2937;
            color: #ffffff;
            padding: 5px 4px;
            text-align: center;
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
            border: 1px solid #374151;
        }
        table.data-table td {
            padding: 4px 4px;
            border: 1px solid #e5e7eb;
            font-size: 7.5px;
            text-align: center;
        }
        table.data-table tr:nth-child(even) {
            background-color: #f9fafb;
        }
        .text-left { text-align: left !important; }
        .text-center { text-align: center !important; }
        .text-right { text-align: right !important; }
        .badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
        }
        .badge-al-dia { background-color: #d1fae5; color: #065f46; }
        .badge-deudor { background-color: #fee2e2; color: #991b1b; }
        .badge-abonado { background-color: #fef3c7; color: #92400e; }
        .footer {
            position: fixed;
            bottom: -10mm;
            left: 0;
            right: 0;
            border-top: 0.5px solid #d1d5db;
            padding-top: 3px;
            font-size: 7px;
            color: #9ca3af;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>COMUNIKATE ACADEMY</h1>
        <div class="subtitle">Listado General de Estudiantes</div>
    </div>

    <div class="info-box">
        <table>
            <tr>
                <td style="width: 50%;"><strong>REPORTE:</strong> LISTADO GENERAL</td>
                <td style="width: 50%; text-align: right;"><strong>TOTAL REGISTROS:</strong> {{ count($estudiantes) }}</td>
            </tr>
            <tr>
                <td><strong>FECHA DE EMISIÓN:</strong> {{ $fecha }}</td>
                <td style="text-align: right;"><strong>ESTADO:</strong> OFICIAL</td>
            </tr>
        </table>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 20px;">N°</th>
                @foreach($campos as $campo)
                    @php
                        $c = strtolower($campo);
                        $isLeft = in_array($c, ['nombres', 'apellidos', 'correo', 'direccion']);
                    @endphp
                    <th class="{{ $isLeft ? 'text-left' : 'text-center' }}">
                        {{ strtoupper(str_replace('_', ' ', $campo)) }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($estudiantes as $index => $estudiante)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    @foreach($campos as $campo)
                        @php
                            $c = strtolower($campo);
                            $val = $estudiante[$campo] ?? '';
                            $isLeft = in_array($c, ['nombres', 'apellidos', 'correo', 'direccion']);
                            $isMoney = in_array($c, ['saldo', 'saldo_pendiente']);
                            $isStatus = in_array($c, ['estado_pago', 'estado_financiero']);
                        @endphp
                        <td class="{{ $isLeft ? 'text-left' : 'text-center' }}">
                            @if($isMoney)
                                {{ $val !== '' && is_numeric($val) ? '$' . number_format((float)$val, 2) : '—' }}
                            @elseif($isStatus)
                                @php
                                    $st = strtolower((string)$val);
                                @endphp
                                @if($st === 'al_dia' || $st === 'al día')
                                    <span class="badge badge-al-dia">AL DÍA</span>
                                @elseif($st === 'deudor' || $st === 'pendiente')
                                    <span class="badge badge-deudor">PENDIENTE</span>
                                @elseif($st === 'abonado')
                                    <span class="badge badge-abonado">ABONADO</span>
                                @else
                                    {{ strtoupper($val ?: '—') }}
                                @endif
                            @else
                                {{ $val !== '' ? ($isLeft ? $val : strtoupper((string)$val)) : '—' }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($campos) + 1 }}" style="text-align: center; padding: 12px; color: #9ca3af; font-style: italic;">
                        No se encontraron registros para exportar.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Comunikate Academy  |  Generado: {{ $fecha }}
    </div>
</body>
</html>
