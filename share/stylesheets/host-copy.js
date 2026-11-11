(function () {
  'use strict';

  var copyIcon = '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/></svg>';
  var checkIcon = '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>';

  // Install alongside the controls so cached CGI styles cannot hide the icons.
  function installStyle(doc) {
    if (doc.getElementById('nagios-host-copy-style')) return;
    var style = doc.createElement('style');
    style.id = 'nagios-host-copy-style';
    style.textContent = `
      html:root body.extinfo button.host-copy-button[type="button"] {
        display: inline-flex; align-items: center; justify-content: center;
        vertical-align: middle; box-sizing: border-box; flex: 0 0 auto;
        width: 30px; height: 30px; margin: 0 0 0 6px; padding: 6px;
        border: 0; border-radius: 50%; background: transparent;
        color: var(--cgi-muted, #94a3b8); cursor: pointer;
      }
      html:root body.extinfo button.host-copy-button[type="button"]:hover {
        background: var(--cgi-panel-alt, #263242); color: var(--cgi-text, #e2e8f0);
      }
      html:root body.extinfo button.host-copy-button[type="button"]:focus-visible {
        outline: 2px solid currentColor; outline-offset: 2px;
      }
      html:root body.extinfo button.host-copy-button[type="button"].is-copied {
        color: #4ade80; background: rgba(74, 222, 128, .1);
      }
      html:root:is(.light, [data-theme="light"]) body.extinfo button.host-copy-button[type="button"].is-copied {
        color: #15803d; background: rgba(21, 128, 61, .08);
      }
      html:root body.extinfo button.host-copy-button[type="button"] svg {
        display: block; flex: 0 0 18px; width: 18px; height: 18px;
        fill: none; stroke: currentColor; stroke-width: 1.8;
        stroke-linecap: round; stroke-linejoin: round;
      }
    `;
    (doc.head || doc.documentElement).appendChild(style);
  }

  function legacyCopy(doc, value) {
    var active = doc.activeElement;
    var input = doc.createElement('textarea');
    input.value = value;
    input.setAttribute('readonly', '');
    input.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0';
    doc.body.appendChild(input);
    try {
      input.select();
      return doc.execCommand('copy');
    } finally {
      input.remove();
      if (active && active.focus) active.focus({ preventScroll: true });
    }
  }

  function addButton(doc, field, value, label) {
    if (!value || field.querySelector('.host-copy-button')) return;
    var button = doc.createElement('button');
    var timer;
    button.type = 'button';
    button.className = 'host-copy-button';
    button.innerHTML = copyIcon;
    button.title = label;
    button.setAttribute('aria-label', label);
    field.appendChild(button);

    button.addEventListener('click', async function () {
      clearTimeout(timer);
      button.disabled = true;
      button.classList.remove('is-copied');
      try {
        var clipboard = doc.defaultView.navigator.clipboard;
        if (clipboard && clipboard.writeText) {
          try {
            await clipboard.writeText(value);
          } catch (error) {
            if (!legacyCopy(doc, value)) throw error;
          }
        } else if (!legacyCopy(doc, value)) {
          throw new Error('Copy failed');
        }
        button.innerHTML = checkIcon;
        button.classList.add('is-copied');
        button.title = 'Copied!';
        button.setAttribute('aria-label', 'Copied!');
      } catch (error) {
        button.innerHTML = copyIcon;
        button.title = 'Copy failed. Click to retry.';
        button.setAttribute('aria-label', button.title);
      } finally {
        button.disabled = false;
        timer = setTimeout(function () {
          button.innerHTML = copyIcon;
          button.classList.remove('is-copied');
          button.title = label;
          button.setAttribute('aria-label', label);
        }, 2000);
      }
    });
  }

  window.addNagiosHostCopyButtons = function (frame) {
    try {
      var doc = frame.contentDocument;
      var url = new URL(frame.contentWindow.location.href);
      if (!doc || !doc.body || !doc.body.classList.contains('extinfo') ||
          !url.pathname.endsWith('/extinfo.cgi') || url.searchParams.get('type') !== '1' ||
          !doc.querySelector('.stateInfoPanel')) return;

      var card = doc.querySelector('body > table:has(> tbody > tr > td > table.infoBox) > tbody > tr > td:nth-child(2)');
      if (!card) return;
      installStyle(doc);
      var titles = Array.from(card.children).filter(function (el) { return el.classList.contains('dataTitle'); });
      var host = url.searchParams.get('host');
      var nameField = titles.find(function (el) { return el.textContent.trim() === '(' + host + ')'; });
      // Use the prominent title when it is also the actual object name, not an alias.
      if (nameField && titles[0].textContent.trim() === host) nameField = titles[0];
      if (nameField) addButton(doc, nameField, host, 'Copy host name');

      var fields = Array.from(card.children).filter(function (el) { return el.classList.contains('data'); });
      var addressField = fields[fields.length - 1];
      if (addressField && !addressField.querySelector('.host-copy-button')) {
        addButton(doc, addressField, addressField.textContent.trim(), 'Copy host address');
      }
    } catch (error) {
      // Leave unrelated or cross-origin pages untouched.
    }
  };
})();
