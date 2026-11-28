<?php
require __DIR__ . '/../share/modern_ui_live_widgets.inc.php';

function check($actual, $expected, string $message): void
{
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . json_encode($actual));
}

$now = 2000000;
$hosts = [];
foreach (['healthy', 'warning', 'critical', 'unknown', 'pending', 'down', 'unreachable'] as $name) {
    $hosts[] = ['host_name' => $name, 'current_state' => $name === 'down' ? 1 : ($name === 'unreachable' ? 2 : 0), 'has_been_checked' => 1];
}
$services = [];
foreach (['warning' => 1, 'critical' => 2, 'unknown' => 3, 'pending' => 0, 'down' => 2, 'unreachable' => 2] as $name => $state) {
    $services[] = ['host_name' => $name, 'current_state' => $state, 'has_been_checked' => $name === 'pending' ? 0 : 1];
}
$incidents = [];
// Includes exact bucket boundaries, unknown/future timestamps and an ACK/downtime overlap.
foreach ([1, 3599, 3600, 86399, 86400, 604799, 604800, 900000, null, -1] as $index => $age) {
    $incidents[] = ['last_state_change' => $age === null ? 0 : $now - $age,
        'acknowledged' => $index < 3, 'downtime' => $index >= 2 && $index < 5];
}
for ($i = 0; $i < 8; $i++) $incidents[] = ['last_state_change' => $now - 100, 'acknowledged' => false, 'downtime' => false];
$data = modernUiLiveWidgetData($hosts, $services, $incidents, $now);
foreach (['ok', 'pending', 'unknown', 'warning', 'critical', 'unreachable', 'down'] as $state) check($data['counts'][$state], 1, $state . ' host count');
check($data['ages'], [10, 2, 2, 2], 'all incidents counted with half-open age boundaries');
check($data['unknownAge'], 2, 'missing and future ages excluded from bars');
check($data['total'], 18, 'aggregation does not stop at the 12 visible incidents');
check($data['handled'], 5, 'acknowledgement/downtime union counted once');
check(count($data['hosts']), 7, 'one tile per host');
$orphan = modernUiLiveWidgetData([], [['host_name' => 'missing-host', 'current_state' => 0]], [], $now);
check($orphan['hosts'][0]['state'], 'pending', 'missing host status never reported healthy');
$empty = modernUiLiveWidgetData([], [], [], $now);
check($empty['total'], 0, 'empty total');
check($empty['handled'], 0, 'empty handled');
check($empty['ages'], [0, 0, 0, 0], 'empty histogram');
echo "Live widget aggregation checks passed\n";
