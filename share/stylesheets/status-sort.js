(function () {
  'use strict';

  var dividerTables = new WeakSet();

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

    var badge = title.querySelector('.status-live-badge');
    if (!(seconds > 0)) {
      if (badge) badge.remove();
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
  }

  function addStatusDashboard(doc) {
    if (doc.body.classList.contains('status-dashboard')) return;
    var title = doc.querySelector('.pageTitle .statusTitle');
    if (!title || !/^(?:Service|Host) Status Details For\b/.test(title.textContent.trim())) return;
    var row = title.closest('tr');
    var isHost = /^Host Status Details For\b/.test(title.textContent.trim());
    var totals = doc.querySelector('.headertable table.' + (isHost ? 'hostTotals' : 'serviceTotals'));
    if (!row || !totals) return;
    var cells = Array.from(totals.querySelectorAll(':scope > tbody > tr:nth-child(2) > td'));
    var headers = totals.querySelectorAll(':scope > tbody > tr:first-child > th');
    if (cells.length !== (isHost ? 4 : 5)) return;
    var counts = cells.map(function (cell) { return Number(cell.textContent.trim()); });
    if (counts.some(function (count) { return !Number.isFinite(count) || count < 0; })) return;
    var labels = isHost ? ['Up', 'Down', 'Unreachable', 'Pending'] : ['OK', 'Warning', 'Unknown', 'Critical', 'Pending'];
    var total = counts.reduce(function (sum, count) { return sum + count; }, 0);
    var view = doc.defaultView;
    function element(tag, className, text) {
      var node = doc.createElement(tag);
      node.className = className;
      if (text !== undefined) node.textContent = text;
      return node;
    }
    var chartCell = element('td', 'status-dashboard-chart-cell');
    var chart = element('section', 'status-dashboard-card status-dashboard-chart');
    chart.setAttribute('aria-label', isHost ? 'Host status distribution' : 'Service status distribution');
    chart.appendChild(element('div', 'status-dashboard-label', isHost ? 'HOST STATUS' : 'SERVICE STATUS'));
    var chartBody = element('div', 'status-dashboard-chart-body');
    var donut = element('div', 'status-dashboard-donut');
    donut.setAttribute('role', 'img');
    donut.setAttribute('aria-label', labels.map(function (label, index) {
      return label + ': ' + counts[index];
    }).join(', ') + '. Total: ' + total);
    var center = element('div', 'status-dashboard-donut-center');
    center.setAttribute('aria-hidden', 'true');
    center.appendChild(element('strong', '', total.toLocaleString('en-US')));
    center.appendChild(element('span', '', isHost ? 'hosts' : 'services'));
    donut.appendChild(center);
    chartBody.appendChild(donut);
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
    chartCell.appendChild(chart);

    var clockCell = element('td', 'status-dashboard-clock-cell');
    var clock = element('section', 'status-dashboard-card status-dashboard-clock');
    clock.setAttribute('aria-label', 'Local time on this device');
    var clockDetails = element('div', 'status-dashboard-clock-details');
    clockDetails.appendChild(element('div', 'status-dashboard-label', 'LOCAL TIME'));
    var time = element('time', 'status-dashboard-time');
    var date = element('div', 'status-dashboard-date');
    var zone = element('div', 'status-dashboard-zone', Intl.DateTimeFormat().resolvedOptions().timeZone);
    clockDetails.appendChild(time);
    clockDetails.appendChild(date);
    clockDetails.appendChild(zone);
    var trend = element('div', 'status-dashboard-mini-trend');
    trend.appendChild(element('div', 'status-dashboard-label', 'AVAILABILITY'));
    var history = [];
    var selectedRange = '24h';
    var ranges = { '24h': 86400, '7gg': 604800, '30gg': 2592000 };
    try { selectedRange = view.sessionStorage.getItem('nagios.status.trendRange') || '24h'; } catch (error) {}
    if (!ranges[selectedRange]) selectedRange = '24h';
    var rangeControls = element('div', 'status-dashboard-trend-ranges');
    rangeControls.setAttribute('role', 'group');
    rangeControls.setAttribute('aria-label', 'Periodo disponibilità complessiva');
    Object.keys(ranges).forEach(function (range) {
      var button = element('button', '', range);
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
        button.setAttribute('aria-pressed', String(button.textContent === selectedRange));
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
    clockCell.appendChild(clock);
    var dateFormatter = new Intl.DateTimeFormat('it-IT', {
      weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
    });
    function updateClock() {
      var now = new Date();
      time.dateTime = now.toISOString();
      time.textContent = now.toLocaleTimeString('en-GB', { hour12: false });
      var dateParts = {};
      dateFormatter.formatToParts(now).forEach(function (part) {
        dateParts[part.type] = part.value;
      });
      function capitalize(value) { return value.charAt(0).toUpperCase() + value.slice(1); }
      date.textContent = capitalize(dateParts.weekday) + ', ' + dateParts.day + ' ' +
        capitalize(dateParts.month) + ' ' + dateParts.year;
    }
    function updateColors() {
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
    }
    var titleCell = title.closest('td');
    titleCell.classList.add('status-dashboard-title-cell');
    // CGI emits an empty filter cell even when display_header is disabled.
    Array.from(row.children).forEach(function (cell) {
      if (cell !== titleCell) cell.classList.add('status-dashboard-unused-cell');
    });
    row.appendChild(chartCell);
    row.appendChild(clockCell);
    doc.body.classList.add('status-dashboard');
    function alignDashboardPanels() {
      var header = doc.querySelector('body > table.headertable');
      if (!header) return;
      var headerCells = header.querySelectorAll(':scope > tbody > tr > td');
      [chartCell, clockCell].forEach(function (cell, index) {
        var above = headerCells[index + 1];
        // On desktop, tuck each panel under its own totals card rather than
        // leaving a gap determined by the taller network information card.
        var lift = above && view.matchMedia('(min-width: 1051px)').matches
          ? Math.max(0, header.offsetHeight - above.offsetHeight) : 0;
        cell.style.marginTop = -lift + 'px';
      });
    }
    alignDashboardPanels();
    view.addEventListener('resize', alignDashboardPanels);
    if (view.ResizeObserver) {
      var layoutObserver = new view.ResizeObserver(alignDashboardPanels);
      var header = doc.querySelector('body > table.headertable');
      if (header) {
        layoutObserver.observe(header);
        header.querySelectorAll(':scope > tbody > tr > td').forEach(function (cell) {
          layoutObserver.observe(cell);
        });
      }
    }
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

  window.enhanceNagiosStatusSorting = function (frame) {
    try {
      var doc = frame && frame.contentDocument;
      if (!doc || !doc.body || !doc.body.classList.contains('status')) return;
      var current = new URL(doc.location.href);
      if (!/\/status\.cgi$/.test(current.pathname)) return;
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
