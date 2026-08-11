@page { margin: 8mm; }

* { box-sizing: border-box; }

body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    color: #010101;
    margin: 0;
    padding: 0;
}

.pdf-header {
    display: table;
    width: 100%;
    margin-bottom: 14px;
}

.pdf-header-main {
    display: table-cell;
    vertical-align: bottom;
}

.pdf-header-main h1 {
    font-size: 18px;
    font-weight: 800;
    margin: 0 0 2px;
    color: #D61A00;
    letter-spacing: 0.02em;
}

.pdf-subtitle {
    font-size: 11px;
    color: #464646;
    margin: 0;
    text-transform: capitalize;
}

.pdf-legend {
    display: table-cell;
    text-align: right;
    vertical-align: bottom;
    max-width: 55%;
}

.legend-item {
    font-size: 9px;
    color: #464646;
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
