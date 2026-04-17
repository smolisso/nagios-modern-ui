# Changelog

All notable changes to this project will be documented in this file.

---

## [0.6.1] - 2026-04-17

### Changed
- `share/index.php`: improved anti-flicker theme handling while navigating between pages (prevents transient dark/light flash in frame transitions)
- `share/side.php`: fixed light-theme rendering edge case on Firefox for the lower sidebar area
- `share/stylesheets/outages.css`: completed light-theme restyle for `outages.cgi`
- `share/stylesheets/common.css` + `share/stylesheets/status.css`: normalized `Notifications are disabled` badge style and text color across classic CGI pages
- `share/stylesheets/common.css`: adjusted Page Tour widget position to avoid overlap with the signature badge

### Notes
- Maintenance release focused on visual consistency and navigation polish after `0.6.0`

---

## [0.6.0] - 2026-04-16

### Added
- Global theme switch (`dark` / `light`) with persistent user preference via `localStorage`
- New `share/stylesheets/theme.js` used across shell and modern pages

### Changed
- `share/index.php` updated to synchronize selected theme across `side` and `main` frames
- `share/side.php` updated with theme toggle button and improved light-mode behavior
- `share/live.php`, `share/problems.php`, `share/host_detail.php`, `share/hostgroups.php`, `share/hostgroups_summary.php`, `share/nagiosgraph_modern/show.php` updated for full light-theme compatibility
- Graph shortcut icon in modern pages is now always visible (not only on hover)
- Classic CGI styles updated for light mode across:
  - `status.cgi` views
  - `avail.cgi`
  - `trends.cgi`
  - `history.cgi`
  - `summary.cgi`
  - `histogram.cgi`
  - `notifications.cgi`
  - `showlog.cgi`
  - `extinfo.cgi` (types `0/1/3/4/6/7`)
  - `config.cgi`
  - `cmd.cgi`
- Main shared stylesheet (`share/stylesheets/common.css`) extended with broader classic light-theme token overrides

### Notes
- Dark theme remains the default fallback
- Light/dark selection is preserved across browser restarts (standard mode) and resets in private/incognito sessions

---

## [0.5.2] - 2026-04-02

### Changed
- `share/live.php` trend axis labels now switch format by zoom range:
  - `1h`, `6h`, `12h`, `24h` keep hour-based labels (`H:i`)
  - `7d`, `14d`, `30d` now show day-based labels (`d M`)
- Trend range labels and bar timestamps in `share/live.php` now use day-oriented formatting for long ranges (`7d`, `14d`, `30d`)

### Notes
- Fixes the live overview bug where long-range zoom levels still displayed only hours on the timeline

---

## [0.5.1] - 2026-03-31

### Changed
- `hostgroups.php` and `hostgroups_summary.php` now prefer `objects.cache` for Nagios object discovery
- Fallback object parsing now reads only files actually included by `nagios.cfg` via `cfg_file` and `cfg_dir`
- Prevented stale `.cfg` files left in `objects/` from appearing in the modern host group pages when they are not loaded by Nagios

### Notes
- Tested against the new object discovery logic with `objects.cache` present and fallback enabled
- `status.dat` remains the live status source, while object membership stays aligned with the effective Nagios configuration

---

## [0.5.0] - 2026-03-29

### Added
- New `problems.php` standalone problems overview sourced from `status.dat`
- New `host_detail.php` standalone host detail page for host service status drill-down
- New `hostgroups.php` modern host group overview
- New `hostgroups_summary.php` modern host group summary page
- Sidebar link integration for the new modern pages
- Active page highlighting in the sidebar shell

### Changed
- `README.md` updated for version `0.5.0` and current project scope
- `index.php` and `side.php` redesigned again to support:
  - compact and expanded sidebar modes
  - active navigation state
  - modernized Nagios shell behavior
- `live.php` refined with:
  - more compact metadata and KPI blocks
  - stronger severity highlighting on incident cards
  - card-wide navigation to host detail
  - graph shortcut visibility on service incidents
- `hostgroups.php` and `hostgroups_summary.php` updated to resolve host group membership more accurately from Nagios object definitions and template inheritance
- `hostgroups.php` and `hostgroups_summary.php` adjusted for more stable hero layout behavior across sidebar states
- `problems.php` refined to focus more strongly on active incidents and severity-first triage
- `nagiosgraph_modern/` refined with host selection flow, better host icon handling and deeper integration into the navigation shell

### Notes
- Tested on Nagios Core 4.5.x
- Reuses `status.dat`, `hostextinfo.cfg` and Nagios object definitions as data sources
- Reuses existing Nagiosgraph RRD data without modifying Nagios CGI binaries

---

## [0.4.0] - 2026-03-25

### Added
- New `live.php` standalone dashboard replacing the classic tactical overview with a modern operational layout
- KPI rows for host and service states, active incidents, oldest service problems, and most impacted hosts
- Historical availability trend powered by `live_trend_snapshot.php` and periodic JSON snapshots
- `nagiosgraph_modern/` standalone graph frontend with:
  - host and service selectors
  - modern graph rendering from existing Nagiosgraph RRD files
  - human-readable units for disk, memory and traffic metrics
  - drag-to-zoom plus stepwise zoom-out
  - hover preview popup via `popup.js`
- Host logo support in dashboard and graph pages using `hostextinfo.cfg`
- New sidebar entry for `Nagiosgraph Modern`

### Changed
- `README.md` updated for version `0.4.0`, deployment steps, cron setup and graph integration instructions
- `index.php` extended to support the responsive shell and modern graph popup loader
- `side.php` updated to link the Live Overview and Nagiosgraph Modern pages
- Project scope now includes CSS, PHP and JavaScript changes, not just styling tweaks

### Notes
- Tested on Nagios Core 4.5.x
- Includes optional Nagios configuration changes for `graphed-service` `action_url`
- Includes optional cron configuration for historical availability snapshots
- Reuses existing Nagiosgraph RRD data without modifying Nagios CGI binaries

---

## [0.3.1] - 2026-03-24

### Added
- Curated set of service and host **logos** in PNG format, stored in `images/logos/`
- Logos optimized for dark mode with transparent backgrounds where applicable
- Consistent sizing: 256px width, proportional height scaling
- Coverage includes: Operating Systems (Debian, CentOS, Ubuntu), Infrastructure (Proxmox, ESXi), Networking (Fortinet, OPNsense), Applications (Mattermost, Zammad, Nextcloud, and others)
- Updated `README.md` to document logo set and asset conventions

### Notes
- Tested on Nagios Core 4.5.x
- Image assets only — no CSS or PHP changes
- All logos packaged in `share.zip` for easy distribution

---


## [0.3.0] - 2026-03-15

### Added
- New **Dark Blue** color palette — deep navy tones (`#0f1117`, `#141b26`, `#1a2133`, `#1e2d42`) replacing the previous neutral dark gray theme
- **Rounded corners** on tables, status cards, info boxes and badges (`border-radius` applied consistently across all views)
- New `side.php` — fully rewritten sidebar navigation in PHP, replaces the old static HTML sidebar; includes sections: General, Current Status, Reports, System, with Quick Search form
- Theme-aware sidebar: reads `$cfg['theme']` and applies `dark` or `light` class to `<html>` element
- Refined **status badge colors** for all states: OK/UP (green `#6BC497`), WARNING (amber `#e5ca3e`), CRITICAL/DOWN (red `#a84040`), UNKNOWN/UNREACHABLE (muted purple `#9e82a0`) — with matching high-contrast text colors

### Changed
- Complete dark theme overhaul across **all CSS files**: `status.css`, `tac.css`, `common.css`, `extinfo.css`, `notifications.css`, `avail.css`, `summary.css`, `outages.css`, `showlog.css`, `history.css`, `histogram.css`, `trends.css`, `cmd.css`, `config.css`
- Table rows alternating colors updated to dark blue palette (`#141b26` / `#1a2133`)
- Table headers and borders updated to match dark blue theme (`#1e2d42`)
- Log entry rows (`logEntriesOdd`/`Even`) now use dark blue tones
- `index.php` updated to reference new `side.php` sidebar

### Notes
- Tested on Nagios Core 4.5.x
- Modifies CSS files, `index.php`, and introduces new `side.php`

---

## [0.2.0] - 2026-03-01

### Added
- Fully responsive layout — works on desktop, tablet and mobile
- Hamburger button (☰) always visible, collapses/expands the sidebar with a smooth CSS transition
- Mobile overlay mode — on screens ≤ 768px the sidebar slides in as an overlay with a semi-transparent backdrop
- Auto-scaling for main iframe content on narrow screens to compensate for Nagios CGI fixed native width (~1024px)

### Changed
- `index.php` modified to replace the original fixed-width frameset with a fluid CSS layout
- Project is no longer CSS/images only — PHP changes are now included

### Notes
- Tested on Nagios Core 4.5.x
- Modifies CSS, image files and `index.php`

---

## [0.1.0] - 2026-02-18

### Added
- Modern dark neutral background
- Updated OK / WARNING / CRITICAL color palette
- Improved green service badges
- Better contrast for status counters
- System UI font stack
- Improved readability for "Unhandled" indicators
- Cleaned up status summary layout
- New modern SVG icon set:
  - comments
  - actions
  - status2
  - flapping

### Changed
- Refined service badge colors for better contrast
- Improved miniStatus readability
- Improved host status summary styling

### Notes
- Tested on Nagios Core 4.5.x
- CSS and image changes only
- No backend modifications
