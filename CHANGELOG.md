# Changelog

All notable changes to this project will be documented in this file.

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