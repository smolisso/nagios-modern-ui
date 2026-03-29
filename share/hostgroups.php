<?php

$statusFile = '../var/status.dat';
$hostExtInfoFile = __DIR__ . '/../etc/objects/hostextinfo.cfg';
$objectsDir = __DIR__ . '/../etc/objects';
$hostIconBaseUrl = '/nagios/images/logos/';
$hostDetailBaseUrl = '/nagios/cgi-bin/status.cgi?host=';
$hostServicesDetailBaseUrl = '/nagios/host_detail.php?host=';
$hostGroupClassicBaseUrl = '/nagios/cgi-bin/status.cgi?style=overview&hostgroup=';
$hostGroupDetailBaseUrl = '/nagios/cgi-bin/status.cgi?style=detail&hostgroup=';
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

function host_detail_url(string $baseUrl, string $hostName): string
{
    return $baseUrl . rawurlencode($hostName);
}

function host_services_detail_url(string $baseUrl, string $hostName): string
{
    return $baseUrl . rawurlencode($hostName);
}

function hostgroup_classic_url(string $baseUrl, string $groupName): string
{
    return $baseUrl . rawurlencode($groupName);
}

function hostgroup_detail_url(string $baseUrl, string $groupName): string
{
    return $baseUrl . rawurlencode($groupName);
}

function parse_object_configs(string $objectsDir): array
{
    $hostAliases = [];
    $hostGroups = [];
    $hostTemplates = [];
    $hosts = [];

    $files = glob(rtrim($objectsDir, '/') . '/*.cfg');
    if ($files === false) {
        return [$hostAliases, $hostGroups];
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
                            'alias' => isset($current['alias']) && trim((string) $current['alias']) !== ''
                                ? trim((string) $current['alias'])
                                : $hostName,
                            'use' => isset($current['use']) ? trim((string) $current['use']) : '',
                            'hostgroups' => isset($current['hostgroups']) ? trim((string) $current['hostgroups']) : '',
                        ];
                        $hostAliases[$hostName] = $hosts[$hostName]['alias'];
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

    return [$hostAliases, $hostGroups];
}

$status = parse_status_dat($statusFile);
$services = $status['service'];
$hosts = $status['host'];
$hostIconMap = parse_hostextinfo_icons($hostExtInfoFile);
[$hostAliases, $hostGroups] = parse_object_configs($objectsDir);
$now = time();

$hostStatusMap = [];
foreach ($hosts as $host) {
    $hostName = value_string($host, 'host_name');
    if ($hostName === '-') {
        continue;
    }

    $hostStatusMap[$hostName] = $host;
}

$serviceProblemsByHost = [];
foreach ($services as $service) {
    $state = value_int($service, 'current_state');
    if ($state <= 0) {
        continue;
    }

    $hostName = value_string($service, 'host_name');
    if (!isset($serviceProblemsByHost[$hostName])) {
        $serviceProblemsByHost[$hostName] = [
            'critical' => 0,
            'warning' => 0,
            'unknown' => 0,
            'unhandled' => 0,
        ];
    }

    if ($state === 2) {
        $serviceProblemsByHost[$hostName]['critical']++;
    } elseif ($state === 1) {
        $serviceProblemsByHost[$hostName]['warning']++;
    } elseif ($state === 3) {
        $serviceProblemsByHost[$hostName]['unknown']++;
    }

    if (!is_acknowledged($service) && !is_in_downtime($service)) {
        $serviceProblemsByHost[$hostName]['unhandled']++;
    }
}

$groups = [];
$global = [
    'groups' => 0,
    'hosts' => 0,
    'up' => 0,
    'down' => 0,
    'unreachable' => 0,
    'service_problems' => 0,
    'unhandled' => 0,
];

foreach ($hostGroups as $groupName => $group) {
    $members = $group['members'];
    if ($members === []) {
        continue;
    }

    $groupStats = [
        'hosts_total' => count($members),
        'up' => 0,
        'down' => 0,
        'unreachable' => 0,
        'pending' => 0,
        'service_critical' => 0,
        'service_warning' => 0,
        'service_unknown' => 0,
        'service_problems' => 0,
        'unhandled' => 0,
    ];
    $memberRows = [];

    foreach ($members as $hostName) {
        $hostRow = $hostStatusMap[$hostName] ?? [];
        $hostState = value_int($hostRow, 'current_state');

        if ($hostState === 0) {
            $groupStats['up']++;
            $hostStateLabel = 'UP';
            $hostTheme = 'ok';
        } elseif ($hostState === 1) {
            $groupStats['down']++;
            $hostStateLabel = 'DOWN';
            $hostTheme = 'critical';
        } elseif ($hostState === 2) {
            $groupStats['unreachable']++;
            $hostStateLabel = 'UNREACHABLE';
            $hostTheme = 'unknown';
        } else {
            $groupStats['pending']++;
            $hostStateLabel = 'PENDING';
            $hostTheme = 'unknown';
        }

        $serviceStats = $serviceProblemsByHost[$hostName] ?? [
            'critical' => 0,
            'warning' => 0,
            'unknown' => 0,
            'unhandled' => 0,
        ];

        $groupStats['service_critical'] += $serviceStats['critical'];
        $groupStats['service_warning'] += $serviceStats['warning'];
        $groupStats['service_unknown'] += $serviceStats['unknown'];
        $groupStats['service_problems'] += $serviceStats['critical'] + $serviceStats['warning'] + $serviceStats['unknown'];
        $groupStats['unhandled'] += $serviceStats['unhandled'];

        if ($hostState !== 0 && !is_acknowledged($hostRow) && !is_in_downtime($hostRow)) {
            $groupStats['unhandled']++;
        }

        $memberRows[] = [
            'host_name' => $hostName,
            'alias' => $hostAliases[$hostName] ?? $hostName,
            'state_label' => $hostStateLabel,
            'host_theme' => $hostTheme,
            'service_problems' => $serviceStats['critical'] + $serviceStats['warning'] + $serviceStats['unknown'],
            'service_critical' => $serviceStats['critical'],
            'service_warning' => $serviceStats['warning'],
            'service_unknown' => $serviceStats['unknown'],
            'icon_url' => host_icon_url($hostIconMap, $hostIconBaseUrl, $hostName),
        ];
    }

    usort($memberRows, static function (array $left, array $right): int {
        $rank = ['critical' => 3, 'unknown' => 2, 'ok' => 1];
        $leftRank = $rank[$left['host_theme']] ?? 0;
        $rightRank = $rank[$right['host_theme']] ?? 0;

        if ($leftRank !== $rightRank) {
            return $rightRank <=> $leftRank;
        }

        if ($left['service_problems'] !== $right['service_problems']) {
            return $right['service_problems'] <=> $left['service_problems'];
        }

        return strcmp($left['host_name'], $right['host_name']);
    });

    $statusLevel = 'ok';
    if ($groupStats['down'] > 0 || $groupStats['service_critical'] > 0) {
        $statusLevel = 'critical';
    } elseif ($groupStats['unreachable'] > 0 || $groupStats['service_warning'] > 0 || $groupStats['service_unknown'] > 0) {
        $statusLevel = 'warning';
    }

    $groups[] = [
        'name' => $groupName,
        'alias' => $group['alias'],
        'stats' => $groupStats,
        'members' => $memberRows,
        'status_level' => $statusLevel,
    ];

    $global['groups']++;
    $global['hosts'] += $groupStats['hosts_total'];
    $global['up'] += $groupStats['up'];
    $global['down'] += $groupStats['down'];
    $global['unreachable'] += $groupStats['unreachable'];
    $global['service_problems'] += $groupStats['service_problems'];
    $global['unhandled'] += $groupStats['unhandled'];
}

usort($groups, static function (array $left, array $right): int {
    $rank = ['critical' => 3, 'warning' => 2, 'ok' => 1];
    $leftRank = $rank[$left['status_level']] ?? 0;
    $rightRank = $rank[$right['status_level']] ?? 0;

    if ($leftRank !== $rightRank) {
        return $rightRank <=> $leftRank;
    }

    if ($left['stats']['service_problems'] !== $right['stats']['service_problems']) {
        return $right['stats']['service_problems'] <=> $left['stats']['service_problems'];
    }

    if ($left['stats']['down'] !== $right['stats']['down']) {
        return $right['stats']['down'] <=> $left['stats']['down'];
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
<title>Host Groups Modern View</title>
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
        font-size: 14px;
        line-height: 1.55;
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

    .groups {
        display: grid;
        gap: 18px;
    }

    .group-card {
        padding: 20px;
    }

    .group-card.critical {
        background: linear-gradient(180deg, rgba(35, 18, 24, 0.78), rgba(16, 26, 42, 0.98));
    }

    .group-card.warning {
        background: linear-gradient(180deg, rgba(41, 34, 18, 0.72), rgba(16, 26, 42, 0.98));
    }

    .group-head {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 18px;
        align-items: start;
        margin-bottom: 18px;
    }

    .group-head h2 {
        margin: 0;
        font-size: 22px;
        line-height: 1.2;
    }

    .group-head h2 a:hover {
        text-decoration: underline;
    }

    .group-subtitle {
        margin-top: 8px;
        color: var(--muted);
        font-size: 14px;
    }

    .group-links {
        display: inline-flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }

    .group-link {
        padding: 10px 14px;
        border-radius: 999px;
        border: 1px solid var(--border-soft);
        background: rgba(255, 255, 255, 0.03);
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .stat-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 18px;
    }

    .stat {
        padding: 12px 14px;
        border-radius: 16px;
        background: rgba(18, 38, 61, 0.62);
        border: 1px solid var(--border-soft);
    }

    .stat-label { color: var(--muted); font-size: 11px; }
    .stat-value { display: block; margin-top: 6px; font-size: 20px; font-weight: 800; letter-spacing: -0.03em; }
    .stat-value.ok { color: var(--ok); }
    .stat-value.critical { color: var(--critical); }
    .stat-value.warning { color: var(--warning); }
    .stat-value.unknown { color: var(--unknown); }

    .members-panel {
        padding: 16px;
        border-radius: 18px;
        background: rgba(18, 38, 61, 0.44);
        border: 1px solid var(--border-soft);
    }

    .members {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        max-height: 360px;
        overflow-y: auto;
        padding-right: 6px;
    }

    .members::-webkit-scrollbar {
        width: 10px;
    }

    .members::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.04);
        border-radius: 999px;
    }

    .members::-webkit-scrollbar-thumb {
        background: rgba(125, 155, 191, 0.28);
        border-radius: 999px;
    }

    .members-title {
        margin: 0 0 10px;
        color: var(--muted);
        font-size: 13px;
    }

    .member {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
        align-items: start;
        padding: 12px;
        border-radius: 16px;
        background: rgba(18, 38, 61, 0.74);
        border: 1px solid var(--border-soft);
    }

    .member.is-clickable {
        cursor: pointer;
        transition: transform 140ms ease, box-shadow 140ms ease, border-color 140ms ease;
    }

    .member.is-clickable:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 28px rgba(0, 0, 0, 0.22);
        border-color: rgba(111, 143, 177, 0.28);
    }

    .member-main {
        min-width: 0;
    }

    .member-name {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-weight: 700;
        line-height: 1.4;
    }

    .host-icon {
        width: 18px;
        height: 18px;
        border-radius: 4px;
        object-fit: contain;
        flex: 0 0 auto;
    }

    .member-alias {
        display: block;
        margin-top: 4px;
        color: var(--muted);
        font-size: 12px;
        line-height: 1.35;
        word-break: break-word;
    }

    .badge {
        min-width: 82px;
        padding: 8px 10px;
        border-radius: 12px;
        text-align: center;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .badge.ok { color: var(--ok); background: rgba(70, 214, 145, 0.12); }
    .badge.critical { color: var(--critical); background: rgba(191, 67, 74, 0.14); }
    .badge.warning { color: var(--warning); background: rgba(208, 160, 31, 0.15); }
    .badge.unknown { color: var(--unknown); background: rgba(192, 201, 214, 0.12); }

    .member-problems {
        grid-column: 1 / -1;
        min-width: 0;
        margin-top: 2px;
        text-align: left;
        color: var(--muted);
        font-size: 12px;
        line-height: 1.45;
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
        .hero { grid-template-columns: 1fr; }
        .members { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 820px) {
        .kpis,
        .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .group-head { grid-template-columns: 1fr; }
        .members { grid-template-columns: 1fr; max-height: none; overflow: visible; padding-right: 0; }
    }
</style>
</head>
<body>
<div class="page">
    <div class="wrap">
        <section class="panel hero">
            <div>
                <div class="eyebrow">Host Group Monitoring</div>
                <h1>Host Groups Modern View</h1>
                <p>Standalone host group overview sourced from Nagios object definitions and <code>status.dat</code>, focused on group health, member state and service problem concentration.</p>
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
                <p class="kpi-value ok"><?= $global['groups'] ?></p>
                <div class="kpi-foot">configured groups</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Grouped hosts</p>
                <p class="kpi-value ok"><?= $global['hosts'] ?></p>
                <div class="kpi-foot">all members counted</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Hosts up</p>
                <p class="kpi-value ok"><?= $global['up'] ?></p>
                <div class="kpi-foot">healthy endpoints</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Hosts down</p>
                <p class="kpi-value critical"><?= $global['down'] ?></p>
                <div class="kpi-foot">hard host failures</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Service problems</p>
                <p class="kpi-value warning"><?= $global['service_problems'] ?></p>
                <div class="kpi-foot">warning + critical + unknown</div>
            </article>
            <article class="kpi">
                <p class="kpi-title">Unhandled items</p>
                <p class="kpi-value critical"><?= $global['unhandled'] ?></p>
                <div class="kpi-foot">host + service attention</div>
            </article>
        </section>

        <?php if ($groups === []): ?>
            <section class="panel empty">No host groups could be built from the current configuration and runtime data.</section>
        <?php else: ?>
            <section class="groups">
                <?php foreach ($groups as $group): ?>
                    <article class="panel group-card <?= htmlspecialchars($group['status_level'], ENT_QUOTES) ?>">
                        <div class="group-head">
                            <div>
                                <h2><a href="<?= htmlspecialchars(hostgroup_detail_url($hostGroupDetailBaseUrl, $group['name']), ENT_QUOTES) ?>"><?= htmlspecialchars($group['alias'], ENT_QUOTES) ?></a></h2>
                                <div class="group-subtitle"><?= htmlspecialchars($group['name'], ENT_QUOTES) ?> · <?= $group['stats']['hosts_total'] ?> hosts · <?= $group['stats']['service_problems'] ?> service problems</div>
                            </div>
                            <div class="group-links">
                                <a class="group-link" href="<?= htmlspecialchars(hostgroup_classic_url($hostGroupClassicBaseUrl, $group['name']), ENT_QUOTES) ?>">Classic overview</a>
                            </div>
                        </div>

                        <div class="stat-grid">
                            <div class="stat">
                                <span class="stat-label">Hosts up</span>
                                <span class="stat-value ok"><?= $group['stats']['up'] ?></span>
                            </div>
                            <div class="stat">
                                <span class="stat-label">Down / unreachable</span>
                                <span class="stat-value critical"><?= $group['stats']['down'] + $group['stats']['unreachable'] ?></span>
                            </div>
                            <div class="stat">
                                <span class="stat-label">Service problems</span>
                                <span class="stat-value warning"><?= $group['stats']['service_problems'] ?></span>
                            </div>
                            <div class="stat">
                                <span class="stat-label">Unhandled</span>
                                <span class="stat-value critical"><?= $group['stats']['unhandled'] ?></span>
                            </div>
                        </div>

                        <div class="members-panel">
                            <p class="members-title">All group members</p>
                            <div class="members">
                                <?php foreach ($group['members'] as $member): ?>
                                    <div class="member is-clickable" data-card-url="<?= htmlspecialchars(host_services_detail_url($hostServicesDetailBaseUrl, $member['host_name']), ENT_QUOTES) ?>">
                                        <div class="member-main">
                                            <a class="member-name" href="<?= htmlspecialchars(host_detail_url($hostDetailBaseUrl, $member['host_name']), ENT_QUOTES) ?>">
                                                <?php if ($member['icon_url'] !== null): ?>
                                                    <img class="host-icon" src="<?= htmlspecialchars($member['icon_url'], ENT_QUOTES) ?>" alt="">
                                                <?php endif; ?>
                                                <span><?= htmlspecialchars($member['host_name'], ENT_QUOTES) ?></span>
                                            </a>
                                            <span class="member-alias"><?= htmlspecialchars($member['alias'], ENT_QUOTES) ?></span>
                                        </div>
                                        <span class="badge <?= htmlspecialchars($member['host_theme'], ENT_QUOTES) ?>"><?= htmlspecialchars($member['state_label'], ENT_QUOTES) ?></span>
                                        <div class="member-problems">
                                            <?= $member['service_problems'] ?> service issues
                                            <br>
                                            C <?= $member['service_critical'] ?> · W <?= $member['service_warning'] ?> · U <?= $member['service_unknown'] ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
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
