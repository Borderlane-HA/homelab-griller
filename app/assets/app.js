/* HomeLab Griller v2 — mobile-first interactions, no dependencies */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const el = (tag, cls, value) => { const n = document.createElement(tag); if (cls) n.className = cls; if (value != null) n.textContent = value; return n; };
  const form = $('guest-order-form');
  if (form) {
    const wizard = $('order-wizard');
    const panels = [...wizard.querySelectorAll('.wizard-panel')];
    const next = $('wizard-next'), back = $('wizard-back'), submit = $('wizard-submit');
    const countLabel = ($('cart-counter').textContent.replace(/^\d+\s*/, '') || 'items');
    const draftKey = 'griller-v2-draft:' + location.pathname + location.search.split('&edit=')[0] + ':' + wizard.dataset.edit;
    let step = 1, activeCategory = '';
    const cards = () => [...form.querySelectorAll('.menu-product')];
    const qty = card => card.querySelector('.qty-input');
    const picked = () => cards().filter(card => Number(qty(card).value) > 0);
    const count = () => picked().reduce((n, card) => n + Number(qty(card).value), 0);
    const refreshCount = () => { $('cart-counter').textContent = String(count()) + ' ' + countLabel; };
    const saveDraft = () => {
      const values = [...form.elements].filter(x => x.name && !['hidden', 'submit'].includes(x.type))
        .map(x => ({ name: x.name, value: x.value, checked: x.checked, type: x.type }));
      try { sessionStorage.setItem(draftKey, JSON.stringify(values)); } catch (_) { /* storage may be disabled */ }
    };
    try {
      if (wizard.dataset.edit === '0') {
        const saved = JSON.parse(sessionStorage.getItem(draftKey) || 'null');
        if (saved) for (const v of saved) {
          const matches = [...form.elements].filter(x => x.name === v.name && x.type === v.type && x.value === v.value);
          if (v.type === 'checkbox' || v.type === 'radio') matches.forEach(x => x.checked = v.checked);
          else [...form.elements].filter(x => x.name === v.name && x.type === v.type).forEach(x => x.value = v.value);
        }
      }
    } catch (_) {}
    const review = () => {
      const target = $('order-review'); target.replaceChildren();
      if (!picked().length) { target.appendChild(el('p', 'muted', wizard.dataset.emptyLabel || 'Please add an item.')); return; }
      picked().forEach(card => {
        const row = el('div', 'review-line');
        row.appendChild(el('strong', '', qty(card).value + '× ' + card.dataset.product));
        const options = [...card.querySelectorAll('input[type=checkbox]:checked')].map(x => x.value);
        const doneness = card.querySelector('select')?.selectedOptions[0]?.textContent;
        if (doneness && doneness !== '—') options.push(doneness);
        const note = card.querySelector('input[name$="[note]"]')?.value;
        if (note) options.push(note);
        row.appendChild(el('small', 'muted', options.join(' · ')));
        target.appendChild(row);
      });
    };
    // Only scroll when navigating between steps; never on ingredient or quantity changes.
    const show = (n, userNavigation = false) => {
      step = n;
      panels.forEach(p => p.hidden = Number(p.dataset.step) !== step);
      $('step-counter').textContent = step + ' / 3';
      $('wizard-progress').style.width = (step / 3 * 100) + '%';
      back.hidden = step === 1;
      next.hidden = step === 3;
      submit.hidden = step !== 3;
      if (step === 2) cards().forEach(card => { card.hidden = !!activeCategory && card.dataset.category !== activeCategory; });
      if (step === 3) review();
      if (userNavigation && step > 1) wizard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    wizard.querySelectorAll('[data-category]').forEach(button => button.addEventListener('click', () => {
      activeCategory = button.dataset.category;
      show(2, true);
    }));
    $('choose-other-category').addEventListener('click', () => { activeCategory = ''; show(2, false); });
    next.addEventListener('click', () => {
      if (step === 1) { activeCategory = ''; show(2, true); }
      else if (step === 2) {
        if (!count()) { $('cart-counter').classList.add('attention'); setTimeout(() => $('cart-counter').classList.remove('attention'), 1100); return; }
        show(3, true);
      }
    });
    back.addEventListener('click', () => show(Math.max(1, step - 1), true));
    form.querySelectorAll('[data-delta]').forEach(button => button.addEventListener('click', () => {
      const input = button.parentNode.querySelector('.qty-input');
      input.value = Math.min(20, Math.max(0, Number(input.value || 0) + Number(button.dataset.delta)));
      input.dispatchEvent(new Event('input', { bubbles: true }));
    }));
    form.addEventListener('change', e => {
      // Selecting an ingredient, doneness or writing a note must never reset a manually selected quantity.
      const target = e.target;
      if ((target.matches('input[type="checkbox"]') && target.checked) || (target.matches('select') && target.value)) {
        const card = target.closest('.menu-product');
        if (card && Number(qty(card).value) === 0) qty(card).value = 1;
      }
      refreshCount(); saveDraft();
    });
    form.addEventListener('input', e => {
      const target = e.target;
      if (target.classList.contains('qty-input')) target.value = String(Math.min(20, Math.max(0, Number(target.value || 0))));
      refreshCount(); saveDraft();
    });
    form.addEventListener('submit', e => { if (!count()) { e.preventDefault(); show(2, true); return; } sessionStorage.removeItem(draftKey); });
    refreshCount(); show(wizard.dataset.edit !== '0' ? 2 : 1);
  }

  function toast(message) {
    const node = el('div', 'ready-toast', '✓ ' + message);
    node.setAttribute('role', 'status'); document.body.appendChild(node);
    setTimeout(() => node.remove(), 7000);
  }
  const status = $('guest-order-status');
  if (status) {
    const counts = new Map();
    const capture = root => [...root.querySelectorAll('[data-order-id]')].map(x => ({ id: x.dataset.orderId, ready: Number(x.dataset.readyCount), items: [...x.querySelectorAll('[data-item-status="done"]')].map(n => n.dataset.itemName) }));
    for (const x of capture(status)) counts.set(x.id, x);
    const refresh = async () => {
      if (document.hidden || status.contains(document.activeElement)) return;
      try {
        const res = await fetch(status.dataset.pollUrl, { cache: 'no-store', credentials: 'same-origin' });
        if (!res.ok) return;
        const html = await res.text();
        // An identical response must not replace nodes: it can reset scroll/focus on mobile.
        const next = new DOMParser().parseFromString(html, 'text/html');
        if (next.body.innerHTML === status.innerHTML) return;
        const fresh = capture(next);
        for (const o of fresh) {
          const previous = counts.get(o.id);
          if (previous && o.ready > previous.ready) {
            const newItems = o.items.filter(name => !previous.items.includes(name));
            const name = newItems.join(', ') || o.items.join(', ');
            const word = document.documentElement.lang === 'de' ? 'fertig' : (document.documentElement.lang === 'es' ? 'listo' : 'ready');
            toast('#' + o.id + ': ' + name + ' ' + word + ' (' + o.ready + '/' + next.querySelector('[data-order-id="' + o.id + '"]')?.querySelectorAll('.order-item-line').length + ')');
            if ('vibrate' in navigator) navigator.vibrate([100, 50, 100]);
          }
          counts.set(o.id, o);
        }
        status.replaceChildren(...[...next.body.children]);
      } catch (_) { /* retry quietly */ }
    };
    setInterval(refresh, 7000);
  }

  const searchButton = $('weather-city-search');
  if (searchButton) {
    const results = $('weather-place-results');
    const city = $('weather-city');
    const findCity = async () => {
      results.replaceChildren(el('small', 'muted', '…'));
      try {
        const res = await fetch(searchButton.dataset.url + '&q=' + encodeURIComponent(city.value.trim()), { credentials: 'same-origin' });
        if (!res.ok) throw new Error('weather search unavailable');
        const data = await res.json();
        results.replaceChildren();
        if (!data.results?.length) { results.appendChild(el('small', 'muted', 'No matching location')); return; }
        data.results.forEach(p => {
          const b = el('button', 'place-pick', p.name); b.type = 'button';
          b.addEventListener('click', () => { city.value = p.name; $('weather-lat').value = p.lat; $('weather-lon').value = p.lon; results.replaceChildren(); });
          results.appendChild(b);
        });
      } catch (_) { results.replaceChildren(el('small', 'muted', 'Search unavailable. You can enter coordinates manually.')); }
    };
    searchButton.addEventListener('click', findCity);
    city?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); findCity(); } });
  }

  const kitchen = $('kitchen-live-board');
  if (kitchen) {
    const search = $('kitchen-search');
    let dragging = null, saving = false, lastHtml = kitchen.innerHTML;
    const applySearch = () => {
      const q = (search?.value || '').toLowerCase().trim();
      kitchen.querySelectorAll('.ticket').forEach(t => { t.hidden = !!q && !t.dataset.search.includes(q); });
    };
    search?.addEventListener('input', applySearch);
    const reload = async (force = false) => {
      if (saving || dragging || (!force && document.hidden)) return;
      try {
        const res = await fetch(kitchen.dataset.pollUrl, { cache: 'no-store', credentials: 'same-origin' });
        if (!res.ok) return;
        const html = await res.text();
        const newBoard = new DOMParser().parseFromString(html, 'text/html').body.innerHTML;
        if (newBoard !== kitchen.innerHTML) { kitchen.innerHTML = newBoard; lastHtml = newBoard; applySearch(); }
      } catch (_) {}
    };
    const move = async (itemId, status) => {
      if (!itemId || !status || saving) return;
      saving = true;
      try {
        const body = new URLSearchParams({ csrf: kitchen.dataset.csrf, item_id: String(itemId), status });
        const res = await fetch(kitchen.dataset.statusUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body });
        if (!res.ok || !(await res.json()).ok) throw new Error('Could not update status');
      } catch (_) { toast('Status could not be updated'); }
      finally { saving = false; await reload(true); }
    };
    const clearTarget = () => kitchen.querySelectorAll('.lane').forEach(l => l.classList.remove('drag-over'));
    const finish = (itemId, lane) => { clearTarget(); dragging = null; if (lane) move(itemId, lane.dataset.statusLane); };
    kitchen.addEventListener('dragstart', e => {
      const ticket = e.target.closest('.ticket');
      if (!ticket) return;
      dragging = ticket.dataset.itemId;
      e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', dragging);
      ticket.classList.add('ticket-dragging');
    });
    kitchen.addEventListener('dragend', () => { dragging = null; clearTarget(); kitchen.querySelectorAll('.ticket').forEach(t => t.classList.remove('ticket-dragging')); });
    kitchen.addEventListener('dragover', e => {
      const lane = e.target.closest('[data-status-lane]'); if (!lane || !dragging) return;
      e.preventDefault(); e.dataTransfer.dropEffect = 'move'; clearTarget(); lane.classList.add('drag-over');
    });
    kitchen.addEventListener('drop', e => {
      e.preventDefault(); const lane = e.target.closest('[data-status-lane]');
      const id = dragging || e.dataTransfer.getData('text/plain'); finish(id, lane);
    });
    // Long-press on the grab handle supports iOS/Android touch screens without a library.
    let gesture = null;
    kitchen.addEventListener('pointerdown', e => {
      if (e.pointerType === 'mouse' || !e.target.closest('.ticket-drag-handle')) return;
      const handle = e.target.closest('.ticket-drag-handle'), ticket = handle.closest('.ticket');
      if (!ticket) return;
      gesture = { id: ticket.dataset.itemId, x: e.clientX, y: e.clientY, active: false, lane: null, timer: null, handle };
      gesture.timer = setTimeout(() => { if (!gesture) return; gesture.active = true; dragging = gesture.id; handle.setPointerCapture?.(e.pointerId); ticket.classList.add('ticket-dragging'); navigator.vibrate?.(35); }, 280);
    });
    kitchen.addEventListener('pointermove', e => {
      if (!gesture) return;
      if (!gesture.active && Math.hypot(e.clientX - gesture.x, e.clientY - gesture.y) > 12) { clearTimeout(gesture.timer); gesture = null; return; }
      if (!gesture.active) return;
      e.preventDefault();
      if (e.clientY > window.innerHeight - 65) window.scrollBy({top:16,left:0,behavior:'instant'});
      else if (e.clientY < 95) window.scrollBy({top:-16,left:0,behavior:'instant'});
      clearTarget();
      gesture.lane = document.elementFromPoint(e.clientX, e.clientY)?.closest('[data-status-lane]') || null;
      gesture.lane?.classList.add('drag-over');
    }, { passive: false });
    const cancelGesture = (e, shouldMove) => {
      if (!gesture) return;
      clearTimeout(gesture.timer);const g = gesture;gesture = null;
      const target = shouldMove && g.active ? g.lane : null;
      kitchen.querySelectorAll('.ticket').forEach(t => t.classList.remove('ticket-dragging'));
      finish(g.id, target);
    };
    kitchen.addEventListener('pointerup', e => cancelGesture(e, true));
    kitchen.addEventListener('pointercancel', e => cancelGesture(e, false));
    setInterval(() => { if (!kitchen.contains(document.activeElement) && !kitchen.matches(':hover')) reload(); }, 5000);
    const fullscreen = $('kitchen-fullscreen');
    fullscreen?.addEventListener('click', () => { if (!document.fullscreenElement) document.documentElement.requestFullscreen?.(); else document.exitFullscreen?.(); });
  }
})();
