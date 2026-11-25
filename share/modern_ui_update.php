<?php
include_once(dirname(__FILE__).'/includes/utils.inc.php');
require_once __DIR__ . '/modern_ui_updater.inc.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$action = isset($_GET['action']) ? (string)$_GET['action'] : 'check';
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
if (($action !== 'check' && $action !== 'install') ||
    ($action === 'check' && $method !== 'GET') || ($action === 'install' && $method !== 'POST')) {
    modernUiRespond(405, array('success' => false, 'error' => 'Unsupported action or request method.'));
}

$root = __DIR__;
$user = isset($_SERVER['REMOTE_USER']) ? (string)$_SERVER['REMOTE_USER'] : '';
$cgiConfig = read_cgi_config_file();
$isAdmin = modernUiUpdateAdmin($cgiConfig, $user);
$requirements = modernUiUpdateRequirements($root);
$token = '';
if ($isAdmin) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('nagios_modern_ui');
        @session_start(array('cookie_httponly' => true, 'cookie_samesite' => 'Strict',
            'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'use_strict_mode' => true));
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        modernUiRespond(500, array('success' => false, 'error' => 'Cannot start the update session.'));
    }
    if (!isset($_SESSION['modern_ui_update_token'], $_SESSION['modern_ui_update_user']) || $_SESSION['modern_ui_update_user'] !== $user) {
        $_SESSION['modern_ui_update_token'] = bin2hex(random_bytes(32));
        $_SESSION['modern_ui_update_user'] = $user;
    }
    $token = $_SESSION['modern_ui_update_token'];
    session_write_close();
}

if ($action === 'install') {
    if (!$isAdmin) {
        modernUiRespond(403, array('success' => false, 'error' => 'Only users authorized for Nagios system commands can update the interface.'));
    }
    $suppliedToken = isset($_SERVER['HTTP_X_MODERN_UI_TOKEN']) ? (string)$_SERVER['HTTP_X_MODERN_UI_TOKEN'] : '';
    if ($suppliedToken === '' || !hash_equals($token, $suppliedToken)) {
        modernUiRespond(403, array('success' => false, 'error' => 'Update session expired. Check for updates again.'));
    }
    if ($requirements !== '') {
        modernUiRespond(409, array('success' => false, 'error' => $requirements));
    }
}

$currentVersion = is_readable($root . '/modern_ui_version.txt') ? trim(file_get_contents($root . '/modern_ui_version.txt')) : '0.0.0';
$latest = fetchLatestRelease();
if ($latest['ok'] !== true) {
    modernUiRespond(502, array('success' => false, 'current_version' => $currentVersion, 'error' => $latest['error']));
}
$latestVersion = normalizeVersion($latest['tag_name']);
$updateAvailable = version_compare($latestVersion, normalizeVersion($currentVersion), '>');
$asset = null;
foreach ($latest['assets'] as $candidate) {
    if (isset($candidate['name']) && $candidate['name'] === 'share.zip') {
        $asset = $candidate;
        break;
    }
}
if ($asset === null && $requirements === '') {
    $requirements = 'The release does not contain share.zip.';
}
if ($action === 'check') {
    modernUiRespond(200, array(
        'success' => true, 'current_version' => $currentVersion, 'latest_version' => $latestVersion,
        'update_available' => $updateAvailable, 'release_url' => $latest['html_url'],
        'repository' => 'smolisso/nagios-modern-ui',
        'can_update' => $isAdmin && $requirements === '',
        'update_error' => $isAdmin ? $requirements : 'Only users authorized for Nagios system commands can update the interface.',
        'csrf_token' => $token
    ));
}

$requestedVersion = isset($_POST['version']) ? (string)$_POST['version'] : '';
if (!$updateAvailable || $requestedVersion !== $latestVersion || $asset === null) {
    modernUiRespond(409, array('success' => false, 'error' => 'The available release changed. Check for updates again.'));
}

// One writer per installation. Keep the lock file so concurrent requests share the same inode.
$lock = @fopen($root . '/.modern-ui-update.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_resource($lock)) fclose($lock);
    modernUiRespond(409, array('success' => false, 'error' => 'Another update is running, or the update lock is not writable.'));
}
$work = '';
try {
    @set_time_limit(180);
    // A previous request may have finished while this request checked GitHub.
    $installedVersion = is_readable($root . '/modern_ui_version.txt') ? trim(file_get_contents($root . '/modern_ui_version.txt')) : '0.0.0';
    if ($installedVersion !== $currentVersion) {
        throw new RuntimeException('The installed version changed. Check for updates again.');
    }
    $work = sys_get_temp_dir() . '/nagios-modern-ui-' . bin2hex(random_bytes(16));
    if (!@mkdir($work, 0700)) {
        throw new RuntimeException('Cannot create a temporary update directory.');
    }
    modernUiDownloadRelease($asset, $work . '/share.zip');
    $files = modernUiExtractRelease($work . '/share.zip', $work . '/share', $latestVersion);
    $result = modernUiInstallRelease($work . '/share', $root, $files);
    $result['success'] = true;
    $result['current_version'] = $latestVersion;
    $status = 200;
} catch (Throwable $error) {
    $result = array('success' => false, 'error' => $error->getMessage());
    $status = 500;
} finally {
    if ($work !== '') modernUiRemoveStaging($work);
    flock($lock, LOCK_UN);
    fclose($lock);
}
modernUiRespond($status, $result);

function modernUiRespond($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function normalizeVersion($version)
{
	$value = trim((string)$version);
	$value = preg_replace('/^v/i', '', $value);
	if ($value === '') {
		return '0.0.0';
	}
	return $value;
}

function fetchLatestRelease()
{
	$url = 'https://api.github.com/repos/smolisso/nagios-modern-ui/releases/latest';
	$userAgent = 'nagios-modern-ui-update-check';
	$body = '';
	$error = '';

	if (function_exists('curl_init')) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 6);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Accept: application/vnd.github+json',
			'User-Agent: ' . $userAgent
		));
		$body = (string)curl_exec($ch);
		$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($body === '' || $httpCode < 200 || $httpCode >= 300) {
			$error = 'GitHub API request failed';
			$body = '';
		}
		curl_close($ch);
	}

	if ($body === '') {
		$context = stream_context_create(array(
			'http' => array(
				'method' => 'GET',
				'timeout' => 6,
				'header' => "Accept: application/vnd.github+json\r\nUser-Agent: " . $userAgent . "\r\n"
			)
		));
		$fallbackBody = @file_get_contents($url, false, $context);
		if ($fallbackBody !== false) {
			$body = (string)$fallbackBody;
			$error = '';
		}
	}

	if ($body === '') {
		return array('ok' => false, 'error' => ($error !== '' ? $error : 'Unable to contact GitHub API'));
	}

	$data = json_decode($body, true);
	if (!is_array($data) || !isset($data['tag_name'])) {
		return array('ok' => false, 'error' => 'Invalid response from GitHub API');
	}

	return array(
		'ok' => true,
		'tag_name' => (string)$data['tag_name'],
		'html_url' => isset($data['html_url']) ? (string)$data['html_url'] : '',
		'assets' => isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : array()
	);
}
