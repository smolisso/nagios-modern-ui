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
  ```
  - avail.cgi
  - status.cgi
  - extinfo.cgi
  - cmd.cgi


## 📸 Screenshots
| Status Overview | Host Detail |
|-----------------|------------|
| ![](screenshots/status-all.png) | ![](screenshots/hostdetail.png) |


## 🛠 Installation
Replace the following files in your Nagios Core installation:
```
- <nagiosroot>/share/stylesheets/avail.css
- <nagiosroot>/share/stylesheets/cmd.css
- <nagiosroot>/share/stylesheets/extinfo.css
- <nagiosroot>/share/stylesheets/history.css
- <nagiosroot>/share/stylesheets/outage.css
- <nagiosroot>/share/stylesheets/status.css
- <nagiosroot>/share/stylesheets/tac.css

Restart is **not required**.  
Just hard-refresh your browser (Ctrl+F5).


## ⚠️ Notes
- Tested on **Nagios Core 4.5.x**
- This project modifies only CSS files
- Fully reversible by restoring original stylesheets


## ❤️ Credits
Original UI: Nagios Core  
Modifications: community-driven

PRs welcome.
