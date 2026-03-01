![GitHub stars](https://img.shields.io/github/stars/smolisso/nagios-modern-ui)
![License](https://img.shields.io/github/license/smolisso/nagios-modern-ui?v=1)
![Version](https://img.shields.io/badge/version-0.2.0-blue)


# Nagios Core – Modern UI Tweaks
A modern, responsive redesign of the Nagios Core web interface.

No JavaScript.  
No themes engine.

Just cleaner colors, modern fonts, better readability and a fully responsive layout — while keeping the original Nagios look & feel.


## ✨ Features

- Modern neutral dark background
- Softer, more readable OK / WARNING / CRITICAL colors
- Improved contrast for status counters
- System UI font stack (Segoe UI / Roboto / Ubuntu / San Francisco)
- Host names emphasized, services unchanged
- Cleaner status summary layout
- Better readability for Unhandled / Problem indicators
- Fully dark-mode optimized
- **Responsive layout** — works on desktop, tablet and mobile screens

Consistent styling across:

- `avail.cgi`
- `status.cgi`
- `extinfo.cgi`
- `cmd.cgi`
- `tac.cgi`
- `summary.cgi`
- and all standard Nagios Core pages


## 📱 Responsive Layout

Starting from version 0.2.0, the UI is fully responsive.

To achieve this, a modification to `index.php` was necessary in addition to the CSS changes. The original fixed-width frameset layout has been replaced with a fluid structure that adapts to different screen sizes and orientations.

Key changes introduced in `index.php`:

- **Hamburger button** — a toggle button (☰) is always visible, on both desktop and mobile. It shows the Nagios logo and collapses/expands the sidebar with a smooth CSS transition.
- **Desktop behavior** — clicking the hamburger collapses the sidebar and expands the main content area to full width.
- **Mobile behavior** — on screens ≤ 768px, the sidebar slides in as an overlay with a semi-transparent backdrop, leaving the main content always full width underneath.
- **Auto-scaling** — on narrow screens, a JavaScript zoom is applied to the main iframe content to compensate for Nagios CGI pages having a fixed native width (~1024px).

This means the project now includes **both CSS and PHP changes**.


## 🎨 New Icon Set

In addition to the CSS modernization, this project introduces a **new modern SVG icon set**.

Redesigned icons include:

- 💬 Comments
- ⚙ Actions
- 📊 Status (alternative icon)
- 🔁 Flapping

### Design Goals

- Flat, minimal SVG style  
- Crisp rendering at 16–20px  
- Consistent stroke weight  
- Dark-mode friendly contrast  
- Lightweight and scalable  

Icons replace the original bitmap-style images while preserving:

- Original behavior  
- Original layout  
- Original functionality  


## 📸 Screenshots
| Status Overview | Host Detail |
|-----------------|------------|
| ![](screenshots/status-service-all.png) | ![](screenshots/hostdetail.png) |

| Extended Info | Service Problems |
|-----------------|------------|
| ![](screenshots/extinfo.png) | ![](screenshots/service-problems.png) |


## 🛠 Installation

Replace the following files in your Nagios Core installation:

```
[nagios_root_path]/share/index.php

[nagios_root_path]/share/stylesheets/avail.css
[nagios_root_path]/share/stylesheets/cmd.css
[nagios_root_path]/share/stylesheets/config.css
[nagios_root_path]/share/stylesheets/extinfo.css
[nagios_root_path]/share/stylesheets/histogram.css
[nagios_root_path]/share/stylesheets/history.css
[nagios_root_path]/share/stylesheets/notifications.css
[nagios_root_path]/share/stylesheets/outages.css
[nagios_root_path]/share/stylesheets/showlog.css
[nagios_root_path]/share/stylesheets/status.css
[nagios_root_path]/share/stylesheets/summary.css
[nagios_root_path]/share/stylesheets/tac.css
[nagios_root_path]/share/stylesheets/trends.css
```

For the new icons, replace:
```
[nagios_root_path]/share/images/comments.svg
[nagios_root_path]/share/images/action.svg
[nagios_root_path]/share/images/status2.svg
[nagios_root_path]/share/images/flapping.svg
```

Restart is **not required**.  
Just hard-refresh your browser (Ctrl+F5).

You can also download `stylesheets.zip` with all CSS files included,  
and `images.zip` with all new icons.


## ⚠️ Notes
- Tested on Nagios Core 4.5.x
- Modifies CSS files, image files and `index.php`
- No configuration changes
- Fully reversible by restoring original files


## ❤️ Credits
Original UI: Nagios Core  
Modifications: community-driven

PRs welcome.