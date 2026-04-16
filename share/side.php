<?php
include_once(dirname(__FILE__).'/includes/utils.inc.php');

$this_version = '4.5.11';
$link_target = 'main';
$theme = isset($cfg['theme']) ? $cfg['theme'] : 'dark';
if ($theme != 'dark' && $theme != 'light') {
	$theme = 'dark';
}
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN">

<html id="side" class="<?= $theme ?>">

<head>
<meta name="ROBOTS" content="NOINDEX, NOFOLLOW">
<title>Nagios Core</title>
<script src="stylesheets/theme.js"></script>
<link href="stylesheets/common.css?<?php echo $this_version; ?>" type="text/css" rel="stylesheet">
<style>
html#side {
	background-color: #08111d;
}

body.navbar {
	background:
		radial-gradient(circle at top left, rgba(78, 123, 178, 0.16), transparent 32%),
		linear-gradient(180deg, #0a1421 0%, #08111d 100%);
	color: #e8eef8;
	gap: 8px;
	padding: 12px 10px 12px 10px;
	min-height: 100vh;
	transition: padding 0.18s ease;
}

.navbarlogo {
	display: flex;
	align-items: center;
	justify-content: flex-start;
	flex-wrap: wrap;
	position: sticky;
	top: 0;
	z-index: 20;
	margin-bottom: 10px;
	padding: 0 0 10px;
	gap: 8px;
	background: transparent;
}

.navbarbrand {
	display: inline-flex;
	align-items: center;
	min-width: 0;
	text-decoration: none;
}

.navbarbrand-mark {
	width: 22px;
	height: 22px;
	object-fit: contain;
	display: none;
}

.navbarbrand-full {
	display: block;
}

.navbar-toggle {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 30px;
	height: 30px;
	border: none;
	border-radius: 9px;
	background: rgba(20, 36, 58, 0.62);
	color: #d8e2ef;
	font-size: 15px;
	cursor: pointer;
	transition: background-color 0.14s ease, transform 0.14s ease;
}

.navbar-toggle:hover {
	background: rgba(27, 48, 74, 0.88);
	transform: translateY(-1px);
}

.theme-switch {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	height: 30px;
	margin-left: 0;
	padding: 0 10px;
	border: 1px solid rgba(111, 143, 177, 0.16);
	border-radius: 9px;
	background: rgba(20, 36, 58, 0.62);
	color: #d8e2ef;
	font-size: 13px;
	font-weight: 700;
	line-height: 1;
	cursor: pointer;
	transition: background-color 0.14s ease, transform 0.14s ease, border-color 0.14s ease;
}

.theme-switch:hover {
	background: rgba(27, 48, 74, 0.88);
	border-color: rgba(111, 143, 177, 0.28);
	transform: translateY(-1px);
}

.theme-switch-icon {
	line-height: 1;
}

.navbarlogo .theme-switch {
	order: 3;
	flex: 0 0 100%;
	width: 30px;
	padding: 0;
	margin-left: 0;
	margin-top: 6px;
}

.navbarlogo .theme-switch .theme-switch-label {
	display: none;
}

div.navsection {
	gap: 6px;
	padding: 7px 0 0;
	border-top: 1px solid rgba(104, 133, 169, 0.12);
}

div.navsection:first-of-type {
	padding-top: 0;
	border-top: none;
}

div.navsectiontitle {
	margin: 0;
	padding: 0 4px;
	border-bottom: none;
	color: #7789a3;
	font-size: 9px;
	font-weight: 800;
	letter-spacing: 0.16em;
	text-transform: uppercase;
}

ul.navsectionlinks {
	gap: 4px;
}

ul.navsectionlinks li {
	padding: 0;
	border-radius: 0;
}

ul.navsectionlinks li:hover:not(:has(li:hover)) {
	background: none;
}

.navlink,
.navgroup-label {
	display: flex;
	align-items: center;
	justify-content: flex-start;
	gap: 8px;
	padding: 8px 10px;
	border-radius: 10px;
	background: rgba(20, 36, 58, 0.56);
	border: 1px solid rgba(111, 143, 177, 0.10);
	transition: transform 0.14s ease, background-color 0.14s ease, border-color 0.14s ease;
}

.navlink[data-icon]::before,
.navgroup-label[data-icon]::before {
	content: attr(data-icon);
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 18px;
	width: 18px;
	height: 18px;
	color: #d6e0ef;
	font-size: 14px;
	font-weight: 700;
	line-height: 18px;
	text-align: center;
}

.navlink:hover,
.navgroup:hover > .navgroup-label {
	background: rgba(27, 48, 74, 0.82);
	border-color: rgba(111, 143, 177, 0.20);
	text-decoration: none;
	transform: translateY(-1px);
}

.navlink.is-active,
.navgroup-label.is-active {
	background: linear-gradient(135deg, rgba(108, 213, 189, 0.22), rgba(100, 178, 236, 0.18));
	border-color: rgba(108, 213, 189, 0.40);
	color: #eef7ff;
	box-shadow: 0 10px 24px rgba(42, 116, 101, 0.16);
}

.navlink.is-active .navlink-text,
.navgroup-label.is-active .label-main,
.navlink.is-active::before,
.navgroup-label.is-active::before,
.navgroup-label.is-active .navgroup-caret {
	color: #eef7ff;
}

.navlink.is-active .navbadge.modern,
.navgroup-label.is-active .navbadge.modern {
	background: rgba(108, 213, 189, 0.20);
	border-color: rgba(108, 213, 189, 0.16);
	color: #7be2ae;
}

.navgroup > ul .navlink.is-active {
	background: linear-gradient(135deg, rgba(108, 213, 189, 0.18), rgba(100, 178, 236, 0.14));
	border-color: rgba(108, 213, 189, 0.34);
	box-shadow: none;
}

.navgroup > ul .navlink.is-active .navlink-text {
	color: #eef7ff;
}

.navgroup-label {
	color: #edf3fb;
	font-size: 8.5pt;
	font-weight: 600;
	cursor: pointer;
	width: 100%;
	border: 1px solid rgba(111, 143, 177, 0.10);
	text-align: left;
}

.navgroup-label .label-main,
.navlink .label-main,
.navlink-text {
	min-width: 0;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.navlink-main {
	display: flex;
	align-items: center;
	gap: 6px;
	min-width: 0;
	flex: 1 1 auto;
}

.navlink-text {
	font-size: 8.5pt;
	font-weight: 600;
	color: #edf3fb;
	text-align: left;
}

.navlink-meta {
	margin-left: auto;
	flex: 0 0 auto;
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.navbadge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 2px 6px;
	border-radius: 999px;
	font-size: 7px;
	font-weight: 800;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	white-space: nowrap;
	min-width: 18px;
}

.navbadge.modern {
	color: #68e1a3;
	background: rgba(70, 214, 145, 0.12);
	border: 1px solid rgba(70, 214, 145, 0.16);
}

.navbadge.classic {
	color: #97a8bf;
	background: rgba(111, 143, 177, 0.10);
	border: 1px solid rgba(111, 143, 177, 0.12);
}

.navgroup > ul {
	margin-top: 4px;
	padding: 0 0 0 10px;
	gap: 4px;
}

.navgroup.is-collapsed > ul {
	display: none;
}

.navgroup > ul li {
	padding: 0;
}

.navgroup > ul .navlink {
	padding: 7px 9px;
	border-radius: 9px;
	background: rgba(15, 28, 46, 0.48);
}

.navgroup > ul .navlink-text {
	font-size: 8pt;
	font-weight: 500;
	color: #c8d4e5;
}

.navgroup > ul .navlink {
	justify-content: flex-start;
}

.navgroup-caret {
	color: #6f839c;
	font-size: 10px;
	transition: transform 0.14s ease;
}

.navgroup.is-collapsed .navgroup-caret {
	transform: rotate(-90deg);
}

.nav-inline-links {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	flex-wrap: wrap;
}

.nav-inline-links .navlink {
	padding: 6px 8px;
	border-radius: 9px;
}

.navbarsearch {
	margin-top: 8px;
	padding: 10px;
	border-radius: 12px;
	background: rgba(20, 36, 58, 0.48);
	border: 1px solid rgba(111, 143, 177, 0.10);
}

.navbarsearch form {
	gap: 6px;
}

.search-title {
	color: #7789a3;
	font-size: 9px;
	font-weight: 800;
	letter-spacing: 0.16em;
	text-transform: uppercase;
}

.navbarsearch input {
	padding: 7px 9px;
	border-radius: 9px;
	background: rgba(8, 17, 29, 0.62);
	border: 1px solid rgba(111, 143, 177, 0.14);
}

body.compact {
	padding: 12px 6px;
}

body.compact div.navsectiontitle,
body.compact .search-title,
body.compact .navbarsearch {
	display: none !important;
}

body.compact .navbarlogo {
	justify-content: center;
	gap: 0;
}

body.compact .navbarbrand-full,
body.compact .navbarbrand-text {
	display: none;
}

body.compact .navbarbrand-mark {
	display: none;
}

body.compact .navlink,
body.compact .navgroup-label {
	min-height: 40px;
	width: 40px;
	padding: 0;
	justify-content: center;
	border-radius: 10px;
	gap: 0;
}

body.compact .navbarbrand {
	display: none;
}

body.compact .theme-switch {
	width: 40px;
	padding: 0;
	margin-left: 0;
}

body.compact .theme-switch .theme-switch-label {
	display: none;
}

body.compact .navlink-text,
body.compact .label-main,
body.compact .navlink-main,
body.compact .navlink-meta,
body.compact .navgroup-caret {
	display: none !important;
}

body.compact .navlink[data-icon]::before,
body.compact .navgroup-label[data-icon]::before {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 16px;
	width: 16px;
	height: 16px;
	font-size: 14px;
	line-height: 16px;
	transform: translateY(0);
	margin: 0 auto;
}

body.compact div.navsection {
	align-items: center;
}

body.compact ul.navsectionlinks {
	align-items: center;
}

body.compact .navgroup > ul {
	padding-left: 0;
	align-items: center;
}

body.compact .navgroup > ul .navlink,
body.compact .nav-inline-links .navlink {
	width: 40px;
	min-height: 40px;
	padding: 0;
	justify-content: center;
	gap: 0;
}

html.light body.navbar,
:root[data-theme="light"] body.navbar {
	background:
		radial-gradient(circle at top left, rgba(127, 175, 230, 0.22), transparent 36%),
		linear-gradient(180deg, #f8fbff 0%, #eef4fb 100%);
	color: #1d2d41;
}

html.light html#side,
:root[data-theme="light"] html#side {
	background-color: #eef4fb;
}

html.light .navlink,
html.light .navgroup-label,
html.light .navbarsearch,
html.light .theme-switch,
:root[data-theme="light"] .navlink,
:root[data-theme="light"] .navgroup-label,
:root[data-theme="light"] .navbarsearch,
:root[data-theme="light"] .theme-switch {
	background: rgba(255, 255, 255, 0.84);
	border-color: rgba(66, 101, 141, 0.16);
}

html.light .navlink:hover,
html.light .navgroup:hover > .navgroup-label,
html.light .theme-switch:hover,
:root[data-theme="light"] .navlink:hover,
:root[data-theme="light"] .navgroup:hover > .navgroup-label,
:root[data-theme="light"] .theme-switch:hover {
	background: rgba(236, 243, 252, 0.96);
}

html.light .navlink-text,
html.light .navgroup-label .label-main,
html.light .navgroup-label::before,
html.light .navlink[data-icon]::before,
html.light .navgroup-label[data-icon]::before,
html.light .theme-switch,
html.light .search-title,
:root[data-theme="light"] .navlink-text,
:root[data-theme="light"] .navgroup-label .label-main,
:root[data-theme="light"] .navgroup-label::before,
:root[data-theme="light"] .navlink[data-icon]::before,
:root[data-theme="light"] .navgroup-label[data-icon]::before,
:root[data-theme="light"] .theme-switch,
:root[data-theme="light"] .search-title {
	color: #23405d;
}

html.light .navgroup > ul .navlink,
:root[data-theme="light"] .navgroup > ul .navlink {
	background: rgba(244, 248, 255, 0.88);
}

html.light .navbarsearch,
:root[data-theme="light"] .navbarsearch {
	background: #ffffff;
	border-color: rgba(66, 101, 141, 0.18);
}

html.light .navbarsearch input,
:root[data-theme="light"] .navbarsearch input {
	background: #ffffff;
	border-color: rgba(86, 120, 158, 0.26);
	color: #173a5c;
}

html.light .navbarsearch input::placeholder,
:root[data-theme="light"] .navbarsearch input::placeholder {
	color: #6b7f99;
}

html.light .navgroup > ul .navlink.is-active,
:root[data-theme="light"] .navgroup > ul .navlink.is-active {
	background: linear-gradient(135deg, rgba(173, 226, 206, 0.55), rgba(169, 208, 242, 0.50));
	border-color: rgba(66, 131, 109, 0.34);
}

html.light .navgroup > ul .navlink.is-active .navlink-text,
html.light .navgroup > ul .navlink.is-active::before,
:root[data-theme="light"] .navgroup > ul .navlink.is-active .navlink-text,
:root[data-theme="light"] .navgroup > ul .navlink.is-active::before {
	color: #173a5c;
}

html.light .navbadge.classic,
:root[data-theme="light"] .navbadge.classic {
	color: #48627e;
	background: rgba(87, 122, 160, 0.12);
	border-color: rgba(87, 122, 160, 0.14);
}
</style>
</head>


<body class='navbar'>

<div class="navbarlogo">
	<button class="navbar-toggle" type="button" aria-label="Toggle navigation" onclick="if (window.parent && typeof window.parent.toggleSidebar === 'function') { window.parent.toggleSidebar(); }">&#9776;</button>
	<a class="navbarbrand" href="main.php" target="<?php echo $link_target;?>">
		<img class="navbarbrand-mark" src="nagios.png" alt="Nagios">
		<div class="navbarbrand-full fulllogo nagioslogo"></div>
	</a>
	<button class="theme-switch" id="theme-toggle" type="button" aria-label="Cambia tema">
		<span class="theme-switch-icon" aria-hidden="true">&#9728;</span>
		<span class="theme-switch-label">Tema</span>
		<span class="theme-switch-icon" aria-hidden="true">&#9790;</span>
	</button>
</div>

<div class="navsection">
	<div class="navsectiontitle">General</div>
	<div class="navsectionlinks">
		<ul class="navsectionlinks">
			<li><a class="navlink" data-icon="⌂" href="main.php" target="<?php echo $link_target;?>" title="Home"><span class="navlink-text">Home</span></a></li>
			<li><a class="navlink" data-icon="?" href="https://assets.nagios.com/downloads/nagioscore/docs/nagioscore/4/en/" target="_blank" title="Documentation"><span class="navlink-text">Documentation</span></a></li>
		</ul>
	</div>
</div>

<div class="navsection">
	<div class="navsectiontitle">Current Status</div>
	<div class="navsectionlinks">
		<ul class="navsectionlinks">
			<!-- <li><a href="<?php echo $cfg["cgi_base_url"];?>/tac.cgi" target="<?php echo $link_target;?>">Tactical Overview</a></li> -->
			<li><a class="navlink" data-icon="◉" href="live.php" target="<?php echo $link_target;?>" title="Live Overview"><span class="navlink-main"><span class="navlink-text">Live Overview</span></span><span class="navlink-meta"><span class="navbadge modern">Modern</span></span></a></li>
			<li><a class="navlink" data-icon="▣" href="<?php echo $cfg["cgi_base_url"];?>/status.cgi?hostgroup=all&amp;style=hostdetail" target="<?php echo $link_target;?>" title="Hosts"><span class="navlink-text">Hosts</span></a></li>
			<li><a class="navlink" data-icon="◎" href="host_detail.php?host=" target="<?php echo $link_target;?>" title="Host Detail"><span class="navlink-text">Host Detail</span><span class="navlink-meta"><span class="navbadge modern">Modern</span></span></a></li>
			<li><a class="navlink" data-icon="≣" href="<?php echo $cfg["cgi_base_url"];?>/status.cgi?host=all" target="<?php echo $link_target;?>" title="Services"><span class="navlink-text">Services</span></a></li>
			<li class="navgroup is-collapsed">
				<button class="navgroup-label" data-icon="▤" type="button" aria-expanded="false" title="Host Groups"><span class="label-main">Host Groups</span><span class="navgroup-caret">▾</span></button>
				<ul>
					<li><a class="navlink" href="hostgroups.php" target="<?php echo $link_target;?>"><span class="navlink-text">Modern View</span><span class="navlink-meta"><span class="navbadge modern">Modern</span></span></a></li>
					<li><a class="navlink" href="hostgroups_summary.php" target="<?php echo $link_target;?>"><span class="navlink-text">Modern Summary</span><span class="navlink-meta"><span class="navbadge modern">Modern</span></span></a></li>
					<li><a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/status.cgi?hostgroup=all&amp;style=overview" target="<?php echo $link_target;?>"><span class="navlink-text">Classic Overview</span><span class="navlink-meta"><span class="navbadge classic">Classic</span></span></a></li>
					<li><a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/status.cgi?hostgroup=all&amp;style=summary" target="<?php echo $link_target;?>"><span class="navlink-text">Classic Summary</span><span class="navlink-meta"><span class="navbadge classic">Classic</span></span></a></li>
					<li><a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/status.cgi?hostgroup=all&amp;style=grid" target="<?php echo $link_target;?>"><span class="navlink-text">Grid</span></a></li>
				</ul>
			</li>
			<li class="navgroup is-collapsed">
				<button class="navgroup-label" data-icon="▥" type="button" aria-expanded="false" title="Service Groups"><span class="label-main">Service Groups</span><span class="navgroup-caret">▾</span></button>
				<ul>
					<li><a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/status.cgi?servicegroup=all&amp;style=overview" target="<?php echo $link_target;?>"><span class="navlink-text">Overview</span></a></li>
					<li><a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/status.cgi?servicegroup=all&amp;style=summary" target="<?php echo $link_target;?>"><span class="navlink-text">Summary</span></a></li>
					<li><a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/status.cgi?servicegroup=all&amp;style=grid" target="<?php echo $link_target;?>"><span class="navlink-text">Grid</span></a></li>
				</ul>
			</li>
			<li class="navgroup">
				<button class="navgroup-label" data-icon="!" type="button" aria-expanded="true" title="Problems"><span class="label-main">Problems</span><span class="navgroup-caret">▾</span></button>
				<ul>
					<li><a class="navlink" href="problems.php" target="<?php echo $link_target;?>"><span class="navlink-text">Overview</span><span class="navlink-meta"><span class="navbadge modern">Modern</span></span></a></li>
					<li>
						<div class="nav-inline-links">
							<a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/status.cgi?host=all&amp;servicestatustypes=28&hostprops=42" target="<?php echo $link_target;?>"><span class="navlink-text">Services</span></a>
							<a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/status.cgi?host=all&amp;type=detail&amp;hoststatustypes=3&amp;serviceprops=10&amp;servicestatustypes=28" target="<?php echo $link_target;?>"><span class="navlink-text">Unhandled</span></a>
						</div>
					</li>
					<li>
						<div class="nav-inline-links">
							<a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/status.cgi?hostgroup=all&amp;style=hostdetail&amp;hoststatustypes=12" target="<?php echo $link_target;?>"><span class="navlink-text">Hosts</span></a>
							<a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/status.cgi?hostgroup=all&amp;style=hostdetail&amp;hoststatustypes=12&amp;hostprops=42" target="<?php echo $link_target;?>"><span class="navlink-text">Unhandled</span></a>
						</div>
					</li>
					<li><a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/outages.cgi" target="<?php echo $link_target;?>"><span class="navlink-text">Network Outages</span></a></li>
				</ul>
			</li>
		</ul>
	</div>
	<div class="navbarsearch">
		<form method="get" action="<?php echo $cfg["cgi_base_url"];?>/status.cgi" target="<?php echo $link_target;?>">
			<div class="search-title">Quick Search</div>
			<input type='hidden' name='navbarsearch' value='1'>
			<input type='text' name='host' size='15' class="NavBarSearchItem">
		</form>
	</div>
</div>

<div class="navsection">
	<div class="navsectiontitle">Reports</div>
	<div class="navsectionlinks">
		<ul class="navsectionlinks">
			<li><a class="navlink" data-icon="◷" href="<?php echo $cfg["cgi_base_url"];?>/avail.cgi" target="<?php echo $link_target;?>" title="Availability"><span class="navlink-text">Availability</span></a></li>
			<li><a class="navlink" data-icon="≈" href="/nagios/nagiosgraph_modern/show.php" target="<?php echo $link_target;?>" title="Nagiosgraph"><span class="navlink-text">Nagiosgraph</span><span class="navlink-meta"><span class="navbadge modern">Modern</span></span></a></li>
			<li><a class="navlink" data-icon="↗" href="<?php echo $cfg["cgi_base_url"];?>/trends.cgi" target="<?php echo $link_target;?>" title="Trends"><span class="navlink-text">Trends</span></a></li>
			<li class="navgroup is-collapsed">
				<button class="navgroup-label" data-icon="⚑" type="button" aria-expanded="false" title="Alerts"><span class="label-main">Alerts</span><span class="navgroup-caret">▾</span></button>
			<ul>
				<li><a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/history.cgi?host=all" target="<?php echo $link_target;?>"><span class="navlink-text">History</span></a></li>
				<li><a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/summary.cgi" target="<?php echo $link_target;?>"><span class="navlink-text">Summary</span></a></li>
				<li><a class="navlink" href="<?php echo $cfg["cgi_base_url"];?>/histogram.cgi" target="<?php echo $link_target;?>"><span class="navlink-text">Histogram</span></a></li>
			</ul>
			</li>
			<li><a class="navlink" data-icon="✉" href="<?php echo $cfg["cgi_base_url"];?>/notifications.cgi?contact=all" target="<?php echo $link_target;?>" title="Notifications"><span class="navlink-text">Notifications</span></a></li>
			<li><a class="navlink" data-icon="☷" href="<?php echo $cfg["cgi_base_url"];?>/showlog.cgi" target="<?php echo $link_target;?>" title="Event Log"><span class="navlink-text">Event Log</span></a></li>
		</ul>
	</div>
</div>

<div class="navsection">
	<div class="navsectiontitle">System</div>
	<div class="navsectionlinks">
		<ul class="navsectionlinks">
			<li><a class="navlink" data-icon="✎" href="<?php echo $cfg["cgi_base_url"];?>/extinfo.cgi?type=3" target="<?php echo $link_target;?>" title="Comments"><span class="navlink-text">Comments</span></a></li>
			<li><a class="navlink" data-icon="Ⅱ" href="<?php echo $cfg["cgi_base_url"];?>/extinfo.cgi?type=6" target="<?php echo $link_target;?>" title="Downtime"><span class="navlink-text">Downtime</span></a></li>
			<li><a class="navlink" data-icon="⚙" href="<?php echo $cfg["cgi_base_url"];?>/extinfo.cgi?type=0" target="<?php echo $link_target;?>" title="Process Info"><span class="navlink-text">Process Info</span></a></li>
			<li><a class="navlink" data-icon="◍" href="<?php echo $cfg["cgi_base_url"];?>/extinfo.cgi?type=4" target="<?php echo $link_target;?>" title="Performance Info"><span class="navlink-text">Performance Info</span></a></li>
			<li><a class="navlink" data-icon="☰" href="<?php echo $cfg["cgi_base_url"];?>/extinfo.cgi?type=7" target="<?php echo $link_target;?>" title="Scheduling Queue"><span class="navlink-text">Scheduling Queue</span></a></li>
			<li><a class="navlink" data-icon="⌘" href="<?php echo $cfg["cgi_base_url"];?>/config.cgi" target="<?php echo $link_target;?>" title="Configuration"><span class="navlink-text">Configuration</span></a></li>
		</ul>
	</div>
</div>

<script>
window.setSidebarCompact = function (compact) {
	document.body.classList.toggle('compact', !!compact);
};

window.updateThemeToggle = function (theme) {
	var button = document.getElementById('theme-toggle');
	if (!button) {
		return;
	}

	var mode = theme === 'light' ? 'chiaro' : 'scuro';
	button.setAttribute('title', 'Tema attivo: ' + mode + ' (clicca per cambiare)');
	button.setAttribute('aria-label', 'Cambia tema, ora: ' + mode);
};

window.syncCompactGroupIcons = function () {
	Array.prototype.slice.call(document.querySelectorAll('.navgroup')).forEach(function (group) {
		var label = group.querySelector('.navgroup-label[data-icon]');
		if (!label) {
			return;
		}

		var icon = label.getAttribute('data-icon');
		Array.prototype.slice.call(group.querySelectorAll('ul .navlink')).forEach(function (link) {
			if (!link.getAttribute('data-icon')) {
				link.setAttribute('data-icon', icon);
			}
			if (!link.getAttribute('title')) {
				var labelText = link.textContent.replace(/\s+/g, ' ').trim();
				if (labelText !== '') {
					link.setAttribute('title', labelText);
				}
			}
		});
	});
};

window.syncActiveNav = function () {
	var links = Array.prototype.slice.call(document.querySelectorAll('.navlink[href]'));
	var groupLabels = Array.prototype.slice.call(document.querySelectorAll('.navgroup-label'));

	links.forEach(function (link) {
		link.classList.remove('is-active');
	});

	groupLabels.forEach(function (label) {
		label.classList.remove('is-active');
	});

	var currentUrl = '';
	try {
		currentUrl = window.parent.frames.main.location.href || '';
	} catch (e) {
		return;
	}

	if (!currentUrl) {
		return;
	}

	var current;
	try {
		current = new URL(currentUrl, window.location.origin);
	} catch (e) {
		return;
	}

	var currentPath = current.pathname;
	var currentParams = current.searchParams;
	var hostsLink = document.querySelector('a[href*="status.cgi?hostgroup=all"][href*="style=hostdetail"]');

	if (
		(
			currentPath.indexOf('/cgi-bin/extinfo.cgi') !== -1 &&
			currentParams.get('type') === '1' &&
			currentParams.get('host')
		) ||
		(
			currentPath.indexOf('/cgi-bin/cmd.cgi') !== -1 &&
			currentParams.get('host') &&
			currentParams.get('host') !== 'all' &&
			!currentParams.get('service')
		) ||
		(
			currentPath.indexOf('/cgi-bin/status.cgi') !== -1 &&
			currentParams.get('host') &&
			currentParams.get('host') !== 'all' &&
			!currentParams.get('servicegroup') &&
			!currentParams.get('hostgroup')
		)
	) {
		if (hostsLink) {
			hostsLink.classList.add('is-active');
		}
		return;
	}

	var bestMatch = null;
	var bestScore = -1;

	links.forEach(function (link) {
		var href = link.getAttribute('href');
		if (!href || href === '#') {
			return;
		}

		var candidate;
		try {
			candidate = new URL(href, window.location.href);
		} catch (e) {
			return;
		}

		if (candidate.pathname !== current.pathname) {
			return;
		}

		var score = 10;
		var candidateParams = Array.from(candidate.searchParams.entries());
		candidateParams.forEach(function (entry) {
			if (currentParams.get(entry[0]) === entry[1]) {
				score += 2;
			}
		});

		if (candidate.search === current.search) {
			score += 20;
		}

		if (score > bestScore) {
			bestScore = score;
			bestMatch = link;
		}
	});

	if (!bestMatch) {
		return;
	}

	bestMatch.classList.add('is-active');
	var parentGroup = bestMatch.closest('.navgroup');
	if (parentGroup) {
		parentGroup.classList.remove('is-collapsed');
		var groupLabel = parentGroup.querySelector('.navgroup-label');
		if (groupLabel) {
			groupLabel.setAttribute('aria-expanded', 'true');
			if (document.body.classList.contains('compact')) {
				groupLabel.classList.add('is-active');
			}
		}
	}
};

document.addEventListener('click', function (event) {
	var toggle = event.target.closest('.navgroup-label');
	if (!toggle) {
		var link = event.target.closest('.navlink');
		if (link) {
			setTimeout(function () {
				if (typeof window.syncActiveNav === 'function') {
					window.syncActiveNav();
				}
			}, 50);
		}
		return;
	}

	var group = toggle.closest('.navgroup');
	if (!group) {
		return;
	}

	var collapsed = group.classList.toggle('is-collapsed');
	toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
	if (typeof window.syncCompactGroupIcons === 'function') {
		window.syncCompactGroupIcons();
	}
});

window.addEventListener('load', function () {
	var themeToggle = document.getElementById('theme-toggle');
	if (themeToggle) {
		themeToggle.addEventListener('click', function () {
			if (!window.NagiosTheme || typeof window.NagiosTheme.toggleTheme !== 'function') {
				return;
			}
			var theme = window.NagiosTheme.toggleTheme();
			window.updateThemeToggle(theme);
			if (window.parent && typeof window.parent.applyThemeToFrames === 'function') {
				window.parent.applyThemeToFrames(theme);
			}
		});
	}

	var initialTheme = (window.NagiosTheme && typeof window.NagiosTheme.getTheme === 'function')
		? window.NagiosTheme.getTheme()
		: 'dark';
	window.updateThemeToggle(initialTheme);

	window.addEventListener('nagios-theme-change', function (event) {
		var theme = event && event.detail ? event.detail.theme : 'dark';
		window.updateThemeToggle(theme);
		if (window.parent && typeof window.parent.applyThemeToFrames === 'function') {
			window.parent.applyThemeToFrames(theme);
		}
	});

	if (typeof window.syncCompactGroupIcons === 'function') {
		window.syncCompactGroupIcons();
	}
	if (typeof window.syncActiveNav === 'function') {
		window.syncActiveNav();
	}
});
</script>

</body>
</html>
