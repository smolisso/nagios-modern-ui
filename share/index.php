<?php
require_once(dirname(__FILE__).'/config.inc.php');

// Allow specifying main window URL for permalinks, etc.
$url = 'main.php';

if ("no" == "yes" && isset($_GET['corewindow'])) {

	// The default window url may have been overridden with a permalink...
	// Parse the URL and remove permalink option from base.
	$a = parse_url($_GET['corewindow']);

	// Build the base url.
	$url = htmlentities($a['path']).'?';
	$url = (isset($a['host'])) ? $a['scheme'].'://'.$a['host'].$url : '/'.$url;

	$query = isset($a['query']) ? $a['query'] : '';
	$pairs = explode('&', $query);
	foreach ($pairs as $pair) {
		$v = explode('=', $pair);
		if (is_array($v)) {
			$key = urlencode($v[0]);
			$val = urlencode(isset($v[1]) ? $v[1] : '');
			$url .= "&$key=$val";
		}
	}
	if (preg_match("/^http:\/\/|^https:\/\/|^\//", $url) != 1)
		$url = "main.php";
}

$this_year = '2026';
$theme = $cfg['theme'] ?? 'dark';
if ($theme != 'dark' && $theme != 'light') {
	$theme = 'dark';
}
?>
<!DOCTYPE html>

<html class="<?= $theme ?>">
<head>
	<meta name="ROBOTS" content="NOINDEX, NOFOLLOW">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Nagios: <?php echo $_SERVER['SERVER_NAME']; ?></title>
	<link rel="shortcut icon" href="images/favicon.ico" type="image/ico">

	<script LANGUAGE="javascript">
		var n = Math.round(Math.random() * 10000000000);
		document.cookie = "NagFormId=" + n.toString(16);
	</script>
	<script src="nagiosgraph_modern/popup.js"></script>

	<style>
		:root {
			--border: #1e2d42;
			--sidebar-width: 200px;
			--toggle-bg: #1a2133;
			--toggle-color: #c8cdd5;
		}

		.light {
			--border: #D6D6D6;
		}

		* { box-sizing: border-box; }

		body {
			margin: 0;
			padding: 0;
			overflow: hidden;
			background-color: #0f1117;
		}

		#root {
			margin: 0;
		}

		/* Sidebar: sempre fixed, così non interferisce col layout */
		iframe[name="side"] {
			position: fixed;
			top: 0;
			left: 0;
			height: 100vh;
			width: var(--sidebar-width);
			border: none;
			border-right: 1px solid var(--border);
			z-index: 100;
			transition: transform 0.3s ease;
			background-color: #141b26;
		}

		/* Main: occupa tutto tranne lo spazio della sidebar */
		iframe[name="main"] {
			position: fixed;
			top: 0;
			left: var(--sidebar-width);
			height: 100vh;
			width: calc(100% - var(--sidebar-width));
			border: none;
			transition: left 0.3s ease, width 0.3s ease;
		}

		/* === Desktop: sidebar collassata === */
		iframe[name="side"].sidebar-hidden {
			transform: translateX(calc(-1 * var(--sidebar-width)));
		}

		iframe[name="main"].sidebar-hidden {
			left: 0;
			width: 100%;
		}

		/* Hamburger button – visibile sempre, desktop e mobile */
		#sidebar-toggle {
			display: flex;
			align-items: center;
			gap: 5px;
			position: fixed;
			top: 44px;
			left: 60px;
			z-index: 1001;
			padding: 4px 8px 4px 6px;
			background: var(--toggle-bg);
			color: var(--toggle-color);
			border: none;
			border-radius: 6px;
			font-size: 18px;
			cursor: pointer;
			box-shadow: 0 2px 6px rgba(0, 0, 0, 0.4);
			transition: background 0.2s;
			line-height: 1;
		}

		#sidebar-toggle:hover {
			background: #1e3652;
		}

		#sidebar-toggle img {
			height: 20px;
			width: 20px;
			object-fit: contain;
			border-radius: 50%;
			display: block;
		}

		/* Backdrop – nascosto per default */
		#sidebar-overlay {
			display: none;
			position: fixed;
			inset: 0;
			background: rgba(0, 0, 0, 0.55);
			z-index: 999;
			cursor: pointer;
		}

		/* === MOBILE ≤ 768px === */
		@media (max-width: 768px) {

			/* Su mobile la sidebar parte fuori schermo */
			iframe[name="side"] {
				transform: translateX(calc(-1 * var(--sidebar-width)));
				z-index: 1000;
				box-shadow: 3px 0 12px rgba(0, 0, 0, 0.6);
			}

			/* Sidebar aperta come overlay */
			iframe[name="side"].sidebar-open {
				transform: translateX(0);
			}

			/* Main: sempre full width su mobile */
			iframe[name="main"],
			iframe[name="main"].sidebar-hidden {
				left: 0;
				width: 100%;
			}

			/* Backdrop visibile quando sidebar è aperta */
			#sidebar-overlay.sidebar-open {
				display: block;
			}
		}
	</style>
</head>

<body id="root">
	<button id="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle navigazione">
		<img src="nagios.png" alt="Nagios">
		<span>&#9776;</span>
	</button>
	<div id="sidebar-overlay" onclick="toggleSidebar()"></div>
	<iframe src="side.php" name="side"></iframe>
	<iframe src="<?php echo $url; ?>" name="main"></iframe>

	<script>
		// Larghezza di riferimento delle pagine CGI di Nagios (layout fisso anni 2000)
		var NATIVE_WIDTH = 1024;

		// Applica zoom al contenuto dell'iframe main per adattarlo alla finestra
		function applyScale() {
			var main = document.querySelector('iframe[name="main"]');
			try {
				var doc = main.contentDocument || main.contentWindow.document;
				var w = main.offsetWidth;
				var old = doc.getElementById('__rscale');
				if (old) old.remove();
				if (w > 0 && w < NATIVE_WIDTH) {
					var s = doc.createElement('style');
					s.id = '__rscale';
					s.textContent = 'html { zoom: ' + (w / NATIVE_WIDTH).toFixed(4) + '; }';
					(doc.head || doc.documentElement).appendChild(s);
				}
			} catch(e) {
				// iframe cross-origin o non ancora caricato: ignora
			}
		}

		// Applica lo scale dopo che la transizione CSS (0.3s) è terminata
		function applyScaleDelayed() {
			setTimeout(applyScale, 320);
		}

		function updateTogglePosition() {
			var btn     = document.getElementById('sidebar-toggle');
			var sidebar = document.querySelector('iframe[name="side"]');
			// Su mobile la sidebar è sempre overlay, il bottone resta a sinistra
			if (window.innerWidth <= 768 || sidebar.classList.contains('sidebar-hidden')) {
				btn.style.top  = '44px';
				btn.style.left = '10px';
			} else {
				btn.style.top  = '44px';
				btn.style.left = '120px';
			}
		}

		function toggleSidebar() {
			var sidebar = document.querySelector('iframe[name="side"]');
			var main    = document.querySelector('iframe[name="main"]');
			var overlay = document.getElementById('sidebar-overlay');

			if (window.innerWidth <= 768) {
				// Mobile: sidebar come overlay, backdrop visibile
				sidebar.classList.toggle('sidebar-open');
				overlay.classList.toggle('sidebar-open');
			} else {
				// Desktop: sidebar collassa, il main si espande
				sidebar.classList.toggle('sidebar-hidden');
				main.classList.toggle('sidebar-hidden');
			}
			updateTogglePosition();
			applyScaleDelayed();
		}

		// Al resize pulisce le classi dell'altro breakpoint e ricalcola posizione + scale
		window.addEventListener('resize', function () {
			var sidebar = document.querySelector('iframe[name="side"]');
			var main    = document.querySelector('iframe[name="main"]');
			var overlay = document.getElementById('sidebar-overlay');

			if (window.innerWidth > 768) {
				sidebar.classList.remove('sidebar-open');
				overlay.classList.remove('sidebar-open');
			} else {
				sidebar.classList.remove('sidebar-hidden');
				main.classList.remove('sidebar-hidden');
			}
			updateTogglePosition();
			applyScale();
		});

		// Applica lo scale ogni volta che l'iframe main carica una nuova pagina
		document.querySelector('iframe[name="main"]').addEventListener('load', function () {
			applyScale();
			if (window.hideModernGraphPopup) {
				window.hideModernGraphPopup();
			}
		});

		// Posizione iniziale corretta al caricamento
		updateTogglePosition();
	</script>
</body>

</html>
