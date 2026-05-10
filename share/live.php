<?php

$statusFile = '../var/status.dat';
$trendSnapshotFile = __DIR__ . '/live_trend_snapshot.json';
$hostExtInfoFile = __DIR__ . '/../etc/objects/hostextinfo.cfg';
$hostIconBaseUrl = '/nagios/images/logos/';
$hostDetailBaseUrl = '/nagios/cgi-bin/status.cgi?host=';
$hostServicesDetailBaseUrl = '/nagios/host_detail.php?host=';
$serviceDetailBaseUrl = '/nagios/cgi-bin/extinfo.cgi?type=2';
$graphShowBaseUrl = '/nagios/nagiosgraph_modern/show.php';
$graphActionIconUrl = '/nagios/images/action.gif';
$rrdRoot = '/usr/local/nagiosgraph/var/rrd';
$criticalServicesUrl = '/nagios/cgi-bin/status.cgi?host=all&servicestatustypes=28&hostprops=42';
$hostsDownUrl = '/nagios/cgi-bin/status.cgi?hostgroup=all&style=hostdetail&hoststatustypes=12';
$hostsUpUrl = '/nagios/cgi-bin/status.cgi?hostgroup=all&style=hostdetail';
$servicesOkUrl = '/nagios/cgi-bin/status.cgi?host=all';
$refreshSeconds = 30;
$liveTimezone = 'Europe/Rome';

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

function format_timestamp(int $timestamp): string
{
    return $timestamp > 0 ? date('d M H:i', $timestamp) : 'n/a';
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

function read_trend_snapshot(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }

    $contents = @file_get_contents($path);
    if ($contents === false || $contents === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded) || !isset($decoded['samples']) || !is_array($decoded['samples'])) {
        return [];
    }

    return $decoded['samples'];
}

function trend_time_format(string $range): string
{
    switch ($range) {
        case '7d':
        case '14d':
        case '30d':
            return 'd M';
        case '1h':
        case '6h':
        case '12h':
        case '24h':
        default:
            return 'H:i';
    }
}

function trend_time_range_format(string $range): string
{
    switch ($range) {
        case '7d':
        case '14d':
        case '30d':
            return 'd M Y';
        case '1h':
        case '6h':
        case '12h':
        case '24h':
        default:
            return 'd M H:i';
    }
}

function trend_axis_labels(array $samples, string $range): array
{
    $count = count($samples);
    if ($count === 0) {
        return [];
    }

    $indexes = array_unique([
        0,
        (int) floor(($count - 1) * 0.25),
        (int) floor(($count - 1) * 0.5),
        (int) floor(($count - 1) * 0.75),
        $count - 1,
    ]);

    $labels = [];
    $format = trend_time_format($range);
    foreach ($indexes as $index) {
        if (!isset($samples[$index]['timestamp'])) {
            continue;
        }

        $labels[] = date($format, (int) $samples[$index]['timestamp']);
    }

    return $labels;
}

function requested_trend_range(): string
{
    $allowed = ['1h', '6h', '12h', '24h', '7d', '14d', '30d'];
    $requested = isset($_GET['range']) ? (string) $_GET['range'] : '24h';

    return in_array($requested, $allowed, true) ? $requested : '24h';
}

function trend_range_seconds(string $range): int
{
    switch ($range) {
        case '1h':
            return 3600;
        case '6h':
            return 6 * 3600;
        case '12h':
            return 12 * 3600;
        case '7d':
            return 7 * 86400;
        case '14d':
            return 14 * 86400;
        case '30d':
            return 30 * 86400;
        case '24h':
        default:
            return 86400;
    }
}

function trend_range_label(string $range): string
{
    switch ($range) {
        case '1h':
            return '1 hour';
        case '6h':
            return '6 hours';
        case '12h':
            return '12 hours';
        case '7d':
            return '7 days';
        case '14d':
            return '14 days';
        case '30d':
            return '30 days';
        case '24h':
        default:
            return '24 hours';
    }
}

function trend_range_step(string $range, string $direction): string
{
    $order = ['1h', '6h', '12h', '24h', '7d', '14d', '30d'];
    $index = array_search($range, $order, true);
    if ($index === false) {
        return '24h';
    }

    if ($direction === 'out') {
        return $order[min(count($order) - 1, $index + 1)];
    }

    return $order[max(0, $index - 1)];
}

function filter_trend_samples(array $samples, int $seconds, int $now): array
{
    $cutoff = $now - $seconds;

    return array_values(array_filter($samples, static function (array $sample) use ($cutoff): bool {
        return isset($sample['timestamp']) && (int) $sample['timestamp'] >= $cutoff;
    }));
}

function bucket_trend_samples(array $samples, int $bucketCount): array
{
    $count = count($samples);
    if ($count === 0) {
        return [];
    }

    if ($count <= $bucketCount) {
        return array_values(array_map(static function (array $sample): array {
            $availability = isset($sample['availability_pct']) ? (float) $sample['availability_pct'] : 0.0;

            return [
                'timestamp' => isset($sample['timestamp']) ? (int) $sample['timestamp'] : 0,
                'availability_pct' => $availability,
            ];
        }, $samples));
    }

    $bucketSize = (int) ceil($count / $bucketCount);
    $buckets = [];

    for ($offset = 0; $offset < $count; $offset += $bucketSize) {
        $chunk = array_slice($samples, $offset, $bucketSize);
        if ($chunk === []) {
            continue;
        }

        $sum = 0.0;
        $items = 0;

        foreach ($chunk as $sample) {
            $sum += isset($sample['availability_pct']) ? (float) $sample['availability_pct'] : 0.0;
            $items++;
        }

        $last = $chunk[count($chunk) - 1];
        $buckets[] = [
            'timestamp' => isset($last['timestamp']) ? (int) $last['timestamp'] : 0,
            'availability_pct' => $items > 0 ? round($sum / $items, 1) : 0.0,
        ];
    }

    return $buckets;
}

function trend_scale(array $samples): array
{
    if ($samples === []) {
        return [
            'min' => 0.0,
            'max' => 100.0,
            'span' => 100.0,
        ];
    }

    $values = array_map(static function (array $sample): float {
        return isset($sample['availability_pct']) ? (float) $sample['availability_pct'] : 0.0;
    }, $samples);

    $min = min($values);
    $max = max($values);

    if (abs($max - $min) < 0.001) {
        $padding = 0.5;
    } else {
        $padding = max(0.2, ($max - $min) * 0.15);
    }

    $scaledMin = max(0.0, $min - $padding);
    $scaledMax = min(100.0, $max + $padding);

    if (($scaledMax - $scaledMin) < 1.0) {
        $mid = ($scaledMin + $scaledMax) / 2;
        $scaledMin = max(0.0, $mid - 0.5);
        $scaledMax = min(100.0, $mid + 0.5);
    }

    return [
        'min' => $scaledMin,
        'max' => $scaledMax,
        'span' => max(0.1, $scaledMax - $scaledMin),
    ];
}

function trend_scale_ticks(array $scale): array
{
    $ticks = [];
    $steps = 4;

    for ($index = 0; $index <= $steps; $index++) {
        $ratio = $steps > 0 ? ($index / $steps) : 0;
        $value = $scale['max'] - ($scale['span'] * $ratio);
        $ticks[] = [
            'value' => $value,
            'label' => number_format($value, 1) . '%',
            'position' => $ratio * 100,
        ];
    }

    return $ticks;
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

$now = time();
$timezone = in_array($liveTimezone, timezone_identifiers_list(), true) ? $liveTimezone : 'UTC';
date_default_timezone_set($timezone);
$status = parse_status_dat($statusFile);
$services = $status['service'];
$hosts = $status['host'];

$summary = [
    'services_ok' => 0,
    'services_warning' => 0,
    'services_critical' => 0,
    'services_unknown' => 0,
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
        'age_seconds' => $ageSeconds,
        'age_label' => format_duration($ageSeconds),
        'changed_at' => format_timestamp($lastStateChange),
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
        'age_seconds' => $ageSeconds,
        'age_label' => format_duration($ageSeconds),
        'changed_at' => format_timestamp($lastStateChange),
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
$lastUpdateLabel = $statusFileMtime ? date('d M Y H:i:s', $statusFileMtime) : 'status.dat not readable';
$lastAgeLabel = $statusFileMtime ? format_duration(max(0, $now - (int) $statusFileMtime)) . ' ago' : 'n/a';
$dataSourceStatus = is_readable($statusFile) ? 'Live feed online' : 'Live feed unavailable';
$trendRange = requested_trend_range();
$trendSamples = filter_trend_samples(read_trend_snapshot($trendSnapshotFile), trend_range_seconds($trendRange), $now);
$trendSamples = bucket_trend_samples($trendSamples, 48);
$trendScale = trend_scale($trendSamples);
$hostIconMap = parse_hostextinfo_icons($hostExtInfoFile);
$trendBars = [];
$trendPointFormat = trend_time_range_format($trendRange);

foreach ($trendSamples as $sample) {
    $availability = isset($sample['availability_pct']) ? (float) $sample['availability_pct'] : 0.0;
    $normalizedHeight = (($availability - $trendScale['min']) / $trendScale['span']) * 100;

    $trendBars[] = [
        'height' => max(6, min(100, (int) round($normalizedHeight))),
        'label' => number_format($availability, 1) . '%',
        'timestamp' => isset($sample['timestamp']) ? date($trendPointFormat, (int) $sample['timestamp']) : 'n/a',
    ];
}

$trendAxis = trend_axis_labels($trendSamples, $trendRange);
$trendRangeFormat = trend_time_range_format($trendRange);
$trendRangeLabel = $trendSamples !== []
    ? date($trendRangeFormat, (int) $trendSamples[0]['timestamp']) . ' to ' . date($trendRangeFormat, (int) $trendSamples[count($trendSamples) - 1]['timestamp'])
    : 'Waiting for samples';
$trendZoomOutRange = trend_range_step($trendRange, 'out');
$trendZoomInRange = trend_range_step($trendRange, 'in');
$trendScaleLabel = number_format($trendScale['min'], 1) . '% to ' . number_format($trendScale['max'], 1) . '%';
$trendTicks = trend_scale_ticks($trendScale);
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<meta charset="utf-8">
<meta name="ROBOTS" content="NOINDEX, NOFOLLOW">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="<?= (int) $refreshSeconds ?>">
<title>Live Overview</title>
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
        --critical-bg: rgba(191, 67, 74, 0.14);
        --critical-border: rgba(255, 141, 146, 0.28);
        --warning: #f1c95a;
        --warning-bg: rgba(208, 160, 31, 0.15);
        --warning-border: rgba(241, 201, 90, 0.30);
        --ok: #46d691;
        --ok-bg: rgba(70, 214, 145, 0.13);
        --ok-border: rgba(70, 214, 145, 0.28);
        --unknown: #c0c9d6;
        --unknown-bg: rgba(192, 201, 214, 0.12);
        --unknown-border: rgba(192, 201, 214, 0.22);
        --shadow: 0 18px 40px rgba(0, 0, 0, 0.32);
        --radius-xl: 28px;
        --radius-lg: 22px;
        --radius-md: 16px;
    }

    html.light,
    :root[data-theme="light"] {
        --bg: #f2f6fc;
        --bg-accent: #ffffff;
        --panel: rgba(255, 255, 255, 0.95);
        --panel-strong: rgba(255, 255, 255, 0.98);
        --panel-soft: rgba(245, 250, 255, 0.94);
        --border: rgba(65, 98, 133, 0.24);
        --border-soft: rgba(65, 98, 133, 0.20);
        --text: #1b2a3f;
        --muted: #4f6280;
        --muted-2: #7385a0;
        --critical: #b91e2f;
        --critical-bg: rgba(230, 56, 75, 0.14);
        --critical-border: rgba(209, 49, 69, 0.32);
        --warning: #8b6400;
        --warning-bg: rgba(245, 181, 44, 0.20);
        --warning-border: rgba(201, 141, 10, 0.30);
        --ok: #0b7a49;
        --ok-bg: rgba(31, 164, 99, 0.16);
        --ok-border: rgba(22, 133, 81, 0.28);
        --unknown: #4c627f;
        --unknown-bg: rgba(78, 102, 135, 0.14);
        --unknown-border: rgba(78, 102, 135, 0.24);
        --shadow: 0 14px 26px rgba(31, 57, 88, 0.12);
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
            radial-gradient(circle at top left, rgba(53, 92, 137, 0.18), transparent 32%),
            linear-gradient(180deg, var(--bg-accent) 0%, var(--bg) 100%);
    }

    a {
        color: inherit;
        text-decoration: none;
    }

    a.host-link:hover {
        text-decoration: underline;
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

    a.kpi-link:hover {
        text-decoration: underline;
    }

    .page {
        padding: 18px;
    }

    .wrap {
        max-width: 1440px;
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
        align-items: start;
    }

    .hero-corner-logo {
        grid-column: 2;
        grid-row: 1;
        justify-self: end;
        align-self: start;
        width: 32px;
        height: 32px;
        padding: 2px;
        border-radius: 999px;
        border: 1px solid rgba(118, 159, 201, 0.34);
        background: rgba(20, 36, 58, 0.46);
        box-shadow: 0 6px 14px rgba(5, 12, 23, 0.22);
    }

    .hero-corner-logo img {
        width: 100%;
        height: 100%;
        border-radius: 999px;
        display: block;
        object-fit: cover;
    }

    .hero-main {
        grid-column: 1;
        grid-row: 1;
        min-width: 0;
    }

    .eyebrow {
        margin-bottom: 10px;
        color: var(--muted-2);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.24em;
        text-transform: uppercase;
    }

    .hero h1 {
        margin: 0;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        font-size: 32px;
        line-height: 1.1;
        letter-spacing: -0.03em;
    }

    @keyframes live-overview-title-icon-blink {
        0%,
        45%,
        100% {
            opacity: 1;
        }
        55%,
        90% {
            opacity: 0.24;
        }
    }

    .hero-title-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 0.9em;
        color: #7be2ae;
        font-size: 0.88em;
        line-height: 1;
        animation: live-overview-title-icon-blink 1.2s ease-in-out infinite;
    }

    .hero p {
        margin: 12px 0 0 0;
        max-width: 760px;
        color: var(--muted);
        font-size: 13px;
        font-style: italic;
        line-height: 1.5;
    }

    .hero-side {
        grid-column: 2;
        grid-row: 2;
        display: grid;
        align-content: start;
        gap: 12px;
        min-height: 0;
    }

    .hero-meta {
        display: grid;
        gap: 10px;
        margin: 0;
    }

    .hero-side-kicker {
        color: var(--muted-2);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        text-align: right;
        padding-right: 2px;
    }

    .hero-trend {
        grid-column: 1;
        grid-row: 2;
        margin-top: 0;
        padding: 18px;
        border-radius: 22px;
        background: var(--panel-soft);
        border: 1px solid var(--border-soft);
    }

    .pill {
        display: flex;
        justify-content: space-between;
        gap: 14px;
        align-items: center;
        position: relative;
        padding: 12px 14px 12px 42px;
        background: linear-gradient(145deg, rgba(22, 38, 60, 0.60), rgba(15, 29, 47, 0.38));
        border: 1px solid rgba(116, 145, 178, 0.24);
        border-radius: 14px;
        min-height: 0;
        box-shadow:
            inset 0 1px 0 rgba(184, 206, 232, 0.08),
            0 0 0 1px rgba(110, 170, 232, 0.12),
            0 8px 20px rgba(5, 13, 24, 0.18);
        transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
    }

    .pill::before {
        position: absolute;
        left: 14px;
        top: 50%;
        width: 20px;
        height: 20px;
        margin-top: -10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        border: 1px solid rgba(134, 171, 212, 0.30);
        background: rgba(57, 94, 136, 0.18);
        color: #c9dcf4;
        font-size: 12px;
        font-weight: 700;
        line-height: 1;
        transition: transform 0.16s ease, background-color 0.16s ease, border-color 0.16s ease;
    }

    .pill-source::before { content: "◉"; }
    .pill-update::before { content: "◷"; }
    .pill-freshness::before { content: "↻"; }
    .pill-refresh::before { content: "⟳"; }

    .pill:hover {
        transform: translateY(-1px);
        border-color: rgba(126, 172, 220, 0.36);
        box-shadow:
            inset 0 1px 0 rgba(196, 220, 245, 0.12),
            0 0 0 1px rgba(124, 188, 255, 0.24),
            0 11px 24px rgba(6, 15, 27, 0.26);
    }

    .pill:hover::before {
        transform: scale(1.06);
        border-color: rgba(145, 193, 245, 0.48);
        background: rgba(74, 119, 168, 0.24);
    }

    .pill-label {
        color: var(--muted-2);
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .pill-value {
        color: var(--text);
        font-size: 13px;
        font-weight: 700;
        text-align: right;
    }

    .kpi-grid {
        display: grid;
        gap: 12px;
        margin-bottom: 14px;
    }

    .kpi-grid.hosts {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        margin-bottom: 12px;
    }

    .kpi-grid.services {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .kpi-card {
        padding: 10px 11px 9px;
        border-radius: var(--radius-lg);
        background: var(--panel-strong);
        border: 1px solid var(--border-soft);
        min-height: 88px;
    }

    .kpi-card.alert {
        background: linear-gradient(180deg, rgba(35, 18, 24, 0.92), rgba(16, 26, 42, 0.98));
    }

    .kpi-title {
        color: var(--muted);
        font-size: 10px;
        margin-bottom: 6px;
    }

    .kpi-value {
        margin: 0;
        font-size: 28px;
        line-height: 1;
        letter-spacing: -0.04em;
    }

    .kpi-value.critical {
        color: var(--critical);
    }

    .kpi-value.warning {
        color: var(--warning);
    }

    .kpi-value.ok {
        color: var(--ok);
    }

    .kpi-foot {
        margin-top: 6px;
        color: var(--muted-2);
        font-size: 8px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .layout {
        display: grid;
        grid-template-columns: minmax(0, 1.7fr) minmax(320px, 0.9fr);
        gap: 20px;
        align-items: start;
    }

    .stack {
        display: grid;
        gap: 20px;
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
        line-height: 1.2;
    }

    .panel-head p {
        margin: 8px 0 0 0;
        color: var(--muted);
        font-size: 14px;
        line-height: 1.4;
    }

    .range {
        color: var(--muted-2);
        font-size: 12px;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        white-space: nowrap;
        padding-top: 4px;
    }

    .trend-toolbar {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .zoom-controls {
        display: inline-flex;
        gap: 8px;
    }

    .zoom-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 999px;
        border: 1px solid var(--border-soft);
        background: var(--panel-soft);
        color: var(--text);
        font-size: 18px;
        line-height: 1;
    }

    .zoom-button.is-disabled {
        opacity: 0.45;
        pointer-events: none;
    }

    .trend-panel,
    .summary-panel,
    .list-panel,
    .incidents-panel {
        padding: 20px;
    }

    .hero-trend .panel-head {
        margin-bottom: 14px;
    }

    .hero-trend .panel-head h2 {
        font-size: 16px;
    }

    .hero-trend .panel-head p {
        font-size: 13px;
    }

    .hero-trend .trend-box {
        height: 250px;
    }

    .hero-trend .footer-note {
        margin-top: 12px;
    }

    .trend-box {
        position: relative;
        height: 260px;
        overflow: hidden;
        border-radius: 24px;
        border: 1px solid var(--border-soft);
        background:
            linear-gradient(180deg, rgba(79, 117, 162, 0.10), rgba(9, 18, 31, 0.95)),
            #08111c;
    }

    .trend-grid-line {
        position: absolute;
        left: 56px;
        right: 0;
        border-top: 1px solid rgba(111, 128, 152, 0.10);
    }

    .trend-grid-line strong {
        position: absolute;
        left: -50px;
        top: 0;
        transform: translateY(-50%);
        width: 42px;
        color: var(--muted-2);
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-align: right;
    }

    .trend-plot {
        position: absolute;
        left: 0;
        right: 0;
        top: 24px;
        bottom: 52px;
    }

    .trend-bars {
        position: absolute;
        left: 56px;
        right: 18px;
        top: 0;
        bottom: 0;
        display: flex;
        align-items: flex-end;
        gap: 8px;
    }

    .trend-bar {
        flex: 1;
        height: 100%;
        border-radius: 18px 18px 0 0;
        background: rgba(111, 143, 177, 0.08);
        overflow: visible;
        display: flex;
        align-items: flex-end;
        position: relative;
        z-index: 1;
        transition: transform 120ms ease, background-color 120ms ease;
    }

    .trend-bar span {
        display: block;
        width: 100%;
        border-radius: 18px 18px 0 0;
        background: linear-gradient(180deg, rgba(95, 185, 148, 0.95), rgba(63, 120, 188, 0.88));
        transition: background 120ms ease, box-shadow 120ms ease, opacity 120ms ease;
    }

    .trend-bar {
        cursor: default;
    }

    .trend-bar:hover {
        transform: translateY(-2px);
        z-index: 10;
    }

    .trend-bar:hover span {
        background: linear-gradient(180deg, rgba(248, 212, 102, 0.98), rgba(88, 194, 149, 0.94));
        box-shadow: 0 0 0 1px rgba(248, 212, 102, 0.25), 0 10px 18px rgba(0, 0, 0, 0.22);
    }

    .trend-tooltip {
        position: absolute;
        left: 50%;
        top: 8px;
        transform: translateX(-50%) translateY(6px);
        padding: 8px 10px;
        border-radius: 10px;
        border: 1px solid rgba(111, 143, 177, 0.16);
        background: rgba(6, 17, 29, 0.96);
        color: var(--text);
        font-size: 12px;
        line-height: 1.35;
        white-space: nowrap;
        opacity: 0;
        pointer-events: none;
        transition: opacity 120ms ease, transform 120ms ease;
        box-shadow: 0 10px 18px rgba(0, 0, 0, 0.22);
        z-index: 20;
    }

    .trend-bar:hover .trend-tooltip {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }

    .trend-axis {
        position: absolute;
        left: 56px;
        right: 18px;
        bottom: 20px;
        display: flex;
        justify-content: space-between;
        color: var(--muted-2);
        font-size: 11px;
        letter-spacing: 0.16em;
        text-transform: uppercase;
    }

    .trend-empty {
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        color: var(--muted);
        font-size: 14px;
        text-align: center;
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .summary-item {
        padding: 16px;
        border-radius: var(--radius-md);
        background: var(--panel-soft);
        border: 1px solid var(--border-soft);
    }

    .summary-item strong {
        display: block;
        margin-bottom: 8px;
        color: var(--text);
        font-size: 22px;
        letter-spacing: -0.03em;
    }

    .summary-item.ok strong {
        color: #5ee8a5;
    }

    .summary-item.critical strong {
        color: #ff7f8e;
    }

    .summary-item span {
        color: var(--muted);
        font-size: 13px;
    }

    .list {
        display: grid;
        gap: 10px;
    }

    .list-row {
        padding: 13px 16px;
        border-radius: var(--radius-md);
        background: var(--panel-soft);
        border: 1px solid var(--border-soft);
    }

    .incident-row {
        padding: 9px 12px;
        border-radius: 14px;
        background: var(--panel-soft);
        border: 1px solid var(--border-soft);
    }

    .incident-row.critical {
        background: linear-gradient(180deg, rgba(88, 28, 40, 0.62), rgba(29, 32, 50, 0.92));
        border-color: rgba(255, 141, 146, 0.26);
    }

    .incident-row.warning {
        background: linear-gradient(180deg, rgba(89, 69, 18, 0.52), rgba(29, 32, 50, 0.92));
        border-color: rgba(241, 201, 90, 0.24);
    }

    .incident-row.unknown {
        background: linear-gradient(180deg, rgba(60, 68, 83, 0.5), rgba(29, 32, 50, 0.92));
        border-color: rgba(192, 201, 214, 0.20);
    }

    .list-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 12px;
        align-items: center;
    }

    .list-title {
        margin: 0 0 6px 0;
        color: var(--text);
        font-size: 16px;
        font-weight: 600;
    }

    .incident-title {
        margin: 0;
        color: var(--text);
        font-size: 14px;
        line-height: 1.2;
        font-weight: 600;
    }

    .incident-title-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 8px;
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
        opacity: 1;
        transform: translateY(0);
        pointer-events: auto;
        transition: opacity 140ms ease, transform 140ms ease, border-color 140ms ease, background 140ms ease;
    }

    .graph-action img {
        width: 14px;
        height: 14px;
        display: block;
    }

    .incident-row:hover .graph-action,
    .incident-row:focus-within .graph-action {
        opacity: 1;
        transform: translateY(0);
        pointer-events: auto;
    }

    .graph-action:hover {
        background: rgba(22, 42, 68, 0.72);
        border-color: rgba(111, 143, 177, 0.28);
        text-decoration: none;
    }

    .list-meta {
        color: var(--muted);
        font-size: 12px;
        line-height: 1.4;
    }

    .incident-meta {
        color: var(--muted);
        font-size: 11px;
        line-height: 1.3;
        margin-top: 4px;
    }

    .incident-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
        align-items: start;
    }

    .incident-row.is-clickable {
        cursor: pointer;
        transition: transform 140ms ease, box-shadow 140ms ease, border-color 140ms ease;
    }

    .incident-row.is-clickable:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 28px rgba(0, 0, 0, 0.22);
        border-color: rgba(111, 143, 177, 0.28);
    }

    .incident-flags {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        margin-top: 6px;
    }

    .chip,
    .badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        white-space: nowrap;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.15em;
    }

    .badge {
        min-width: 118px;
        padding: 8px 11px;
        font-size: 11px;
        border: 1px solid transparent;
    }

    .chip {
        padding: 6px 9px;
        font-size: 10px;
        color: var(--muted);
        background: rgba(8, 17, 29, 0.55);
        border: 1px solid rgba(111, 143, 177, 0.12);
    }

    .incidents-panel .badge {
        min-width: 94px;
        padding: 6px 9px;
        font-size: 10px;
        letter-spacing: 0.10em;
    }

    .incidents-panel .chip {
        padding: 4px 8px;
        font-size: 9px;
        letter-spacing: 0.10em;
    }

    .incident-row .host-link-wrap {
        gap: 6px;
    }

    .incident-row .host-icon {
        width: 16px;
        height: 16px;
    }

    .chip.unhandled {
        color: var(--critical);
        border-color: rgba(255, 141, 146, 0.22);
        background: rgba(191, 67, 74, 0.12);
    }

    .badge-critical {
        color: var(--critical);
        background: var(--critical-bg);
        border-color: var(--critical-border);
    }

    .badge-warning {
        color: var(--warning);
        background: var(--warning-bg);
        border-color: var(--warning-border);
    }

    .badge-ok {
        color: var(--ok);
        background: var(--ok-bg);
        border-color: var(--ok-border);
    }

    .badge-unknown {
        color: var(--unknown);
        background: var(--unknown-bg);
        border-color: var(--unknown-border);
    }

    .empty-state {
        padding: 18px;
        color: var(--muted);
        background: var(--panel-soft);
        border: 1px dashed var(--border-soft);
        border-radius: var(--radius-md);
    }

    .footer-note {
        margin-top: 20px;
        color: var(--muted-2);
        font-size: 12px;
        text-align: right;
    }

    @media (max-width: 1180px) {
        .kpi-grid.hosts,
        .kpi-grid.services {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .layout,
        .hero {
            grid-template-columns: 1fr;
        }

        .hero-main,
        .hero-trend,
        .hero-side {
            grid-column: 1;
            grid-row: auto;
        }

        .hero-corner-logo {
            grid-column: 1;
            grid-row: 1;
            margin-top: 2px;
        }
    }

    @media (max-width: 720px) {
        .page {
            padding: 12px;
        }

        .kpi-grid.hosts,
        .kpi-grid.services,
        .summary-grid {
            grid-template-columns: 1fr;
        }

        .incident-row,
        .list-row {
            grid-template-columns: 1fr;
        }

        .hero h1 {
            font-size: 26px;
        }

        .badge {
            min-width: 0;
        }
    }

    html.light body,
    :root[data-theme="light"] body {
        background:
            radial-gradient(circle at top left, rgba(127, 175, 230, 0.22), transparent 36%),
            linear-gradient(180deg, var(--bg-accent) 0%, var(--bg) 100%);
    }

    html.light .kpi-card.alert,
    :root[data-theme="light"] .kpi-card.alert {
        background: linear-gradient(180deg, rgba(248, 229, 232, 0.92), rgba(236, 244, 253, 0.98));
        border-color: rgba(209, 49, 69, 0.18);
    }

    html.light .trend-box,
    :root[data-theme="light"] .trend-box {
        background:
            linear-gradient(180deg, rgba(125, 165, 208, 0.14), rgba(234, 242, 251, 0.98)),
            #eaf2fb;
        border-color: rgba(86, 120, 158, 0.22);
    }

    html.light .trend-grid-line,
    :root[data-theme="light"] .trend-grid-line {
        border-top-color: rgba(88, 118, 154, 0.22);
    }

    html.light .trend-bar,
    :root[data-theme="light"] .trend-bar {
        background: rgba(86, 120, 158, 0.14);
    }

    html.light .trend-bar:hover span,
    :root[data-theme="light"] .trend-bar:hover span {
        box-shadow: 0 0 0 1px rgba(248, 212, 102, 0.25), 0 8px 14px rgba(52, 89, 132, 0.20);
    }

    html.light .trend-tooltip,
    :root[data-theme="light"] .trend-tooltip {
        border-color: rgba(86, 120, 158, 0.24);
        background: rgba(255, 255, 255, 0.98);
        box-shadow: 0 8px 16px rgba(52, 89, 132, 0.16);
    }

    html.light .incident-row.critical,
    :root[data-theme="light"] .incident-row.critical {
        background: linear-gradient(180deg, rgba(248, 214, 220, 0.92), rgba(239, 246, 253, 0.98));
        border-color: rgba(209, 49, 69, 0.22);
    }

    html.light .incident-row.warning,
    :root[data-theme="light"] .incident-row.warning {
        background: linear-gradient(180deg, rgba(252, 239, 204, 0.92), rgba(239, 246, 253, 0.98));
        border-color: rgba(201, 141, 10, 0.24);
    }

    html.light .incident-row.unknown,
    :root[data-theme="light"] .incident-row.unknown {
        background: linear-gradient(180deg, rgba(230, 237, 247, 0.92), rgba(239, 246, 253, 0.98));
        border-color: rgba(78, 102, 135, 0.22);
    }

    html.light .graph-action,
    :root[data-theme="light"] .graph-action {
        border-color: rgba(86, 120, 158, 0.24);
        background: rgba(242, 247, 253, 0.92);
    }

    html.light .graph-action:hover,
    :root[data-theme="light"] .graph-action:hover {
        background: rgba(227, 237, 249, 0.98);
        border-color: rgba(86, 120, 158, 0.34);
    }

    html.light .chip,
    :root[data-theme="light"] .chip {
        background: rgba(231, 239, 249, 0.85);
        border-color: rgba(86, 120, 158, 0.20);
    }

    html.light .chip.unhandled,
    :root[data-theme="light"] .chip.unhandled {
        border-color: rgba(209, 49, 69, 0.30);
        background: rgba(230, 56, 75, 0.12);
    }

    html.light .pill,
    :root[data-theme="light"] .pill {
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.94), rgba(244, 250, 255, 0.90));
        border-color: rgba(86, 120, 158, 0.24);
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.78),
            0 0 0 1px rgba(134, 176, 221, 0.20),
            0 10px 22px rgba(61, 99, 139, 0.10);
    }

    html.light .pill::before,
    :root[data-theme="light"] .pill::before {
        color: #33597f;
        border-color: rgba(86, 120, 158, 0.30);
        background: rgba(126, 169, 214, 0.16);
    }

    html.light .pill:hover,
    :root[data-theme="light"] .pill:hover {
        border-color: rgba(86, 120, 158, 0.36);
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.92),
            0 0 0 1px rgba(110, 159, 209, 0.26),
            0 12px 26px rgba(61, 99, 139, 0.15);
    }

    html.light .pill:hover::before,
    :root[data-theme="light"] .pill:hover::before {
        border-color: rgba(86, 120, 158, 0.42);
        background: rgba(126, 169, 214, 0.26);
    }

    html.light .hero-side-kicker,
    :root[data-theme="light"] .hero-side-kicker {
        color: #6b7f99;
    }

    html.light .hero-corner-logo,
    :root[data-theme="light"] .hero-corner-logo {
        background: rgba(255, 255, 255, 0.94);
        border-color: rgba(86, 120, 158, 0.34);
        box-shadow: 0 8px 18px rgba(61, 99, 139, 0.14);
    }

    html.light .hero-title-icon,
    :root[data-theme="light"] .hero-title-icon {
        color: #1e7a56;
    }
</style>
</head>
<body>
<div class="page">
    <div class="wrap">
        <section class="panel hero">
            <div class="hero-corner-logo" aria-hidden="true">
                <img src="nagios.png" alt="">
            </div>
            <div class="hero-main">
                <div class="eyebrow">Live Monitoring</div>
                <h1>Nagios Live Overview<span class="hero-title-icon" aria-hidden="true">◉</span></h1>
                <p>Standalone operational dashboard sourced directly from `status.dat`</p>
            </div>
            <section class="hero-trend">
                <div class="panel-head">
                    <div>
                        <h2>Availability trend</h2>
                    </div>
                    <div class="trend-toolbar">
                        <div class="range"><?= htmlspecialchars(trend_range_label($trendRange), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="zoom-controls">
                            <a class="zoom-button <?= $trendRange === '1h' ? 'is-disabled' : '' ?>" href="?range=<?= htmlspecialchars($trendZoomInRange, ENT_QUOTES, 'UTF-8') ?>">-</a>
                            <a class="zoom-button <?= $trendRange === '30d' ? 'is-disabled' : '' ?>" href="?range=<?= htmlspecialchars($trendZoomOutRange, ENT_QUOTES, 'UTF-8') ?>">+</a>
                        </div>
                    </div>
                </div>

                <div class="trend-box" aria-hidden="true">
                    <?php if ($trendBars === []): ?>
                        <div class="trend-empty">
                            No trend samples available for the selected range. Populate <?= htmlspecialchars(basename($trendSnapshotFile), ENT_QUOTES, 'UTF-8') ?> from cron to render history here.
                        </div>
                    <?php else: ?>
                        <div class="trend-plot">
                            <?php foreach ($trendTicks as $tick): ?>
                                <div class="trend-grid-line" style="top: <?= htmlspecialchars((string) $tick['position'], ENT_QUOTES, 'UTF-8') ?>%;">
                                    <strong><?= htmlspecialchars($tick['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                            <?php endforeach; ?>
                            <div class="trend-bars">
                                <?php foreach ($trendBars as $bar): ?>
                                    <div class="trend-bar">
                                        <div class="trend-tooltip">
                                            <div><?= htmlspecialchars($bar['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div><?= htmlspecialchars($bar['timestamp'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <span style="height: <?= (int) $bar['height'] ?>%"></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="trend-axis">
                            <?php foreach ($trendAxis as $axisLabel): ?>
                                <span><?= htmlspecialchars($axisLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="footer-note">
                    <?= htmlspecialchars($trendRangeLabel, ENT_QUOTES, 'UTF-8') ?> | Visible scale: <?= htmlspecialchars($trendScaleLabel, ENT_QUOTES, 'UTF-8') ?>
                </div>
            </section>
            <div class="hero-side">
                <div class="hero-side-kicker">Live Runtime</div>
                <div class="hero-meta">
                    <div class="pill pill-source">
                        <span class="pill-label">Data source</span>
                        <span class="pill-value"><?= htmlspecialchars($dataSourceStatus, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="pill pill-update">
                        <span class="pill-label">Last file update</span>
                        <span class="pill-value"><?= htmlspecialchars($lastUpdateLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="pill pill-freshness">
                        <span class="pill-label">Data freshness</span>
                        <span class="pill-value"><?= htmlspecialchars($lastAgeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="pill pill-refresh">
                        <span class="pill-label">Auto refresh</span>
                        <span class="pill-value">Every <?= (int) $refreshSeconds ?>s</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="kpi-grid hosts">
            <article class="kpi-card alert">
                <div class="kpi-title">Hosts Down</div>
                <p class="kpi-value critical">
                    <a class="kpi-link" href="<?= htmlspecialchars($hostsDownUrl, ENT_QUOTES, 'UTF-8') ?>">
                        <?= $summary['hosts_down'] + $summary['hosts_unreachable'] ?>
                    </a>
                </p>
                <div class="kpi-foot"><?= $summary['hosts_down'] ?> down, <?= $summary['hosts_unreachable'] ?> unreachable</div>
            </article>

            <article class="kpi-card">
                <div class="kpi-title">Hosts UP</div>
                <p class="kpi-value ok">
                    <a class="kpi-link" href="<?= htmlspecialchars($hostsUpUrl, ENT_QUOTES, 'UTF-8') ?>">
                        <?= $summary['hosts_up'] ?>
                    </a>
                </p>
                <div class="kpi-foot">Currently reachable hosts</div>
            </article>

            <article class="kpi-card">
                <div class="kpi-title">Hosts Pending</div>
                <p class="kpi-value"><?= $summary['hosts_pending'] ?></p>
                <div class="kpi-foot">No definitive host state yet</div>
            </article>
        </section>

        <section class="kpi-grid services">
            <article class="kpi-card alert">
                <div class="kpi-title">Critical Services</div>
                <p class="kpi-value critical">
                    <a class="kpi-link" href="<?= htmlspecialchars($criticalServicesUrl, ENT_QUOTES, 'UTF-8') ?>">
                        <?= $summary['services_critical'] ?>
                    </a>
                </p>
                <div class="kpi-foot">Highest urgency service impact</div>
            </article>

            <article class="kpi-card">
                <div class="kpi-title">Warning Services</div>
                <p class="kpi-value warning">
                    <a class="kpi-link" href="<?= htmlspecialchars($criticalServicesUrl, ENT_QUOTES, 'UTF-8') ?>">
                        <?= $summary['services_warning'] ?>
                    </a>
                </p>
                <div class="kpi-foot">Degraded but not critical</div>
            </article>

            <article class="kpi-card">
                <div class="kpi-title">Unknown Services</div>
                <p class="kpi-value">
                    <a class="kpi-link" href="<?= htmlspecialchars($criticalServicesUrl, ENT_QUOTES, 'UTF-8') ?>">
                        <?= $summary['services_unknown'] ?>
                    </a>
                </p>
                <div class="kpi-foot">State needs inspection</div>
            </article>

            <article class="kpi-card">
                <div class="kpi-title">Service OK</div>
                <p class="kpi-value ok">
                    <a class="kpi-link" href="<?= htmlspecialchars($servicesOkUrl, ENT_QUOTES, 'UTF-8') ?>">
                        <?= $summary['services_ok'] ?>
                    </a>
                </p>
                <div class="kpi-foot">Healthy service checks</div>
            </article>
        </section>

        <div class="layout">
            <div class="stack">
                <section class="panel incidents-panel">
                    <div class="panel-head">
                        <div>
                            <h2>Active incidents</h2>
                            <p>Sorted by severity first, then by most recent state change so fresh breakages stay at the top.</p>
                        </div>
                        <div class="range">Top <?= count($activeIncidents) ?></div>
                    </div>

                    <?php if ($activeIncidents === []): ?>
                        <div class="empty-state">No active host or service incidents right now.</div>
                    <?php else: ?>
                        <div class="list">
                            <?php foreach ($activeIncidents as $incident): ?>
                                <?php
                                $incidentSeverityClass = '';
                                if ($incident['state'] === 2) {
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
                                                <?php if (service_has_graph($rrdRoot, $incident['host_name'], $incident['service_description'])): ?>
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
                                            • at <?= htmlspecialchars($incident['changed_at'], ENT_QUOTES, 'UTF-8') ?>
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

            </div>

            <div class="stack">
                <section class="panel summary-panel">
                    <div class="panel-head">
                        <div>
                            <h2>Operational summary</h2>
                            <p>Quick counters for hidden work, suppression, and check-state quality.</p>
                        </div>
                    </div>

                    <div class="summary-grid">
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

                <section class="panel list-panel">
                    <div class="panel-head">
                        <div>
                            <h2>Oldest service problems</h2>
                            <p>Long-running issues that may be normalized or forgotten.</p>
                        </div>
                    </div>

                    <?php if ($oldestProblems === []): ?>
                        <div class="empty-state">No active service problems.</div>
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
                                            • changed at <?= htmlspecialchars($problem['changed_at'], ENT_QUOTES, 'UTF-8') ?>
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

                <section class="panel list-panel">
                    <div class="panel-head">
                        <div>
                            <h2>Most impacted hosts</h2>
                            <p>Hosts carrying the largest concentration of service issues.</p>
                        </div>
                    </div>

                    <?php if ($noisyHosts === []): ?>
                        <div class="empty-state">No hosts with active service impact.</div>
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

        <div class="footer-note">
            Source: <?= htmlspecialchars($statusFile, ENT_QUOTES, 'UTF-8') ?> | Trend store: <?= htmlspecialchars($trendSnapshotFile, ENT_QUOTES, 'UTF-8') ?>
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
