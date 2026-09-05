{{-- Reporte de conversaciones por agente, en PDF (dompdf).
     Sin CSS moderno a propósito: dompdf no soporta flex ni grid, solo tablas y estilos
     básicos. Lo que se ve aquí es lo que imprime. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Conversaciones por agente</title>
    <style>
        @page { margin: 28px 32px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #1f2937;
        }

        .titulo   { font-size: 17px; font-weight: bold; margin: 0 0 2px; color: #064e3b; }
        .periodo  { font-size: 11px; margin: 0 0 2px; }
        .generado { font-size: 9px; color: #6b7280; margin: 0 0 16px; }

        table { width: 100%; border-collapse: collapse; }

        th {
            background: #064e3b;
            color: #fff;
            text-align: left;
            padding: 6px 8px;
            font-size: 10px;
        }

        td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }

        tr:nth-child(even) td { background: #f9fafb; }

        .num { text-align: right; }

        .total td {
            font-weight: bold;
            border-top: 2px solid #064e3b;
            border-bottom: none;
            background: #fff;
        }

        .vacio { padding: 14px 8px; color: #6b7280; }

        .pie {
            margin-top: 14px;
            font-size: 9px;
            color: #6b7280;
            border-top: 1px solid #e5e7eb;
            padding-top: 6px;
        }
    </style>
</head>
<body>
    <p class="titulo">Conversaciones por agente</p>
    <p class="periodo">
        Periodo: <strong>{{ $desde === $hasta ? $desde : $desde . ' a ' . $hasta }}</strong>
    </p>
    <p class="generado">Generado el {{ $genera }} (hora del centro de México)</p>

    <table>
        <thead>
            <tr>
                <th>Agente</th>
                <th>Rol</th>
                <th class="num">Recibidas en el periodo</th>
                <th class="num">Abiertas ahora</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td>{{ $r['agent'] }}</td>
                    <td>{{ ['admin' => 'Administrador', 'operator' => 'Operador', 'agent' => 'Agente'][$r['role']] ?? $r['role'] }}</td>
                    <td class="num">{{ $r['received'] }}</td>
                    <td class="num">{{ $r['open_now'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="vacio">Sin datos para este periodo.</td></tr>
            @endforelse

            @if (count($rows))
                <tr class="total">
                    <td>Total</td>
                    <td></td>
                    <td class="num">{{ $totals['received'] }}</td>
                    <td class="num">{{ $totals['open_now'] }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <p class="pie">
        <strong>Recibidas en el periodo</strong>: conversaciones que se le asignaron entre las
        fechas del filtro. <strong>Abiertas ahora</strong>: las que tiene a su cargo en este
        momento, sin importar cuándo se le asignaron - por eso no cambia con el filtro de fecha.
    </p>
</body>
</html>
