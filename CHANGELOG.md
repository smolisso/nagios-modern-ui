# Changelog

All notable changes to this project will be documented in this file.

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
