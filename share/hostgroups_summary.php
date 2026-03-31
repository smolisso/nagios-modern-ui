<?php

$statusFile = '../var/status.dat';
$objectCacheFile = __DIR__ . '/../var/objects.cache';
$mainConfigFile = __DIR__ . '/../etc/nagios.cfg';
$objectsDir = __DIR__ . '/../etc/objects';
$refreshSeconds = 30;
$hostGroupSummaryBaseUrl = '/nagios/cgi-bin/status.cgi?hostgroup=';
$hostGroupDetailBaseUrl = '/nagios/cgi-bin/status.cgi?style=detail&hostgroup=';

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

function resolve_config_path(string $path, string $configDir, string $installRoot): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if ($path[0] !== '/') {
        return rtrim($configDir, '/') . '/' . $path;
    }

    $nagiosRoot = '/usr/local/nagios';
    if (strpos($path, $nagiosRoot . '/') === 0) {
        return rtrim($installRoot, '/') . substr($path, strlen($nagiosRoot));
    }

    return $path;
}

function discover_included_object_files(string $mainConfigFile, string $objectsDir): array
{
    if (!is_readable($mainConfigFile)) {
        $files = glob(rtrim($objectsDir, '/') . '/*.cfg');
        return $files === false ? [] : $files;
    }

    $lines = file($mainConfigFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }

    $configDir = dirname($mainConfigFile);
    $installRoot = realpath(__DIR__ . '/..');
    if ($installRoot === false) {
        $installRoot = dirname($configDir);
    }

    $files = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0 || strpos($trimmed, ';') === 0) {
            continue;
        }

        if (strpos($trimmed, 'cfg_file=') === 0) {
            $path = resolve_config_path(substr($trimmed, strlen('cfg_file=')), $configDir, $installRoot);
            if ($path !== '' && is_readable($path)) {
                $files[] = $path;
            }
            continue;
        }

        if (strpos($trimmed, 'cfg_dir=') === 0) {
            $dir = resolve_config_path(substr($trimmed, strlen('cfg_dir=')), $configDir, $installRoot);
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }

            $dirFiles = glob(rtrim($dir, '/') . '/*.cfg');
            if ($dirFiles === false) {
                continue;
            }

            sort($dirFiles);
            foreach ($dirFiles as $dirFile) {
                if (is_readable($dirFile)) {
                    $files[] = $dirFile;
                }
            }
        }
    }

    return array_values(array_unique($files));
}

function parse_object_configs(string $objectCacheFile, string $mainConfigFile, string $objectsDir): array
{
    $hostGroups = [];
    $hostTemplates = [];
    $hosts = [];

    $files = [];
    if (is_readable($objectCacheFile)) {
        $files[] = $objectCacheFile;
    } else {
        $files = discover_included_object_files($mainConfigFile, $objectsDir);
        if ($files === []) {
            return $hostGroups;
        }
    }

    foreach ($files as $path) {
        if (!is_readable($path)) {
            continue;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }

        $inside = false;
        $objectType = '';
        $current = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || strpos($trimmed, '#') === 0 || strpos($trimmed, ';') === 0) {
                continue;
            }

            if (preg_match('/^define\s+(host|hostgroup)\s*\{?$/', $trimmed, $matches) === 1) {
                $inside = true;
                $objectType = $matches[1];
                $current = [];
                continue;
            }

            if (!$inside) {
                continue;
            }

            if ($trimmed === '}') {
                if ($objectType === 'host') {
                    $hostName = isset($current['host_name']) ? trim((string) $current['host_name']) : '';
                    $templateName = isset($current['name']) ? trim((string) $current['name']) : '';
                    $isTemplate = (isset($current['register']) && trim((string) $current['register']) === '0') || ($templateName !== '' && $hostName === '');

                    if ($isTemplate && $templateName !== '') {
                        $hostTemplates[$templateName] = [
                            'use' => isset($current['use']) ? trim((string) $current['use']) : '',
                            'hostgroups' => isset($current['hostgroups']) ? trim((string) $current['hostgroups']) : '',
                        ];
                    } elseif ($hostName !== '') {
                        $hosts[$hostName] = [
                            'use' => isset($current['use']) ? trim((string) $current['use']) : '',
                            'hostgroups' => isset($current['hostgroups']) ? trim((string) $current['hostgroups']) : '',
                        ];
                    }
                } elseif ($objectType === 'hostgroup') {
                    $groupName = isset($current['hostgroup_name']) ? trim((string) $current['hostgroup_name']) : '';
                    if ($groupName !== '') {
                        $members = [];
                        if (isset($current['members'])) {
                            $members = array_values(array_filter(array_map('trim', explode(',', (string) $current['members']))));
                        }

                        $hostGroups[$groupName] = [
                            'name' => $groupName,
                            'alias' => isset($current['alias']) && trim((string) $current['alias']) !== ''
                                ? trim((string) $current['alias'])
                                : $groupName,
                            'members' => $members,
                        ];
                    }
                }

                $inside = false;
                $objectType = '';
                $current = [];
                continue;
            }

            $cleanLine = preg_replace('/\s+[;#].*$/', '', $trimmed);
            if ($cleanLine === null || $cleanLine === '') {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_]+)\s+(.+)$/', $cleanLine, $matches) === 1) {
                $current[$matches[1]] = trim($matches[2]);
            }
        }
    }

    $resolveTemplateGroups = static function (string $templateName, array $templateMap, array &$cache, array $seen = []) use (&$resolveTemplateGroups): array {
        if ($templateName === '') {
            return [];
        }

        if (isset($cache[$templateName])) {
            return $cache[$templateName];
        }

        if (isset($seen[$templateName]) || !isset($templateMap[$templateName])) {
            return [];
        }

        $seen[$templateName] = true;
        $template = $templateMap[$templateName];
        $groups = [];

        if ($template['use'] !== '') {
            $groups = array_merge($groups, $resolveTemplateGroups($template['use'], $templateMap, $cache, $seen));
        }

        if ($template['hostgroups'] !== '') {
            $groups = array_merge($groups, array_map('trim', explode(',', $template['hostgroups'])));
        }

        $groups = array_values(array_unique(array_filter($groups, static function (string $value): bool {
            return $value !== '';
        })));
        $cache[$templateName] = $groups;

        return $groups;
    };

    $templateGroupCache = [];
    foreach ($hosts as $hostName => $host) {
        $groupNames = [];

        if ($host['use'] !== '') {
            $groupNames = array_merge($groupNames, $resolveTemplateGroups($host['use'], $hostTemplates, $templateGroupCache));
        }

        if ($host['hostgroups'] !== '') {
            $groupNames = array_merge($groupNames, array_map('trim', explode(',', $host['hostgroups'])));
        }

        $groupNames = array_values(array_unique(array_filter($groupNames, static function (string $value): bool {
            return $value !== '';
        })));

        foreach ($groupNames as $groupName) {
            if (!isset($hostGroups[$groupName])) {
                $hostGroups[$groupName] = [
                    'name' => $groupName,
                    'alias' => $groupName,
                    'members' => [],
                ];
            }

            if (!in_array($hostName, $hostGroups[$groupName]['members'], true)) {
                $hostGroups[$groupName]['members'][] = $hostName;
            }
        }
    }

    foreach ($hostGroups as $groupName => $group) {
        $members = array_values(array_unique(array_filter(array_map('trim', $group['members']), static function (string $value): bool {
            return $value !== '';
        })));
        sort($members);
        $hostGroups[$groupName]['members'] = $members;
    }

    return $hostGroups;
}

function group_summary_url(string $baseUrl, string $groupName): string
{
    return $baseUrl . rawurlencode($groupName) . '&style=summary';
}

function group_detail_url(string $baseUrl, string $groupName): string
{
    return $baseUrl . rawurlencode($groupName);
}

$status = parse_status_dat($statusFile);
$services = $status['service'];
$hosts = $status['host'];
$hostGroups = parse_object_configs($objectCacheFile, $mainConfigFile, $objectsDir);
$now = time();

$hostStateMap = [];
foreach ($hosts as $host) {
    $hostName = value_string($host, 'host_name');
    if ($hostName === '-') {
        continue;
    }

    $hostStateMap[$hostName] = value_int($host, 'current_state');
}

$serviceStateByHost = [];
foreach ($services as $service) {
    $hostName = value_string($service, 'host_name');
    if (!isset($serviceStateByHost[$hostName])) {
        $serviceStateByHost[$hostName] = [
            'ok' => 0,
            'warning' => 0,
            'unknown' => 0,
            'critical' => 0,
            'pending' => 0,
            'unhandled' => 0,
        ];
    }

    $state = value_int($service, 'current_state');
    if ($state === 0) {
        $serviceStateByHost[$hostName]['ok']++;
    } elseif ($state === 1) {
        $serviceStateByHost[$hostName]['warning']++;
    } elseif ($state === 2) {
        $serviceStateByHost[$hostName]['critical']++;
    } elseif ($state === 3) {
        $serviceStateByHost[$hostName]['unknown']++;
    } else {
        $serviceStateByHost[$hostName]['pending']++;
    }

    if ($state > 0 && !is_acknowledged($service) && !is_in_downtime($service)) {
        $serviceStateByHost[$hostName]['unhandled']++;
    }
}

$rows = [];
$totals = [
    'groups' => 0,
    'hosts' => 0,
    'hosts_up' => 0,
    'hosts_down' => 0,
    'services_ok' => 0,
    'services_problem' => 0,
    'unhandled' => 0,
];

foreach ($hostGroups as $groupName => $group) {
    if ($group['members'] === []) {
        continue;
    }

    $hostStats = [
        'up' => 0,
        'down' => 0,
        'unreachable' => 0,
        'pending' => 0,
    ];
    $serviceStats = [
        'ok' => 0,
        'warning' => 0,
        'unknown' => 0,
        'critical' => 0,
        'pending' => 0,
        'unhandled' => 0,
    ];

    foreach ($group['members'] as $hostName) {
        $hostState = $hostStateMap[$hostName] ?? 3;
        if ($hostState === 0) {
            $hostStats['up']++;
        } elseif ($hostState === 1) {
            $hostStats['down']++;
        } elseif ($hostState === 2) {
            $hostStats['unreachable']++;
        } else {
            $hostStats['pending']++;
        }

        $serviceHostStats = $serviceStateByHost[$hostName] ?? [
            'ok' => 0,
            'warning' => 0,
            'unknown' => 0,
            'critical' => 0,
            'pending' => 0,
            'unhandled' => 0,
        ];

        foreach ($serviceHostStats as $key => $value) {
            $serviceStats[$key] += $value;
        }
    }

    $problemCount = $serviceStats['warning'] + $serviceStats['unknown'] + $serviceStats['critical'];
    $statusLevel = 'ok';
    if ($hostStats['down'] > 0 || $serviceStats['critical'] > 0) {
        $statusLevel = 'critical';
    } elseif ($hostStats['unreachable'] > 0 || $serviceStats['warning'] > 0 || $serviceStats['unknown'] > 0) {
        $statusLevel = 'warning';
    }

    $rows[] = [
        'name' => $groupName,
        'alias' => $group['alias'],
        'members' => count($group['members']),
        'host_stats' => $hostStats,
        'service_stats' => $serviceStats,
        'problem_count' => $problemCount,
        'status_level' => $statusLevel,
    ];

    $totals['groups']++;
    $totals['hosts'] += count($group['members']);
    $totals['hosts_up'] += $hostStats['up'];
    $totals['hosts_down'] += $hostStats['down'] + $hostStats['unreachable'];
    $totals['services_ok'] += $serviceStats['ok'];
    $totals['services_problem'] += $problemCount;
    $totals['unhandled'] += $serviceStats['unhandled'];
}

usort($rows, static function (array $left, array $right): int {
    $rank = ['critical' => 3, 'warning' => 2, 'ok' => 1];
    $leftRank = $rank[$left['status_level']] ?? 0;
    $rightRank = $rank[$right['status_level']] ?? 0;

    if ($leftRank !== $rightRank) {
        return $rightRank <=> $leftRank;
    }

    if ($left['problem_count'] !== $right['problem_count']) {
        return $right['problem_count'] <=> $left['problem_count'];
    }

    return strcmp($left['alias'], $right['alias']);
});

$statusFileMtime = is_readable($statusFile) ? @filemtime($statusFile) : false;
$lastUpdateLabel = $statusFileMtime ? date('d M Y H:i:s', $statusFileMtime) : 'status.dat not readable';
$freshnessLabel = $statusFileMtime ? format_duration(max(0, $now - (int) $statusFileMtime)) . ' ago' : 'n/a';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="ROBOTS" content="NOINDEX, NOFOLLOW">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="<?= (int) $refreshSeconds ?>">
<title>Host Groups Modern Summary</title>
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
        align-items: start;
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
        max-width: 760px;
        color: var(--muted);
        font-size: 13px;
        font-style: italic;
        line-height: 1.5;
    }

    .hero-meta {
        display: grid;
        gap: 10px;
        align-content: start;
        justify-self: end;
        align-self: start;
        place-self: start end;
        width: 100%;
        max-width: 320px;
        min-width: 0;
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
    .kpi-value.ok { color: var(--ok); }
    .kpi-value.critical { color: var(--critical); }
    .kpi-value.warning { color: var(--warning); }
    .kpi-foot { margin-top: 12px; color: var(--muted-2); font-size: 10px; letter-spacing: 0.16em; text-transform: uppercase; }

    .summary-panel {
        padding: 18px;
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

    .summary-table {
        display: grid;
        gap: 10px;
    }

    .summary-row {
        display: grid;
        grid-template-columns: minmax(240px, 1.1fr) minmax(180px, 0.8fr) minmax(280px, 1.3fr) auto;
        gap: 12px;
        align-items: center;
        padding: 12px 14px;
        border-radius: 16px;
        background: rgba(18, 38, 61, 0.56);
        border: 1px solid var(--border-soft);
    }

    .summary-row.critical {
        background: linear-gradient(180deg, rgba(88, 28, 40, 0.36), rgba(18, 38, 61, 0.82));
        border-color: rgba(255, 141, 146, 0.18);
    }

    .summary-row.warning {
        background: linear-gradient(180deg, rgba(89, 69, 18, 0.28), rgba(18, 38, 61, 0.82));
        border-color: rgba(241, 201, 90, 0.16);
    }

    .group-name {
        min-width: 0;
    }

    .group-name strong {
        display: block;
        font-size: 15px;
        line-height: 1.35;
    }

    .group-name span {
        display: block;
        margin-top: 4px;
        color: var(--muted);
        font-size: 12px;
        line-height: 1.35;
    }

    .chip-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }

    .chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 7px 10px;
        border-radius: 999px;
        background: rgba(8, 17, 29, 0.55);
        border: 1px solid rgba(111, 143, 177, 0.12);
        color: var(--muted);
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .chip.ok { color: var(--ok); background: rgba(70, 214, 145, 0.12); border-color: rgba(70, 214, 145, 0.16); }
    .chip.warning { color: var(--warning); background: rgba(208, 160, 31, 0.14); border-color: rgba(241, 201, 90, 0.18); }
    .chip.critical { color: var(--critical); background: rgba(191, 67, 74, 0.14); border-color: rgba(255, 141, 146, 0.20); }
    .chip.unknown { color: var(--unknown); background: rgba(192, 201, 214, 0.12); border-color: rgba(192, 201, 214, 0.16); }

    .actions {
        display: inline-flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .action-link {
        padding: 8px 11px;
        border-radius: 999px;
        border: 1px solid var(--border-soft);
        background: rgba(255, 255, 255, 0.03);
        color: var(--muted);
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .empty {
        padding: 28px;
        border-radius: 18px;
        background: rgba(70, 214, 145, 0.08);
        border: 1px solid rgba(70, 214, 145, 0.18);
        color: var(--text);
    }

    @media (max-width: 1080px) {
        .kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .summary-row { grid-template-columns: 1fr; }
        .actions { justify-content: flex-start; }
    }

    @media (max-width: 900px) {
        .hero { grid-template-columns: 1fr; }
    }

    @media (max-width: 720px) {
        .page { padding: 12px; }
        .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
</style>
</head>
<body>
<div class="page">
    <div class="wrap">
        <section class="panel hero">
            <div>
                <div class="eyebrow">Host Group Monitoring</div>
                <h1>Host Groups Modern Summary</h1>
                <p>Compact summary view sourced directly from <code>status.dat</code> and Nagios hostgroup definitions, focused on host state totals and service state distribution.</p>
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
                <p class="kpi-title">Host groups</p>
                <p class="kpi-value ok"><?= $totals['groups'] ?></p>
                <div class="kpi-foot">configured groups</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Grouped hosts</p>
                <p class="kpi-value ok"><?= $totals['hosts'] ?></p>
                <div class="kpi-foot">all members counted</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Hosts up</p>
                <p class="kpi-value ok"><?= $totals['hosts_up'] ?></p>
                <div class="kpi-foot">healthy endpoints</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Hosts down / unreachable</p>
                <p class="kpi-value critical"><?= $totals['hosts_down'] ?></p>
                <div class="kpi-foot">host state issues</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Service problems</p>
                <p class="kpi-value warning"><?= $totals['services_problem'] ?></p>
                <div class="kpi-foot">warning + critical + unknown</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Unhandled items</p>
                <p class="kpi-value critical"><?= $totals['unhandled'] ?></p>
                <div class="kpi-foot">service attention</div>
            </article>
        </section>

        <?php if ($rows === []): ?>
            <section class="panel empty">No host groups could be built from the current configuration and runtime data.</section>
        <?php else: ?>
            <section class="panel summary-panel">
                <div class="panel-head">
                    <div>
                        <h2>Status Summary For All Host Groups</h2>
                        <p>Each row keeps only the essentials: group identity, host state totals and service state distribution.</p>
                    </div>
                </div>

                <div class="summary-table">
                    <?php foreach ($rows as $row): ?>
                        <article class="summary-row <?= htmlspecialchars($row['status_level'], ENT_QUOTES) ?>">
                            <div class="group-name">
                                <strong><a href="<?= htmlspecialchars(group_detail_url($hostGroupDetailBaseUrl, $row['name']), ENT_QUOTES) ?>"><?= htmlspecialchars($row['alias'], ENT_QUOTES) ?></a></strong>
                                <span><?= htmlspecialchars($row['name'], ENT_QUOTES) ?> · <?= $row['members'] ?> hosts</span>
                            </div>

                            <div class="chip-row">
                                <span class="chip ok"><?= $row['host_stats']['up'] ?> up</span>
                                <?php if ($row['host_stats']['down'] > 0): ?>
                                    <span class="chip critical"><?= $row['host_stats']['down'] ?> down</span>
                                <?php endif; ?>
                                <?php if ($row['host_stats']['unreachable'] > 0): ?>
                                    <span class="chip unknown"><?= $row['host_stats']['unreachable'] ?> unreachable</span>
                                <?php endif; ?>
                                <?php if ($row['host_stats']['pending'] > 0): ?>
                                    <span class="chip unknown"><?= $row['host_stats']['pending'] ?> pending</span>
                                <?php endif; ?>
                            </div>

                            <div class="chip-row">
                                <?php if ($row['service_stats']['ok'] > 0): ?>
                                    <span class="chip ok"><?= $row['service_stats']['ok'] ?> ok</span>
                                <?php endif; ?>
                                <?php if ($row['service_stats']['warning'] > 0): ?>
                                    <span class="chip warning"><?= $row['service_stats']['warning'] ?> warning</span>
                                <?php endif; ?>
                                <?php if ($row['service_stats']['unknown'] > 0): ?>
                                    <span class="chip unknown"><?= $row['service_stats']['unknown'] ?> unknown</span>
                                <?php endif; ?>
                                <?php if ($row['service_stats']['critical'] > 0): ?>
                                    <span class="chip critical"><?= $row['service_stats']['critical'] ?> critical</span>
                                <?php endif; ?>
                                <?php if ($row['service_stats']['pending'] > 0): ?>
                                    <span class="chip unknown"><?= $row['service_stats']['pending'] ?> pending</span>
                                <?php endif; ?>
                                <?php if ($row['service_stats']['unhandled'] > 0): ?>
                                    <span class="chip critical"><?= $row['service_stats']['unhandled'] ?> unhandled</span>
                                <?php endif; ?>
                            </div>

                            <div class="actions">
                                <a class="action-link" href="<?= htmlspecialchars(group_summary_url($hostGroupSummaryBaseUrl, $row['name']), ENT_QUOTES) ?>">Classic summary</a>
                                <a class="action-link" href="<?= htmlspecialchars(group_detail_url($hostGroupDetailBaseUrl, $row['name']), ENT_QUOTES) ?>">Host detail</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
