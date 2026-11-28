(() => {
  'use strict';
  const root = document.querySelector('body.live-overview');
  const app = root?.querySelector('.wrap'), grid = document.getElementById('live-widget-grid');
  if (!app || !grid || !root.classList.contains('status-dashboard')) return;
  const cockpit = document.getElementById('live-cockpit');
  const charts = cockpit.querySelectorAll('.status-dashboard-chart');
  const instruments = [cockpit.querySelector('.status-cockpit-ops'), charts[0], charts[1], cockpit.querySelector('.status-dashboard-clock')];
  if (instruments.some(node => !node)) return;
  grid.className = 'status-cockpit lw-grid';
  instruments.forEach((node,index) => { node.dataset.widget = ['network','hosts','services','clock'][index]; grid.append(node); });
  ['incidents','summary','oldest','impacted','host-map','ownership','incident-age'].forEach(id => grid.append(root.querySelector('[data-widget="'+id+'"]')));
  cockpit.remove();
  root.querySelectorAll('.layout,.live-section-heading').forEach(node => node.remove());
  root.querySelector('.lw-toolbar').hidden = false;
  const edit = root.querySelector('.lw-edit'), reset = root.querySelector('.lw-reset'), status = root.querySelector('.lw-layout-status');
  const widgets = new Map([...grid.children].map(node => [node.dataset.widget,node]));
  const defaults = [...widgets.keys()], spans = [3,3,3,3,8,4,8,4,4,4,4];
  widgets.forEach(node => { node.classList.add('lw-widget'); if (!node.hasAttribute('aria-label')) node.setAttribute('aria-label',node.querySelector('h2').textContent); });
  const choose = root.querySelector('.lw-choose'), picker = root.querySelector('.lw-picker'), choiceInputs = new Map();
  const storageKey = 'nagios.live.widgets.v1:'+location.pathname;
  let order = [...defaults], hiddenWidgets = new Set(), editing = false, drag = null, picked = null;
  const valid = list => Array.isArray(list) && list.length === defaults.length && new Set(list).size === defaults.length && list.every(id => widgets.has(id));
  function migrate(list) {
    if (!Array.isArray(list)) return null;
    const known = list.filter(id => widgets.has(id));
    if (new Set(known).size !== known.length) return null;
    return [...known,...defaults.filter(id => !known.includes(id))];
  }
  function refreshChoices() {
    choose.textContent = 'Widgets · '+(defaults.length-hiddenWidgets.size)+'/'+defaults.length;
    choiceInputs.forEach((input,id) => { input.checked = !hiddenWidgets.has(id); });
    root.querySelector('.lw-empty').hidden = hiddenWidgets.size !== defaults.length;
  }
  function applyOrder(next) {
    if (!valid(next)) return;
    const active = document.activeElement;
    order = [...next];
    let slot = 0;
    order.forEach(id => {
      const index = hiddenWidgets.has(id) ? 0 : slot++, span = spans[index];
      const node = widgets.get(id);
      node.hidden = hiddenWidgets.has(id);
      node.style.setProperty('--lw-span',span);
      node.style.setProperty('--lw-tablet-span',span === 4 ? 3 : Math.min(span,6));
      grid.append(node);
    });
    refreshChoices();
    if (active && root.contains(active) && active.classList.contains('lw-handle')) active.focus({preventScroll:true});
  }
  function save() {
    let stored = false;
    try { localStorage.setItem(storageKey,JSON.stringify({version:2,order,hidden:[...hiddenWidgets]})); stored = true; } catch (error) {}
    status.textContent = stored ? 'Layout saved in this browser' : 'Layout updated; browser storage unavailable';
  }
  function move(id,index) {
    const next = order.filter(value => value !== id);
    next.splice(Math.max(0,Math.min(index,next.length)),0,id);
    applyOrder(next);
  }
  function clearPicked(restore = false) {
    if (!picked) return;
    const old = picked; picked = null;
    widgets.get(old.id).classList.remove('lw-picked');
    widgets.get(old.id).querySelector('.lw-handle').setAttribute('aria-pressed','false');
    if (restore) applyOrder(old.original);
  }
  function pick(id) {
    picked = {id,original:[...order]};
    widgets.get(id).classList.add('lw-picked');
    widgets.get(id).querySelector('.lw-handle').setAttribute('aria-pressed','true');
    status.textContent = 'Choose a destination, or use arrow keys and Enter. Escape cancels.';
  }
  widgets.forEach((node,id) => {
    const handle = document.createElement('button');
    handle.type = 'button'; handle.className = 'lw-handle';
    handle.setAttribute('aria-label','Move '+node.getAttribute('aria-label'));
    handle.setAttribute('aria-pressed','false');
    handle.textContent = '⠿';
    const heading = node.querySelector('.panel-head,.status-cockpit-ops-heading,.status-cockpit-instrument-heading,.status-dashboard-clock-toolbar');
    heading.classList.add('lw-widget-header');
    heading.prepend(handle);
    const label = document.createElement('label'), input = document.createElement('input'), name = document.createElement('span');
    input.type = 'checkbox'; input.checked = true;
    name.textContent = node.getAttribute('aria-label'); label.append(input,name); root.querySelector('.lw-options').append(label);
    choiceInputs.set(id,input);
    input.addEventListener('change',() => {
      const visible = input.checked;
      clearDrag(); clearPicked(true);
      if (visible) hiddenWidgets.delete(id); else hiddenWidgets.add(id);
      applyOrder(order); save();
    });
  });
  let initial;
  try { initial = JSON.parse(localStorage.getItem(storageKey)); } catch (error) {}
  function restore(saved) {
    const next = migrate(Array.isArray(saved) ? saved : saved?.widgetOrder || saved?.order);
    if (!next) return false;
    const hidden = saved?.hiddenWidgets || saved?.hidden;
    hiddenWidgets = new Set(Array.isArray(hidden) ? hidden.filter(id => widgets.has(id)) : []);
    applyOrder(next);
    return true;
  }
  if (!restore(initial)) applyOrder(defaults);
  function updateRefreshPause() {
    document.dispatchEvent(new CustomEvent('nagios:refresh-pause',{detail:editing || !picker.hidden}));
  }
  choose.addEventListener('click',() => { picker.hidden = !picker.hidden; choose.setAttribute('aria-expanded',String(!picker.hidden)); updateRefreshPause(); });
  root.querySelector('.lw-picker-close').addEventListener('click',() => { picker.hidden = true; choose.setAttribute('aria-expanded','false'); choose.focus(); updateRefreshPause(); });
  root.querySelector('.lw-show-all').addEventListener('click',() => { clearPicked(true); hiddenWidgets.clear(); applyOrder(order); save(); });
  picker.addEventListener('keydown',event => { if (event.key === 'Escape') { event.preventDefault(); picker.hidden = true; choose.setAttribute('aria-expanded','false'); choose.focus(); updateRefreshPause(); } });
  edit.addEventListener('click',() => {
    if (drag) return;
    if (picked) { clearPicked(); save(); }
    editing = !editing;
    app.classList.toggle('lw-is-editing',editing);
    edit.setAttribute('aria-pressed',String(editing));
    edit.textContent = editing ? 'Done' : 'Edit layout';
    reset.hidden = !editing;
    updateRefreshPause();
    status.textContent = editing ? 'Drag the handles, select two panels, or use arrow keys and Enter. Refresh is paused while editing.' : '';
  });
  reset.addEventListener('click',() => { clearPicked(); hiddenWidgets.clear(); applyOrder(defaults); save(); });
  function clearDrag() {
    if (!drag) return;
    const old = drag; drag = null;
    widgets.get(old.id).classList.remove('lw-origin');
    grid.querySelectorAll('.lw-target').forEach(node => node.classList.remove('lw-target'));
    old.ghost?.remove();
    cancelAnimationFrame(old.frame);
    if (old.handle.hasPointerCapture(old.pointerId)) old.handle.releasePointerCapture(old.pointerId);
  }
  grid.addEventListener('pointerdown',event => {
    const handle = event.target.closest('.lw-handle');
    if (!editing || !handle || picked || (event.button !== 0 && event.pointerType !== 'touch')) return;
    drag = {id:handle.closest('[data-widget]').dataset.widget,handle,pointerId:event.pointerId,startX:event.clientX,startY:event.clientY,moved:false,target:null,ghost:null};
    handle.setPointerCapture(event.pointerId);
  });
  grid.addEventListener('pointermove',event => {
    if (!drag || drag.pointerId !== event.pointerId) return;
    if (!drag.moved && Math.hypot(event.clientX-drag.startX,event.clientY-drag.startY)<6) return;
    if (!drag.moved) {
      drag.moved = true;
      widgets.get(drag.id).classList.add('lw-origin');
      drag.ghost = document.createElement('div'); drag.ghost.className = 'lw-ghost';
      drag.ghost.textContent = widgets.get(drag.id).getAttribute('aria-label'); app.append(drag.ghost);
      status.textContent = 'Drop onto the panel at the desired position.';
      drag.frame = requestAnimationFrame(scrollDrag);
    }
    drag.x = event.clientX; drag.y = event.clientY;
    positionDrag();
  });
  function positionDrag() {
    const x = drag.x, y = drag.y;
    const bounds = app.getBoundingClientRect();
    drag.ghost.style.left = Math.max(0,Math.min(x-bounds.left+12,bounds.width-230))+'px';
    drag.ghost.style.top = y-bounds.top+12+'px';
    const target = document.elementFromPoint(x,y)?.closest('.lw-widget');
    grid.querySelectorAll('.lw-target').forEach(node => node.classList.remove('lw-target'));
    drag.target = target && grid.contains(target) && target.dataset.widget !== drag.id ? target.dataset.widget : null;
    if (drag.target) widgets.get(drag.target).classList.add('lw-target');
  }
  function scrollDrag() {
    if (!drag?.moved) return;
    const distance = drag.y < 64 ? -12 : drag.y > innerHeight-64 ? 12 : 0;
    if (distance) { window.scrollBy(0,distance); positionDrag(); }
    drag.frame = requestAnimationFrame(scrollDrag);
  }
  let suppressClick = false;
  grid.addEventListener('pointerup',event => {
    if (!drag || drag.pointerId !== event.pointerId) return;
    const {id,moved,target} = drag;
    clearDrag();
    if (!moved) return;
    suppressClick = true; setTimeout(() => { suppressClick = false; },0);
    if (target) { move(id,order.indexOf(target)); save(); }
    else status.textContent = 'Move cancelled';
  });
  grid.addEventListener('pointercancel',() => { if (drag) { clearDrag(); status.textContent = 'Move cancelled'; } });
  grid.addEventListener('lostpointercapture',() => { if (drag) clearDrag(); });
  grid.addEventListener('click',event => {
    const handle = event.target.closest('.lw-handle');
    if (!editing || !handle || suppressClick) return;
    const id = handle.closest('[data-widget]').dataset.widget;
    if (!picked) pick(id);
    else { const old = picked.id; clearPicked(); if (old !== id) move(old,order.indexOf(id)); save(); }
  });
  grid.addEventListener('keydown',event => {
    const handle = event.target.closest('.lw-handle');
    if (!editing || !handle) return;
    if (event.key === 'Escape') { event.preventDefault(); clearDrag(); clearPicked(true); status.textContent = 'Move cancelled'; return; }
    const direction = {ArrowLeft:-1,ArrowUp:-1,ArrowRight:1,ArrowDown:1}[event.key];
    if (!direction) return;
    event.preventDefault();
    const id = handle.closest('[data-widget]').dataset.widget;
    if (!picked) pick(id);
    if (picked.id !== id) return;
    const visible = order.filter(value => !hiddenWidgets.has(value)), target = visible[visible.indexOf(id)+direction];
    if (target) move(id,order.indexOf(target));
    status.textContent = 'Position '+(order.filter(value => !hiddenWidgets.has(value)).indexOf(id)+1)+' of '+visible.length+'. Enter confirms, Escape cancels.';
  });
  const map = root.querySelector('.lw-host-map'), select = root.querySelector('.lw-host-select');
  if (map && select) {
    function inspectHost() {
      map.querySelector('.lw-host-selected')?.classList.remove('lw-host-selected');
      map.querySelector('[data-host-index="'+select.value+'"]')?.classList.add('lw-host-selected');
      const option = select.selectedOptions[0], link = root.querySelector('.lw-host-detail a');
      link.href = option.dataset.url;
      link.textContent = option.textContent+' ↗';
    }
    map.addEventListener('click',event => {
      const tile = event.target.closest('[data-host-index]');
      if (!tile) return;
      select.value = tile.dataset.hostIndex; inspectHost();
    });
    select.addEventListener('change',inspectHost);
    inspectHost();
  }
})();
