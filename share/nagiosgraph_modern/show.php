<?php

declare(strict_types=1);

$host = isset($_GET['host']) ? trim((string) $_GET['host']) : '';
$service = isset($_GET['service']) ? trim((string) $_GET['service']) : '';
$range = isset($_GET['range']) ? trim((string) $_GET['range']) : '24h';
$embedMode = isset($_GET['embed']) && $_GET['embed'] === '1';
$hostExtInfoFile = dirname(__DIR__, 2) . '/etc/objects/hostextinfo.cfg';
$hostIconBaseUrl = '/nagios/images/logos/';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function parse_hostextinfo_icons(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }

    $icons = [];
    $insideBlock = false;
    $hostNames = [];
    $iconImage = null;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }

        if (strpos($trimmed, 'define hostextinfo') === 0) {
            $insideBlock = true;
            $hostNames = [];
            $iconImage = null;
            continue;
        }

        if (!$insideBlock) {
            continue;
        }

        if ($trimmed === '}') {
            if ($iconImage !== null) {
                foreach ($hostNames as $hostName) {
                    $icons[$hostName] = $iconImage;
                }
            }

            $insideBlock = false;
            $hostNames = [];
            $iconImage = null;
            continue;
        }

        if (preg_match('/^host_name\s+(.+)$/', $trimmed, $matches) === 1) {
            $hostNames = array_values(array_filter(array_map('trim', explode(',', $matches[1]))));
            continue;
        }

        if (preg_match('/^icon_image\s+(.+)$/', $trimmed, $matches) === 1) {
            $iconImage = trim($matches[1]);
        }
    }

    return $icons;
}

function host_icon_url(array $iconMap, string $baseUrl, string $hostName): ?string
{
    if (!isset($iconMap[$hostName]) || $iconMap[$hostName] === '') {
        return null;
    }

    return $baseUrl . rawurlencode($iconMap[$hostName]);
}

$hostIconMap = parse_hostextinfo_icons($hostExtInfoFile);
$hostIconUrl = $host !== '' ? host_icon_url($hostIconMap, $hostIconBaseUrl, $host) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="ROBOTS" content="NOINDEX, NOFOLLOW">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nagiosgraph Modern</title>
<link rel="icon" href="../nagios.png" type="image/png">
<style>
    :root {
        --bg: #07111d;
        --panel: rgba(12, 25, 41, 0.96);
        --panel-soft: rgba(18, 38, 61, 0.86);
        --border: rgba(99, 126, 156, 0.26);
        --text: #edf4fc;
        --muted: #95a6bc;
        --muted-2: #7387a1;
        --ok: #5fd09f;
        --warn: #f1c85a;
        --crit: #ff8f98;
        --shadow: 0 18px 36px rgba(0, 0, 0, 0.28);
        --radius-xl: 26px;
        --radius-lg: 18px;
        --radius-md: 14px;
    }

    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        min-height: 100vh;
        color: var(--text);
        font-family: "Segoe UI", Roboto, Ubuntu, Arial, sans-serif;
        background:
            radial-gradient(circle at top left, rgba(70, 114, 171, 0.16), transparent 28%),
            linear-gradient(180deg, #0b1523 0%, var(--bg) 100%);
    }

    body.embed {
        min-height: 0;
        overflow: hidden;
        background:
            linear-gradient(180deg, rgba(12, 25, 41, 0.98), rgba(8, 17, 29, 0.98));
    }

    .page {
        padding: 18px;
    }

    body.embed .page {
        padding: 0;
    }

    .wrap {
        max-width: 1480px;
        margin: 0 auto;
    }

    body.embed .wrap {
        max-width: none;
    }

    .hero,
    .panel {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: var(--radius-xl);
        box-shadow: var(--shadow);
    }

    .hero {
        display: grid;
        grid-template-columns: minmax(0, 1.5fr) minmax(260px, 0.7fr);
        gap: 18px;
        padding: 22px;
        margin-bottom: 18px;
        align-items: start;
    }

    .eyebrow {
        color: var(--muted-2);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.22em;
        text-transform: uppercase;
        margin-bottom: 10px;
    }

    h1 {
        margin: 0;
        font-size: 34px;
        line-height: 1.08;
        letter-spacing: -0.03em;
    }

    .hero p {
        margin: 12px 0 0 0;
        color: var(--muted);
        font-size: 14px;
    }

    .hero-side {
        display: grid;
        gap: 10px;
    }

    .info-card {
        padding: 14px 16px;
        border-radius: var(--radius-lg);
        background: var(--panel-soft);
        border: 1px solid rgba(99, 126, 156, 0.18);
    }

    .info-label {
        color: var(--muted);
        font-size: 12px;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.14em;
    }

    .info-value {
        font-size: 20px;
        font-weight: 700;
    }

    .host-value {
        display: inline-flex;
        align-items: center;
        gap: 10px;
    }

    .host-icon {
        width: 22px;
        height: 22px;
        border-radius: 5px;
        object-fit: contain;
        flex: 0 0 auto;
    }

    .layout {
        display: grid;
        grid-template-columns: minmax(0, 1.8fr) minmax(300px, 0.8fr);
        gap: 18px;
        align-items: start;
    }

    .panel {
        padding: 20px;
    }

    body.embed .panel {
        border-radius: 0;
        border: 0;
        box-shadow: none;
        padding: 10px 12px;
        background: transparent;
    }

    .panel-head {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
        margin-bottom: 16px;
    }

    .panel-head h2 {
        margin: 0;
        font-size: 18px;
    }

    .panel-head p {
        margin: 8px 0 0 0;
        color: var(--muted);
        font-size: 14px;
    }

    .browser-bar {
        display: grid;
        grid-template-columns: minmax(220px, 1fr) minmax(260px, 1fr) auto;
        gap: 12px;
        padding: 16px 18px;
        margin-bottom: 18px;
    }

    .browser-field label {
        display: block;
        margin-bottom: 8px;
        color: var(--muted);
        font-size: 12px;
        letter-spacing: 0.16em;
        text-transform: uppercase;
    }

    .browser-field select {
        width: 100%;
        padding: 12px 14px;
        border-radius: 14px;
        border: 1px solid rgba(99, 126, 156, 0.18);
        background: var(--panel-soft);
        color: var(--text);
        font-size: 15px;
    }

    .browser-action {
        display: flex;
        align-items: flex-end;
    }

    .range-tabs {
        display: inline-flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .range-tab {
        padding: 9px 12px;
        border-radius: 999px;
        border: 1px solid rgba(99, 126, 156, 0.18);
        background: var(--panel-soft);
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        cursor: pointer;
    }

    .range-tab.active {
        color: var(--text);
        border-color: rgba(95, 208, 159, 0.26);
        box-shadow: inset 0 0 0 1px rgba(95, 208, 159, 0.16);
    }

    .toolbar {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 8px;
    }

    .ghost-button {
        padding: 9px 12px;
        border-radius: 999px;
        border: 1px solid rgba(99, 126, 156, 0.18);
        background: var(--panel-soft);
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        cursor: pointer;
    }

    .ghost-button:disabled {
        opacity: 0.45;
        cursor: default;
    }

    .chart-wrap {
        position: relative;
        border-radius: 22px;
        background:
            linear-gradient(180deg, rgba(71, 102, 142, 0.08), rgba(8, 17, 29, 0.94)),
            #091321;
        border: 1px solid rgba(99, 126, 156, 0.18);
        min-height: 420px;
        overflow: hidden;
    }

    body.embed .chart-wrap {
        min-height: 250px;
        border-radius: 16px;
    }

    #chart {
        display: block;
        width: 100%;
        height: 420px;
    }

    body.embed #chart {
        height: 250px;
    }

    .brush-layer {
        position: absolute;
        inset: 0;
        z-index: 2;
        cursor: crosshair;
    }

    .brush-selection {
        position: absolute;
        top: 28px;
        bottom: 40px;
        border: 1px solid rgba(95, 208, 159, 0.65);
        background: rgba(95, 208, 159, 0.12);
        pointer-events: none;
        display: none;
    }

    .tooltip {
        position: absolute;
        min-width: 170px;
        padding: 10px 12px;
        border-radius: 12px;
        border: 1px solid rgba(99, 126, 156, 0.18);
        background: rgba(7, 17, 29, 0.96);
        color: var(--text);
        font-size: 12px;
        line-height: 1.45;
        pointer-events: none;
        opacity: 0;
        transform: translateY(6px);
        transition: opacity 120ms ease, transform 120ms ease;
        box-shadow: 0 16px 28px rgba(0, 0, 0, 0.28);
        z-index: 3;
    }

    .tooltip.visible {
        opacity: 1;
        transform: translateY(0);
    }

    .legend {
        display: grid;
        gap: 10px;
    }

    .legend-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 14px;
        border-radius: var(--radius-md);
        border: 1px solid rgba(99, 126, 156, 0.16);
        background: var(--panel-soft);
    }

    .legend-left {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }

    .legend-color {
        width: 12px;
        height: 12px;
        border-radius: 999px;
        flex: 0 0 auto;
    }

    .legend-name {
        font-size: 14px;
        font-weight: 600;
    }

    .legend-toggle {
        margin-left: auto;
        cursor: pointer;
    }

    .legend-checkbox {
        accent-color: #5fd09f;
    }

    .stats {
        display: grid;
        gap: 10px;
    }

    .stat-card {
        padding: 14px 16px;
        border-radius: var(--radius-md);
        border: 1px solid rgba(99, 126, 156, 0.16);
        background: var(--panel-soft);
    }

    .stat-name {
        color: var(--muted);
        font-size: 12px;
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.16em;
    }

    .stat-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px 14px;
    }

    .stat-line {
        color: var(--muted);
        font-size: 13px;
    }

    .stat-line strong {
        display: block;
        color: var(--text);
        font-size: 16px;
        margin-top: 2px;
    }

    .empty {
        padding: 24px;
        border-radius: 18px;
        color: var(--muted);
        border: 1px dashed rgba(99, 126, 156, 0.18);
        background: rgba(18, 38, 61, 0.48);
    }

    body.embed .empty {
        margin-top: 8px;
        padding: 10px 12px;
        border-radius: 14px;
    }

    .error {
        color: var(--crit);
    }

    @media (max-width: 1100px) {
        .hero,
        .layout,
        .browser-bar {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 720px) {
        .page {
            padding: 12px;
        }

        h1 {
            font-size: 28px;
        }

        #chart,
        .chart-wrap {
            min-height: 320px;
            height: 320px;
        }
    }
    body.embed .embed-head {
        display: flex;
        justify-content: flex-start;
        gap: 12px;
        align-items: center;
        margin-bottom: 8px;
    }

    body.embed .embed-title {
        min-width: 0;
    }

    body.embed .embed-title strong {
        display: block;
        color: var(--text);
        font-size: 14px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    body.embed .embed-title span {
        display: block;
        margin-top: 2px;
        color: var(--muted);
        font-size: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    body.embed .range-tabs,
    body.embed .panel-head,
    body.embed .hero,
    body.embed .layout > div:last-child {
        display: none;
    }
</style>
</head>
<body class="<?= $embedMode ? 'embed' : '' ?>">
<div class="page">
    <div class="wrap">
        <?php if ($embedMode): ?>
            <section class="panel">
                <div class="embed-head">
                    <div class="embed-title">
                        <strong><?= h($service !== '' ? $service : 'Service Graph') ?></strong>
                        <span><?= h($host !== '' ? $host : 'n/a') ?></span>
                    </div>
                </div>
                <div class="chart-wrap">
                    <svg id="chart" viewBox="0 0 980 420" preserveAspectRatio="none" aria-hidden="true"></svg>
                    <div id="brush-layer" class="brush-layer"></div>
                    <div id="brush-selection" class="brush-selection"></div>
                    <div id="tooltip" class="tooltip"></div>
                </div>
                <div id="chart-empty" class="empty" style="display:none;"></div>
            </section>
        <?php else: ?>
        <section class="panel browser-bar">
            <div class="browser-field">
                <label for="host-select">Host</label>
                <select id="host-select">
                    <option value="">Select host</option>
                </select>
            </div>
            <div class="browser-field">
                <label for="service-select">Service</label>
                <select id="service-select" disabled>
                    <option value="">Select service</option>
                </select>
            </div>
            <div class="browser-action">
                <button id="update-graph" class="ghost-button">Update Graphs</button>
            </div>
        </section>

        <section class="hero">
            <div>
                <div class="eyebrow">Nagiosgraph Modern</div>
                <h1 id="hero-service-title"><?= h($service !== '' ? $service : 'Service Graph') ?></h1>
                <p id="hero-host-subtitle"><?= h($host !== '' ? 'Host: ' . $host : 'Select a host and service to render the graph.') ?></p>
            </div>
            <div class="hero-side">
                <div class="info-card">
                    <div class="info-label">Selected host</div>
                    <div class="info-value host-value">
                        <img
                            id="selected-host-icon"
                            class="host-icon"
                            src="<?= h($hostIconUrl ?? '') ?>"
                            alt=""
                            style="<?= $hostIconUrl === null ? 'display:none;' : '' ?>"
                        >
                        <span id="selected-host-label"><?= h($host !== '' ? $host : 'n/a') ?></span>
                    </div>
                </div>
                <div class="info-card">
                    <div class="info-label">Selected service</div>
                    <div id="selected-service-label" class="info-value"><?= h($service !== '' ? $service : 'n/a') ?></div>
                </div>
            </div>
        </section>

        <div class="layout">
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Performance graph</h2>
                        <p>Standalone graph layer on top of the existing Nagiosgraph RRD files. Drag inside the chart to zoom on a time range.</p>
                    </div>
                    <div class="toolbar">
                        <div class="range-tabs" id="range-tabs">
                            <?php foreach (['1h', '6h', '24h', '7d', '30d', '365d'] as $tab): ?>
                                <button class="range-tab<?= $tab === $range ? ' active' : '' ?>" data-range="<?= h($tab) ?>"><?= h($tab) ?></button>
                            <?php endforeach; ?>
                        </div>
                        <button id="reset-zoom" class="ghost-button" disabled>Reset Zoom</button>
                    </div>
                </div>
                <div class="chart-wrap">
                    <svg id="chart" viewBox="0 0 980 420" preserveAspectRatio="none" aria-hidden="true"></svg>
                    <div id="brush-layer" class="brush-layer"></div>
                    <div id="brush-selection" class="brush-selection"></div>
                    <div id="tooltip" class="tooltip"></div>
                </div>
                <div id="chart-empty" class="empty" style="display:none;"></div>
            </section>

            <div style="display:grid; gap:18px;">
                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h2>Series</h2>
                            <p>Toggle individual metrics on and off.</p>
                        </div>
                    </div>
                    <div id="legend" class="legend"></div>
                </section>

                <section class="panel">
                    <div class="panel-head">
                        <div>
                            <h2>Summary</h2>
                            <p>Current and aggregated values for visible series.</p>
                        </div>
                    </div>
                    <div id="stats" class="stats"></div>
                </section>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const state = {
    host: <?= json_encode($host) ?>,
    service: <?= json_encode($service) ?>,
    range: <?= json_encode($range) ?>,
    data: null,
    hidden: new Set(),
};

const palette = [
    '#5fd09f',
    '#6caef7',
    '#f3c65d',
    '#ff8f98',
    '#b594ff',
    '#69d2e7',
    '#fca86d',
];

const chart = document.getElementById('chart');
const tooltip = document.getElementById('tooltip');
const legend = document.getElementById('legend');
const stats = document.getElementById('stats');
const empty = document.getElementById('chart-empty');
const brushLayer = document.getElementById('brush-layer');
const brushSelection = document.getElementById('brush-selection');
const resetZoomButton = document.getElementById('reset-zoom');
const hostSelect = document.getElementById('host-select');
const serviceSelect = document.getElementById('service-select');
const updateGraphButton = document.getElementById('update-graph');
const selectedHostLabel = document.getElementById('selected-host-label');
const selectedServiceLabel = document.getElementById('selected-service-label');
const selectedHostIcon = document.getElementById('selected-host-icon');
const heroServiceTitle = document.getElementById('hero-service-title');
const heroHostSubtitle = document.getElementById('hero-host-subtitle');
const hostIconMap = <?= json_encode($hostIconMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const hostIconBaseUrl = <?= json_encode($hostIconBaseUrl, JSON_UNESCAPED_SLASHES) ?>;
const CHART_DIMENSIONS = {
    width: 980,
    height: 420,
    margin: { top: 28, right: 22, bottom: 40, left: 70 },
};
state.zoom = null;
state.zoomHistory = [];
state.viewport = null;
state.brush = null;
state.valueFormatter = null;

function metricLooksLikeBytes(name) {
    const value = (name || '').toLowerCase();
    return [
        'octet',
        'byte',
        'bytes',
        'space',
        'memory',
        'swap',
        'partition',
        'disk',
        'storage',
        'size'
    ].some((token) => value.includes(token));
}

function computeBounds(seriesList) {
    const values = [];

    seriesList.forEach((series) => {
        series.points.forEach((point) => {
            if (point.value !== null) {
                values.push(point.value);
            }
        });
    });

    if (!values.length) {
        return { min: 0, max: 1, span: 1 };
    }

    let min = Math.min(...values);
    let max = Math.max(...values);

    if (Math.abs(max - min) < 0.0001) {
        min -= 1;
        max += 1;
    } else {
        const padding = (max - min) * 0.12;
        min -= padding;
        max += padding;
    }

    return { min, max, span: Math.max(0.0001, max - min) };
}

function createValueFormatter(seriesList, bounds) {
    const byteSeries = metricLooksLikeBytes(state.service) || seriesList.some((series) => metricLooksLikeBytes(series.metric));

    if (byteSeries) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

        return {
            format(value) {
                if (value === null || Number.isNaN(value)) {
                    return 'n/a';
                }

                let scaled = Math.abs(value);
                let unitIndex = 0;

                while (scaled >= 1024 && unitIndex < units.length - 1) {
                    scaled /= 1024;
                    unitIndex++;
                }

                const signed = value < 0 ? -scaled : scaled;
                let scaledSpan = bounds && bounds.span ? bounds.span : 0;
                let spanUnitIndex = 0;
                while (scaledSpan >= 1024 && spanUnitIndex < units.length - 1) {
                    scaledSpan /= 1024;
                    spanUnitIndex++;
                }

                let decimals = Math.abs(signed) >= 100 ? 1 : (Math.abs(signed) >= 10 ? 2 : 3);
                if (spanUnitIndex === unitIndex) {
                    if (scaledSpan < 0.1) {
                        decimals = Math.max(decimals, 4);
                    } else if (scaledSpan < 1) {
                        decimals = Math.max(decimals, 3);
                    }
                }
                return `${signed.toFixed(decimals)} ${units[unitIndex]}`;
            },
        };
    }

    return {
        format(value) {
            if (value === null || Number.isNaN(value)) {
                return 'n/a';
            }

            if (Math.abs(value) >= 1000) {
                return value.toFixed(1);
            }

            if (Math.abs(value) >= 100) {
                return value.toFixed(1);
            }

            if (Math.abs(value) >= 10) {
                return value.toFixed(2);
            }

            return value.toFixed(3);
        },
    };
}

function formatValue(value) {
    if (state.valueFormatter) {
        return state.valueFormatter.format(value);
    }

    if (value === null || Number.isNaN(value)) {
        return 'n/a';
    }

    if (Math.abs(value) >= 1000) {
        return value.toFixed(1);
    }

    if (Math.abs(value) >= 100) {
        return value.toFixed(1);
    }

    if (Math.abs(value) >= 10) {
        return value.toFixed(2);
    }

    return value.toFixed(3);
}

function formatTimestamp(epoch) {
    const date = new Date(epoch * 1000);
    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit'
    }).format(date);
}

function buildUrl() {
    const params = new URLSearchParams({
        host: state.host,
        service: state.service,
        range: state.range,
    });

    return `data.php?${params.toString()}`;
}

function updateSelectionLabels() {
    if (selectedHostLabel) {
        selectedHostLabel.textContent = state.host || 'n/a';
    }

    if (selectedServiceLabel) {
        selectedServiceLabel.textContent = state.service || 'n/a';
    }

    if (selectedHostIcon) {
        const icon = state.host && hostIconMap[state.host] ? `${hostIconBaseUrl}${encodeURIComponent(hostIconMap[state.host])}` : '';
        if (icon) {
            selectedHostIcon.src = icon;
            selectedHostIcon.style.display = '';
        } else {
            selectedHostIcon.removeAttribute('src');
            selectedHostIcon.style.display = 'none';
        }
    }
}

function updateHeroLabels() {
    if (heroServiceTitle) {
        heroServiceTitle.textContent = state.service || 'Service Graph';
    }

    if (heroHostSubtitle) {
        heroHostSubtitle.textContent = state.host ? `Host: ${state.host}` : 'Select a host and service to render the graph.';
    }
}

async function loadHosts() {
    if (!hostSelect) {
        return;
    }

    const response = await fetch('data.php?list=hosts', { credentials: 'same-origin' });
    const payload = await response.json();
    const hosts = payload.hosts || [];

    hostSelect.innerHTML = '<option value="">Select host</option>';
    hosts.forEach((host) => {
        const option = document.createElement('option');
        option.value = host;
        option.textContent = host;
        if (host === state.host) {
            option.selected = true;
        }
        hostSelect.appendChild(option);
    });
}

async function loadServices(selectedHost) {
    if (!serviceSelect) {
        return;
    }

    if (!selectedHost) {
        serviceSelect.innerHTML = '<option value="">Select service</option>';
        serviceSelect.disabled = true;
        return;
    }

    const params = new URLSearchParams({ list: 'services', host: selectedHost });
    const response = await fetch(`data.php?${params.toString()}`, { credentials: 'same-origin' });
    const payload = await response.json();
    const services = payload.services || [];

    serviceSelect.innerHTML = '<option value="">Select service</option>';
    services.forEach((service) => {
        const option = document.createElement('option');
        option.value = service;
        option.textContent = service;
        if (service === state.service) {
            option.selected = true;
        }
        serviceSelect.appendChild(option);
    });
    serviceSelect.disabled = false;
}

async function loadData() {
    if (!state.host || !state.service) {
        empty.style.display = 'block';
        empty.classList.remove('error');
        empty.textContent = 'Select a host and a service to render the graph.';
        chart.innerHTML = '';
        if (legend) {
            legend.innerHTML = '';
        }
        if (stats) {
            stats.innerHTML = '';
        }
        return;
    }

    try {
        const response = await fetch(buildUrl(), { credentials: 'same-origin' });
        const payload = await response.json();

        if (!response.ok || payload.error) {
            throw new Error(payload.error || 'Unable to load graph data.');
        }

        state.data = payload;
        render();
    } catch (error) {
        empty.style.display = 'block';
        empty.classList.add('error');
        empty.textContent = error.message;
        chart.innerHTML = '';
        legend.innerHTML = '';
        stats.innerHTML = '';
    }
}

function visibleSeries() {
    if (!state.data) {
        return [];
    }

    return state.data.series.filter((series) => !state.hidden.has(series.metric_key));
}

function zoomedSeries() {
    return visibleSeries().map((series) => {
        const points = state.zoom
            ? series.points.filter((point) => point.timestamp >= state.zoom.start && point.timestamp <= state.zoom.end)
            : series.points.slice();

        return {
            ...series,
            points,
            stats: series_stats(points),
        };
    }).filter((series) => series.points.length > 0);
}

function series_stats(points) {
    const values = points
        .filter((point) => point.value !== null)
        .map((point) => Number(point.value));

    if (!values.length) {
        return { current: null, avg: null, min: null, max: null };
    }

    return {
        current: values[values.length - 1],
        avg: values.reduce((sum, value) => sum + value, 0) / values.length,
        min: Math.min(...values),
        max: Math.max(...values),
    };
}

function renderLegend() {
    if (!state.data || !legend) {
        return;
    }

    legend.innerHTML = '';

    state.data.series.forEach((series, index) => {
        const row = document.createElement('label');
        row.className = 'legend-item';

        const left = document.createElement('div');
        left.className = 'legend-left';

        const color = document.createElement('span');
        color.className = 'legend-color';
        color.style.backgroundColor = seriesColor(series, index);

        const name = document.createElement('div');
        name.className = 'legend-name';
        name.textContent = series.metric;

        left.appendChild(color);
        left.appendChild(name);

        const toggle = document.createElement('span');
        toggle.className = 'legend-toggle';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'legend-checkbox';
        checkbox.checked = !state.hidden.has(series.metric_key);
        checkbox.addEventListener('change', () => {
            if (checkbox.checked) {
                state.hidden.delete(series.metric_key);
            } else {
                state.hidden.add(series.metric_key);
            }
            render();
        });

        toggle.appendChild(checkbox);
        row.appendChild(left);
        row.appendChild(toggle);
        legend.appendChild(row);
    });
}

function renderStats() {
    if (!state.data || !stats) {
        return;
    }

    stats.innerHTML = '';

    zoomedSeries().forEach((series, index) => {
        const card = document.createElement('div');
        card.className = 'stat-card';

        const title = document.createElement('div');
        title.className = 'stat-name';
        title.textContent = series.metric;
        title.style.color = seriesColor(series, index);

        const grid = document.createElement('div');
        grid.className = 'stat-grid';

        const fields = [
            ['Current', series.stats.current],
            ['Average', series.stats.avg],
            ['Min', series.stats.min],
            ['Max', series.stats.max],
        ];

        fields.forEach(([label, value]) => {
            const line = document.createElement('div');
            line.className = 'stat-line';
            line.innerHTML = `${label}<strong>${formatValue(value)}</strong>`;
            grid.appendChild(line);
        });

        card.appendChild(title);
        card.appendChild(grid);
        stats.appendChild(card);
    });
}

function seriesColor(series, index) {
    if (series.series_type === 'threshold') {
        if (series.threshold_kind === 'crit' || series.threshold_kind === 'critical') {
            return '#ff8f98';
        }

        if (series.threshold_kind === 'warn' || series.threshold_kind === 'warning') {
            return '#f3c65d';
        }
    }

    return palette[index % palette.length];
}

function renderChart() {
    const seriesList = zoomedSeries();

    if (!seriesList.length) {
        empty.style.display = 'block';
        empty.classList.remove('error');
        empty.textContent = 'No visible data series. Enable at least one series to render the graph.';
        chart.innerHTML = '';
        return;
    }

    empty.style.display = 'none';

    const width = CHART_DIMENSIONS.width;
    const height = CHART_DIMENSIONS.height;
    const margin = CHART_DIMENSIONS.margin;
    const innerWidth = width - margin.left - margin.right;
    const innerHeight = height - margin.top - margin.bottom;
    const bounds = computeBounds(seriesList);

    const allPoints = seriesList.flatMap((series) => series.points);
    const timestamps = allPoints.map((point) => point.timestamp);
    const minTs = Math.min(...timestamps);
    const maxTs = Math.max(...timestamps);
    const tsSpan = Math.max(1, maxTs - minTs);
    const valSpan = Math.max(0.0001, bounds.span);

    const x = (timestamp) => margin.left + ((timestamp - minTs) / tsSpan) * innerWidth;
    const y = (value) => margin.top + (1 - ((value - bounds.min) / valSpan)) * innerHeight;
    state.viewport = { minTs, maxTs, width, innerWidth, margin };
    if (resetZoomButton) {
        resetZoomButton.disabled = state.zoom === null && state.zoomHistory.length === 0;
    }

    const gridTicks = 5;
    let svg = '';

    for (let i = 0; i <= gridTicks; i++) {
        const ratio = i / gridTicks;
        const tickValue = bounds.max - (valSpan * ratio);
        const py = margin.top + ratio * innerHeight;

        svg += `<line x1="${margin.left}" y1="${py}" x2="${width - margin.right}" y2="${py}" stroke="rgba(115,135,161,0.18)" stroke-width="1" />`;
        svg += `<text x="${margin.left - 12}" y="${py + 4}" fill="#8092ab" font-size="12" text-anchor="end">${formatValue(tickValue)}</text>`;
    }

    const xTicks = 5;
    for (let i = 0; i <= xTicks; i++) {
        const ratio = i / xTicks;
        const tickTs = Math.round(minTs + tsSpan * ratio);
        const px = margin.left + ratio * innerWidth;

        svg += `<text x="${px}" y="${height - 14}" fill="#8092ab" font-size="12" text-anchor="middle">${formatTimestamp(tickTs)}</text>`;
    }

    seriesList.forEach((series, index) => {
        const color = seriesColor(series, index);
        const points = series.points
            .filter((point) => point.value !== null)
            .map((point) => `${x(point.timestamp)},${y(point.value)}`);

        if (!points.length) {
            return;
        }

        const strokeWidth = series.series_type === 'threshold' ? 1.8 : 2.4;
        const strokeDash = series.series_type === 'threshold' ? '6 6' : '';
        const strokeOpacity = series.series_type === 'threshold' ? '0.9' : '1';

        svg += `<polyline fill="none" stroke="${color}" stroke-width="${strokeWidth}" stroke-linejoin="round" stroke-linecap="round" stroke-dasharray="${strokeDash}" stroke-opacity="${strokeOpacity}" points="${points.join(' ')}" />`;

        series.points.forEach((point) => {
            if (point.value === null) {
                return;
            }

            const cx = x(point.timestamp);
            const cy = y(point.value);
            const safeMetric = series.metric
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            const radius = series.series_type === 'threshold' ? 4 : 5;
            svg += `<circle class="hover-point" cx="${cx}" cy="${cy}" r="${radius}" fill="${color}" fill-opacity="0" stroke="transparent" data-metric="${safeMetric}" data-value="${point.value}" data-ts="${point.timestamp}" />`;
        });
    });

    chart.innerHTML = svg;

    chart.querySelectorAll('.hover-point').forEach((node) => {
        node.addEventListener('mouseenter', (event) => {
            const target = event.currentTarget;
            tooltip.innerHTML = `
                <div><strong>${target.dataset.metric}</strong></div>
                <div>${formatValue(Number(target.dataset.value))}</div>
                <div>${formatTimestamp(Number(target.dataset.ts))}</div>
            `;

            const rect = chart.getBoundingClientRect();
            const hostRect = chart.parentElement.getBoundingClientRect();
            tooltip.style.left = `${rect.left - hostRect.left + Number(target.getAttribute('cx')) + 12}px`;
            tooltip.style.top = `${rect.top - hostRect.top + Number(target.getAttribute('cy')) - 12}px`;
            tooltip.classList.add('visible');
            target.setAttribute('fill-opacity', '1');
        });

        node.addEventListener('mouseleave', (event) => {
            tooltip.classList.remove('visible');
            event.currentTarget.setAttribute('fill-opacity', '0');
        });
    });
}

function render() {
    const seriesList = zoomedSeries();
    const bounds = computeBounds(seriesList);
    state.valueFormatter = createValueFormatter(seriesList, bounds);
    renderLegend();
    renderStats();
    renderChart();
}

function brushClientX(clientX) {
    const rect = brushLayer.getBoundingClientRect();
    const x = clientX - rect.left;
    const width = rect.width;
    const plotLeft = (CHART_DIMENSIONS.margin.left / CHART_DIMENSIONS.width) * width;
    const plotRight = width - ((CHART_DIMENSIONS.margin.right / CHART_DIMENSIONS.width) * width);

    return Math.max(plotLeft, Math.min(plotRight, x));
}

function brushToTimestamp(clientX) {
    if (!state.viewport) {
        return null;
    }

    const rect = brushLayer.getBoundingClientRect();
    const width = rect.width;
    const plotLeft = (CHART_DIMENSIONS.margin.left / CHART_DIMENSIONS.width) * width;
    const plotWidth = width - plotLeft - ((CHART_DIMENSIONS.margin.right / CHART_DIMENSIONS.width) * width);
    const relative = (brushClientX(clientX) - plotLeft) / Math.max(1, plotWidth);

    return Math.round(state.viewport.minTs + ((state.viewport.maxTs - state.viewport.minTs) * relative));
}

function brushXToTimestamp(x) {
    if (!state.viewport) {
        return null;
    }

    const rect = brushLayer.getBoundingClientRect();
    const width = rect.width;
    const plotLeft = (CHART_DIMENSIONS.margin.left / CHART_DIMENSIONS.width) * width;
    const plotWidth = width - plotLeft - ((CHART_DIMENSIONS.margin.right / CHART_DIMENSIONS.width) * width);
    const relative = (x - plotLeft) / Math.max(1, plotWidth);

    return Math.round(state.viewport.minTs + ((state.viewport.maxTs - state.viewport.minTs) * relative));
}

brushLayer.addEventListener('mousedown', (event) => {
    if (!state.viewport) {
        return;
    }

    const startX = brushClientX(event.clientX);
    state.brush = { startX, endX: startX };
    brushSelection.style.display = 'block';
    brushSelection.style.left = `${startX}px`;
    brushSelection.style.width = '0px';
});

window.addEventListener('mousemove', (event) => {
    if (!state.brush) {
        return;
    }

    const endX = brushClientX(event.clientX);
    state.brush.endX = endX;

    const left = Math.min(state.brush.startX, endX);
    const width = Math.abs(endX - state.brush.startX);

    brushSelection.style.left = `${left}px`;
    brushSelection.style.width = `${width}px`;
});

window.addEventListener('mouseup', (event) => {
    if (!state.brush) {
        return;
    }

    const endX = brushClientX(event.clientX);
    const width = Math.abs(endX - state.brush.startX);
    brushSelection.style.display = 'none';

    if (width >= 18) {
        const leftX = Math.min(state.brush.startX, endX);
        const rightX = Math.max(state.brush.startX, endX);
        const startTs = brushXToTimestamp(leftX);
        const endTs = brushXToTimestamp(rightX);

        if (startTs !== null && endTs !== null && endTs > startTs) {
            state.zoomHistory.push(state.zoom ? { ...state.zoom } : null);
            state.zoom = { start: startTs, end: endTs };
            render();
        }
    }

    state.brush = null;
});

if (resetZoomButton) {
    resetZoomButton.addEventListener('click', () => {
        state.zoom = null;
        state.zoomHistory = [];
        render();
    });
}

brushLayer.addEventListener('contextmenu', (event) => {
    event.preventDefault();

    if (state.zoomHistory.length > 0) {
        state.zoom = state.zoomHistory.pop();
    } else {
        state.zoom = null;
    }

    render();
});

document.querySelectorAll('.range-tab').forEach((button) => {
    button.addEventListener('click', () => {
        state.range = button.dataset.range;
        document.querySelectorAll('.range-tab').forEach((item) => item.classList.remove('active'));
        button.classList.add('active');

        const url = new URL(window.location.href);
        url.searchParams.set('range', state.range);
        window.history.replaceState({}, '', url.toString());
        loadData();
    });
});

if (hostSelect) {
    hostSelect.addEventListener('change', async () => {
        state.host = hostSelect.value;
        state.service = '';
        state.zoom = null;
        updateSelectionLabels();
        await loadServices(state.host);
        const url = new URL(window.location.href);
        if (state.host) {
            url.searchParams.set('host', state.host);
        } else {
            url.searchParams.delete('host');
        }
        url.searchParams.delete('service');
        window.history.replaceState({}, '', url.toString());
        loadData();
    });
}

if (serviceSelect) {
    serviceSelect.addEventListener('change', () => {
        state.service = serviceSelect.value;
        updateSelectionLabels();
    });
}

if (updateGraphButton) {
    updateGraphButton.addEventListener('click', () => {
        state.host = hostSelect ? hostSelect.value : state.host;
        state.service = serviceSelect ? serviceSelect.value : state.service;
        state.zoom = null;
        updateSelectionLabels();
        updateHeroLabels();
        const url = new URL(window.location.href);
        if (state.host) {
            url.searchParams.set('host', state.host);
        } else {
            url.searchParams.delete('host');
        }
        if (state.service) {
            url.searchParams.set('service', state.service);
        } else {
            url.searchParams.delete('service');
        }
        url.searchParams.set('range', state.range);
        window.history.replaceState({}, '', url.toString());
        loadData();
    });
}
(async function init() {
    updateSelectionLabels();
    updateHeroLabels();
    await loadHosts();
    await loadServices(state.host);
    loadData();
}());
</script>
</body>
</html>
