# Changelog

All notable changes to this project will be documented in this file.

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