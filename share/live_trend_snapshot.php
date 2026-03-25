<?php

$statusFile = '/usr/local/nagios/var/status.dat';
$snapshotFile = __DIR__ . '/live_trend_snapshot.json';
$maxSamples = 8640;

function parse_status_blocks(string $path): array
{
    $result = [
        'service' => [],
        'host' => [],
    ];

    if (!is_readable($path)) {
        fwrite(STDERR, "status.dat is not readable: {$path}\n");
        exit(1);
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        fwrite(STDERR, "Unable to open status.dat: {$path}\n");
        exit(1);
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

function row_int(array $row, string $key): int
{
    return isset($row[$key]) ? (int) $row[$key] : 0;
}

function load_existing_samples(string $path): array
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

function write_snapshot_file(string $path, array $payload): void
{
    $tempFile = $path . '.tmp';
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        fwrite(STDERR, "Failed to encode snapshot payload\n");
        exit(1);
    }

    if (@file_put_contents($tempFile, $json . PHP_EOL, LOCK_EX) === false) {
        fwrite(STDERR, "Failed to write temporary snapshot file: {$tempFile}\n");
        exit(1);
    }

    if (!@rename($tempFile, $path)) {
        @unlink($tempFile);
        fwrite(STDERR, "Failed to move snapshot file into place: {$path}\n");
        exit(1);
    }
}

$status = parse_status_blocks($statusFile);
$services = $status['service'];
$hosts = $status['host'];

$serviceTotal = count($services);
$serviceHealthy = 0;
$serviceProblems = 0;
$hostTotal = count($hosts);
$hostHealthy = 0;
$hostProblems = 0;
$unhandledServices = 0;
$unhandledHosts = 0;

foreach ($services as $service) {
    $state = row_int($service, 'current_state');

    if ($state === 0) {
        $serviceHealthy++;
    } else {
        $serviceProblems++;

        $ack = row_int($service, 'problem_has_been_acknowledged');
        $downtime = row_int($service, 'scheduled_downtime_depth');
        if ($ack === 0 && $downtime === 0) {
            $unhandledServices++;
        }
    }
}

foreach ($hosts as $host) {
    $state = row_int($host, 'current_state');

    if ($state === 0) {
        $hostHealthy++;
    } else {
        $hostProblems++;

        $ack = row_int($host, 'problem_has_been_acknowledged');
        $downtime = row_int($host, 'scheduled_downtime_depth');
        if ($ack === 0 && $downtime === 0) {
            $unhandledHosts++;
        }
    }
}

$objectsTotal = $serviceTotal + $hostTotal;
$objectsHealthy = $serviceHealthy + $hostHealthy;
$availabilityPct = $objectsTotal > 0 ? round(($objectsHealthy / $objectsTotal) * 100, 1) : 0.0;

$sample = [
    'timestamp' => time(),
    'availability_pct' => $availabilityPct,
    'services_total' => $serviceTotal,
    'services_ok' => $serviceHealthy,
    'services_problem' => $serviceProblems,
    'hosts_total' => $hostTotal,
    'hosts_up' => $hostHealthy,
    'hosts_problem' => $hostProblems,
    'unhandled_total' => $unhandledServices + $unhandledHosts,
];

$samples = load_existing_samples($snapshotFile);
$samples[] = $sample;
$samples = array_slice($samples, -$maxSamples);

$payload = [
    'generated_at' => date('c'),
    'source' => $statusFile,
    'max_samples' => $maxSamples,
    'samples' => array_values($samples),
];

write_snapshot_file($snapshotFile, $payload);

echo "Snapshot updated: {$snapshotFile}\n";
echo 'Availability: ' . number_format($availabilityPct, 1) . "%\n";
