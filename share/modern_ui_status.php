<?php
// Read-only program indicators for the classic CGI cockpit. No object data is returned.
require_once __DIR__ . '/includes/utils.inc.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$cgiConfig = read_cgi_config_file();
if (($cgiConfig['use_authentication'] ?? '1') !== '0' && empty($_SERVER['REMOTE_USER'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}
$mainConfigFile = $cgiConfig['main_config_file'] ?? ($cfg['main_config_file'] ?? '');
$mainConfig = $mainConfigFile !== '' ? read_main_config_file($mainConfigFile) : [];
$statusFile = $mainConfig['status_file'] ?? (__DIR__ . '/../var/status.dat');
$handle = $statusFile !== '' ? @fopen($statusFile, 'rb') : false;
if ($handle === false) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Program status unavailable.']);
    exit;
}

$keys = [
    'enable_notifications', 'active_service_checks_enabled', 'active_host_checks_enabled',
    'passive_service_checks_enabled', 'passive_host_checks_enabled', 'enable_event_handlers',
];
$flags = array_fill_keys($keys, null);
$inside = false;
$complete = false;
while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if ($line === 'programstatus {') {
        $inside = true;
        continue;
    }
    if (!$inside) continue;
    if ($line === '}') {
        $complete = true;
        break;
    }
    $pair = explode('=', $line, 2);
    if (count($pair) === 2) {
        $key = trim($pair[0]);
        $value = trim($pair[1]);
        if (array_key_exists($key, $flags) && ($value === '0' || $value === '1')) {
            $flags[$key] = $value === '1';
        }
    }
}
fclose($handle);
if (!$complete) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Program status unavailable.']);
    exit;
}
echo json_encode(['success' => true, 'flags' => $flags]);
