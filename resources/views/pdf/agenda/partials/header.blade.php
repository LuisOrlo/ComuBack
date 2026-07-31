<div class="pdf-header">
    <div class="pdf-header-main">
        <h1>{{ $titulo }}</h1>
        <p class="pdf-subtitle">del {{ $fechaInicio }} al {{ $fechaFin }}</p>
    </div>
    <div class="pdf-legend">
        @foreach ($leyenda as $tipo => $info)
            @if (in_array($tipo, $tiposActivos, true))
                <span class="legend-item">
                    <span class="legend-dot" style="background: {{ $info['color'] }}"></span>
                    {{ $info['label'] }}
                </span>
            @endif
        @endforeach
    </div>
</div>
