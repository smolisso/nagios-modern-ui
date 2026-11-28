<?php

$statusFile = __DIR__ . '/../var/status.dat';
$objectCacheFile = __DIR__ . '/../var/objects.cache';
$hostExtInfoFile = __DIR__ . '/../etc/objects/hostextinfo.cfg';
$hostIconBaseUrl = '/nagios/images/logos/';
$hostDetailBaseUrl = '/nagios/cgi-bin/status.cgi?host=';
$hostServicesDetailBaseUrl = '/nagios/host_detail.php?host=';
$serviceDetailBaseUrl = '/nagios/cgi-bin/extinfo.cgi?type=2';
$graphShowBaseUrl = '/nagios/nagiosgraph_modern/show.php';
$graphActionIconUrl = '/nagios/images/action.svg';
require_once __DIR__ . '/modern_ui_graphs.inc.php';
require_once __DIR__ . '/modern_ui_live_widgets.inc.php';
$modernUiConfig = require __DIR__ . '/modern-ui-config.php';
$refreshSeconds = filter_var($modernUiConfig['live_refresh_seconds'] ?? 30, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 30;

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

function state_label(int $state, string $type = 'service'): string
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

function state_badge_class(int $state, string $type = 'service'): string
{
    if ($type === 'host') {
        if ($state === 1 || $state === 2) {
            return 'badge badge-critical';
        }

        return 'badge badge-ok';
    }

    switch ($state) {
        case 1:
            return 'badge badge-warning';
        case 2:
            return 'badge badge-critical';
        case 3:
            return 'badge badge-unknown';
        default:
            return 'badge badge-ok';
    }
}

function severity_weight(int $state, string $type = 'service'): int
{
    if ($type === 'host') {
        switch ($state) {
            case 1:
                return 5;
            case 2:
                return 4;
            default:
                return 0;
        }
    }

    switch ($state) {
        case 2:
            return 4;
        case 1:
            return 3;
        case 3:
            return 2;
        default:
            return 0;
    }
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

function timestamp_html(int $timestamp, string $format = 'd M H:i'): string
{
    if ($timestamp <= 0) {
        return 'n/a';
    }

    return '<time datetime="' . gmdate('Y-m-d\TH:i:s\Z', $timestamp) . '" data-live-timestamp="' . $timestamp
        . '" data-live-format="' . htmlspecialchars($format, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars(gmdate($format, $timestamp), ENT_QUOTES, 'UTF-8') . '</time>';
}

function incident_age(int $lastStateChange, int $now): int
{
    if ($lastStateChange <= 0) {
        return 0;
    }

    return max(0, $now - $lastStateChange);
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

function compare_by_severity_then_recency(array $left, array $right): int
{
    if ($left['severity_weight'] !== $right['severity_weight']) {
        return $right['severity_weight'] <=> $left['severity_weight'];
    }

    if ($left['age_seconds'] !== $right['age_seconds']) {
        return $left['age_seconds'] <=> $right['age_seconds'];
    }

    return strcmp($left['title'], $right['title']);
}

function host_detail_url(string $baseUrl, string $hostName): string
{
    return $baseUrl . rawurlencode($hostName);
}

function host_services_detail_url(string $baseUrl, string $hostName): string
{
    return $baseUrl . rawurlencode($hostName);
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

$now = time();
date_default_timezone_set('UTC');
$status = parse_status_dat($statusFile);
$services = $status['service'];
$hosts = $status['host'];

$summary = [
    'services_ok' => 0,
    'services_warning' => 0,
    'services_critical' => 0,
    'services_unknown' => 0,
    'services_pending' => 0,
    'service_problems' => 0,
    'service_unhandled' => 0,
    'service_acknowledged' => 0,
    'service_downtime' => 0,
    'service_flapping' => 0,
    'hard_problems' => 0,
    'soft_problems' => 0,
    'hosts_up' => 0,
    'hosts_down' => 0,
    'hosts_unreachable' => 0,
    'hosts_pending' => 0,
    'host_unhandled' => 0,
];

$incidents = [];
$oldestProblems = [];
$noisyHosts = [];

foreach ($services as $service) {
    $state = value_int($service, 'current_state');
    if ((isset($service['has_been_checked']) && value_int($service, 'has_been_checked') === 0) || $state < 0 || $state > 3) {
        $summary['services_pending']++;
        continue;
    }

    switch ($state) {
        case 0:
            $summary['services_ok']++;
            break;
        case 1:
            $summary['services_warning']++;
            break;
        case 2:
            $summary['services_critical']++;
            break;
        case 3:
            $summary['services_unknown']++;
            break;
    }

    if ($state <= 0) {
        continue;
    }

    $summary['service_problems']++;

    if (is_acknowledged($service)) {
        $summary['service_acknowledged']++;
    }

    if (is_in_downtime($service)) {
        $summary['service_downtime']++;
    }

    if (is_flapping($service)) {
        $summary['service_flapping']++;
    }

    if (is_hard_state($service)) {
        $summary['hard_problems']++;
    } else {
        $summary['soft_problems']++;
    }

    $unhandled = !is_acknowledged($service) && !is_in_downtime($service);
    if ($unhandled) {
        $summary['service_unhandled']++;
    }

    $hostName = value_string($service, 'host_name');
    $serviceName = value_string($service, 'service_description');
    $lastStateChange = value_int($service, 'last_state_change');
    $ageSeconds = incident_age($lastStateChange, $now);

    $incident = [
        'type' => 'service',
        'title' => $hostName . ' / ' . $serviceName,
        'host_name' => $hostName,
        'service_description' => $serviceName,
        'state' => $state,
        'state_label' => state_label($state, 'service'),
        'badge_class' => state_badge_class($state, 'service'),
        'last_state_change' => $lastStateChange,
        'age_seconds' => $ageSeconds,
        'age_label' => format_duration($ageSeconds),
        'changed_at' => timestamp_html($lastStateChange),
        'severity_weight' => severity_weight($state, 'service'),
        'attempt' => value_int($service, 'current_attempt') . '/' . max(1, value_int($service, 'max_attempts')),
        'unhandled' => $unhandled,
        'acknowledged' => is_acknowledged($service),
        'downtime' => is_in_downtime($service),
        'flapping' => is_flapping($service),
        'hard_state' => is_hard_state($service),
        'plugin_output' => value_string($service, 'plugin_output', ''),
    ];

    $incidents[] = $incident;
    $oldestProblems[] = $incident;

    if (!isset($noisyHosts[$hostName])) {
        $noisyHosts[$hostName] = [
            'host_name' => $hostName,
            'problems' => 0,
            'critical' => 0,
            'warning' => 0,
            'unknown' => 0,
            'highest_severity' => 0,
        ];
    }

    $noisyHosts[$hostName]['problems']++;
    $noisyHosts[$hostName]['highest_severity'] = max(
        $noisyHosts[$hostName]['highest_severity'],
        severity_weight($state, 'service')
    );

    if ($state === 2) {
        $noisyHosts[$hostName]['critical']++;
    } elseif ($state === 1) {
        $noisyHosts[$hostName]['warning']++;
    } elseif ($state === 3) {
        $noisyHosts[$hostName]['unknown']++;
    }
}

foreach ($hosts as $host) {
    $state = value_int($host, 'current_state');
    if (isset($host['has_been_checked']) && value_int($host, 'has_been_checked') === 0) {
        $summary['hosts_pending']++;
        continue;
    }

    if ($state === 0) {
        $summary['hosts_up']++;
        continue;
    }

    if ($state === 1) {
        $summary['hosts_down']++;
    } elseif ($state === 2) {
        $summary['hosts_unreachable']++;
    } else {
        $summary['hosts_pending']++;
        continue;
    }

    $unhandled = !is_acknowledged($host) && !is_in_downtime($host);
    if ($unhandled) {
        $summary['host_unhandled']++;
    }

    $hostName = value_string($host, 'host_name');
    $lastStateChange = value_int($host, 'last_state_change');
    $ageSeconds = incident_age($lastStateChange, $now);

    $incidents[] = [
        'type' => 'host',
        'title' => $hostName,
        'host_name' => $hostName,
        'service_description' => '',
        'state' => $state,
        'state_label' => state_label($state, 'host'),
        'badge_class' => state_badge_class($state, 'host'),
        'last_state_change' => $lastStateChange,
        'age_seconds' => $ageSeconds,
        'age_label' => format_duration($ageSeconds),
        'changed_at' => timestamp_html($lastStateChange),
        'severity_weight' => severity_weight($state, 'host'),
        'attempt' => value_int($host, 'current_attempt') . '/' . max(1, value_int($host, 'max_attempts')),
        'unhandled' => $unhandled,
        'acknowledged' => is_acknowledged($host),
        'downtime' => is_in_downtime($host),
        'flapping' => is_flapping($host),
        'hard_state' => is_hard_state($host),
        'plugin_output' => value_string($host, 'plugin_output', ''),
    ];
}

usort($incidents, 'compare_by_severity_then_recency');
$activeIncidents = array_slice($incidents, 0, 12);

usort($oldestProblems, static function (array $left, array $right): int {
    return $right['age_seconds'] <=> $left['age_seconds'];
});
$oldestProblems = array_slice($oldestProblems, 0, 5);

$noisyHosts = array_values($noisyHosts);
usort($noisyHosts, static function (array $left, array $right): int {
    if ($left['highest_severity'] !== $right['highest_severity']) {
        return $right['highest_severity'] <=> $left['highest_severity'];
    }

    if ($left['problems'] !== $right['problems']) {
        return $right['problems'] <=> $left['problems'];
    }

    return strcmp($left['host_name'], $right['host_name']);
});
$noisyHosts = array_slice($noisyHosts, 0, 5);

$totalProblems = $summary['service_problems'] + $summary['hosts_down'] + $summary['hosts_unreachable'];
$unhandledTotal = $summary['service_unhandled'] + $summary['host_unhandled'];
$statusFileMtime = is_readable($statusFile) ? @filemtime($statusFile) : false;
$lastUpdateLabel = $statusFileMtime ? timestamp_html((int) $statusFileMtime, 'd M Y H:i:s') : 'Unavailable';
$lastAgeLabel = $statusFileMtime ? format_duration(max(0, $now - (int) $statusFileMtime)) . ' ago' : 'n/a';
$dataSourceStatus = is_readable($statusFile) ? 'Live feed online' : 'Live feed unavailable';
$hostIconMap = parse_hostextinfo_icons($hostExtInfoFile);
$widgetData = modernUiLiveWidgetData($hosts, $services, $incidents, $now);
$healthLabels = ['ok' => 'Healthy', 'pending' => 'Pending', 'unknown' => 'Unknown', 'warning' => 'Warning', 'critical' => 'Critical', 'unreachable' => 'Unreachable', 'down' => 'Down'];
$ownedPercent = $widgetData['total'] ? (int) round($widgetData['handled'] / $widgetData['total'] * 100) : null;
$ownedAngle = $widgetData['total'] ? $widgetData['handled'] / $widgetData['total'] * 360 : 0;
$liveDashboard = [
    'refreshSeconds' => $refreshSeconds,
    'manageRefresh' => true,
    'host' => [
        'counts' => [$summary['hosts_up'], $summary['hosts_down'], $summary['hosts_unreachable'], $summary['hosts_pending']],
        'links' => array_map(static function (int $state): string {
            return '/nagios/cgi-bin/status.cgi?hostgroup=all&style=hostdetail&hoststatustypes=' . $state;
        }, [2, 4, 8, 1]),
    ],
    'service' => [
        'counts' => [$summary['services_ok'], $summary['services_warning'], $summary['services_unknown'], $summary['services_critical'], $summary['services_pending']],
        'links' => array_map(static function (int $state): string {
            return '/nagios/cgi-bin/status.cgi?host=all&servicestatustypes=' . $state;
        }, [2, 4, 8, 16, 1]),
    ],
];
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<meta charset="utf-8">
<meta name="ROBOTS" content="NOINDEX, NOFOLLOW">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<noscript><meta http-equiv="refresh" content="<?= (int) $refreshSeconds ?>"></noscript>
<title>Live Overview</title>
<script src="stylesheets/theme.js"></script>
<script>window.NagiosModernUIConfig = <?= json_encode($modernUiConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="stylesheets/modern-ui-time.js?v=<?= filemtime(__DIR__ . '/stylesheets/modern-ui-time.js') ?>"></script>
<link href="stylesheets/common.css" rel="stylesheet">
<link href="stylesheets/status.css?v=<?= filemtime(__DIR__ . '/stylesheets/status.css') ?>" rel="stylesheet">
<link href="stylesheets/live-overview.css?v=<?= filemtime(__DIR__ . '/stylesheets/live-overview.css') ?>" rel="stylesheet">
<script src="stylesheets/status-sort.js?v=<?= filemtime(__DIR__ . '/stylesheets/status-sort.js') ?>"></script>
</head>
<body class="status live-overview">
<div class="page">
    <div class="wrap">
        <header class="live-page-heading">
            <div>
                <div class="live-eyebrow">MONITORING / OVERVIEW</div>
                <h1 id="live-dashboard-title">Live Overview <span class="status-live-badge <?= $statusFileMtime === false ? 'is-unavailable' : '' ?>"><span class="status-live-dot" aria-hidden="true"></span><?= $statusFileMtime === false ? 'NO DATA' : 'LIVE' ?></span></h1>
            </div>
            <nav class="live-view-links" aria-label="Monitoring views">
                <a href="/nagios/cgi-bin/status.cgi?hostgroup=all&style=hostdetail">Host details ↗</a>
                <a href="/nagios/cgi-bin/status.cgi?host=all">Service details ↗</a>
            </nav>
        </header>

        <?php if ($statusFileMtime === false): ?>
            <div class="live-feed-warning" role="status">Live data is unavailable. Host and service counts cannot be determined.</div>
        <?php endif; ?>
        <div class="lw-toolbar" hidden>
            <button type="button" class="lw-choose" aria-expanded="false" aria-controls="live-widget-picker">Widgets</button>
            <button type="button" class="lw-reset" hidden>Reset layout</button>
            <button type="button" class="lw-edit" aria-pressed="false">Edit layout</button>
        </div>
        <section id="live-widget-picker" class="lw-picker" aria-label="Visible widgets" hidden>
            <header><h2>Visible widgets</h2><div><button type="button" class="lw-show-all">Show all</button><button type="button" class="lw-picker-close">Close</button></div></header>
            <div class="lw-options"></div>
        </section>
        <p class="lw-layout-status" role="status" aria-live="polite"></p>
        <p class="lw-empty" hidden>No widgets selected. Use Widgets to show them again.</p>
        <div id="live-widget-grid"></div>
        <div id="live-cockpit">
            <noscript><p>Enable JavaScript to display the monitoring cockpit. <?= count($hosts) ?> hosts · <?= count($services) ?> services.</p></noscript>
        </div>
        <div id="live-feed-info" class="live-feed-info">
            <div class="live-feed-state <?= $statusFileMtime === false ? 'is-unavailable' : '' ?>"><?= htmlspecialchars($dataSourceStatus, ENT_QUOTES, 'UTF-8') ?></div>
            <div><span>Last update</span><strong><?= $lastUpdateLabel ?></strong></div>
            <div><span>Data age</span><strong><?= htmlspecialchars($lastAgeLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
        </div>

        <div class="live-section-heading">
            <h2>Operations</h2>
            <span><?= $totalProblems ?> active problems · <?= $unhandledTotal ?> unhandled</span>
        </div>

        <div class="layout">
            <div class="stack">
                <section class="panel incidents-panel" data-widget="incidents">
                    <div class="panel-head">
                        <div>
                            <h2>Active incidents</h2>
                            <p>Host and service problems, ordered by severity and latest change.</p>
                        </div>
                        <div class="range">Top <?= count($activeIncidents) ?></div>
                    </div>

                    <?php if ($activeIncidents === []): ?>
                        <div class="empty-state"><?= $statusFileMtime === false ? 'Live data is unavailable.' : 'No active host or service incidents right now.' ?></div>
                    <?php else: ?>
                        <div class="list" tabindex="0" role="region" aria-label="Active incidents list">
                            <?php foreach ($activeIncidents as $incident): ?>
                                <?php
                                $incidentSeverityClass = '';
                                if (($incident['type'] === 'host' && in_array($incident['state'], [1, 2], true)) || ($incident['type'] === 'service' && $incident['state'] === 2)) {
                                    $incidentSeverityClass = 'critical';
                                } elseif ($incident['state'] === 1) {
                                    $incidentSeverityClass = 'warning';
                                } elseif ($incident['state'] === 3) {
                                    $incidentSeverityClass = 'unknown';
                                }
                                ?>
                                <article
                                    class="incident-row is-clickable <?= htmlspecialchars($incidentSeverityClass, ENT_QUOTES, 'UTF-8') ?>"
                                    data-card-url="<?= htmlspecialchars(host_services_detail_url($hostServicesDetailBaseUrl, $incident['host_name']), ENT_QUOTES, 'UTF-8') ?>"
                                >
                                    <div>
                                        <?php if ($incident['type'] === 'service'): ?>
                                            <div class="incident-title-row">
                                                <h3 class="incident-title">
                                                    <span class="host-link-wrap">
                                                        <?php $incidentHostIcon = host_icon_url($hostIconMap, $hostIconBaseUrl, $incident['host_name']); ?>
                                                        <?php if ($incidentHostIcon !== null): ?>
                                                            <img class="host-icon" src="<?= htmlspecialchars($incidentHostIcon, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                                        <?php endif; ?>
                                                        <a class="host-link" href="<?= htmlspecialchars(host_detail_url($hostDetailBaseUrl, $incident['host_name']), ENT_QUOTES, 'UTF-8') ?>">
                                                            <?= htmlspecialchars($incident['host_name'], ENT_QUOTES, 'UTF-8') ?>
                                                        </a>
                                                    </span>
                                                    /
                                                    <a class="host-link" href="<?= htmlspecialchars(service_detail_url($serviceDetailBaseUrl, $incident['host_name'], $incident['service_description']), ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars($incident['service_description'], ENT_QUOTES, 'UTF-8') ?>
                                                    </a>
                                                </h3>
                                                <?php if (modernUiServiceHasGraph($objectCacheFile, $incident['host_name'], $incident['service_description'])): ?>
                                                    <a
                                                        class="graph-action"
                                                        href="<?= htmlspecialchars(graph_show_url($graphShowBaseUrl, $incident['host_name'], $incident['service_description']), ENT_QUOTES, 'UTF-8') ?>"
                                                        target="main"
                                                        onClick="if(parent.hideModernGraphPopup){parent.hideModernGraphPopup();}"
                                                        onMouseOver="if(parent.showModernGraphPopup){parent.showModernGraphPopup(this,event);}"
                                                        onMouseMove="if(parent.moveModernGraphPopup){parent.moveModernGraphPopup(event,this);}"
                                                        onMouseOut="if(parent.hideModernGraphPopup){parent.hideModernGraphPopup();}"
                                                        rel="<?= htmlspecialchars(graph_show_url($graphShowBaseUrl, $incident['host_name'], $incident['service_description'], true), ENT_QUOTES, 'UTF-8') ?>"
                                                        title="Open performance graph"
                                                    >
                                                        <img src="<?= htmlspecialchars($graphActionIconUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Open performance graph">
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <h3 class="incident-title">
                                                <span class="host-link-wrap">
                                                    <?php $incidentHostIcon = host_icon_url($hostIconMap, $hostIconBaseUrl, $incident['host_name']); ?>
                                                    <?php if ($incidentHostIcon !== null): ?>
                                                        <img class="host-icon" src="<?= htmlspecialchars($incidentHostIcon, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                                    <?php endif; ?>
                                                    <a class="host-link" href="<?= htmlspecialchars(host_detail_url($hostDetailBaseUrl, $incident['host_name']), ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars($incident['host_name'], ENT_QUOTES, 'UTF-8') ?>
                                                    </a>
                                                </span>
                                            </h3>
                                        <?php endif; ?>
                                        <div class="incident-meta">
                                            Changed <?= htmlspecialchars($incident['age_label'], ENT_QUOTES, 'UTF-8') ?> ago
                                            • at <?= $incident['changed_at'] ?>
                                            • attempt <?= htmlspecialchars($incident['attempt'], ENT_QUOTES, 'UTF-8') ?>
                                            • <?= $incident['hard_state'] ? 'hard state' : 'soft state' ?>
                                        </div>
                                        <?php if ($incident['plugin_output'] !== ''): ?>
                                            <div class="incident-meta"><?= htmlspecialchars($incident['plugin_output'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <div class="incident-flags">
                                            <?php if ($incident['unhandled']): ?>
                                                <span class="chip unhandled">unhandled</span>
                                            <?php endif; ?>
                                            <?php if ($incident['acknowledged']): ?>
                                                <span class="chip">acknowledged</span>
                                            <?php endif; ?>
                                            <?php if ($incident['downtime']): ?>
                                                <span class="chip">downtime</span>
                                            <?php endif; ?>
                                            <?php if ($incident['flapping']): ?>
                                                <span class="chip">flapping</span>
                                            <?php endif; ?>
                                            <span class="chip"><?= htmlspecialchars(strtoupper($incident['type']), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="<?= htmlspecialchars($incident['badge_class'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($incident['state_label'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="panel list-panel live-oldest-panel" data-widget="oldest">
                    <div class="panel-head">
                        <div>
                            <h2>Oldest service problems</h2>
                            <p>Long-running issues that may be normalized or forgotten.</p>
                        </div>
                    </div>

                    <?php if ($oldestProblems === []): ?>
                        <div class="empty-state"><?= $statusFileMtime === false ? 'Live data is unavailable.' : 'No active service problems.' ?></div>
                    <?php else: ?>
                        <div class="list">
                            <?php foreach ($oldestProblems as $problem): ?>
                                <article class="list-row">
                                    <div>
                                        <h3 class="list-title">
                                            <span class="host-link-wrap">
                                                <?php $problemHostIcon = host_icon_url($hostIconMap, $hostIconBaseUrl, $problem['host_name']); ?>
                                                <?php if ($problemHostIcon !== null): ?>
                                                    <img class="host-icon" src="<?= htmlspecialchars($problemHostIcon, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                                <?php endif; ?>
                                                <a class="host-link" href="<?= htmlspecialchars(host_detail_url($hostDetailBaseUrl, $problem['host_name']), ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($problem['host_name'], ENT_QUOTES, 'UTF-8') ?>
                                                </a>
                                            </span>
                                            /
                                            <a class="host-link" href="<?= htmlspecialchars(service_detail_url($serviceDetailBaseUrl, $problem['host_name'], $problem['service_description']), ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($problem['service_description'], ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </h3>
                                        <div class="list-meta">
                                            Open for <?= htmlspecialchars($problem['age_label'], ENT_QUOTES, 'UTF-8') ?>
                                            • changed at <?= $problem['changed_at'] ?>
                                        </div>
                                    </div>
                                    <span class="<?= htmlspecialchars($problem['badge_class'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($problem['state_label'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="stack">
                <section class="panel summary-panel" data-widget="summary">
                    <div class="panel-head">
                        <div>
                            <h2>Operational summary</h2>
                            <p>Acknowledgements, suppression and check states.</p>
                        </div>
                    </div>

                    <div class="summary-grid">
                        <div class="summary-item live-unhandled">
                            <strong><?= $unhandledTotal ?></strong>
                            <span>Unhandled problems</span>
                        </div>
                        <div class="summary-item critical">
                            <strong><?= $totalProblems ?></strong>
                            <span>Total active problems</span>
                        </div>
                        <div class="summary-item">
                            <strong><?= $summary['service_acknowledged'] ?></strong>
                            <span>Acknowledged services</span>
                        </div>
                        <div class="summary-item">
                            <strong><?= $summary['service_downtime'] ?></strong>
                            <span>Services in downtime</span>
                        </div>
                        <div class="summary-item">
                            <strong><?= $summary['service_flapping'] ?></strong>
                            <span>Flapping services</span>
                        </div>
                        <div class="summary-item">
                            <strong><?= $summary['hard_problems'] ?></strong>
                            <span>Hard service problems</span>
                        </div>
                        <div class="summary-item">
                            <strong><?= $summary['soft_problems'] ?></strong>
                            <span>Soft service problems</span>
                        </div>
                        <div class="summary-item">
                            <strong><?= $summary['host_unhandled'] ?></strong>
                            <span>Unhandled host problems</span>
                        </div>
                    </div>
                </section>



                <section class="panel list-panel live-impacted-panel" data-widget="impacted">
                    <div class="panel-head">
                        <div>
                            <h2>Most impacted hosts</h2>
                            <p>Hosts carrying the largest concentration of service issues.</p>
                        </div>
                    </div>

                    <?php if ($noisyHosts === []): ?>
                        <div class="empty-state"><?= $statusFileMtime === false ? 'Live data is unavailable.' : 'No hosts with active service impact.' ?></div>
                    <?php else: ?>
                        <div class="list">
                            <?php foreach ($noisyHosts as $host): ?>
                                <article class="list-row">
                                    <div>
                                        <h3 class="list-title">
                                            <span class="host-link-wrap">
                                                <?php $noisyHostIcon = host_icon_url($hostIconMap, $hostIconBaseUrl, $host['host_name']); ?>
                                                <?php if ($noisyHostIcon !== null): ?>
                                                    <img class="host-icon" src="<?= htmlspecialchars($noisyHostIcon, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                                <?php endif; ?>
                                                <a class="host-link" href="<?= htmlspecialchars(host_detail_url($hostDetailBaseUrl, $host['host_name']), ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars($host['host_name'], ENT_QUOTES, 'UTF-8') ?>
                                                </a>
                                            </span>
                                        </h3>
                                        <div class="list-meta">
                                            <?= $host['problems'] ?> problems
                                            • <?= $host['critical'] ?> critical
                                            • <?= $host['warning'] ?> warning
                                            • <?= $host['unknown'] ?> unknown
                                        </div>
                                    </div>
                                    <span class="badge <?= $host['highest_severity'] >= 4 ? 'badge-critical' : ($host['highest_severity'] === 3 ? 'badge-warning' : 'badge-unknown') ?>">
                                        <?= $host['problems'] ?> open
                                    </span>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            </div>
        </div>

        <div id="live-extra-widgets" class="layout">
            <section class="panel" data-widget="host-map" aria-label="Host health map">
                <div class="panel-head"><div><h2>Host health map</h2><p>One tile per host · worst host or service state.</p></div><span class="range"><?= count($widgetData['hosts']) ?> HOSTS</span></div>
                <?php if ($widgetData['hosts'] === []): ?>
                    <div class="empty-state"><?= $statusFileMtime === false ? 'Live data is unavailable.' : 'No hosts in this snapshot.' ?></div>
                <?php else: $mapColumns = 24; $mapRows = (int) ceil(count($widgetData['hosts']) / $mapColumns); ?>
                    <svg class="lw-host-map" viewBox="0 0 264 <?= $mapRows * 11 ?>" role="img" aria-label="Host health map. Use the host selector below to inspect each tile.">
                        <?php foreach ($widgetData['hosts'] as $index => $item): ?>
                            <rect data-host-index="<?= $index ?>" class="lw-state-<?= $item['state'] ?>" x="<?= ($index % $mapColumns) * 11 + 1 ?>" y="<?= intdiv($index, $mapColumns) * 11 + 1 ?>" width="8" height="8" rx="2"><title><?= htmlspecialchars($item['name'] . ' · ' . $healthLabels[$item['state']], ENT_QUOTES, 'UTF-8') ?></title></rect>
                        <?php endforeach; ?>
                    </svg>
                    <div class="lw-map-legend">
                        <?php foreach ($widgetData['counts'] as $state => $count): if (!$count) continue; ?>
                            <span><i class="lw-state-<?= $state ?>" aria-hidden="true"></i><?= $healthLabels[$state] ?> <strong><?= $count ?></strong></span>
                        <?php endforeach; ?>
                    </div>
                    <label class="lw-host-label">Inspect host
                        <select class="lw-host-select">
                            <?php foreach ($widgetData['hosts'] as $index => $item): ?>
                                <option value="<?= $index ?>" data-url="<?= htmlspecialchars(host_services_detail_url($hostServicesDetailBaseUrl, $item['name']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item['name'] . ' · ' . $healthLabels[$item['state']] . ' · ' . $item['problems'] . ' service problems', ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="lw-host-detail" aria-live="polite"><a href="<?= htmlspecialchars(host_services_detail_url($hostServicesDetailBaseUrl, $widgetData['hosts'][0]['name']), ENT_QUOTES, 'UTF-8') ?>">Open host details ↗</a></div>
                <?php endif; ?>
            </section>
            <section class="panel" data-widget="ownership" aria-label="Problem ownership">
                <div class="panel-head"><div><h2>Problem ownership</h2><p>Acknowledged or in scheduled downtime.</p></div></div>
                <div class="lw-ownership-ring <?= !$widgetData['total'] ? 'is-empty' : '' ?>" style="--lw-owned-angle:<?= (float) $ownedAngle ?>deg" role="img" aria-label="<?= $ownedPercent === null ? ($statusFileMtime === false ? 'Live data unavailable' : 'No active problems') : $ownedPercent . '% of problems handled' ?>">
                    <div><strong data-owned-percent><?= $ownedPercent === null ? '—' : $ownedPercent . '%' ?></strong><span>PROBLEMS HANDLED</span><small><?= $statusFileMtime === false ? 'Live data unavailable' : ($widgetData['total'] ? $widgetData['handled'] . ' of ' . $widgetData['total'] . ' problems' : 'No active problems') ?></small></div>
                </div>
                <div class="lw-ownership-counts">
                    <div><i class="lw-state-ok" aria-hidden="true"></i><span>Handled</span><strong data-owned-count><?= $widgetData['handled'] ?></strong></div>
                    <div><i class="lw-state-critical" aria-hidden="true"></i><span>Unhandled</span><strong data-unhandled-count><?= $widgetData['total'] - $widgetData['handled'] ?></strong></div>
                </div>
            </section>
            <section class="panel" data-widget="incident-age" aria-label="Incident age">
                <div class="panel-head"><div><h2>Incident age</h2><p>All active host and service problems.</p></div><span class="range"><?= $widgetData['total'] ?> PROBLEMS</span></div>
                <?php if ($statusFileMtime === false): ?>
                    <div class="empty-state">Live data is unavailable.</div>
                <?php else: ?>
                    <div class="lw-age-bars">
                        <?php foreach ($widgetData['ages'] as $count): ?>
                            <div><strong data-age-value><?= $count ?></strong><span data-age-bar style="height:<?= (float) ($count / max(1, max($widgetData['ages'])) * 120) ?>px"></span></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="lw-age-labels"><span>&lt;1h</span><span>1–24h</span><span>1–7d</span><span>≥7d</span></div>
                    <div class="lw-age-note"><strong><?= $widgetData['ages'][3] ?></strong><span>problems open for at least a week<?= $widgetData['unknownAge'] ? ' · ' . $widgetData['unknownAge'] . ' with unknown age' : '' ?></span></div>
                <?php endif; ?>
            </section>
        </div>
        <div class="live-bottom-note">Live monitoring · Refresh every <?= (int) $refreshSeconds ?> seconds</div>
    </div>
</div>
<script>
(function () {
    var source = <?= json_encode($liveDashboard, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    source.title = document.getElementById('live-dashboard-title');
    source.mount = document.getElementById('live-cockpit');
    source.info = document.getElementById('live-feed-info');
    window.mountNagiosStatusDashboard(source);
})();
</script>
<script src="stylesheets/live-widgets.js?v=<?= filemtime(__DIR__ . '/stylesheets/live-widgets.js') ?>"></script>
<script>
(function () {
    var timezone = 'UTC';
    var formatter;
    function dateFormatter(zone, language) {
        return new Intl.DateTimeFormat(language, {
            timeZone: zone, day: '2-digit', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23'
        });
    }
    try {
        var settings = window.NagiosModernUITime.resolve();
        timezone = settings.timeZone;
        formatter = dateFormatter(timezone, settings.language);
    } catch (error) {
        timezone = 'UTC';
        try { formatter = dateFormatter(timezone, 'en-GB'); } catch (error) { return; }
    }
    if (typeof formatter.formatToParts !== 'function') return;

    document.querySelectorAll('[data-live-timestamp]').forEach(function (node) {
        var timestamp = Number(node.getAttribute('data-live-timestamp'));
        var date = new Date(timestamp * 1000);
        if (!(timestamp > 0) || !Number.isFinite(date.getTime())) return;
        var parts = {};
        formatter.formatToParts(date).forEach(function (part) {
            parts[part.type] = part.value;
        });
        var tokens = {
            d: parts.day, M: parts.month, Y: parts.year,
            H: parts.hour, i: parts.minute, s: parts.second
        };
        node.textContent = node.getAttribute('data-live-format').replace(/[dMYHis]/g, function (token) {
            return tokens[token];
        });
    });
    document.querySelectorAll('[data-live-timezone]').forEach(function (node) {
        node.textContent = timezone;
    });
})();

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
