@page { margin: 8mm; }

* { box-sizing: border-box; }

body {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    color: #010101;
    margin: 0;
    padding: 0;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

.pdf-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-bottom: 14px;
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
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    max-width: 55%;
    justify-content: flex-end;
}

.legend-item {
    font-size: 9px;
    color: #464646;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
}

.legend-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
