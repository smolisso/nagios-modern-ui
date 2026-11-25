<?php
// File overlay updater. Loaded by modern_ui_update.php; never accepts a client-supplied URL.

function modernUiUpdateAdmin($cgiConfig, $user)
{
    if ($user === '') {
        return false;
    }
    $readOnly = array_map('trim', explode(',', isset($cgiConfig['authorized_for_read_only']) ? $cgiConfig['authorized_for_read_only'] : ''));
    if (in_array($user, $readOnly, true) || in_array('*', $readOnly, true)) {
        return false;
    }
    $users = array_map('trim', explode(',', isset($cgiConfig['authorized_for_system_commands']) ? $cgiConfig['authorized_for_system_commands'] : ''));
    return in_array($user, $users, true) || in_array('*', $users, true);
}

function modernUiUpdateRequirements($root)
{
    if (!extension_loaded('curl') || !class_exists('ZipArchive')) {
        return 'Browser updates require the PHP cURL and Zip extensions.';
    }
    if (!is_writable($root)) {
        return 'The web server cannot write to the Nagios share directory.';
    }
    return '';
}

function modernUiPreserveFile($path)
{
    return $path === 'modern-ui-config.php' || basename($path) === 'config.php';
}

function modernUiDownloadRelease($asset, $destination)
{
    if (!isset($asset['browser_download_url'], $asset['digest'], $asset['size']) ||
        !preg_match('~^https://github\.com/smolisso/nagios-modern-ui/releases/download/[^/]+/share\.zip$~D', $asset['browser_download_url']) ||
        !preg_match('/^sha256:([a-f0-9]{64})$/D', $asset['digest'], $digest) ||
        $asset['size'] <= 0 || $asset['size'] > 16 * 1024 * 1024) {
        throw new RuntimeException('The release has no valid share.zip package and SHA-256 digest.');
    }
    $file = @fopen($destination, 'wb');
    if ($file === false) {
        throw new RuntimeException('Cannot create the temporary download.');
    }
    $received = 0;
    $ch = curl_init($asset['browser_download_url']);
    curl_setopt_array($ch, array(
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'nagios-modern-ui-updater',
        CURLOPT_WRITEFUNCTION => function ($handle, $data) use ($file, &$received) {
            $received += strlen($data);
            if ($received > 16 * 1024 * 1024) {
                return 0;
            }
            return fwrite($file, $data);
        }
    ));
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($file);
    if ($ok !== true || $status !== 200 || $received !== (int)$asset['size']) {
        throw new RuntimeException('Release download failed or was incomplete. No files were updated.');
    }
    if (!hash_equals($digest[1], hash_file('sha256', $destination))) {
        throw new RuntimeException('Release checksum verification failed. No files were updated.');
    }
}

function modernUiExtractRelease($archive, $staging, $version)
{
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        throw new RuntimeException('Cannot open the release ZIP.');
    }
    $files = array();
    $total = 0;
    try {
        if ($zip->numFiles > 1000) {
            throw new RuntimeException('The release contains too many files.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            $name = $entry['name'];
            if (!preg_match('~^share/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]*/?$~D', $name) ||
                preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $name)) {
                throw new RuntimeException('The release contains an unsafe path.');
            }
            $opsys = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            if ((($attributes >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('The release contains a symbolic link.');
            }
            if (substr($name, -1) === '/') {
                continue;
            }
            $path = substr($name, 6);
            if ($path === '' || isset($files[$path])) {
                throw new RuntimeException('The release contains duplicate or invalid files.');
            }
            $total += $entry['size'];
            if ($total > 64 * 1024 * 1024) {
                throw new RuntimeException('The extracted release is too large.');
            }
            $target = $staging . '/' . $path;
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0700, true)) {
                throw new RuntimeException('Cannot create the staging directory.');
            }
            $input = $zip->getStream($name);
            $output = @fopen($target, 'wb');
            if ($input === false || $output === false) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                throw new RuntimeException('Cannot extract the release.');
            }
            $written = stream_copy_to_stream($input, $output, $entry['size'] + 1);
            fclose($input);
            fclose($output);
            if ($written !== $entry['size']) {
                throw new RuntimeException('The release contains an incomplete file.');
            }
            $files[$path] = $path;
        }
    } finally {
        $zip->close();
    }
    foreach (array('index.php', 'side.php', 'modern_ui_update.php', 'modern_ui_updater.inc.php', 'modern_ui_version.txt') as $required) {
        if (!isset($files[$required])) {
            throw new RuntimeException('The release is missing required interface files.');
        }
    }
    if (trim(file_get_contents($staging . '/modern_ui_version.txt')) !== $version) {
        throw new RuntimeException('The package version does not match the selected release.');
    }
    return array_values($files);
}

function modernUiInstallRelease($staging, $root, $files)
{
    $copy = array();
    $preserved = array();
    // Check every destination before overwriting anything. Never follow installation symlinks.
    foreach ($files as $path) {
        $target = $root . '/' . $path;
        if (modernUiPreserveFile($path) && (file_exists($target) || is_link($target))) {
            $preserved[] = $path;
            continue;
        }
        $cursor = $root;
        $parts = explode('/', $path);
        foreach ($parts as $index => $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                throw new RuntimeException('Cannot update a symbolic link: ' . $path);
            }
            $last = $index === count($parts) - 1;
            if (file_exists($cursor) && ($last ? !is_file($cursor) : !is_dir($cursor))) {
                throw new RuntimeException('Destination type conflicts with the release: ' . $path);
            }
            if (!$last && is_dir($cursor) && !is_writable($cursor)) {
                throw new RuntimeException('The web server cannot write the directory for: ' . $path);
            }
        }
        if (!is_readable($staging . '/' . $path)) {
            throw new RuntimeException('Cannot read the staged file: ' . $path);
        }
        $copy[] = $path;
    }
    // Publish the version only after all other files have been copied.
    $copy = array_values(array_diff($copy, array('modern_ui_version.txt')));
    $copy[] = 'modern_ui_version.txt';
    $updated = 0;
    foreach ($copy as $path) {
        $target = $root . '/' . $path;
        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
            throw new RuntimeException('Cannot create the destination directory for: ' . $path);
        }
        // A rename in the same directory prevents readers seeing half-written PHP files.
        $temporary = @tempnam($directory, '.modern-ui-');
        if ($temporary === false || dirname($temporary) !== realpath($directory)) {
            if ($temporary !== false) @unlink($temporary);
            throw new RuntimeException('Cannot prepare the destination file: ' . $path);
        }
        $mode = is_file($target) ? (fileperms($target) & 0777) : 0644;
        if (!@copy($staging . '/' . $path, $temporary) || !@chmod($temporary, $mode) || !@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Copy failed for ' . $path . '. Some files may already be updated; retry the update or install share.zip manually.');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }
        $updated++;
    }
    return array('updated_files' => $updated, 'preserved_files' => $preserved);
}

function modernUiRemoveStaging($directory)
{
    if (!is_dir($directory) || is_link($directory)) return;
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot()) continue;
        if ($entry->isDir() && !$entry->isLink()) {
            modernUiRemoveStaging($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($directory);
}
