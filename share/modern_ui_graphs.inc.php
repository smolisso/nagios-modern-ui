<?php
// Effective service objects include action_url inherited from graphed-service templates.
// Old RRD files are not evidence that a service still has a graph action configured.
function modernUiServiceHasGraph(string $objectCacheFile, string $hostName, string $serviceName): bool
{
    static $indexes = [];
    if (!array_key_exists($objectCacheFile, $indexes)) {
        $indexes[$objectCacheFile] = [];
        $handle = @fopen($objectCacheFile, 'rb');
        if ($handle === false) {
            return false;
        }
        $service = null;
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (preg_match('/^define\s+service\s*\{$/', $line) === 1) {
                $service = [];
                continue;
            }
            if ($service === null) {
                continue;
            }
            if ($line === '}') {
                $host = trim($service['host_name'] ?? '');
                $description = trim($service['service_description'] ?? '');
                $action = trim($service['action_url'] ?? '');
                if ($host !== '' && $description !== '') {
                    $indexes[$objectCacheFile][$host . "\0" . $description] = $action !== '' && strcasecmp($action, 'null') !== 0;
                }
                $service = null;
                continue;
            }
            if (preg_match('/^(host_name|service_description|action_url)\s+(.+)$/', $line, $matches) === 1) {
                $service[$matches[1]] = $matches[2];
            }
        }
        fclose($handle);
    }
    return $indexes[$objectCacheFile][$hostName . "\0" . $serviceName] ?? false;
}
