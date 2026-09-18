@page { margin: 9mm; }

* { box-sizing: border-box; }

body {
    font-family: 'DejaVu Sans', Arial, sans-serif;
    color: #0b1c30;
    background: #f8f9ff;
    margin: 0;
    padding: 0;
}

.pdf-header {
    display: table;
    width: 100%;
    margin-bottom: 16px;
    padding-bottom: 11px;
    border-bottom: 1px solid #dce9ff;
}

.pdf-header-main {
    display: table-cell;
    vertical-align: bottom;
}

.pdf-header-main h1 {
    font-size: 19px;
    font-weight: 800;
    margin: 0 0 2px;
    color: #0b1c30;
    letter-spacing: -0.01em;
}

.pdf-subtitle {
    font-size: 10px;
    color: #45464d;
    margin: 0;
    text-transform: capitalize;
}

.pdf-legend {
    display: table-cell;
    text-align: right;
    vertical-align: bottom;
    max-width: 58%;
}

.legend-item {
    font-size: 8px;
    color: #45464d;
    display: inline;
    margin-left: 8px;
    white-space: nowrap;
}

.legend-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
    margin-right: 2px;
    vertical-align: middle;
}

.agenda-card {
    background: #ffffff;
    border: 1px solid #dce9ff;
    border-radius: 14px;
    padding: 8px;
}
