(function () {
  'use strict';

  var dividerTables = new WeakSet();

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
