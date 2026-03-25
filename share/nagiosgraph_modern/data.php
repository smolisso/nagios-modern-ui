<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$listMode = isset($_GET['list']) ? trim((string) $_GET['list']) : '';
$host = isset($_GET['host']) ? trim((string) $_GET['host']) : '';
$service = isset($_GET['service']) ? trim((string) $_GET['service']) : '';
$requestedRange = isset($_GET['range']) ? trim((string) $_GET['range']) : '24h';
$debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';
$rrdtoolOverride = '';
$statusFile = '/usr/local/nagios/var/status.dat';

function json_error(string $message, int $status = 500): void
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function normalize_metric_name(string $value): string
{
    $decoded = rawurldecode($value);
    $decoded = str_replace('_', ' ', $decoded);
    $decoded = preg_replace('/\s+/', ' ', $decoded);

    return strtolower(trim((string) $decoded));
}

function range_config(string $range): array
{
    $ranges = [
        '1h' => ['seconds' => 3600, 'bucket_target' => 60],
        '6h' => ['seconds' => 21600, 'bucket_target' => 90],
        '24h' => ['seconds' => 86400, 'bucket_target' => 120],
        '7d' => ['seconds' => 604800, 'bucket_target' => 160],
        '30d' => ['seconds' => 2592000, 'bucket_target' => 180],
        '365d' => ['seconds' => 31536000, 'bucket_target' => 220],
    ];

    return $ranges[$range] ?? $ranges['24h'];
}

function resolve_rrd_root(): ?string
{
    $candidates = [
        '/usr/local/nagiosgraph/var/rrd',
        dirname(__DIR__, 3) . '/nagiosgraph/var/rrd',
        dirname(__DIR__, 2) . '/nagiosgraph/var/rrd',
    ];

    foreach ($candidates as $candidate) {
        if (is_dir($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function resolve_rrdtool(): ?string
{
    global $rrdtoolOverride;

    if ($rrdtoolOverride !== '' && is_file($rrdtoolOverride) && is_executable($rrdtoolOverride)) {
        return $rrdtoolOverride;
    }

    $candidates = [
        '/usr/bin/rrdtool',
        '/bin/rrdtool',
        '/usr/sbin/rrdtool',
        '/usr/local/sbin/rrdtool',
        '/usr/local/bin/rrdtool',
        '/opt/rrdtool/bin/rrdtool',
        'rrdtool',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === 'rrdtool') {
            return $candidate;
        }

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    foreach (['command -v rrdtool', 'which rrdtool'] as $probe) {
        $output = [];
        $code = 0;
        exec($probe . ' 2>/dev/null', $output, $code);

        if ($code !== 0 || $output === []) {
            continue;
        }

        $resolved = trim((string) $output[0]);
        if ($resolved !== '' && is_file($resolved) && is_executable($resolved)) {
            return $resolved;
        }
    }

    return null;
}

function discover_service_rrds(string $rrdRoot, string $host, string $service): array
{
    $hostDir = rtrim($rrdRoot, '/') . '/' . $host;
    if (!is_dir($hostDir)) {
        return [];
    }

    $files = glob($hostDir . '/*.rrd');
    if ($files === false) {
        return [];
    }

    $target = normalize_metric_name($service);
    $matched = [];

    foreach ($files as $file) {
        $basename = basename($file, '.rrd');
        $parts = explode('___', $basename, 2);
        if (count($parts) !== 2) {
            continue;
        }

        if (normalize_metric_name($parts[0]) !== $target) {
            continue;
        }

        $matched[] = [
            'file' => $file,
            'metric' => rawurldecode($parts[1]),
            'metric_key' => $parts[1],
        ];
    }

    usort($matched, static function (array $left, array $right): int {
        return strcmp($left['metric'], $right['metric']);
    });

    return $matched;
}

function list_hosts(string $rrdRoot): array
{
    $dirs = glob(rtrim($rrdRoot, '/') . '/*', GLOB_ONLYDIR);
    if ($dirs === false) {
        return [];
    }

    $hosts = array_map('basename', $dirs);
    sort($hosts, SORT_NATURAL | SORT_FLAG_CASE);

    return $hosts;
}

function runtime_hosts_from_status(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }

    $hosts = [];
    $insideHostStatus = false;
    $currentHost = null;

    while (($line = fgets($handle)) !== false) {
        $trimmed = trim($line);

        if ($trimmed === 'hoststatus {') {
            $insideHostStatus = true;
            $currentHost = null;
            continue;
        }

        if (!$insideHostStatus) {
            continue;
        }

        if ($trimmed === '}') {
            if ($currentHost !== null && $currentHost !== '') {
                $hosts[$currentHost] = true;
            }

            $insideHostStatus = false;
            $currentHost = null;
            continue;
        }

        if (preg_match('/^host_name=(.+)$/', $trimmed, $matches) === 1) {
            $currentHost = trim($matches[1]);
        }
    }

    fclose($handle);

    return array_keys($hosts);
}

function filtered_runtime_hosts(string $rrdRoot, string $statusFile): array
{
    $rrdHosts = list_hosts($rrdRoot);
    $runtimeHosts = runtime_hosts_from_status($statusFile);

    if ($runtimeHosts === []) {
        return $rrdHosts;
    }

    $runtimeMap = array_fill_keys($runtimeHosts, true);
    $filtered = array_values(array_filter($rrdHosts, static function (string $host) use ($runtimeMap): bool {
        return isset($runtimeMap[$host]);
    }));

    sort($filtered, SORT_NATURAL | SORT_FLAG_CASE);

    return $filtered;
}

function list_services(string $rrdRoot, string $host): array
{
    $hostDir = rtrim($rrdRoot, '/') . '/' . $host;
    if (!is_dir($hostDir)) {
        return [];
    }

    $files = glob($hostDir . '/*.rrd');
    if ($files === false) {
        return [];
    }

    $services = [];

    foreach ($files as $file) {
        $basename = basename($file, '.rrd');
        $parts = explode('___', $basename, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $decoded = rawurldecode($parts[0]);
        $decoded = str_replace('_', ' ', $decoded);
        $services[$decoded] = $decoded;
    }

    natcasesort($services);

    return array_values($services);
}

function fetch_rrd_series(string $rrdtool, string $file, int $start, int $end): array
{
    $command = sprintf(
        '%s fetch %s AVERAGE --start %d --end %d 2>&1',
        escapeshellcmd($rrdtool),
        escapeshellarg($file),
        $start,
        $end
    );

    $output = [];
    $code = 0;
    exec($command, $output, $code);

    if ($code !== 0) {
        return [
            'error' => trim(implode("\n", $output)),
            'command' => $command,
            'output_preview' => array_slice($output, 0, 20),
            'step' => 0,
            'columns' => [],
            'points_by_column' => [],
        ];
    }

    $step = 0;
    $columns = [];
    $pointsByColumn = [];

    foreach ($output as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }

        if (preg_match('/^(\d+)\s+/', $trimmed, $matches) === 1 && strpos($trimmed, ':') === false) {
            $step = (int) $matches[1];
            continue;
        }

        if ($columns === [] && preg_match('/^[A-Za-z0-9_.%-]+(\s+[A-Za-z0-9_.%-]+)+$/', $trimmed) === 1 && strpos($trimmed, ':') === false) {
            $columns = preg_split('/\s+/', $trimmed) ?: [];
            foreach ($columns as $column) {
                $pointsByColumn[$column] = [];
            }
            continue;
        }

        if (preg_match('/^(\d+):\s+(.+)$/', $trimmed, $matches) !== 1) {
            continue;
        }

        $timestamp = (int) $matches[1];
        $tokens = preg_split('/\s+/', trim($matches[2])) ?: [];

        if ($columns === []) {
            $columns = ['data'];
            $pointsByColumn['data'] = [];
        }

        foreach ($columns as $index => $column) {
            $valueToken = isset($tokens[$index]) ? trim((string) $tokens[$index]) : 'NaN';
            $value = (strtoupper($valueToken) === 'NAN' || $valueToken === '-nan') ? null : (float) $valueToken;
            $pointsByColumn[$column][] = [
                'timestamp' => $timestamp,
                'value' => $value,
            ];
        }
    }

    return [
        'error' => null,
        'command' => $command,
        'output_preview' => array_slice($output, 0, 20),
        'step' => $step,
        'columns' => $columns,
        'points_by_column' => $pointsByColumn,
    ];
}

function bucket_points(array $points, int $targetBuckets): array
{
    $filtered = array_values(array_filter($points, static function (array $point): bool {
        return $point['value'] !== null;
    }));

    $count = count($filtered);
    if ($count === 0) {
        return [];
    }

    if ($count <= $targetBuckets) {
        return $filtered;
    }

    $bucketSize = (int) ceil($count / $targetBuckets);
    $result = [];

    for ($offset = 0; $offset < $count; $offset += $bucketSize) {
        $chunk = array_slice($filtered, $offset, $bucketSize);
        if ($chunk === []) {
            continue;
        }

        $sum = 0.0;
        $items = 0;

        foreach ($chunk as $point) {
            $sum += (float) $point['value'];
            $items++;
        }

        $last = $chunk[count($chunk) - 1];
        $result[] = [
            'timestamp' => $last['timestamp'],
            'value' => $items > 0 ? round($sum / $items, 4) : null,
        ];
    }

    return $result;
}

function series_stats(array $points): array
{
    $values = [];
    foreach ($points as $point) {
        if ($point['value'] !== null) {
            $values[] = (float) $point['value'];
        }
    }

    if ($values === []) {
        return [
            'min' => null,
            'max' => null,
            'avg' => null,
            'current' => null,
        ];
    }

    return [
        'min' => min($values),
        'max' => max($values),
        'avg' => array_sum($values) / count($values),
        'current' => $values[count($values) - 1],
    ];
}

$range = range_config($requestedRange);
$rrdRoot = resolve_rrd_root();
$rrdtool = resolve_rrdtool();

if ($rrdRoot === null) {
    json_error('RRD root directory not found.');
}

if ($listMode === 'hosts') {
    echo json_encode([
        'hosts' => filtered_runtime_hosts($rrdRoot, $statusFile),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($listMode === 'services') {
    if ($host === '') {
        json_error('Missing host parameter.', 400);
    }

    echo json_encode([
        'host' => $host,
        'services' => list_services($rrdRoot, $host),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($host === '' || $service === '') {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing host or service parameter.',
    ]);
    exit;
}

if ($rrdtool === null) {
    json_error('rrdtool executable not found.');
}

$seriesFiles = discover_service_rrds($rrdRoot, $host, $service);
if ($seriesFiles === []) {
    json_error('No RRD files found for the requested host and service.', 404);
}

$end = time();
$start = $end - $range['seconds'];
$series = [];
$errors = [];

foreach ($seriesFiles as $seriesFile) {
    $fetched = fetch_rrd_series($rrdtool, $seriesFile['file'], $start, $end);
    if ($fetched['error'] !== null) {
        $errors[] = [
            'file' => basename($seriesFile['file']),
            'message' => $fetched['error'],
            'command' => $fetched['command'],
            'output_preview' => $fetched['output_preview'],
        ];
        continue;
    }

    if ($debugMode) {
        $errors[] = [
            'file' => basename($seriesFile['file']),
            'message' => 'debug preview',
            'command' => $fetched['command'],
            'output_preview' => $fetched['output_preview'],
        ];
    }

    $columnMap = $fetched['points_by_column'] ?? [];

    foreach ($columnMap as $columnName => $columnPoints) {
        $bucketed = bucket_points($columnPoints, $range['bucket_target']);
        if ($bucketed === []) {
            continue;
        }

        $metricName = $columnName === 'data' ? $seriesFile['metric'] : $columnName;
        $series[] = [
            'metric' => $metricName,
            'metric_key' => $seriesFile['metric_key'] . '::' . $columnName,
            'series_type' => in_array(strtolower($columnName), ['warn', 'warning', 'crit', 'critical'], true) ? 'threshold' : 'data',
            'threshold_kind' => strtolower($columnName),
            'file' => basename($seriesFile['file']),
            'points' => $bucketed,
            'stats' => series_stats($bucketed),
        ];
    }
}

if ($series === []) {
    http_response_code(500);
    echo json_encode([
        'error' => 'RRD files were found, but no readable data points were returned.',
        'debug' => [
            'host' => $host,
            'service' => $service,
            'rrd_root' => $rrdRoot,
            'rrdtool' => $rrdtool,
            'matched_files' => array_map(static function (array $item): string {
                return basename($item['file']);
            }, $seriesFiles),
            'fetch_errors' => $errors,
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($debugMode) {
    echo json_encode([
        'host' => $host,
        'service' => $service,
        'range' => $requestedRange,
        'matched_files' => array_map(static function (array $item): string {
            return basename($item['file']);
        }, $seriesFiles),
        'debug_preview' => $errors,
        'series_count' => count($series),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'host' => $host,
    'service' => $service,
    'range' => $requestedRange,
    'start' => $start,
    'end' => $end,
    'rrd_root' => $rrdRoot,
    'series' => $series,
], JSON_UNESCAPED_SLASHES);
