<?php

$statusFile = '../var/status.dat';
$objectCacheFile = __DIR__ . '/../var/objects.cache';
$hostExtInfoFile = __DIR__ . '/../etc/objects/hostextinfo.cfg';
$hostIconBaseUrl = '/nagios/images/logos/';
$serviceDetailBaseUrl = '/nagios/cgi-bin/extinfo.cgi?type=2';
$graphShowBaseUrl = '/nagios/nagiosgraph_modern/show.php';
$graphActionIconUrl = '/nagios/images/action.gif';
$rrdRoot = '/usr/local/nagiosgraph/var/rrd';
$refreshSeconds = 30;

function parse_status_dat(string $path): array
{
    $result = [
        'service' => [],
        'host' => [],
    ];

    if (!is_readable($path)) {
        return $result;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return $result;
    }

    $type = null;
    $current = [];

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if ($line === 'servicestatus {') {
            $type = 'service';
            $current = [];
            continue;
        }

        if ($line === 'hoststatus {') {
            $type = 'host';
            $current = [];
            continue;
        }

        if ($line === '}') {
            if ($type !== null && $current !== []) {
                $result[$type][] = $current;
            }

            $type = null;
            $current = [];
            continue;
        }

        if ($type === null || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $current[trim($key)] = trim($value);
    }

    fclose($handle);

    return $result;
}

function value_int(array $row, string $key): int
{
    return isset($row[$key]) ? (int) $row[$key] : 0;
}

function host_address_from_cache(string $path, string $hostName): ?string
{
    if ($hostName === '' || !is_readable($path)) {
        return null;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }

    $inHost = false;
    $host = [];
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if (preg_match('/^define\s+host\s*\{?$/', $line) === 1) {
            $inHost = true;
            $host = [];
            continue;
        }
        if (!$inHost) {
            continue;
        }
        if ($line === '}') {
            if (($host['host_name'] ?? '') === $hostName) {
                fclose($handle);
                $address = trim($host['address'] ?? '');
                return $address !== '' ? $address : null;
            }
            $inHost = false;
            continue;
        }
        if (preg_match('/^(host_name|address)\s+(.+)$/', $line, $matches) === 1) {
            $host[$matches[1]] = trim($matches[2]);
        }
    }

    fclose($handle);
    return null;
}

function value_string(array $row, string $key, string $fallback = '-'): string
{
    if (!isset($row[$key])) {
        return $fallback;
    }

    $value = trim((string) $row[$key]);

    return $value !== '' ? $value : $fallback;
}

function is_acknowledged(array $row): bool
{
    return value_int($row, 'problem_has_been_acknowledged') > 0;
}

function is_in_downtime(array $row): bool
{
    return value_int($row, 'scheduled_downtime_depth') > 0;
}

function is_flapping(array $row): bool
{
    return value_int($row, 'is_flapping') > 0;
}

function is_hard_state(array $row): bool
{
    return value_int($row, 'state_type') === 1;
}

function format_duration(int $seconds): string
{
    if ($seconds <= 0) {
        return 'n/a';
    }

    if ($seconds < 60) {
        return $seconds . 's';
    }

    if ($seconds < 3600) {
        return floor($seconds / 60) . 'm';
    }

    if ($seconds < 86400) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }

    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);

    return $days . 'd ' . $hours . 'h';
}

function format_timestamp(int $timestamp): string
{
    return $timestamp > 0 ? date('d M Y H:i', $timestamp) : 'n/a';
}

function state_label(int $state, string $type): string
{
    if ($type === 'host') {
        switch ($state) {
            case 0:
                return 'UP';
            case 1:
                return 'DOWN';
            case 2:
                return 'UNREACHABLE';
            default:
                return 'PENDING';
        }
    }

    switch ($state) {
        case 0:
            return 'OK';
        case 1:
            return 'WARNING';
        case 2:
            return 'CRITICAL';
        case 3:
            return 'UNKNOWN';
        default:
            return 'PENDING';
    }
}

function state_theme(int $state, string $type): string
{
    if ($type === 'host') {
        if ($state === 1) {
            return 'critical';
        }
        if ($state === 2) {
            return 'unknown';
        }
        return 'ok';
    }

    if ($state === 2) {
        return 'critical';
    }
    if ($state === 1) {
        return 'warning';
    }
    if ($state === 3) {
        return 'unknown';
    }

    return 'ok';
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

function service_detail_url(string $baseUrl, string $hostName, string $serviceName): string
{
    return $baseUrl
        . '&host=' . rawurlencode($hostName)
        . '&service=' . rawurlencode($serviceName);
}

function graph_show_url(string $baseUrl, string $hostName, string $serviceName, bool $embed = false): string
{
    $url = $baseUrl
        . '?host=' . rawurlencode($hostName)
        . '&service=' . rawurlencode($serviceName);

    if ($embed) {
        $url .= '&range=24h&embed=1';
    }

    return $url;
}

function service_has_graph(string $rrdRoot, string $hostName, string $serviceName): bool
{
    static $cache = [];

    $cacheKey = $hostName . "\0" . $serviceName;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $hostDir = rtrim($rrdRoot, '/') . '/' . rawurlencode($hostName);
    if (!is_dir($hostDir)) {
        $cache[$cacheKey] = false;
        return false;
    }

    $prefix = rawurlencode($serviceName) . '___';
    $matches = glob($hostDir . '/' . $prefix . '*.rrd');
    $cache[$cacheKey] = is_array($matches) && $matches !== [];

    return $cache[$cacheKey];
}

$requestedHost = isset($_GET['host']) ? trim((string) $_GET['host']) : '';
$status = parse_status_dat($statusFile);
$hostIconMap = parse_hostextinfo_icons($hostExtInfoFile);
$hostRow = null;
$availableHosts = [];

foreach ($status['host'] as $host) {
    $hostName = value_string($host, 'host_name', '');
    if ($hostName !== '') {
        $availableHosts[$hostName] = $hostName;
    }
}

foreach ($status['service'] as $service) {
    $hostName = value_string($service, 'host_name', '');
    if ($hostName !== '') {
        $availableHosts[$hostName] = $hostName;
    }
}

ksort($availableHosts, SORT_NATURAL | SORT_FLAG_CASE);

foreach ($status['host'] as $host) {
    if (value_string($host, 'host_name', '') === $requestedHost) {
        $hostRow = $host;
        break;
    }
}

$services = [];
foreach ($status['service'] as $service) {
    if (value_string($service, 'host_name', '') === $requestedHost) {
        $services[] = $service;
    }
}

usort($services, static function (array $left, array $right): int {
    $stateCompare = value_int($right, 'current_state') <=> value_int($left, 'current_state');
    if ($stateCompare !== 0) {
        return $stateCompare;
    }

    $changedCompare = value_int($right, 'last_state_change') <=> value_int($left, 'last_state_change');
    if ($changedCompare !== 0) {
        return $changedCompare;
    }

    return strcmp(value_string($left, 'service_description', ''), value_string($right, 'service_description', ''));
});

$summary = [
    'ok' => 0,
    'warning' => 0,
    'critical' => 0,
    'unknown' => 0,
    'unhandled' => 0,
];

foreach ($services as $service) {
    $state = value_int($service, 'current_state');
    if ($state === 0) {
        $summary['ok']++;
    } elseif ($state === 1) {
        $summary['warning']++;
    } elseif ($state === 2) {
        $summary['critical']++;
    } elseif ($state === 3) {
        $summary['unknown']++;
    }

    if ($state > 0 && !is_acknowledged($service) && !is_in_downtime($service)) {
        $summary['unhandled']++;
    }
}

$statusFileMtime = is_readable($statusFile) ? @filemtime($statusFile) : false;
$lastUpdateLabel = $statusFileMtime ? date('d M Y H:i:s', $statusFileMtime) : 'status.dat not readable';
$freshnessLabel = $statusFileMtime ? format_duration(max(0, time() - (int) $statusFileMtime)) . ' ago' : 'n/a';
$hostIcon = $requestedHost !== '' ? host_icon_url($hostIconMap, $hostIconBaseUrl, $requestedHost) : null;
$hostAddress = host_address_from_cache($objectCacheFile, $requestedHost);
$hostAddressLabel = $hostAddress === null || filter_var($hostAddress, FILTER_VALIDATE_IP) !== false
    ? 'IP address' : 'Host address';
$hostState = $hostRow !== null ? value_int($hostRow, 'current_state') : 3;
$hostStateLabel = $hostRow !== null ? state_label($hostState, 'host') : 'UNKNOWN';
$hostStateTheme = state_theme($hostState, 'host');
$hostPluginOutput = $hostRow !== null ? value_string($hostRow, 'plugin_output', '') : '';
$hostChangedAt = $hostRow !== null ? format_timestamp(value_int($hostRow, 'last_state_change')) : 'n/a';
$hostAttempt = $hostRow !== null ? value_int($hostRow, 'current_attempt') . '/' . max(1, value_int($hostRow, 'max_attempts')) : 'n/a';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<meta charset="utf-8">
<meta name="ROBOTS" content="NOINDEX, NOFOLLOW">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="<?= (int) $refreshSeconds ?>">
<title><?= htmlspecialchars($requestedHost !== '' ? $requestedHost . ' Services' : 'Host Detail', ENT_QUOTES) ?></title>
<script src="stylesheets/theme.js"></script>
<link href="stylesheets/common.css" rel="stylesheet">
<style>
    :root {
        --bg: #06111d;
        --bg-accent: #0c1a2c;
        --panel: rgba(10, 23, 39, 0.92);
        --panel-strong: rgba(14, 31, 50, 0.98);
        --panel-soft: rgba(18, 38, 61, 0.85);
        --border: rgba(78, 104, 132, 0.34);
        --border-soft: rgba(111, 143, 177, 0.18);
        --text: #edf3fb;
        --muted: #9dafc5;
        --muted-2: #71839c;
        --critical: #ff8d92;
        --warning: #f1c95a;
        --ok: #46d691;
        --unknown: #c0c9d6;
        --shadow: 0 18px 40px rgba(0, 0, 0, 0.32);
        --radius-xl: 28px;
        --radius-lg: 22px;
        --radius-md: 16px;
    }

    * { box-sizing: border-box; }
    body {
        margin: 0;
        min-height: 100vh;
        color: var(--text);
        font-family: "Segoe UI", Roboto, Ubuntu, Arial, sans-serif;
        background:
            radial-gradient(circle at top left, rgba(53, 92, 137, 0.18), transparent 32%),
            linear-gradient(180deg, var(--bg-accent) 0%, var(--bg) 100%);
    }

    a { color: inherit; text-decoration: none; }
    a:hover { text-decoration: underline; }
    .page { padding: 18px; }
    .wrap { max-width: 1480px; margin: 0 auto; }
    .panel {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: var(--radius-xl);
        box-shadow: var(--shadow);
        backdrop-filter: blur(8px);
    }

    .hero {
        display: grid;
        grid-template-columns: minmax(0, 1.9fr) minmax(220px, 0.62fr);
        gap: 18px;
        padding: 22px;
        margin-bottom: 20px;
    }

    .browser-bar {
        /* Keep autocomplete above the stacking contexts created by panel backdrop filters. */
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: minmax(220px, 1fr) minmax(220px, 1fr) auto;
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

    .browser-field select,
    .browser-field input {
        width: 100%;
        padding: 12px 14px;
        border-radius: 14px;
        border: 1px solid rgba(99, 126, 156, 0.18);
        background: var(--panel-soft);
        color: var(--text);
        font-size: 15px;
    }

    .host-search { position: relative; min-width: 0; }
    .host-suggestions {
        position: absolute;
        z-index: 10;
        top: 100%;
        left: 0;
        right: 0;
        max-height: 280px;
        overflow-y: auto;
        margin: 6px 0 0;
        padding: 6px;
        list-style: none;
        border: 1px solid var(--border);
        border-radius: 14px;
        background: var(--panel-strong);
        box-shadow: var(--shadow);
    }
    .host-suggestions [role="option"] {
        padding: 10px;
        border-radius: 8px;
        overflow-wrap: anywhere;
        cursor: pointer;
    }
    .host-suggestions [role="option"]:hover,
    .host-suggestions [aria-selected="true"] { background: var(--panel-soft); }
    .search-status {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip-path: inset(50%);
    }

    .browser-action {
        display: flex;
        align-items: flex-end;
    }

    .browser-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 138px;
        padding: 12px 16px;
        border-radius: 14px;
        border: 1px solid rgba(99, 126, 156, 0.18);
        background: var(--panel-soft);
        color: var(--text);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        cursor: pointer;
    }

    .eyebrow {
        margin-bottom: 10px;
        color: var(--muted-2);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.24em;
        text-transform: uppercase;
    }

    h1 {
        margin: 0;
        font-size: 34px;
        line-height: 1.1;
        letter-spacing: -0.03em;
    }

    .hero p {
        margin: 12px 0 0 0;
        max-width: 760px;
        color: var(--muted);
        font-size: 13px;
        font-style: italic;
        line-height: 1.5;
    }

    .host-head {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .host-icon {
        width: 22px;
        height: 22px;
        border-radius: 6px;
        object-fit: contain;
        flex: 0 0 auto;
    }

    .host-address {
        display: flex;
        align-items: center;
        gap: 8px;
        width: fit-content;
        min-width: min(210px, 100%);
        max-width: 100%;
        margin-top: 12px;
        padding: 8px 11px;
        border: 1px solid var(--border);
        border-radius: var(--radius-md);
        background: var(--panel-soft);
    }
    .host-address-icon {
        display: grid;
        place-items: center;
        flex: 0 0 30px;
        height: 30px;
        border-radius: 10px;
        background: var(--panel-strong);
        color: var(--text);
    }
    .host-address-icon svg { width: 19px; height: 19px; }
    .host-address-text { min-width: 0; }
    .host-address-label {
        display: block;
        margin-bottom: 4px;
        color: var(--muted);
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.1em;
        text-transform: uppercase;
    }
    .host-address-value {
        display: block;
        color: var(--text);
        font: 700 17px/1.3 ui-monospace, SFMono-Regular, Consolas, monospace;
        overflow-wrap: anywhere;
    }
    .host-address-unavailable { color: var(--muted); font-size: 16px; }

    .hero-meta {
        display: grid;
        gap: 10px;
        align-content: end;
    }

    .pill {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: center;
        padding: 10px 12px;
        background: var(--panel-soft);
        border: 1px solid var(--border-soft);
        border-radius: 16px;
    }

    .pill-label { color: var(--muted); font-size: 11px; }
    .pill-value { color: var(--text); font-size: 12px; font-weight: 700; text-align: right; }

    .kpis {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 20px;
    }

    .kpi {
        padding: 16px;
        border-radius: var(--radius-lg);
        background: var(--panel-strong);
        border: 1px solid var(--border-soft);
    }

    .kpi-title { margin: 0 0 12px; color: var(--muted); font-size: 12px; }
    .kpi-value { margin: 0; font-size: 30px; line-height: 1; letter-spacing: -0.04em; }
    .kpi-value.ok { color: var(--ok); }
    .kpi-value.warning { color: var(--warning); }
    .kpi-value.critical { color: var(--critical); }
    .kpi-value.unknown { color: var(--unknown); }
    .kpi-foot { margin-top: 12px; color: var(--muted-2); font-size: 10px; letter-spacing: 0.16em; text-transform: uppercase; }

    .layout {
        display: grid;
        grid-template-columns: minmax(0, 1.9fr) minmax(260px, 0.62fr);
        gap: 20px;
        align-items: start;
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
        margin: 8px 0 0;
        color: var(--muted);
        font-size: 13px;
        line-height: 1.4;
    }

    .list-panel,
    .summary-panel {
        padding: 18px;
    }

    .summary-panel {
        padding: 14px;
        border-radius: 20px;
    }

    .service-list {
        display: grid;
        gap: 10px;
    }

    .service-card {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 12px;
        align-items: start;
        padding: 8px 12px;
        border-radius: 18px;
        background: rgba(18, 38, 61, 0.62);
        border: 1px solid var(--border-soft);
    }

    .service-card.critical {
        background: linear-gradient(180deg, rgba(88, 28, 40, 0.62), rgba(29, 32, 50, 0.92));
        border-color: rgba(255, 141, 146, 0.26);
    }

    .service-card.warning {
        background: linear-gradient(180deg, rgba(89, 69, 18, 0.52), rgba(29, 32, 50, 0.92));
        border-color: rgba(241, 201, 90, 0.24);
    }

    .service-card.unknown {
        background: linear-gradient(180deg, rgba(60, 68, 83, 0.5), rgba(29, 32, 50, 0.92));
        border-color: rgba(192, 201, 214, 0.20);
    }

    .badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 92px;
        padding: 6px 9px;
        border-radius: 999px;
        white-space: nowrap;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.15em;
        border: 1px solid transparent;
    }

    .badge.ok { color: var(--ok); background: rgba(70, 214, 145, 0.12); border-color: rgba(70, 214, 145, 0.18); }
    .badge.warning { color: var(--warning); background: rgba(208, 160, 31, 0.14); border-color: rgba(241, 201, 90, 0.20); }
    .badge.critical { color: var(--critical); background: rgba(191, 67, 74, 0.14); border-color: rgba(255, 141, 146, 0.22); }
    .badge.unknown { color: var(--unknown); background: rgba(192, 201, 214, 0.12); border-color: rgba(192, 201, 214, 0.18); }

    .service-title-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }

    .service-title {
        margin: 0;
        font-size: 14px;
        line-height: 1.2;
    }

    .service-meta,
    .service-output,
    .side-item span {
        color: var(--muted);
        font-size: 11px;
        line-height: 1.3;
    }

    .service-meta { margin-top: 3px; }
    .service-output { margin-top: 4px; }

    .flags {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        justify-content: flex-end;
    }

    .chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 5px 8px;
        border-radius: 999px;
        background: rgba(8, 17, 29, 0.55);
        border: 1px solid rgba(111, 143, 177, 0.12);
        color: var(--muted);
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .chip.unhandled {
        color: var(--critical);
        border-color: rgba(255, 141, 146, 0.22);
        background: rgba(191, 67, 74, 0.12);
    }

    .graph-action {
        flex: 0 0 auto;
        width: 20px;
        height: 20px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        border: 1px solid rgba(111, 143, 177, 0.18);
        background: rgba(8, 17, 29, 0.46);
        opacity: 1;
        transform: translateY(0);
        pointer-events: auto;
        transition: opacity 140ms ease, transform 140ms ease, border-color 140ms ease, background 140ms ease;
    }

    .graph-action img {
        width: 12px;
        height: 12px;
        display: block;
    }

    .service-side {
        display: grid;
        justify-items: end;
        gap: 6px;
    }

    .service-card:hover .graph-action,
    .service-card:focus-within .graph-action {
        opacity: 1;
        transform: translateY(0);
        pointer-events: auto;
    }

    .graph-action:hover {
        background: rgba(22, 42, 68, 0.72);
        border-color: rgba(111, 143, 177, 0.28);
        text-decoration: none;
    }

    .side-list { display: grid; gap: 8px; }
    .side-item {
        padding: 10px 12px;
        border-radius: 14px;
        background: rgba(18, 38, 61, 0.5);
        border: 1px solid var(--border-soft);
    }
    .side-item strong { display: block; font-size: 13px; line-height: 1.25; }
    .side-item span { display: block; margin-top: 3px; }

    .empty {
        padding: 28px;
        border-radius: 18px;
        background: rgba(70, 214, 145, 0.08);
        border: 1px solid rgba(70, 214, 145, 0.18);
        color: var(--text);
    }

    html.light .service-card,
    :root[data-theme="light"] .service-card {
        background: rgba(235, 242, 250, 0.92);
        border-color: rgba(103, 132, 165, 0.26);
    }

    html.light .service-card.critical,
    :root[data-theme="light"] .service-card.critical {
        background: linear-gradient(180deg, rgba(248, 214, 220, 0.92), rgba(237, 245, 253, 0.98));
        border-color: rgba(209, 49, 69, 0.26);
    }

    html.light .service-card.warning,
    :root[data-theme="light"] .service-card.warning {
        background: linear-gradient(180deg, rgba(252, 239, 204, 0.92), rgba(237, 245, 253, 0.98));
        border-color: rgba(201, 141, 10, 0.28);
    }

    html.light .service-card.unknown,
    :root[data-theme="light"] .service-card.unknown {
        background: linear-gradient(180deg, rgba(230, 237, 247, 0.92), rgba(237, 245, 253, 0.98));
        border-color: rgba(78, 102, 135, 0.24);
    }

    html.light .chip,
    :root[data-theme="light"] .chip {
        background: rgba(226, 236, 247, 0.9);
        border-color: rgba(103, 132, 165, 0.24);
    }

    html.light .graph-action,
    :root[data-theme="light"] .graph-action {
        border-color: rgba(103, 132, 165, 0.28);
        background: rgba(244, 249, 255, 0.95);
    }

    html.light .graph-action:hover,
    :root[data-theme="light"] .graph-action:hover {
        background: rgba(227, 238, 249, 0.98);
        border-color: rgba(103, 132, 165, 0.36);
    }

    html.light .side-item,
    :root[data-theme="light"] .side-item {
        background: rgba(235, 242, 250, 0.92);
        border-color: rgba(103, 132, 165, 0.24);
    }

    @media (max-width: 1280px) {
        .kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .layout { grid-template-columns: 1fr; }
    }

    @media (max-width: 980px) {
        .hero,
        .browser-bar { grid-template-columns: 1fr; }
    }

    @media (max-width: 720px) {
        .page { padding: 12px; }
        .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .service-card { grid-template-columns: 1fr; }
        .service-side { justify-items: start; }
        .flags { justify-content: flex-start; }
    }
</style>
</head>
<body>
<div class="page">
    <div class="wrap">
        <form class="panel browser-bar" id="host-browser" method="get">
            <div class="browser-field">
                <label for="host">Host</label>
                <select id="host" name="host">
                    <option value="">Select a host</option>
                    <?php foreach ($availableHosts as $hostName): ?>
                        <option value="<?= htmlspecialchars($hostName, ENT_QUOTES) ?>"<?= $requestedHost === $hostName ? ' selected' : '' ?>><?= htmlspecialchars($hostName, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="browser-field host-search" id="host-search-field" hidden>
                <label for="host-search">Search host</label>
                <input id="host-search" type="search" placeholder="Type part of a host name…"
                       autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list"
                       aria-expanded="false" aria-controls="host-suggestions" aria-describedby="host-search-status">
                <ul class="host-suggestions" id="host-suggestions" role="listbox" aria-label="Matching hosts" hidden></ul>
                <span class="search-status" id="host-search-status" role="status" aria-live="polite"></span>
            </div>
            <div class="browser-action">
                <button class="browser-button" type="submit">Update Detail</button>
            </div>
        </form>

        <section class="panel hero">
            <div>
                <div class="eyebrow">Host Monitoring</div>
                <?php if ($requestedHost !== ''): ?>
                    <div class="host-head">
                        <?php if ($hostIcon !== null): ?>
                            <img class="host-icon" src="<?= htmlspecialchars($hostIcon, ENT_QUOTES) ?>" alt="">
                        <?php endif; ?>
                        <h1><?= htmlspecialchars($requestedHost, ENT_QUOTES) ?></h1>
                    </div>
                    <div class="host-address">
                        <span class="host-address-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="8" y="2" width="8" height="6" rx="1.5"/>
                                <path d="M12 8v5M5 16v-3h14v3"/>
                                <rect x="2" y="16" width="6" height="6" rx="1.5"/>
                                <rect x="16" y="16" width="6" height="6" rx="1.5"/>
                            </svg>
                        </span>
                        <div class="host-address-text">
                            <span class="host-address-label"><?= htmlspecialchars($hostAddressLabel, ENT_QUOTES) ?></span>
                            <span class="host-address-value<?= $hostAddress === null ? ' host-address-unavailable' : '' ?>"><?= htmlspecialchars($hostAddress ?? 'Not available', ENT_QUOTES) ?></span>
                        </div>
                    </div>
                <?php else: ?>
                    <h1>Host Detail</h1>
                <?php endif; ?>
                <p>Standalone service detail view for a single host, sourced directly from <code>status.dat</code> and focused on service state, triage and graph access.</p>
            </div>
            <div class="hero-meta">
                <div class="pill">
                    <span class="pill-label">Host state</span>
                    <span class="pill-value"><?= htmlspecialchars($hostStateLabel, ENT_QUOTES) ?></span>
                </div>
                <div class="pill">
                    <span class="pill-label">Selected host</span>
                    <span class="pill-value"><?= htmlspecialchars($requestedHost !== '' ? $requestedHost : 'n/a', ENT_QUOTES) ?></span>
                </div>
                <div class="pill">
                    <span class="pill-label">Last host change</span>
                    <span class="pill-value"><?= htmlspecialchars($hostChangedAt, ENT_QUOTES) ?></span>
                </div>
                <div class="pill">
                    <span class="pill-label">Attempt</span>
                    <span class="pill-value"><?= htmlspecialchars($hostAttempt, ENT_QUOTES) ?></span>
                </div>
                <div class="pill">
                    <span class="pill-label">Data freshness</span>
                    <span class="pill-value"><?= htmlspecialchars($freshnessLabel, ENT_QUOTES) ?></span>
                </div>
            </div>
        </section>

        <?php if ($requestedHost === '' || $hostRow === null): ?>
            <section class="panel empty">Pass a valid <code>host</code> parameter to open the modern host detail page.</section>
        <?php else: ?>
            <section class="kpis">
                <article class="kpi">
                    <p class="kpi-title">Host state</p>
                    <p class="kpi-value <?= htmlspecialchars($hostStateTheme, ENT_QUOTES) ?>"><?= htmlspecialchars($hostStateLabel, ENT_QUOTES) ?></p>
                    <div class="kpi-foot">current host condition</div>
                </article>
                <article class="kpi">
                    <p class="kpi-title">Critical services</p>
                    <p class="kpi-value critical"><?= $summary['critical'] ?></p>
                    <div class="kpi-foot">highest urgency checks</div>
                </article>
                <article class="kpi">
                    <p class="kpi-title">Warning services</p>
                    <p class="kpi-value warning"><?= $summary['warning'] ?></p>
                    <div class="kpi-foot">degraded but not critical</div>
                </article>
                <article class="kpi">
                    <p class="kpi-title">Unknown services</p>
                    <p class="kpi-value unknown"><?= $summary['unknown'] ?></p>
                    <div class="kpi-foot">needs investigation</div>
                </article>
                <article class="kpi">
                    <p class="kpi-title">Service OK</p>
                    <p class="kpi-value ok"><?= $summary['ok'] ?></p>
                    <div class="kpi-foot">healthy service checks</div>
                </article>
            </section>

            <div class="layout">
                <section class="panel list-panel">
                    <div class="panel-head">
                        <div>
                            <h2>Services for this host</h2>
                            <p>All current service states with host-local triage info and direct graph access where performance data exists.</p>
                        </div>
                    </div>
                    <div class="service-list">
                        <?php foreach ($services as $service): ?>
                            <?php
                            $serviceName = value_string($service, 'service_description');
                            $state = value_int($service, 'current_state');
                            $theme = state_theme($state, 'service');
                            $changedAt = format_timestamp(value_int($service, 'last_state_change'));
                            ?>
                            <article class="service-card <?= htmlspecialchars($theme, ENT_QUOTES) ?>">
                                <div>
                                    <span class="badge <?= htmlspecialchars($theme, ENT_QUOTES) ?>"><?= htmlspecialchars(state_label($state, 'service'), ENT_QUOTES) ?></span>
                                </div>
                                <div>
                                    <div class="service-title-row">
                                        <h3 class="service-title">
                                            <a href="<?= htmlspecialchars(service_detail_url($serviceDetailBaseUrl, $requestedHost, $serviceName), ENT_QUOTES) ?>">
                                                <?= htmlspecialchars($serviceName, ENT_QUOTES) ?>
                                            </a>
                                        </h3>
                                    </div>
                                    <div class="service-meta">
                                        Changed <?= htmlspecialchars(format_duration(max(0, time() - value_int($service, 'last_state_change'))), ENT_QUOTES) ?> ago
                                        · at <?= htmlspecialchars($changedAt, ENT_QUOTES) ?>
                                        · attempt <?= value_int($service, 'current_attempt') ?>/<?= max(1, value_int($service, 'max_attempts')) ?>
                                        · <?= is_hard_state($service) ? 'hard state' : 'soft state' ?>
                                    </div>
                                    <?php if (value_string($service, 'plugin_output', '') !== ''): ?>
                                        <div class="service-output"><?= htmlspecialchars(value_string($service, 'plugin_output', ''), ENT_QUOTES) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="service-side">
                                    <?php if (service_has_graph($rrdRoot, $requestedHost, $serviceName)): ?>
                                        <a
                                            class="graph-action"
                                            href="<?= htmlspecialchars(graph_show_url($graphShowBaseUrl, $requestedHost, $serviceName), ENT_QUOTES) ?>"
                                            target="main"
                                            onClick="if(parent.hideModernGraphPopup){parent.hideModernGraphPopup();}"
                                            onMouseOver="if(parent.showModernGraphPopup){parent.showModernGraphPopup(this,event);}"
                                            onMouseMove="if(parent.moveModernGraphPopup){parent.moveModernGraphPopup(event,this);}"
                                            onMouseOut="if(parent.hideModernGraphPopup){parent.hideModernGraphPopup();}"
                                            rel="<?= htmlspecialchars(graph_show_url($graphShowBaseUrl, $requestedHost, $serviceName, true), ENT_QUOTES) ?>"
                                            title="Open performance graph"
                                        >
                                            <img src="<?= htmlspecialchars($graphActionIconUrl, ENT_QUOTES) ?>" alt="Open performance graph">
                                        </a>
                                    <?php endif; ?>
                                    <div class="flags">
                                        <?php if ($state > 0 && !is_acknowledged($service) && !is_in_downtime($service)): ?>
                                            <span class="chip unhandled">unhandled</span>
                                        <?php endif; ?>
                                        <?php if (is_acknowledged($service)): ?>
                                            <span class="chip">acknowledged</span>
                                        <?php endif; ?>
                                        <?php if (is_in_downtime($service)): ?>
                                            <span class="chip">downtime</span>
                                        <?php endif; ?>
                                        <?php if (is_flapping($service)): ?>
                                            <span class="chip">flapping</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <aside class="panel summary-panel">
                    <div class="panel-head">
                        <div>
                            <h2>Host context</h2>
                            <p>Secondary details to keep the focus on the service list.</p>
                        </div>
                    </div>
                    <div class="side-list">
                        <div class="side-item">
                            <strong>Plugin output</strong>
                            <span><?= htmlspecialchars($hostPluginOutput !== '' ? $hostPluginOutput : 'No host plugin output available', ENT_QUOTES) ?></span>
                        </div>
                        <div class="side-item">
                            <strong>Last status file update</strong>
                            <span><?= htmlspecialchars($lastUpdateLabel, ENT_QUOTES) ?></span>
                        </div>
                        <div class="side-item">
                            <strong>Unhandled services</strong>
                            <span><?= $summary['unhandled'] ?> items require action</span>
                        </div>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
(() => {
    const form = document.getElementById('host-browser');
    const select = document.getElementById('host');
    const input = document.getElementById('host-search');
    const list = document.getElementById('host-suggestions');
    const status = document.getElementById('host-search-status');
    const hosts = Array.from(select.options, option => option.value).filter(Boolean);
    let matches = [];
    let active = -1;

    document.getElementById('host-search-field').hidden = false;

    function close() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    }

    function showMatches() {
        const query = input.value.trim().toLowerCase();
        matches = query ? hosts.filter(host => host.toLowerCase().includes(query)) : [];
        list.replaceChildren();
        close();
        matches.forEach((host, index) => {
            const option = document.createElement('li');
            option.id = 'host-suggestion-' + index;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.textContent = host;
            option.addEventListener('pointerdown', event => event.preventDefault());
            option.addEventListener('click', () => choose(host));
            list.appendChild(option);
        });
        list.hidden = matches.length === 0;
        input.setAttribute('aria-expanded', String(matches.length > 0));
        status.textContent = !query ? '' : matches.length
            ? matches.length + ' matching hosts. Use arrow keys to select and Enter to open.'
            : 'No matching hosts.';
    }

    function choose(host) {
        select.value = host;
        input.value = host;
        input.setCustomValidity('');
        close();
        form.requestSubmit();
    }

    input.addEventListener('input', () => {
        input.setCustomValidity('');
        showMatches();
    });
    input.addEventListener('focus', showMatches);
    input.addEventListener('blur', close);
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (list.hidden) showMatches();
            if (!matches.length) return;
            active = active < 0 ? (event.key === 'ArrowDown' ? 0 : matches.length - 1)
                : (active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length;
            Array.from(list.children).forEach((option, index) => {
                option.setAttribute('aria-selected', String(index === active));
            });
            const option = list.children[active];
            input.setAttribute('aria-activedescendant', option.id);
            option.scrollIntoView({ block: 'nearest' });
        } else if (event.key === 'Enter' && active >= 0 && !list.hidden) {
            event.preventDefault();
            choose(matches[active]);
        }
    });
    select.addEventListener('change', () => {
        input.value = '';
        input.setCustomValidity('');
        status.textContent = '';
        close();
    });
    form.addEventListener('submit', event => {
        const query = input.value.trim().toLowerCase();
        if (!query) return;
        const exact = hosts.find(host => host.toLowerCase() === query);
        const candidates = hosts.filter(host => host.toLowerCase().includes(query));
        if (exact || candidates.length === 1) {
            select.value = exact || candidates[0];
            return;
        }
        event.preventDefault();
        input.setCustomValidity(candidates.length ? 'Select a host from the suggestions.' : 'No matching hosts.');
        input.reportValidity();
    });
})();
</script>
</body>
</html>
