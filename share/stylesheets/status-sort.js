(function () {
  'use strict';

  var dividerTables = new WeakSet();
  var refreshCountdowns = new WeakMap();

  function updateFooterRefresh(doc, progress) {
    var footer = document.getElementById('modern-ui-footer-progress');
    var main = document.querySelector('iframe[name="main"]');
    if (!footer || !main || main.contentDocument !== doc) return;
    footer.hidden = progress == null || document.body.classList.contains('main-loading');
    footer.style.transform = 'scaleX(' + (progress == null ? 0 : progress) + ')';
  }

  function addRefreshCountdown(doc, title, seconds) {
    var previous = refreshCountdowns.get(doc);
    if (previous && previous.seconds === seconds) return;
    if (previous) {
      doc.defaultView.clearInterval(previous.timer);
      previous.node.remove();
      refreshCountdowns.delete(doc);
    }
    if (!(seconds > 0)) {
      updateFooterRefresh(doc, null);
      return;
    }
    var countdown = doc.createElement('div');
    countdown.className = 'status-refresh-countdown';
    countdown.title = 'Auto refresh every ' + seconds + ' seconds';
    var caption = doc.createElement('div');
    caption.className = 'status-refresh-caption';
    var label = doc.createElement('span');
    label.textContent = 'Next refresh';
    var remaining = doc.createElement('span');
    remaining.className = 'status-refresh-remaining';
    caption.appendChild(label);
    caption.appendChild(remaining);
    var track = doc.createElement('div');
    track.className = 'status-refresh-track';
    track.setAttribute('aria-hidden', 'true');
    var fill = doc.createElement('div');
    fill.className = 'status-refresh-fill';
    track.appendChild(fill);
    countdown.appendChild(caption);
    countdown.appendChild(track);
    (doc.querySelector('.status-cockpit-refresh') || title).appendChild(countdown);
    var view = doc.defaultView;
    var started = view.performance.timeOrigin || Date.now();
    var deadline = started + seconds * 1000;
    function updateCountdown() {
      var milliseconds = Math.max(0, deadline - Date.now());
      var value = Math.ceil(milliseconds / 1000);
      remaining.textContent = value > 0
        ? String(Math.floor(value / 60)).padStart(2, '0') + ':' + String(value % 60).padStart(2, '0')
        : 'Refreshing soon';
      var progress = Math.min(1, milliseconds / (seconds * 1000));
      fill.style.transform = 'scaleX(' + progress + ')';
      updateFooterRefresh(doc, progress);
    }
    updateCountdown();
    var timer = view.setInterval(updateCountdown, 1000);
    refreshCountdowns.set(doc, { node: countdown, seconds: seconds, timer: timer });
  }

  function addLiveIndicator(doc) {
    var title = Array.from(doc.querySelectorAll('.pageTitle .statusTitle')).find(function (node) {
      return /^(?:Service|Host) Status Details For\b/.test(node.textContent.trim());
    });
    if (!title) return;

    var seconds = 0;
    var refresh = Array.from(doc.querySelectorAll('meta[http-equiv]')).find(function (node) {
      return node.getAttribute('http-equiv').toLowerCase() === 'refresh';
    });
    if (refresh) {
      var match = /^\s*(\d+(?:\.\d+)?)\s*(?:;|$)/.exec(refresh.getAttribute('content') || '');
      seconds = match ? Number(match[1]) : 0;
    } else {
      // Classic CGI sends an HTTP Refresh header and prints its interval here.
      var info = doc.querySelector('td.infoBox');
      var interval = info && /Updated every\s+(\d+)\s+seconds\b/i.exec(info.textContent);
      seconds = interval ? Number(interval[1]) : 0;
    }

    var badge = doc.querySelector('.status-cockpit .status-live-badge') || title.querySelector('.status-live-badge');
    if (!(seconds > 0)) {
      if (badge) badge.remove();
      addRefreshCountdown(doc, title, 0);
      return;
    }
    if (!badge) {
      badge = doc.createElement('span');
      badge.className = 'status-live-badge';
      var dot = doc.createElement('span');
      dot.className = 'status-live-dot';
      dot.setAttribute('aria-hidden', 'true');
      badge.appendChild(dot);
      badge.appendChild(doc.createTextNode('LIVE'));
      badge.tabIndex = 0;
      title.appendChild(badge);
    }
    var description = 'Auto refresh every ' + seconds + ' seconds';
    badge.title = description;
    badge.setAttribute('aria-label', 'Live status. ' + description);
    addRefreshCountdown(doc, title, seconds);
  }

  function addStatusDashboard(doc) {
    if (doc.body.classList.contains('status-dashboard')) return;
    var title = doc.querySelector('.pageTitle .statusTitle');
    if (!title || !/^(?:Service|Host) Status Details For\b/.test(title.textContent.trim())) return;
    var header = doc.querySelector('body > table.headertable');
    var infoCell = header && header.querySelector(':scope > tbody > tr > td:first-child');
    if (!header || !infoCell) return;
    var view = doc.defaultView;
    function element(tag, className, text) {
      var node = doc.createElement(tag);
      node.className = className;
      if (text !== undefined) node.textContent = text;
      return node;
    }
    var charts = [];
    var colorUpdates = [];
    function createChart(isHost) {
      var totals = header.querySelector('table.' + (isHost ? 'hostTotals' : 'serviceTotals'));
      if (!totals) return;
      var cells = Array.from(totals.querySelectorAll(':scope > tbody > tr:nth-child(2) > td'));
      var headers = totals.querySelectorAll(':scope > tbody > tr:first-child > th');
      if (cells.length !== (isHost ? 4 : 5)) return;
      var counts = cells.map(function (cell) {
        var value = cell.textContent.trim();
        return /^\d+$/.test(value) ? Number(value) : NaN;
      });
      if (counts.some(function (count) { return !Number.isFinite(count); })) return;
      var labels = isHost ? ['Up', 'Down', 'Unreachable', 'Pending'] : ['OK', 'Warning', 'Unknown', 'Critical', 'Pending'];
      var total = counts.reduce(function (sum, count) { return sum + count; }, 0);
      var problems = counts.slice(1, -1).reduce(function (sum, count) { return sum + count; }, 0);
      var chart = element('section', 'status-dashboard-card status-dashboard-chart');
      chart.setAttribute('aria-label', isHost ? 'Host status distribution' : 'Service status distribution');
      var heading = element('div', 'status-cockpit-instrument-heading');
      heading.appendChild(element('div', 'status-dashboard-label', isHost ? 'HOST STATUS' : 'SERVICE STATUS'));
      var flag = element('span', 'status-cockpit-chart-flag', !total ? 'NO DATA' :
        problems ? problems.toLocaleString('en-US') + ' PROBLEMS' : counts[counts.length - 1] ? 'PENDING' : isHost ? 'ALL UP' : 'ALL OK');
      if (!total) flag.classList.add('is-unavailable');
      if (problems || counts[counts.length - 1]) flag.classList.add('is-attention');
      heading.appendChild(flag);
      chart.appendChild(heading);
      var chartBody = element('div', 'status-dashboard-chart-body');
      var dial = element('div', 'status-cockpit-dial');
      var ticks = element('span', 'status-cockpit-dial-ticks');
      ticks.setAttribute('aria-hidden', 'true');
      dial.appendChild(ticks);
      dial.addEventListener('pointermove', function (event) {
        if (event.pointerType !== 'mouse') return;
        var box = dial.getBoundingClientRect();
        var x = (event.clientX - box.left) / box.width;
        var y = (event.clientY - box.top) / box.height;
        dial.style.setProperty('--dial-light-angle', (Math.atan2(y - .5, x - .5) * 180 / Math.PI + 30) + 'deg');
        dial.style.setProperty('--dial-light-x', (x * 100) + '%');
        dial.style.setProperty('--dial-light-y', (y * 100) + '%');
        dial.classList.add('is-lit');
      });
      function clearDialLight() { dial.classList.remove('is-lit'); }
      dial.addEventListener('pointerleave', clearDialLight);
      dial.addEventListener('pointercancel', clearDialLight);
      var donut = element('div', 'status-dashboard-donut');
      donut.setAttribute('role', 'img');
      donut.setAttribute('aria-label', labels.map(function (label, index) {
        return label + ': ' + counts[index];
      }).join(', ') + '. Total: ' + total);
      var center = element('div', 'status-dashboard-donut-center');
      center.setAttribute('aria-hidden', 'true');
      center.appendChild(element('strong', '', total.toLocaleString('en-US')));
      center.appendChild(element('span', '', isHost ? 'hosts' : 'services'));
      center.appendChild(element('small', 'status-cockpit-ratio', total ?
        (counts[0] / total * 100).toFixed(isHost ? 1 : 2).replace(/\.0$/, '') + '% ' + (isHost ? 'UP' : 'OK') : '—'));
      donut.appendChild(center);
      dial.appendChild(donut);
      chartBody.appendChild(dial);
      var legend = element('div', 'status-dashboard-legend');
      var swatches = [];
      labels.forEach(function (label, index) {
        var original = headers[index] && headers[index].querySelector('a');
        var item = element(original ? 'a' : 'div', 'status-dashboard-legend-item');
        if (original) item.setAttribute('href', original.getAttribute('href'));
        var swatch = element('span', 'status-dashboard-swatch');
        swatch.setAttribute('aria-hidden', 'true');
        swatches.push(swatch);
        item.appendChild(swatch);
        item.appendChild(element('span', '', label));
        item.appendChild(element('strong', '', counts[index].toLocaleString('en-US')));
        item.title = total ? (counts[index] / total * 100).toFixed(2) + '%' : '0%';
        legend.appendChild(item);
      });
      chartBody.appendChild(legend);
      chart.appendChild(chartBody);
      colorUpdates.push(function () {
        var position = 0;
        var stops = [];
        cells.forEach(function (cell, index) {
          var color = view.getComputedStyle(cell).backgroundColor;
          swatches[index].style.backgroundColor = color;
          if (!counts[index] || !total) return;
          var end = position + counts[index] / total * 100;
          stops.push(color + ' ' + position + '% ' + end + '%');
          position = end;
        });
        donut.style.background = total ? 'conic-gradient(' + stops.join(',') + ')' : 'var(--detail-neutral)';
      });
      charts.push(chart);
    }
    createChart(true);
    createChart(false);
    if (!charts.length) return;

    var cockpit = element('div', 'status-cockpit');
    var operations = element('section', 'status-dashboard-card status-cockpit-ops');
    operations.setAttribute('aria-label', 'Current network status');
    var operationsHeading = element('div', 'status-cockpit-ops-heading');
    var nativeHeading = infoCell.querySelector('.infoBoxTitle');
    operationsHeading.appendChild(nativeHeading || element('div', 'infoBoxTitle', 'Current Network Status'));
    var liveBadge = title.querySelector('.status-live-badge');
    if (liveBadge) operationsHeading.appendChild(liveBadge);
    operations.appendChild(operationsHeading);
    var annunciators = element('div', 'status-cockpit-annunciators');
    var programFlags = {};
    var commandDialog = null;
    var indicators = [
      { label: 'NOTIFICATIONS', keys: ['enable_notifications'], actions: [
        { label: 'Notifications', key: 'enable_notifications', enable: 12, disable: 11 }
      ] },
      { label: 'ACTIVE CHECKS', keys: ['active_host_checks_enabled', 'active_service_checks_enabled'], actions: [
        { label: 'Active host checks', key: 'active_host_checks_enabled', enable: 88, disable: 89 },
        { label: 'Active service checks', key: 'active_service_checks_enabled', enable: 35, disable: 36 }
      ] },
      { label: 'PASSIVE CHECKS', keys: ['passive_host_checks_enabled', 'passive_service_checks_enabled'], actions: [
        { label: 'Passive host checks', key: 'passive_host_checks_enabled', enable: 90, disable: 91 },
        { label: 'Passive service checks', key: 'passive_service_checks_enabled', enable: 37, disable: 38 }
      ] },
      { label: 'EVENT HANDLERS', keys: ['enable_event_handlers'], actions: [
        { label: 'Event handlers', key: 'enable_event_handlers', enable: 41, disable: 42 }
      ] }
    ];
    indicators.forEach(function (indicator) {
      indicator.node = element('button', 'status-cockpit-lamp');
      indicator.node.type = 'button';
      indicator.node.disabled = true;
      indicator.node.setAttribute('aria-haspopup', 'dialog');
      indicator.node.appendChild(element('span', '', indicator.label));
      indicator.value = element('strong', '', '—');
      indicator.node.appendChild(indicator.value);
      indicator.node.title = 'Program status unavailable';
      indicator.node.addEventListener('click', function () { openCommandDialog(indicator); });
      annunciators.appendChild(indicator.node);
    });
    operations.appendChild(annunciators);
    var commandFeedback = element('div', 'status-cockpit-command-feedback');
    commandFeedback.setAttribute('role', 'status');
    commandFeedback.hidden = true;
    operations.appendChild(commandFeedback);
    var refreshPanel = element('div', 'status-cockpit-refresh');
    var countdown = title.querySelector('.status-refresh-countdown');
    if (countdown) refreshPanel.appendChild(countdown);
    operations.appendChild(refreshPanel);
    var nativeInfo = element('div', 'status-cockpit-native-info');
    while (infoCell.firstChild) nativeInfo.appendChild(infoCell.firstChild);
    operations.appendChild(nativeInfo);
    header.querySelectorAll(':scope > tbody > tr > td').forEach(function (cell) {
      if (cell.querySelector('table.hostTotals, table.serviceTotals')) {
        cell.classList.add('status-cockpit-total-cell');
      } else {
        // Some CGI versions append an empty fourth cell; keep any extra content.
        while (cell.firstChild) nativeInfo.appendChild(cell.firstChild);
        cell.classList.add('status-cockpit-empty-info');
      }
    });
    cockpit.appendChild(operations);
    var instruments = element('div', 'status-cockpit-instruments');
    var gauges = element('div', 'status-cockpit-gauges');
    gauges.classList.toggle('status-cockpit-single-gauge', charts.length === 1);
    charts.forEach(function (chart) { gauges.appendChild(chart); });
    cockpit.appendChild(instruments);

    function readProgramStatus() {
      return view.fetch(new URL('../modern_ui_status.php', doc.location.href).href, {
        cache: 'no-store', credentials: 'same-origin'
      }).then(function (response) {
        if (!response.ok) throw new Error('Program status unavailable');
        return response.json();
      }).then(function (payload) {
        if (!payload.success || !payload.flags) throw new Error('Program status unavailable');
        programFlags = payload.flags;
        indicators.forEach(function (indicator) {
          var states = indicator.keys.map(function (key) { return programFlags[key]; });
          var available = states.every(function (state) { return typeof state === 'boolean'; });
          var enabled = available && states.every(function (state) { return state; });
          var disabled = available && states.every(function (state) { return !state; });
          indicator.node.disabled = !available;
          indicator.value.textContent = !available ? '—' : enabled ? 'ENABLED' : disabled ? 'DISABLED' : 'MIXED';
          indicator.node.classList.toggle('is-enabled', enabled);
          indicator.node.classList.toggle('is-disabled', available && !enabled);
          indicator.node.title = !available ? 'Program status unavailable' : indicator.keys.length === 1 ?
            indicator.label + ': ' + indicator.value.textContent + '. Click to manage.' :
            'Host checks: ' + (states[0] ? 'enabled' : 'disabled') + '; service checks: ' +
              (states[1] ? 'enabled' : 'disabled') + '. Click to manage.';
          indicator.node.setAttribute('aria-label', indicator.node.title);
        });
        return programFlags;
      });
    }

    function showCommandFeedback(message) {
      commandFeedback.textContent = message;
      commandFeedback.hidden = false;
    }

    function confirmProgramState(action, expected, status, dialog) {
      var attempts = 0;
      function checkState() {
        readProgramStatus().then(function (flags) {
          if (flags[action.key] === expected) {
            var message = action.label + ' ' + (expected ? 'enabled' : 'disabled') + '. State confirmed by Nagios.';
            showCommandFeedback(message);
            status.textContent = message;
            if (dialog.open) dialog.close();
            return;
          }
          retry();
        }).catch(retry);
      }
      function retry() {
        attempts++;
        if (attempts >= 15) {
          var message = 'Command submitted. State not confirmed yet; the next page refresh will update it.';
          showCommandFeedback(message);
          status.textContent = message;
          return;
        }
        view.setTimeout(checkState, 1000);
      }
      checkState();
    }

    function openCommandDialog(indicator) {
      if (commandDialog && commandDialog.open) return;
      var dialog = element('dialog', 'status-cockpit-command-dialog');
      commandDialog = dialog;
      var heading = element('div', 'status-cockpit-command-heading');
      var title = element('h2', '', indicator.label);
      title.id = 'status-cockpit-command-title';
      dialog.setAttribute('aria-labelledby', title.id);
      heading.appendChild(title);
      var close = element('button', 'status-cockpit-command-close', 'Close');
      close.type = 'button';
      close.addEventListener('click', function () { dialog.close(); });
      heading.appendChild(close);
      dialog.appendChild(heading);
      var status = element('p', 'status-cockpit-command-status', 'Choose which checks to manage.');
      status.setAttribute('role', 'status');
      dialog.appendChild(status);
      var choices = element('div', 'status-cockpit-command-choices');
      dialog.appendChild(choices);
      var frame = null;

      function loadCommand(action) {
        var current = programFlags[action.key];
        if (typeof current !== 'boolean') return;
        var expected = !current;
        var command = expected ? action.enable : action.disable;
        var url = new URL('cmd.cgi', doc.location.href);
        url.searchParams.set('cmd_typ', command);
        title.textContent = (expected ? 'Enable ' : 'Disable ') + action.label.toLowerCase();
        status.textContent = 'Review the native Nagios command and click Commit to confirm.';
        choices.hidden = true;
        frame = element('iframe', 'status-cockpit-command-frame');
        frame.title = title.textContent + ' — Nagios confirmation';
        var submitted = false;
        var acknowledged = false;
        frame.addEventListener('load', function () {
          try {
            var commandDoc = frame.contentDocument;
            if (!commandDoc || !commandDoc.body || !/\/cmd\.cgi$/.test(new URL(commandDoc.location.href).pathname)) {
              status.textContent = 'The command page is unavailable or requires sign-in. No command has been confirmed.';
              return;
            }
            var theme = doc.documentElement.getAttribute('data-theme') ||
              (doc.documentElement.classList.contains('light') ? 'light' : 'dark');
            commandDoc.documentElement.setAttribute('data-theme', theme);
            commandDoc.documentElement.classList.add(theme);
            removeNativeNotificationNotice(commandDoc);
            var compact = commandDoc.createElement('style');
            compact.textContent = 'body.cmd{zoom:1!important;margin:0!important;padding:12px!important}' +
              'body.cmd>.headertable{display:none!important}' +
              'body.cmd .cmdType{padding-bottom:8px!important;font-size:15px!important}' +
              'body.cmd table.optBox{zoom:1!important}' +
              'body.cmd .infoMessage{margin:12px!important}';
            commandDoc.head.appendChild(compact);
            frame.style.height = '240px';
            frame.style.height = Math.min(Math.max(240, commandDoc.documentElement.scrollHeight + 2),
              Math.max(240, view.innerHeight * .6)) + 'px';
            var form = commandDoc.querySelector('form input[name="cmd_typ"][value="' + command + '"]');
            form = form && form.closest('form');
            if (form && !form.dataset.cockpitBound) {
              form.dataset.cockpitBound = 'true';
              form.addEventListener('submit', function (event) {
                // The browser posts the original CGI form, including its native CSRF token.
                if (submitted) { event.preventDefault(); return; }
                submitted = true;
                status.textContent = 'Submitting command to Nagios…';
              });
            }
            var result = commandDoc.querySelector('.infoMessage');
            if (submitted && !acknowledged && result &&
                /Your command request was successfully submitted to Nagios for processing\./.test(result.textContent)) {
              acknowledged = true;
              status.textContent = 'Command submitted. Waiting for Nagios to confirm the new state…';
              showCommandFeedback(status.textContent);
              confirmProgramState(action, expected, status, dialog);
            } else if (submitted && !form && !acknowledged) {
              status.textContent = 'Nagios did not confirm the command. Review the response below.';
            }
          } catch (error) {
            status.textContent = 'Cannot read the command page. Review it below or use the standard Nagios command interface.';
          }
        });
        // GET opens the confirmation form only; no process command is sent here.
        frame.src = url.href;
        dialog.appendChild(frame);
      }

      indicator.actions.forEach(function (action) {
        var enabled = programFlags[action.key];
        var choice = element('button', 'status-cockpit-command-choice');
        choice.type = 'button';
        choice.disabled = typeof enabled !== 'boolean';
        choice.appendChild(element('span', '', action.label));
        choice.appendChild(element('strong', '', (enabled ? 'Disable' : 'Enable') + ' →'));
        choice.addEventListener('click', function () { loadCommand(action); });
        choices.appendChild(choice);
      });
      dialog.addEventListener('close', function () {
        if (frame) frame.remove();
        dialog.remove();
        if (commandDialog === dialog) {
          commandDialog = null;
          indicator.node.focus();
        }
      }, { once: true });
      doc.body.appendChild(dialog);
      dialog.showModal();
      if (indicator.actions.length === 1) loadCommand(indicator.actions[0]);
    }

    // Only program-wide switches are read here; commands use the original cmd.cgi form.
    readProgramStatus().catch(function () { /* Unavailable indicators remain disabled. */ });

    var clock = element('section', 'status-dashboard-card status-dashboard-clock');
    clock.setAttribute('aria-label', 'Dashboard clock');
    var clockDetails = element('div', 'status-dashboard-clock-details');
    var clockToolbar = element('div', 'status-dashboard-clock-toolbar');
    var clockHeading = element('div', 'status-dashboard-clock-heading');
    clockHeading.appendChild(element('div', 'status-dashboard-label', 'LOCAL TIME'));
    clockToolbar.appendChild(clockHeading);
    clockDetails.appendChild(clockToolbar);
    var time = element('time', 'status-dashboard-time');
    var timeHoursMinutes = doc.createTextNode('');
    var timeSeconds = element('span', 'status-dashboard-seconds');
    time.appendChild(timeHoursMinutes);
    time.appendChild(timeSeconds);
    var date = element('div', 'status-dashboard-date');
    var clockSettings = window.NagiosModernUITime.resolve();
    var clockTimezone = clockSettings.timeZone;
    var clockLocale = clockSettings.language;
    date.lang = clockLocale;
    var zone = element('div', 'status-dashboard-zone', clockTimezone);
    var clockDisplay = element('div', 'status-dashboard-clock-display');
    clockDisplay.appendChild(time);
    clockDetails.appendChild(clockDisplay);
    clockDetails.appendChild(date);
    clockHeading.appendChild(zone);
    var trend = element('div', 'status-dashboard-mini-trend');
    trend.appendChild(element('div', 'status-dashboard-label', 'AVAILABILITY'));
    var history = [];
    var selectedRange = '24h';
    var ranges = { '24h': 86400, '7gg': 604800, '30gg': 2592000 };
    var daySuffixes = { it: 'gg', en: 'd', de: 'T', fr: 'j', es: 'd' };
    var daySuffix = daySuffixes[clockLocale.toLowerCase().split('-')[0]] || 'd';
    try { selectedRange = view.sessionStorage.getItem('nagios.status.trendRange') || '24h'; } catch (error) {}
    if (!ranges[selectedRange]) selectedRange = '24h';
    var rangeControls = element('div', 'status-dashboard-trend-ranges');
    rangeControls.setAttribute('role', 'group');
    rangeControls.setAttribute('aria-label', 'Periodo disponibilità complessiva');
    Object.keys(ranges).forEach(function (range) {
      var button = element('button', '', range.replace('gg', daySuffix));
      button.setAttribute('data-range', range);
      button.type = 'button';
      button.disabled = true;
      button.setAttribute('aria-pressed', String(range === selectedRange));
      button.addEventListener('click', function () {
        selectedRange = range;
        try { view.sessionStorage.setItem('nagios.status.trendRange', range); } catch (error) {}
        renderTrend();
      });
      rangeControls.appendChild(button);
    });
    var trendValue = element('strong', 'status-dashboard-trend-value', '—');
    var trendBars = element('div', 'status-dashboard-trend-bars');
    trendBars.setAttribute('aria-label', 'Disponibilità complessiva');
    var trendNote = element('div', 'status-dashboard-trend-note', 'Caricamento…');
    trend.appendChild(trendValue);
    trend.appendChild(rangeControls);
    trend.appendChild(trendBars);
    trend.appendChild(trendNote);
    clock.appendChild(trend);
    clock.appendChild(clockDetails);
    function renderTrend() {
      rangeControls.querySelectorAll('button').forEach(function (button) {
        button.setAttribute('aria-pressed', String(button.getAttribute('data-range') === selectedRange));
      });
      trendBars.replaceChildren();
      trendValue.textContent = '—';
      trendValue.removeAttribute('title');
      var now = Date.now() / 1000;
      var samples = history.filter(function (sample) { return sample.timestamp >= now - ranges[selectedRange]; });
      trendBars.hidden = !samples.length;
      if (!samples.length) {
        trendNote.textContent = 'Dati non disponibili nel periodo';
        return;
      }
      trendBars.title = 'Storico disponibile: ' + new Date(samples[0].timestamp * 1000).toLocaleString('it-IT') +
        ' – ' + new Date(samples[samples.length - 1].timestamp * 1000).toLocaleString('it-IT');
      var latest = samples[samples.length - 1];
      trendValue.textContent = latest.availability_pct.toFixed(1) + '%';
      trendValue.title = 'Ultimo campione: ' + new Date(latest.timestamp * 1000).toLocaleString('it-IT');
      var buckets = [];
      var size = Math.ceil(samples.length / 32);
      for (var i = 0; i < samples.length; i += size) {
        var chunk = samples.slice(i, i + size);
        buckets.push({ timestamp: chunk[chunk.length - 1].timestamp,
          value: chunk.reduce(function (sum, sample) { return sum + sample.availability_pct; }, 0) / chunk.length });
      }
      var values = buckets.map(function (sample) { return sample.value; });
      var min = Math.min.apply(null, values), max = Math.max.apply(null, values);
      var padding = max - min < .001 ? .5 : Math.max(.2, (max - min) * .15);
      var lower = Math.max(0, min - padding), upper = Math.min(100, max + padding);
      if (upper - lower < 1) {
        var middle = (upper + lower) / 2;
        lower = Math.max(0, middle - .5); upper = Math.min(100, middle + .5);
      }
      buckets.forEach(function (sample) {
        var bar = element('span', 'status-dashboard-trend-bar');
        bar.style.height = Math.max(2, (sample.value - lower) / Math.max(.1, upper - lower) * 100) + '%';
        bar.title = new Date(sample.timestamp * 1000).toLocaleString('it-IT') + ' · ' + sample.value.toFixed(1) + '%';
        trendBars.appendChild(bar);
      });
      trendNote.textContent = 'Complessiva · ' + lower.toFixed(1) + '–' + upper.toFixed(1) + '%';
    }
    // Read the same snapshot used by live.php, once per page load.
    view.fetch(new URL('../live_trend_snapshot.json', doc.location.href).href, {
      cache: 'no-store', credentials: 'same-origin'
    }).then(function (response) {
      if (!response.ok) throw new Error('Snapshot unavailable');
      return response.json();
    }).then(function (snapshot) {
      var now = Date.now() / 1000;
      history = Array.isArray(snapshot.samples) ? snapshot.samples.filter(function (sample) {
        return Number.isFinite(sample.timestamp) && sample.timestamp >= now - ranges['30gg'] &&
          sample.timestamp <= now && Number.isFinite(sample.availability_pct) &&
          sample.availability_pct >= 0 && sample.availability_pct <= 100;
      }) : [];
      history.sort(function (a, b) { return a.timestamp - b.timestamp; });
      rangeControls.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
      renderTrend();
    }).catch(function () {
      trendNote.textContent = 'Dati non disponibili';
      trendBars.hidden = true;
    });
    var dateFormatter = new Intl.DateTimeFormat(clockLocale, {
      timeZone: clockTimezone, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
    });
    var timeFormatter = new Intl.DateTimeFormat('en-GB', {
      timeZone: clockTimezone, hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23'
    });
    function updateClock() {
      var now = new Date();
      time.dateTime = now.toISOString();
      var clockParts = {};
      timeFormatter.formatToParts(now).forEach(function (part) {
        clockParts[part.type] = part.value;
      });
      var timeText = clockParts.hour + ':' + clockParts.minute + ':' + clockParts.second;
      timeHoursMinutes.nodeValue = timeText.slice(0, -2);
      timeSeconds.textContent = timeText.slice(-2);
      var dateParts = {};
      dateFormatter.formatToParts(now).forEach(function (part) {
        dateParts[part.type] = part.value;
      });
      function capitalize(value) { return value.charAt(0).toUpperCase() + value.slice(1); }
      date.textContent = capitalize(dateParts.weekday) + ', ' + dateParts.day + ' ' +
        capitalize(dateParts.month) + ' ' + dateParts.year;
    }
    function updateColors() {
      colorUpdates.forEach(function (update) { update(); });
    }
    // Move native header nodes, preserving their filters and navigation links.
    header.before(cockpit);
    instruments.appendChild(header);
    instruments.appendChild(gauges);
    gauges.appendChild(clock);
    doc.body.classList.add('status-dashboard');
    updateColors();
    updateClock();
    // The timer belongs to the CGI window and ends when that page reloads.
    view.setInterval(updateClock, 1000);
    var observer = new view.MutationObserver(updateColors);
    observer.observe(doc.documentElement, { attributes: true, attributeFilter: ['class', 'data-theme'] });
  }

  function addOutputDivider(doc, table, header) {
    if (!header || dividerTables.has(table)) return;
    dividerTables.add(table);
    table.classList.add('has-status-output-divider');
    function positionDivider() {
      var spacing = parseFloat(doc.defaultView.getComputedStyle(table).borderSpacing) || 0;
      table.style.setProperty('--status-output-divider-x', (header.offsetLeft - spacing / 2) + 'px');
    }
    positionDivider();
    if (doc.defaultView.ResizeObserver) {
      var observer = new doc.defaultView.ResizeObserver(positionDivider);
      observer.observe(table);
      observer.observe(header);
    } else {
      doc.defaultView.addEventListener('resize', positionDivider);
    }
  }

  // Native sort URLs can repeat parameters; the CGI uses the last value.
  function lastParam(url, name) {
    var values = url.searchParams.getAll(name);
    return values.length ? values[values.length - 1] : null;
  }

  function removeNativeNotificationNotice(doc) {
    doc.querySelectorAll('.infoBoxBadProcStatus').forEach(function (notice) {
      if (/^[-–—]?\s*Notifications are disabled[.!]?$/i.test(notice.textContent.replace(/\s+/g, ' ').trim())) {
        notice.remove();
      }
    });
  }

  window.enhanceNagiosStatusSorting = function (frame) {
    try {
      var doc = frame && frame.contentDocument;
      if (!doc || !doc.body) return;
      if (!refreshCountdowns.has(doc)) updateFooterRefresh(doc, null);
      var current = new URL(doc.location.href);
      if (!/\.cgi$/.test(current.pathname)) return;
      removeNativeNotificationNotice(doc);
      if (!doc.body.classList.contains('status') || !/\/status\.cgi$/.test(current.pathname)) return;
      addLiveIndicator(doc);
      addStatusDashboard(doc);
      var table = doc.querySelector('body.status > table.status:has(> tbody > tr > th:nth-child(7))');
      if (!table) return;
      var toolbar = doc.getElementById('pagelimit');
      var summary = Array.from(doc.querySelectorAll('body > table.pageTitle .statusSort')).find(function (node) {
        return /^Entries sorted by\b/.test(node.textContent.trim());
      });
      if (toolbar && summary) {
        summary.classList.add('status-sort-summary');
        toolbar.classList.add('has-sort-summary');
        toolbar.appendChild(summary);
      }
      var direction = lastParam(current, 'sorttype');
      var option = lastParam(current, 'sortoption');
      var headers = table.querySelectorAll(':scope > tbody > tr > th');
      if (!option && headers.length) {
        var hostLink = headers[0].querySelector('a[href*="sortoption="]');
        if (hostLink) option = lastParam(new URL(hostLink.href), 'sortoption');
      }

      headers.forEach(function (header) {
        header.removeAttribute('aria-sort');
        header.querySelectorAll('a[href*="sorttype="][href*="sortoption="]').forEach(function (link) {
          var target = new URL(link.href);
          var sortDirection = lastParam(target, 'sorttype');
          if (sortDirection !== '1' && sortDirection !== '2') return;
          var original = link.querySelector('img');
          if (!original) return;
          var label = original.getAttribute('title') || original.getAttribute('alt') ||
            'Sort ' + header.textContent.trim() + (sortDirection === '1' ? ' ascending' : ' descending');
          link.classList.add('status-sort-link');
          link.setAttribute('aria-label', label);
          link.setAttribute('title', label);
          if (!link.querySelector('svg')) {
            var svg = doc.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.setAttribute('width', '12');
            svg.setAttribute('height', '12');
            svg.setAttribute('aria-hidden', 'true');
            svg.setAttribute('focusable', 'false');
            var path = doc.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', sortDirection === '1' ? 'm6 15 6-6 6 6' : 'm6 9 6 6 6-6');
            svg.appendChild(path);
            link.appendChild(svg);
          }
          var active = sortDirection === direction && lastParam(target, 'sortoption') === option;
          link.classList.toggle('is-active', active);
          if (active) header.setAttribute('aria-sort', direction === '1' ? 'ascending' : 'descending');
        });
      });
      addOutputDivider(doc, table, headers[6]);
    } catch (error) {
      // Preserve native controls on unrelated, unavailable or cross-origin pages.
    }
  };
})();
