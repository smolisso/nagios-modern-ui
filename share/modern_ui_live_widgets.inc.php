<?php

/** Aggregate the full snapshot, independently of the truncated incident lists. */
function modernUiLiveWidgetData(array $hosts, array $services, array $incidents, int $now): array
{
    $health = [];
    $rank = ['ok' => 0, 'pending' => 1, 'unknown' => 2, 'warning' => 3, 'critical' => 4, 'unreachable' => 5, 'down' => 6];
    foreach ($hosts as $host) {
        $name = (string) ($host['host_name'] ?? '');
        if ($name === '') continue;
        $state = (int) ($host['current_state'] ?? -1);
        $key = isset($host['has_been_checked']) && (int) $host['has_been_checked'] === 0
            ? 'pending' : ([0 => 'ok', 1 => 'down', 2 => 'unreachable'][$state] ?? 'pending');
        $health[$name] = ['name' => $name, 'state' => $key, 'problems' => 0];
    }
    foreach ($services as $service) {
        $name = (string) ($service['host_name'] ?? '');
        if ($name === '') continue;
        if (!isset($health[$name])) $health[$name] = ['name' => $name, 'state' => 'pending', 'problems' => 0];
        $state = (int) ($service['current_state'] ?? -1);
        $key = isset($service['has_been_checked']) && (int) $service['has_been_checked'] === 0
            ? 'pending' : ([0 => 'ok', 1 => 'warning', 2 => 'critical', 3 => 'unknown'][$state] ?? 'pending');
        if (in_array($key, ['warning', 'critical', 'unknown'], true)) $health[$name]['problems']++;
        if ($rank[$key] > $rank[$health[$name]['state']]) $health[$name]['state'] = $key;
    }
    ksort($health, SORT_NATURAL | SORT_FLAG_CASE);
    $counts = array_fill_keys(array_keys($rank), 0);
    foreach ($health as $host) $counts[$host['state']]++;

    $handled = 0;
    $ages = [0, 0, 0, 0];
    $unknownAge = 0;
    foreach ($incidents as $incident) {
        if (!empty($incident['acknowledged']) || !empty($incident['downtime'])) $handled++;
        $changed = (int) ($incident['last_state_change'] ?? 0);
        if ($changed <= 0 || $changed > $now) { $unknownAge++; continue; }
        $age = $now - $changed;
        $bucket = $age < 3600 ? 0 : ($age < 86400 ? 1 : ($age < 604800 ? 2 : 3));
        $ages[$bucket]++;
    }
    return ['hosts' => array_values($health), 'counts' => $counts, 'handled' => $handled,
        'total' => count($incidents), 'ages' => $ages, 'unknownAge' => $unknownAge];
}
