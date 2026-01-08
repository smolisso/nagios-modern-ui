![GitHub stars](https://img.shields.io/github/stars/smolisso/nagios-modern-ui)
![License](https://img.shields.io/github/license/smolisso/nagios-modern-ui)


# Nagios Core – Modern UI Tweaks
A minimal, CSS-only modernization of the Nagios Core web interface.

No JavaScript.  
No themes engine.  
No layout changes.

Just cleaner colors, modern fonts and better readability — while keeping the original Nagios look & feel.


## ✨ Features
- Modern neutral gray background
- Softer, readable OK / WARNING / CRITICAL colors
- System UI font stack (Segoe UI / Roboto / Ubuntu)
- Host names emphasized, services unchanged
- Consistent look across:
  - avail.cgi
  - status.cgi
  - extinfo.cgi
  - cmd.cgi


## 📸 Screenshots
| Status Overview | Host Detail |
|-----------------|------------|
| ![](screenshots/status-service-all.png) | ![](screenshots/hostdetail.png) |

| Extended Info | Service Problems |
|-----------------|------------|
| ![](screenshots/extinfo.png) | ![](screenshots/service-problem.png) |


## 🛠 Installation
Replace the following files in your Nagios Core installation:
- [nagios_root_path]/share/stylesheets/avail.css
- [nagios_root_path]/share/stylesheets/cmd.css
- [nagios_root_path]/share/stylesheets/config.css
- [nagios_root_path]/share/stylesheets/extinfo.css
- [nagios_root_path]/share/stylesheets/histogram.css
- [nagios_root_path]/share/stylesheets/history.css
- [nagios_root_path]/share/stylesheets/notifications.css
- [nagios_root_path]/share/stylesheets/outages.css
- [nagios_root_path]/share/stylesheets/showlog.css
- [nagios_root_path]/share/stylesheets/status.css
- [nagios_root_path]/share/stylesheets/summary.css
- [nagios_root_path]/share/stylesheets/tac.css
- [nagios_root_path]/share/stylesheets/trends.css

Restart is **not required**.  
Just hard-refresh your browser (Ctrl+F5).

You can download the stylesheets.zip file with all css included.

## ⚠️ Notes
- Tested on **Nagios Core 4.5.x**
- This project modifies only CSS files
- Fully reversible by restoring original stylesheets


## ❤️ Credits
Original UI: Nagios Core  
Modifications: community-driven

PRs welcome.
