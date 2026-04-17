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
	<script src="stylesheets/theme.js"></script>

	<style>
		:root {
			--border: #1e2d42;
			--sidebar-width-compact: 52px;
			--sidebar-width-expanded: 236px;
			--toggle-bg: #1a2133;
			--toggle-color: #c8cdd5;
			--page-bg: #0f1117;
			--sidebar-bg: #141b26;
		}

		.light,
		:root[data-theme="light"] {
			--border: #D6D6D6;
			--toggle-bg: #f4f7fb;
			--toggle-color: #2a3c52;
			--page-bg: #eef3f9;
			--sidebar-bg: #ffffff;
		}

		* { box-sizing: border-box; }

		body {
			margin: 0;
			padding: 0;
			overflow: hidden;
			background-color: var(--page-bg);
		}

		#root {
			margin: 0;
		}

		iframe[name="side"] {
			position: fixed;
			top: 0;
			left: 0;
			height: 100vh;
			width: var(--sidebar-width-compact);
			border: none;
			border-right: 1px solid var(--border);
			z-index: 100;
			transition: width 0.22s ease;
			background-color: var(--sidebar-bg);
		}

		iframe[name="main"] {
			position: fixed;
			top: 0;
			left: var(--sidebar-width-compact);
			height: 100vh;
			width: calc(100% - var(--sidebar-width-compact));
			border: none;
			opacity: 1;
			transition: left 0.22s ease, width 0.22s ease, opacity 0.06s ease;
		}

		body.main-loading iframe[name="main"] {
			opacity: 0;
		}

		body.desktop-sidebar-expanded iframe[name="side"] {
			width: var(--sidebar-width-expanded);
		}

		body.desktop-sidebar-expanded iframe[name="main"] {
			left: var(--sidebar-width-expanded);
			width: calc(100% - var(--sidebar-width-expanded));
		}

		#sidebar-toggle {
			display: none;
			align-items: center;
			justify-content: center;
			position: fixed;
			top: 14px;
			left: 14px;
			z-index: 1001;
			width: 46px;
			height: 46px;
			background: var(--toggle-bg);
			color: var(--toggle-color);
			border: none;
			border-radius: 12px;
			font-size: 20px;
			cursor: pointer;
			box-shadow: 0 2px 6px rgba(0, 0, 0, 0.4);
			transition: background 0.2s ease, transform 0.2s ease;
			line-height: 1;
		}

		#sidebar-toggle:hover {
			background: #1e3652;
			transform: translateY(-1px);
		}

		#sidebar-overlay {
			display: none;
			position: fixed;
			inset: 0;
			background: rgba(0, 0, 0, 0.55);
			z-index: 999;
			cursor: pointer;
		}

		@media (max-width: 768px) {
			body.desktop-sidebar-expanded iframe[name="side"] {
				width: var(--sidebar-width-expanded);
			}

			iframe[name="side"] {
				width: var(--sidebar-width-expanded);
				transform: translateX(calc(-1 * var(--sidebar-width-expanded)));
				z-index: 1000;
				box-shadow: 3px 0 12px rgba(0, 0, 0, 0.6);
				transition: transform 0.24s ease;
			}

			iframe[name="side"].sidebar-open {
				transform: translateX(0);
			}

			iframe[name="main"] {
				left: 0;
				width: 100%;
			}

			#sidebar-overlay.sidebar-open {
				display: block;
			}

			#sidebar-toggle {
				display: inline-flex;
			}
		}
	</style>
</head>

<body id="root">
	<button id="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle navigazione">&#9776;</button>
	<div id="sidebar-overlay" onclick="toggleSidebar()"></div>
	<iframe src="side.php" name="side"></iframe>
	<iframe src="<?php echo $url; ?>" name="main"></iframe>

	<script>
		// Larghezza di riferimento delle pagine CGI di Nagios (layout fisso anni 2000)
		var NATIVE_WIDTH = 1024;
		var mainBeforeUnloadBound = false;

		function setMainLoading(isLoading) {
			document.body.classList.toggle('main-loading', !!isLoading);
		}

		function bindMainBeforeUnload() {
			var main = document.querySelector('iframe[name="main"]');
			if (!main) {
				return;
			}

			try {
				var mainWindow = main.contentWindow;
				if (!mainWindow || mainBeforeUnloadBound) {
					return;
				}

				mainWindow.addEventListener('beforeunload', function () {
					setMainLoading(true);
				}, { once: true });

				mainBeforeUnloadBound = true;
			} catch (e) {
				// Cross-origin or timing issue.
			}
		}

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

		function applyThemeToFrame(frame, theme) {
			try {
				if (!frame || !frame.contentWindow || !frame.contentWindow.document) {
					return;
				}

				var root = frame.contentWindow.document.documentElement;
				if (!root) {
					return;
				}

				root.classList.remove('dark', 'light');
				root.classList.add(theme);
				root.setAttribute('data-theme', theme);
			} catch (e) {
				// Cross-origin or iframe timing issue.
			}
		}

		window.applyThemeToFrames = function (theme) {
			var selectedTheme = theme;
			if (!selectedTheme && window.NagiosTheme && typeof window.NagiosTheme.getTheme === 'function') {
				selectedTheme = window.NagiosTheme.getTheme();
			}
			if (!selectedTheme) {
				selectedTheme = 'dark';
			}

			document.documentElement.classList.remove('dark', 'light');
			document.documentElement.classList.add(selectedTheme);
			document.documentElement.setAttribute('data-theme', selectedTheme);

			applyThemeToFrame(document.querySelector('iframe[name="side"]'), selectedTheme);
			applyThemeToFrame(document.querySelector('iframe[name="main"]'), selectedTheme);
		};

		function setSidebarCompact(compact) {
			var side = document.querySelector('iframe[name="side"]');
			try {
				if (side.contentWindow && typeof side.contentWindow.setSidebarCompact === 'function') {
					side.contentWindow.setSidebarCompact(compact);
				}
			} catch (e) {
				// ignore same-origin timing errors during initial load
			}
		}

		function setDesktopSidebarExpanded(expanded) {
			if (window.innerWidth <= 768) {
				return;
			}

			document.body.classList.toggle('desktop-sidebar-expanded', expanded);
			setSidebarCompact(!expanded);
			applyScaleDelayed();
		}

		function toggleSidebar() {
			var sidebar = document.querySelector('iframe[name="side"]');
			var overlay = document.getElementById('sidebar-overlay');

			if (window.innerWidth <= 768) {
				sidebar.classList.toggle('sidebar-open');
				overlay.classList.toggle('sidebar-open');
			} else {
				setDesktopSidebarExpanded(!document.body.classList.contains('desktop-sidebar-expanded'));
			}
		}

		window.addEventListener('resize', function () {
			var sidebar = document.querySelector('iframe[name="side"]');
			var overlay = document.getElementById('sidebar-overlay');

			if (window.innerWidth > 768) {
				sidebar.classList.remove('sidebar-open');
				overlay.classList.remove('sidebar-open');
				setDesktopSidebarExpanded(true);
			} else {
				document.body.classList.remove('desktop-sidebar-expanded');
				setSidebarCompact(false);
			}
			applyScale();
		});

		document.querySelector('iframe[name="main"]').addEventListener('load', function () {
			window.applyThemeToFrames();
			applyScale();
			mainBeforeUnloadBound = false;
			bindMainBeforeUnload();
			requestAnimationFrame(function () {
				setMainLoading(false);
			});
			if (window.hideModernGraphPopup) {
				window.hideModernGraphPopup();
			}
			try {
				var side = document.querySelector('iframe[name="side"]');
				if (side.contentWindow && typeof side.contentWindow.syncActiveNav === 'function') {
					side.contentWindow.syncActiveNav();
				}
			} catch (e) {
				// ignore iframe timing issues
			}
		});

		document.querySelector('iframe[name="side"]').addEventListener('load', function () {
			window.applyThemeToFrames();
			try {
				var sideFrame = document.querySelector('iframe[name="side"]');
				var sideDoc = sideFrame && sideFrame.contentDocument;
				if (sideDoc) {
					sideDoc.addEventListener('click', function (event) {
						var link = event.target && event.target.closest ? event.target.closest('a[target="main"]') : null;
						if (link) {
							setMainLoading(true);
						}
					});
				}
			} catch (e) {
				// Cross-origin or timing issue.
			}
			if (window.innerWidth > 768) {
				setDesktopSidebarExpanded(true);
			}
		});

		window.addEventListener('nagios-theme-change', function (event) {
			var theme = event && event.detail ? event.detail.theme : null;
			window.applyThemeToFrames(theme);
		});

		window.applyThemeToFrames();
		bindMainBeforeUnload();

		if (window.innerWidth > 768) {
			setDesktopSidebarExpanded(true);
		}
	</script>
</body>

</html>
