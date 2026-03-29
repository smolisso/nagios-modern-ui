<?php

$statusFile = '../var/status.dat';
$hostExtInfoFile = __DIR__ . '/../etc/objects/hostextinfo.cfg';
$hostIconBaseUrl = '/nagios/images/logos/';
$hostDetailBaseUrl = '/nagios/cgi-bin/status.cgi?host=';
$hostServicesDetailBaseUrl = '/nagios/host_detail.php?host=';
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

function severity_weight(int $state, string $type): int
{
    if ($type === 'host') {
        if ($state === 1) {
            return 5;
        }

        if ($state === 2) {
            return 4;
        }

        return 0;
    }

    if ($state === 2) {
        return 4;
    }

    if ($state === 1) {
        return 3;
    }

    if ($state === 3) {
        return 2;
    }

    return 0;
}

function state_theme(string $type, int $state): string
{
    if ($type === 'host') {
        return $state === 1 ? 'critical' : 'unknown';
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

function incident_age(int $lastStateChange, int $now): int
{
    return $lastStateChange > 0 ? max(0, $now - $lastStateChange) : 0;
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

function host_detail_url(string $baseUrl, string $hostName): string
{
    return $baseUrl . rawurlencode($hostName);
}

function host_services_detail_url(string $baseUrl, string $hostName): string
{
    return $baseUrl . rawurlencode($hostName);
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

function request_param(string $key, string $default, array $allowed): string
{
    $value = isset($_GET[$key]) ? (string) $_GET[$key] : $default;
    return in_array($value, $allowed, true) ? $value : $default;
}

function build_query(array $params): string
{
    return '?' . http_build_query(array_filter($params, static function ($value): bool {
        return $value !== '' && $value !== null;
    }));
}

function incident_matches_filters(array $incident, string $scope, string $kind, string $stateFilter, string $query): bool
{
    if ($kind !== 'all' && $incident['type'] !== $kind) {
        return false;
    }

    if ($scope === 'unhandled' && !$incident['unhandled']) {
        return false;
    }

    if ($stateFilter !== 'all') {
        if ($incident['type'] === 'service') {
            if ($stateFilter === 'critical' && $incident['state'] !== 2) {
                return false;
            }
            if ($stateFilter === 'warning' && $incident['state'] !== 1) {
                return false;
            }
            if ($stateFilter === 'unknown' && $incident['state'] !== 3) {
                return false;
            }
            if (in_array($stateFilter, ['down', 'unreachable'], true)) {
                return false;
            }
        } else {
            if ($stateFilter === 'down' && $incident['state'] !== 1) {
                return false;
            }
            if ($stateFilter === 'unreachable' && $incident['state'] !== 2) {
                return false;
            }
            if (in_array($stateFilter, ['critical', 'warning', 'unknown'], true)) {
                return false;
            }
        }
    }

    if ($query === '') {
        return true;
    }

    $haystack = strtolower(
        $incident['host_name']
        . ' '
        . $incident['service_description']
        . ' '
        . $incident['plugin_output']
    );

    return strpos($haystack, strtolower($query)) !== false;
}

function compare_incidents(array $left, array $right): int
{
    if ($left['severity_weight'] !== $right['severity_weight']) {
        return $right['severity_weight'] <=> $left['severity_weight'];
    }

    if ($left['unhandled'] !== $right['unhandled']) {
        return (int) $right['unhandled'] <=> (int) $left['unhandled'];
    }

    if ($left['hard_state'] !== $right['hard_state']) {
        return (int) $right['hard_state'] <=> (int) $left['hard_state'];
    }

    if ($left['age_seconds'] !== $right['age_seconds']) {
        return $right['age_seconds'] <=> $left['age_seconds'];
    }

    return strcmp($left['title'], $right['title']);
}

$status = parse_status_dat($statusFile);
$services = $status['service'];
$hosts = $status['host'];
$now = time();
$hostIconMap = parse_hostextinfo_icons($hostExtInfoFile);

$summary = [
    'service_critical' => 0,
    'service_warning' => 0,
    'service_unknown' => 0,
    'host_down' => 0,
    'host_unreachable' => 0,
    'unhandled' => 0,
];

$incidents = [];
$hostImpact = [];

foreach ($services as $service) {
    $state = value_int($service, 'current_state');
    if ($state <= 0) {
        continue;
    }

    if ($state === 2) {
        $summary['service_critical']++;
    } elseif ($state === 1) {
        $summary['service_warning']++;
    } elseif ($state === 3) {
        $summary['service_unknown']++;
    }

    $hostName = value_string($service, 'host_name');
    $serviceName = value_string($service, 'service_description');
    $lastStateChange = value_int($service, 'last_state_change');
    $unhandled = !is_acknowledged($service) && !is_in_downtime($service);

    if ($unhandled) {
        $summary['unhandled']++;
    }

    $incidents[] = [
        'type' => 'service',
        'title' => $hostName . ' / ' . $serviceName,
        'host_name' => $hostName,
        'service_description' => $serviceName,
        'state' => $state,
        'state_label' => state_label($state, 'service'),
        'state_theme' => state_theme('service', $state),
        'severity_weight' => severity_weight($state, 'service'),
        'age_seconds' => incident_age($lastStateChange, $now),
        'changed_at' => format_timestamp($lastStateChange),
        'attempt' => value_int($service, 'current_attempt') . '/' . max(1, value_int($service, 'max_attempts')),
        'unhandled' => $unhandled,
        'acknowledged' => is_acknowledged($service),
        'downtime' => is_in_downtime($service),
        'flapping' => is_flapping($service),
        'hard_state' => is_hard_state($service),
        'plugin_output' => value_string($service, 'plugin_output', ''),
    ];

    if (!isset($hostImpact[$hostName])) {
        $hostImpact[$hostName] = [
            'host_name' => $hostName,
            'total' => 0,
            'critical' => 0,
            'warning' => 0,
            'unknown' => 0,
            'highest_severity' => 0,
        ];
    }

    $hostImpact[$hostName]['total']++;
    $hostImpact[$hostName]['highest_severity'] = max($hostImpact[$hostName]['highest_severity'], severity_weight($state, 'service'));

    if ($state === 2) {
        $hostImpact[$hostName]['critical']++;
    } elseif ($state === 1) {
        $hostImpact[$hostName]['warning']++;
    } elseif ($state === 3) {
        $hostImpact[$hostName]['unknown']++;
    }
}

foreach ($hosts as $host) {
    $state = value_int($host, 'current_state');
    if ($state !== 1 && $state !== 2) {
        continue;
    }

    if ($state === 1) {
        $summary['host_down']++;
    } else {
        $summary['host_unreachable']++;
    }

    $hostName = value_string($host, 'host_name');
    $lastStateChange = value_int($host, 'last_state_change');
    $unhandled = !is_acknowledged($host) && !is_in_downtime($host);

    if ($unhandled) {
        $summary['unhandled']++;
    }

    $incidents[] = [
        'type' => 'host',
        'title' => $hostName,
        'host_name' => $hostName,
        'service_description' => '',
        'state' => $state,
        'state_label' => state_label($state, 'host'),
        'state_theme' => state_theme('host', $state),
        'severity_weight' => severity_weight($state, 'host'),
        'age_seconds' => incident_age($lastStateChange, $now),
        'changed_at' => format_timestamp($lastStateChange),
        'attempt' => value_int($host, 'current_attempt') . '/' . max(1, value_int($host, 'max_attempts')),
        'unhandled' => $unhandled,
        'acknowledged' => is_acknowledged($host),
        'downtime' => is_in_downtime($host),
        'flapping' => is_flapping($host),
        'hard_state' => is_hard_state($host),
        'plugin_output' => value_string($host, 'plugin_output', ''),
    ];
}

usort($incidents, 'compare_incidents');

$scope = request_param('scope', 'all', ['all', 'unhandled']);
$kind = request_param('kind', 'all', ['all', 'service', 'host']);
$stateFilter = request_param('state', 'all', ['all', 'critical', 'warning', 'unknown', 'down', 'unreachable']);
$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

$filteredIncidents = array_values(array_filter($incidents, static function (array $incident) use ($scope, $kind, $stateFilter, $query): bool {
    return incident_matches_filters($incident, $scope, $kind, $stateFilter, $query);
}));

$hostImpact = array_values($hostImpact);
usort($hostImpact, static function (array $left, array $right): int {
    if ($left['highest_severity'] !== $right['highest_severity']) {
        return $right['highest_severity'] <=> $left['highest_severity'];
    }

    if ($left['total'] !== $right['total']) {
        return $right['total'] <=> $left['total'];
    }

    return strcmp($left['host_name'], $right['host_name']);
});
$hostImpact = array_slice($hostImpact, 0, 8);

$totalProblems = count($incidents);
$statusFileMtime = is_readable($statusFile) ? @filemtime($statusFile) : false;
$lastUpdateLabel = $statusFileMtime ? date('d M Y H:i:s', $statusFileMtime) : 'status.dat not readable';
$freshnessLabel = $statusFileMtime ? format_duration(max(0, $now - (int) $statusFileMtime)) . ' ago' : 'n/a';
$baseFilters = ['kind' => $kind, 'state' => $stateFilter, 'q' => $query];
$pageTitle = 'Problems Overview';

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="ROBOTS" content="NOINDEX, NOFOLLOW">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="<?= (int) $refreshSeconds ?>">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></title>
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
        --critical-bg: rgba(191, 67, 74, 0.14);
        --warning: #f1c95a;
        --warning-bg: rgba(208, 160, 31, 0.15);
        --ok: #46d691;
        --ok-bg: rgba(70, 214, 145, 0.13);
        --unknown: #c0c9d6;
        --unknown-bg: rgba(192, 201, 214, 0.12);
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

    a {
        color: inherit;
        text-decoration: none;
    }

    a:hover {
        text-decoration: underline;
    }

    .page {
        padding: 18px;
    }

    .wrap {
        max-width: 1480px;
        margin: 0 auto;
    }

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
        max-width: 780px;
        color: var(--muted);
        font-size: 13px;
        font-style: italic;
        line-height: 1.5;
    }

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
        grid-template-columns: repeat(6, minmax(0, 1fr));
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

    .kpi-value.critical { color: var(--critical); }
    .kpi-value.warning { color: var(--warning); }
    .kpi-value.unknown { color: var(--unknown); }
    .kpi-value.ok { color: var(--ok); }

    .kpi-foot { margin-top: 12px; color: var(--muted-2); font-size: 10px; letter-spacing: 0.16em; text-transform: uppercase; }

    .toolbar {
        padding: 18px 20px;
        margin-bottom: 20px;
    }

    .toolbar-grid {
        display: grid;
        grid-template-columns: auto auto auto minmax(220px, 1fr) auto;
        gap: 12px;
        align-items: end;
    }

    .field label {
        display: block;
        margin-bottom: 8px;
        color: var(--muted-2);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
    }

    .field select,
    .field input {
        width: 100%;
        padding: 12px 14px;
        border: 1px solid var(--border-soft);
        border-radius: 14px;
        background: var(--panel-soft);
        color: var(--text);
        font: inherit;
    }

    .button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 46px;
        padding: 0 18px;
        border: 1px solid var(--border-soft);
        border-radius: 14px;
        background: var(--panel-soft);
        color: var(--text);
        font-weight: 700;
        cursor: pointer;
    }

    .scope-switch {
        display: inline-flex;
        gap: 8px;
        margin-top: 16px;
        flex-wrap: wrap;
    }

    .scope-link {
        padding: 10px 14px;
        border-radius: 999px;
        background: rgba(31, 47, 70, 0.72);
        border: 1px solid var(--border-soft);
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .scope-link.is-active {
        color: var(--text);
        border-color: rgba(84, 198, 148, 0.34);
        box-shadow: inset 0 0 0 1px rgba(84, 198, 148, 0.15);
    }

    .layout {
        display: grid;
        grid-template-columns: minmax(0, 1.9fr) minmax(260px, 0.62fr);
        gap: 20px;
        align-items: start;
    }

    .stack {
        display: grid;
        gap: 20px;
    }

    .list-panel,
    .summary-panel {
        padding: 20px;
    }

    .summary-panel {
        padding: 14px;
        border-radius: 20px;
    }

    .panel-head {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
        margin-bottom: 18px;
    }

    .panel-head h2 {
        margin: 0;
        font-size: 18px;
    }

    .summary-panel .panel-head {
        margin-bottom: 12px;
    }

    .summary-panel .panel-head h2 {
        font-size: 15px;
    }

    .summary-panel .panel-head p {
        margin-top: 5px;
        font-size: 12px;
        line-height: 1.35;
    }

    .panel-head p {
        margin: 8px 0 0;
        color: var(--muted);
        font-size: 14px;
        line-height: 1.45;
    }

    .list-meta {
        color: var(--muted-2);
        font-size: 12px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        white-space: nowrap;
        padding-top: 4px;
    }

    .incident-list {
        display: grid;
        gap: 12px;
    }

    .incident {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 14px;
        align-items: start;
        padding: 13px 16px;
        border-radius: 18px;
        background: rgba(18, 38, 61, 0.62);
        border: 1px solid var(--border-soft);
    }

    .incident.is-clickable {
        cursor: pointer;
        transition: transform 140ms ease, box-shadow 140ms ease, border-color 140ms ease;
    }

    .incident.is-clickable:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 28px rgba(0, 0, 0, 0.22);
        border-color: rgba(111, 143, 177, 0.28);
    }

    .incident.critical {
        background: linear-gradient(180deg, rgba(88, 28, 40, 0.62), rgba(29, 32, 50, 0.92));
        border-color: rgba(255, 141, 146, 0.26);
    }

    .incident.warning {
        background: linear-gradient(180deg, rgba(89, 69, 18, 0.52), rgba(29, 32, 50, 0.92));
        border-color: rgba(241, 201, 90, 0.24);
    }

    .incident.unknown {
        background: linear-gradient(180deg, rgba(60, 68, 83, 0.5), rgba(29, 32, 50, 0.92));
        border-color: rgba(192, 201, 214, 0.20);
    }

    .incident-badge {
        min-width: 108px;
        padding: 8px 11px;
        border-radius: 14px;
        text-align: center;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .incident-badge.critical {
        color: var(--critical);
        background: var(--critical-bg);
    }

    .incident-badge.warning {
        color: var(--warning);
        background: var(--warning-bg);
    }

    .incident-badge.unknown {
        color: var(--unknown);
        background: var(--unknown-bg);
    }

    .incident-title {
        margin: 0;
        font-size: 16px;
        line-height: 1.3;
    }

    .incident-title-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }

    .graph-action {
        flex: 0 0 auto;
        width: 24px;
        height: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        border: 1px solid rgba(111, 143, 177, 0.18);
        background: rgba(8, 17, 29, 0.46);
        opacity: 0;
        transform: translateY(-2px);
        pointer-events: none;
        transition: opacity 140ms ease, transform 140ms ease, border-color 140ms ease, background 140ms ease;
    }

    .graph-action img {
        width: 14px;
        height: 14px;
        display: block;
    }

    .incident:hover .graph-action,
    .incident:focus-within .graph-action {
        opacity: 1;
        transform: translateY(0);
        pointer-events: auto;
    }

    .graph-action:hover {
        background: rgba(22, 42, 68, 0.72);
        border-color: rgba(111, 143, 177, 0.28);
        text-decoration: none;
    }

    .host-link-wrap {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        vertical-align: middle;
    }

    .host-icon {
        width: 18px;
        height: 18px;
        border-radius: 4px;
        object-fit: contain;
        flex: 0 0 auto;
    }

    .separator {
        color: var(--muted-2);
    }

    .incident-meta,
    .incident-output,
    .mini-list-meta {
        color: var(--muted);
        font-size: 12px;
        line-height: 1.45;
    }

    .incident-meta {
        margin-top: 6px;
    }

    .incident-output {
        margin-top: 8px;
    }

    .flags {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        justify-content: flex-end;
    }

    .flag {
        padding: 6px 9px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        border: 1px solid var(--border-soft);
        background: rgba(255, 255, 255, 0.03);
        color: var(--muted);
    }

    .flag.emphasis {
        color: var(--text);
    }

    .side-list {
        display: grid;
        gap: 8px;
    }

    .side-item {
        padding: 10px 12px;
        border-radius: 14px;
        background: rgba(18, 38, 61, 0.5);
        border: 1px solid var(--border-soft);
    }

    .side-item strong {
        display: block;
        font-size: 13px;
        line-height: 1.25;
    }

    .side-item span {
        display: block;
        margin-top: 3px;
        color: var(--muted);
        font-size: 11px;
        line-height: 1.3;
    }

    .empty {
        padding: 28px;
        border-radius: 18px;
        background: rgba(70, 214, 145, 0.08);
        border: 1px solid rgba(70, 214, 145, 0.18);
        color: var(--text);
    }

    @media (max-width: 1240px) {
        .kpis {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .layout,
        .hero {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 980px) {
        .toolbar-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .toolbar-grid .field.search,
        .toolbar-grid .field.submit {
            grid-column: 1 / -1;
        }

        .incident {
            grid-template-columns: 1fr;
        }

        .flags {
            justify-content: flex-start;
        }
    }

    @media (max-width: 720px) {
        .page {
            padding: 14px;
        }

        .kpis {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .toolbar-grid {
            grid-template-columns: 1fr;
        }

        .scope-switch {
            display: grid;
            grid-template-columns: 1fr 1fr;
        }
    }
</style>
</head>
<body>
<div class="page">
    <div class="wrap">
        <section class="panel hero">
            <div>
                <div class="eyebrow">Problem Monitoring</div>
                <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES) ?></h1>
                <p>Standalone current-problem view sourced directly from <code>status.dat</code>, focused on severity, duration, ownership signals and fast triage.</p>
            </div>
            <div class="hero-meta">
                <div class="pill">
                    <span class="pill-label">Data source</span>
                    <span class="pill-value"><?= is_readable($statusFile) ? 'Live feed online' : 'Live feed unavailable' ?></span>
                </div>
                <div class="pill">
                    <span class="pill-label">Last file update</span>
                    <span class="pill-value"><?= htmlspecialchars($lastUpdateLabel, ENT_QUOTES) ?></span>
                </div>
                <div class="pill">
                    <span class="pill-label">Data freshness</span>
                    <span class="pill-value"><?= htmlspecialchars($freshnessLabel, ENT_QUOTES) ?></span>
                </div>
                <div class="pill">
                    <span class="pill-label">Auto refresh</span>
                    <span class="pill-value">Every <?= (int) $refreshSeconds ?>s</span>
                </div>
            </div>
        </section>

        <section class="kpis">
            <article class="kpi">
                <p class="kpi-title">Total active problems</p>
                <p class="kpi-value critical"><?= $totalProblems ?></p>
                <div class="kpi-foot">hosts + services</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Unhandled problems</p>
                <p class="kpi-value critical"><?= $summary['unhandled'] ?></p>
                <div class="kpi-foot">needs action now</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Critical services</p>
                <p class="kpi-value critical"><?= $summary['service_critical'] ?></p>
                <div class="kpi-foot">highest service severity</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Warning services</p>
                <p class="kpi-value warning"><?= $summary['service_warning'] ?></p>
                <div class="kpi-foot">degraded but not down</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Unknown services</p>
                <p class="kpi-value unknown"><?= $summary['service_unknown'] ?></p>
                <div class="kpi-foot">check output unclear</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Hosts down / unreachable</p>
                <p class="kpi-value critical"><?= $summary['host_down'] + $summary['host_unreachable'] ?></p>
                <div class="kpi-foot"><?= $summary['host_down'] ?> down · <?= $summary['host_unreachable'] ?> unreachable</div>
            </article>
        </section>

        <section class="panel toolbar">
            <form method="get">
                <div class="toolbar-grid">
                    <div class="field">
                        <label for="kind">Object type</label>
                        <select id="kind" name="kind">
                            <option value="all"<?= $kind === 'all' ? ' selected' : '' ?>>All problems</option>
                            <option value="service"<?= $kind === 'service' ? ' selected' : '' ?>>Services only</option>
                            <option value="host"<?= $kind === 'host' ? ' selected' : '' ?>>Hosts only</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="state">State</label>
                        <select id="state" name="state">
                            <option value="all"<?= $stateFilter === 'all' ? ' selected' : '' ?>>All severities</option>
                            <option value="critical"<?= $stateFilter === 'critical' ? ' selected' : '' ?>>Critical</option>
                            <option value="warning"<?= $stateFilter === 'warning' ? ' selected' : '' ?>>Warning</option>
                            <option value="unknown"<?= $stateFilter === 'unknown' ? ' selected' : '' ?>>Unknown</option>
                            <option value="down"<?= $stateFilter === 'down' ? ' selected' : '' ?>>Host down</option>
                            <option value="unreachable"<?= $stateFilter === 'unreachable' ? ' selected' : '' ?>>Host unreachable</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="scope-select">Scope</label>
                        <select id="scope-select" name="scope">
                            <option value="all"<?= $scope === 'all' ? ' selected' : '' ?>>All current problems</option>
                            <option value="unhandled"<?= $scope === 'unhandled' ? ' selected' : '' ?>>Unhandled only</option>
                        </select>
                    </div>
                    <div class="field search">
                        <label for="q">Search</label>
                        <input id="q" name="q" type="text" value="<?= htmlspecialchars($query, ENT_QUOTES) ?>" placeholder="Host, service or plugin output">
                    </div>
                    <div class="field submit">
                        <label>&nbsp;</label>
                        <button class="button" type="submit">Apply filters</button>
                    </div>
                </div>
            </form>

            <div class="scope-switch">
                <a class="scope-link<?= $scope === 'all' ? ' is-active' : '' ?>" href="<?= htmlspecialchars(build_query($baseFilters + ['scope' => 'all']), ENT_QUOTES) ?>">All current problems</a>
                <a class="scope-link<?= $scope === 'unhandled' ? ' is-active' : '' ?>" href="<?= htmlspecialchars(build_query($baseFilters + ['scope' => 'unhandled']), ENT_QUOTES) ?>">Unhandled only</a>
                <a class="scope-link<?= $kind === 'service' ? ' is-active' : '' ?>" href="<?= htmlspecialchars(build_query(['scope' => $scope, 'kind' => 'service', 'state' => $stateFilter, 'q' => $query]), ENT_QUOTES) ?>">Services</a>
                <a class="scope-link<?= $kind === 'host' ? ' is-active' : '' ?>" href="<?= htmlspecialchars(build_query(['scope' => $scope, 'kind' => 'host', 'state' => $stateFilter, 'q' => $query]), ENT_QUOTES) ?>">Hosts</a>
                <a class="scope-link<?= $stateFilter === 'critical' ? ' is-active' : '' ?>" href="<?= htmlspecialchars(build_query(['scope' => $scope, 'kind' => 'service', 'state' => 'critical', 'q' => $query]), ENT_QUOTES) ?>">Critical</a>
                <a class="scope-link" href="problems.php">Reset</a>
            </div>
        </section>

        <div class="layout">
            <section class="panel list-panel">
                <div class="panel-head">
                    <div>
                        <h2>Active problems</h2>
                        <p>Severity-first list with age, attempt, state type and operational flags. Host and service names link to the relevant Nagios details.</p>
                    </div>
                    <div class="list-meta"><?= count($filteredIncidents) ?> visible</div>
                </div>

                <?php if ($filteredIncidents === []): ?>
                    <div class="empty">No problems match the current filters.</div>
                <?php else: ?>
                    <div class="incident-list">
                        <?php foreach ($filteredIncidents as $incident): ?>
                            <?php $hostIcon = host_icon_url($hostIconMap, $hostIconBaseUrl, $incident['host_name']); ?>
                            <article
                                class="incident is-clickable <?= htmlspecialchars($incident['state_theme'], ENT_QUOTES) ?>"
                                data-card-url="<?= htmlspecialchars(host_services_detail_url($hostServicesDetailBaseUrl, $incident['host_name']), ENT_QUOTES) ?>"
                            >
                                <div class="incident-badge <?= htmlspecialchars($incident['state_theme'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($incident['state_label'], ENT_QUOTES) ?>
                                </div>
                                <div>
                                    <div class="incident-title-row">
                                        <h3 class="incident-title">
                                            <?php if ($incident['type'] === 'host'): ?>
                                                <a href="<?= htmlspecialchars(host_detail_url($hostDetailBaseUrl, $incident['host_name']), ENT_QUOTES) ?>">
                                                    <span class="host-link-wrap">
                                                        <?php if ($hostIcon !== null): ?>
                                                            <img class="host-icon" src="<?= htmlspecialchars($hostIcon, ENT_QUOTES) ?>" alt="">
                                                        <?php endif; ?>
                                                        <span><?= htmlspecialchars($incident['host_name'], ENT_QUOTES) ?></span>
                                                    </span>
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= htmlspecialchars(host_detail_url($hostDetailBaseUrl, $incident['host_name']), ENT_QUOTES) ?>">
                                                    <span class="host-link-wrap">
                                                        <?php if ($hostIcon !== null): ?>
                                                            <img class="host-icon" src="<?= htmlspecialchars($hostIcon, ENT_QUOTES) ?>" alt="">
                                                        <?php endif; ?>
                                                        <span><?= htmlspecialchars($incident['host_name'], ENT_QUOTES) ?></span>
                                                    </span>
                                                </a>
                                                <span class="separator"> / </span>
                                                <a href="<?= htmlspecialchars(service_detail_url($serviceDetailBaseUrl, $incident['host_name'], $incident['service_description']), ENT_QUOTES) ?>">
                                                    <?= htmlspecialchars($incident['service_description'], ENT_QUOTES) ?>
                                                </a>
                                            <?php endif; ?>
                                        </h3>
                                        <?php if ($incident['type'] === 'service' && service_has_graph($rrdRoot, $incident['host_name'], $incident['service_description'])): ?>
                                            <a
                                                class="graph-action"
                                                href="<?= htmlspecialchars(graph_show_url($graphShowBaseUrl, $incident['host_name'], $incident['service_description']), ENT_QUOTES) ?>"
                                                target="main"
                                                onClick="if(parent.hideModernGraphPopup){parent.hideModernGraphPopup();}"
                                                onMouseOver="if(parent.showModernGraphPopup){parent.showModernGraphPopup(this,event);}"
                                                onMouseMove="if(parent.moveModernGraphPopup){parent.moveModernGraphPopup(event,this);}"
                                                onMouseOut="if(parent.hideModernGraphPopup){parent.hideModernGraphPopup();}"
                                                rel="<?= htmlspecialchars(graph_show_url($graphShowBaseUrl, $incident['host_name'], $incident['service_description'], true), ENT_QUOTES) ?>"
                                                title="Open performance graph"
                                            >
                                                <img src="<?= htmlspecialchars($graphActionIconUrl, ENT_QUOTES) ?>" alt="Open performance graph">
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="incident-meta">
                                        Changed <?= htmlspecialchars(format_duration($incident['age_seconds']), ENT_QUOTES) ?> ago
                                        · at <?= htmlspecialchars($incident['changed_at'], ENT_QUOTES) ?>
                                        · attempt <?= htmlspecialchars($incident['attempt'], ENT_QUOTES) ?>
                                        · <?= $incident['hard_state'] ? 'hard state' : 'soft state' ?>
                                    </div>
                                    <?php if ($incident['plugin_output'] !== ''): ?>
                                        <div class="incident-output"><?= htmlspecialchars($incident['plugin_output'], ENT_QUOTES) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="flags">
                                    <?php if ($incident['unhandled']): ?>
                                        <span class="flag emphasis">Unhandled</span>
                                    <?php endif; ?>
                                    <?php if ($incident['acknowledged']): ?>
                                        <span class="flag">Acked</span>
                                    <?php endif; ?>
                                    <?php if ($incident['downtime']): ?>
                                        <span class="flag">Downtime</span>
                                    <?php endif; ?>
                                    <?php if ($incident['flapping']): ?>
                                        <span class="flag">Flapping</span>
                                    <?php endif; ?>
                                    <span class="flag"><?= strtoupper(htmlspecialchars($incident['type'], ENT_QUOTES)) ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="stack">
                <aside class="panel summary-panel">
                    <div class="panel-head">
                        <div>
                            <h2>Most impacted hosts</h2>
                            <p>Hosts with the highest concentration of current service problems.</p>
                        </div>
                    </div>
                    <div class="side-list">
                        <?php if ($hostImpact === []): ?>
                            <div class="side-item"><span>No service-level host concentration detected.</span></div>
                        <?php else: ?>
                            <?php foreach ($hostImpact as $item): ?>
                                <?php $hostIcon = host_icon_url($hostIconMap, $hostIconBaseUrl, $item['host_name']); ?>
                                <div class="side-item">
                                    <strong>
                                        <a href="<?= htmlspecialchars(host_detail_url($hostDetailBaseUrl, $item['host_name']), ENT_QUOTES) ?>">
                                            <span class="host-link-wrap">
                                                <?php if ($hostIcon !== null): ?>
                                                    <img class="host-icon" src="<?= htmlspecialchars($hostIcon, ENT_QUOTES) ?>" alt="">
                                                <?php endif; ?>
                                                <span><?= htmlspecialchars($item['host_name'], ENT_QUOTES) ?></span>
                                            </span>
                                        </a>
                                    </strong>
                                    <span><?= $item['total'] ?> problems · <?= $item['critical'] ?> critical · <?= $item['warning'] ?> warning · <?= $item['unknown'] ?> unknown</span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('click', function (event) {
    var card = event.target.closest('[data-card-url]');
    if (!card) {
        return;
    }

    if (event.target.closest('a, button, input, select, textarea, label')) {
        return;
    }

    window.location.href = card.getAttribute('data-card-url');
});
</script>
</body>
</html>
