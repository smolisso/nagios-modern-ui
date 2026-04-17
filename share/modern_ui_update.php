<?php
include_once(dirname(__FILE__).'/includes/utils.inc.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$action = isset($_GET['action']) ? (string)$_GET['action'] : 'check';
if ($action !== 'check') {
	http_response_code(400);
	echo json_encode(array(
		'success' => false,
		'error' => 'Unsupported action'
	));
	exit;
}

$versionFile = dirname(__FILE__) . '/modern_ui_version.txt';
$currentVersion = '0.0.0';
if (is_readable($versionFile)) {
	$localVersion = trim((string)@file_get_contents($versionFile));
	if ($localVersion !== '') {
		$currentVersion = $localVersion;
	}
}

$latest = fetchLatestRelease();
if ($latest['ok'] !== true) {
	http_response_code(502);
	echo json_encode(array(
		'success' => false,
		'current_version' => $currentVersion,
		'error' => $latest['error']
	));
	exit;
}

$latestVersion = normalizeVersion($latest['tag_name']);
$currentNormalized = normalizeVersion($currentVersion);
$updateAvailable = version_compare($latestVersion, $currentNormalized, '>');

echo json_encode(array(
	'success' => true,
	'current_version' => $currentVersion,
	'latest_version' => $latestVersion,
	'update_available' => $updateAvailable,
	'release_url' => isset($latest['html_url']) ? $latest['html_url'] : 'https://github.com/smolisso/nagios-modern-ui/releases',
	'repository' => 'smolisso/nagios-modern-ui'
));
exit;

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
		'html_url' => isset($data['html_url']) ? (string)$data['html_url'] : ''
	);
}
