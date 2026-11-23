<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$listMode = isset($_GET['list']) ? trim((string) $_GET['list']) : '';
$host = isset($_GET['host']) ? trim((string) $_GET['host']) : '';
$service = isset($_GET['service']) ? trim((string) $_GET['service']) : '';
$requestedRange = isset($_GET['range']) ? trim((string) $_GET['range']) : '24h';
$debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';
$config = ['backend' => 'nagiosgraph', 'rrd_root' => '', 'rrdtool' => '', 'status_file' => '/usr/local/nagios/var/status.dat'];
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
    $localConfig = require $configFile;
    if (!is_array($localConfig)) {
        json_error('Invalid graph configuration.');
    }
    $config = array_merge($config, $localConfig);
}
if (!in_array($config['backend'], ['nagiosgraph', 'pnp4nagios'], true)) {
    json_error('Invalid graph backend.');
}
foreach (['rrd_root', 'rrdtool', 'status_file'] as $key) {
    if (!is_string($config[$key])) {
        json_error('Invalid graph configuration value: ' . $key);
    }
}
$rrdtoolOverride = $config['rrdtool'];
$statusFile = $config['status_file'];

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
    global $config;
    if ($config['rrd_root'] !== '') {
        return is_dir($config['rrd_root']) && is_readable($config['rrd_root']) ? $config['rrd_root'] : null;
    }
    if ($config['backend'] === 'pnp4nagios') {
        return null;
    }
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

    if ($rrdtoolOverride !== '') {
        return is_file($rrdtoolOverride) && is_executable($rrdtoolOverride) ? $rrdtoolOverride : null;
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

function pnp_component(string $value): string
{
    return str_replace(['&', ' ', ':', '/', "\\"], '_', $value);
}

function pnp_decode(string $value): string
{
    // PNP encodes only these three characters in its NAGIOS_* fields.
    return str_ireplace(['%3c', '%3e', '%26'], ['<', '>', '&'], $value);
}

function pnp_xml(string $file): ?SimpleXMLElement
{
    if (!is_readable($file)) {
        return null;
    }
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file, SimpleXMLElement::class, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $xml === false ? null : $xml;
}

function pnp_host_dir(string $root, string $host): ?string
{
    $component = pnp_component($host);
    if ($component === '' || $component === '.' || $component === '..') {
        return null;
    }
    $rootPath = realpath($root);
    $hostPath = realpath(rtrim($root, '/') . '/' . $component);
    if ($rootPath === false || $hostPath === false || !is_dir($hostPath)
        || strpos($hostPath, rtrim($rootPath, '/') . '/') !== 0) {
        return null;
    }
    return $hostPath;
}

function pnp_hosts(string $root): array
{
    $hosts = [];
    foreach (glob(rtrim($root, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        foreach (glob($dir . '/*.xml') ?: [] as $file) {
            $xml = pnp_xml($file);
            if ($xml === null) continue;
            $host = pnp_decode((string) $xml->NAGIOS_DISP_HOSTNAME);
            if ($host === '') $host = basename($dir);
            $hosts[$host] = $host;
            break;
        }
    }
    natcasesort($hosts);
    return array_values($hosts);
}

function pnp_services(string $root, string $host): array
{
    $dir = pnp_host_dir($root, $host);
    if ($dir === null) return [];
    $services = [];
    foreach (glob($dir . '/*.xml') ?: [] as $file) {
        $xml = pnp_xml($file);
        if ($xml === null) continue;
        $service = pnp_decode((string) $xml->NAGIOS_DISP_SERVICEDESC);
        if ($service === '') $service = basename($file, '.xml');
        $services[$service] = $service;
    }
    natcasesort($services);
    return array_values($services);
}

function pnp_service_rrds(string $root, string $host, string $service): array
{
    $dir = pnp_host_dir($root, $host);
    $component = pnp_component($service);
    if ($dir === null || $component === '' || $component === '.' || $component === '..') return [];
    $xml = pnp_xml($dir . '/' . $component . '.xml');
    if ($xml === null) return [];
    $files = [];
    foreach ($xml->DATASOURCE as $source) {
        // Only use the basename from PNP metadata; the configured host directory is authoritative.
        $name = basename((string) $source->RRDFILE);
        if ($name === '' || substr($name, -4) !== '.rrd') continue;
        $file = realpath($dir . '/' . $name);
        if ($file === false || strpos($file, rtrim($dir, '/') . '/') !== 0 || !is_file($file)) continue;
        $column = trim((string) $source->DS);
        if ($column === '') continue;
        $label = trim((string) $source->LABEL);
        if ($label === '') $label = trim((string) $source->NAME);
        if ($label === '') $label = $column;
        $files[] = ['file' => $file, 'metric' => $label, 'metric_key' => $name . '::' . $column,
            'column' => $column, 'unit' => str_replace('%%', '%', (string) $source->UNIT)];
    }
    return $files;
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

        if ($columns === [] && preg_match('/^[A-Za-z0-9_.%-]+(\s+[A-Za-z0-9_.%-]+)*$/', $trimmed) === 1 && strpos($trimmed, ':') === false) {
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

if ($config['backend'] === 'pnp4nagios' && !function_exists('simplexml_load_file')) {
    json_error('The PHP SimpleXML extension is required for PNP4Nagios.');
}
$range = range_config($requestedRange);
$rrdRoot = resolve_rrd_root();
$rrdtool = resolve_rrdtool();

if ($rrdRoot === null) {
    json_error('RRD root directory not found.');
}

if ($listMode === 'hosts') {
    echo json_encode([
        'hosts' => $config['backend'] === 'pnp4nagios' ? pnp_hosts($rrdRoot) : filtered_runtime_hosts($rrdRoot, $statusFile),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($listMode === 'services') {
    if ($host === '') {
        json_error('Missing host parameter.', 400);
    }

    echo json_encode([
        'host' => $host,
        'services' => $config['backend'] === 'pnp4nagios' ? pnp_services($rrdRoot, $host) : list_services($rrdRoot, $host),
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

$seriesFiles = $config['backend'] === 'pnp4nagios'
    ? pnp_service_rrds($rrdRoot, $host, $service)
    : discover_service_rrds($rrdRoot, $host, $service);
if ($seriesFiles === []) {
    json_error('No RRD files found for the requested host and service.', 404);
}

$end = time();
$start = $end - $range['seconds'];
$series = [];
$errors = [];
$fetchCache = [];

foreach ($seriesFiles as $seriesFile) {
    if (!isset($fetchCache[$seriesFile['file']])) {
        $fetchCache[$seriesFile['file']] = fetch_rrd_series($rrdtool, $seriesFile['file'], $start, $end);
    }
    $fetched = $fetchCache[$seriesFile['file']];
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
        $columnName = (string) $columnName;
        if (isset($seriesFile['column']) && (string) $columnName !== $seriesFile['column']) continue;
        $bucketed = bucket_points($columnPoints, $range['bucket_target']);
        if ($bucketed === []) {
            continue;
        }

        $metricName = isset($seriesFile['column']) || $columnName === 'data' ? $seriesFile['metric'] : $columnName;
        $series[] = [
            'metric' => $metricName,
            'metric_key' => $seriesFile['metric_key'] . '::' . $columnName,
            'series_type' => !isset($seriesFile['column']) && in_array(strtolower($columnName), ['warn', 'warning', 'crit', 'critical'], true) ? 'threshold' : 'data',
            'unit' => $seriesFile['unit'] ?? '',
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
